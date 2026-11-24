<?php
/**
 * End-to-end audit service tests.
 *
 * @package X7velo
 */

namespace X7velo\Tests\Audit;

use RuntimeException;
use WP_UnitTestCase;
use X7velo\Audit\AuditService;
use X7velo\Audit\AuditStore;
use X7velo\Audit\Category;
use X7velo\Audit\Check;
use X7velo\Audit\CheckResult;
use X7velo\Audit\Status;
use X7velo\Settings;
use X7velo\Tests\Helpers\Products;

/**
 * Full audit run, storage, determinism, no HTTP, no PII.
 */
final class AuditServiceTest extends WP_UnitTestCase {

	/**
	 * Requires WooCommerce.
	 */
	public function set_up(): void {
		parent::set_up();
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is not loaded.' );
		}
	}

	/**
	 * New service.
	 */
	private static function service(): AuditService {
		return new AuditService( new AuditStore(), new Settings(), X7VELO_VERSION );
	}

	/**
	 * A run stores a versioned, non-autoloaded record with all 12 checks.
	 */
	public function test_run_stores_record(): void {
		Products::mix( 3, 1, array( 'regular_price' => '' ) );

		$record = self::service()->run();

		$this->assertSame( 2, $record['schema_version'] );
		$this->assertSame( AuditService::STATE_COMPLETE, $record['state'] );
		foreach ( $record['checks'] as $check ) {
			$this->assertArrayNotHasKey( 'message', $check, 'Built-in messages are rendered at presentation time.' );
		}
		$this->assertSame( X7VELO_VERSION, $record['plugin_version'] );
		$this->assertCount( 18, $record['checks'] );
		$this->assertIsInt( $record['score'] );
		$this->assertSame( $record, ( new AuditStore() )->get() );

		global $wpdb;
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", AuditStore::OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertContains( $autoload, array( 'no', 'off' ) );

		$this->assertNotFalse( wp_json_encode( $record ), 'Record must be JSON-serialisable.' );
	}

	/**
	 * AC11: same state → identical results (ignoring the timestamp).
	 */
	public function test_deterministic(): void {
		Products::mix( 5, 3, array( 'image' => false ) );
		Products::variable( array( '10', '' ) );

		$first  = self::service()->run();
		$second = self::service()->run();
		unset( $first['generated_at'], $second['generated_at'] );
		$this->assertSame( $first, $second );
	}

	/**
	 * AC24: no outbound HTTP during an audit.
	 */
	public function test_no_outbound_http(): void {
		$attempts = 0;
		$block    = static function () use ( &$attempts ) {
			++$attempts;
			return new \WP_Error( 'blocked', 'blocked' );
		};
		add_filter( 'pre_http_request', $block );
		Products::mix( 2, 1, array( 'gtin' => '' ) );

		self::service()->run();

		$this->assertSame( 0, $attempts );
	}

	/**
	 * AC25: stored details hold only scalars and at most 5 product IDs; no customer data.
	 */
	public function test_no_pii_in_record(): void {
		update_option( 'woocommerce_email_from_address', 'owner@example.com' );
		update_option( 'woocommerce_store_address', 'Secret street 1' );
		Products::mix( 0, 8, array( 'image' => false ) );

		$record = self::service()->run();
		$json   = (string) wp_json_encode( $record );

		$this->assertStringNotContainsString( 'owner@example.com', $json );
		$this->assertStringNotContainsString( 'Secret street', $json );
		foreach ( $record['checks'] as $check ) {
			$this->assertLessThanOrEqual( 5, count( $check['details']['examples'] ?? array() ) );
		}
	}

	/**
	 * AC6: blog_public = 0 caps the score at 20 and marks VIS-01 critical.
	 */
	public function test_search_visibility_caps_score(): void {
		update_option( 'blog_public', '0' );
		Products::complete();

		$record = self::service()->run();

		$this->assertLessThanOrEqual( 20, $record['score'] );
		$this->assertSame( 'VIS-01', $record['checks'][0]['id'] );
		$this->assertTrue( $record['checks'][0]['critical'] );
	}

	/**
	 * AC9/AC10 end to end: a failing product scan errors only the product checks.
	 */
	public function test_scanner_failure_isolated(): void {
		$throw = static function () {
			throw new RuntimeException( 'db down' );
		};
		add_filter( 'x7velo_audit_excluded_product_types', $throw );

		$record = self::service()->run();
		$errors = array_column(
			array_filter( $record['checks'], static fn( $c ) => Status::ERROR === $c['status'] ),
			'id'
		);

		// DATA-01..05 + STORE-03 share the scanner → 6 errors → no score.
		$this->assertSame( array( 'DATA-01', 'DATA-02', 'DATA-03', 'DATA-04', 'DATA-05', 'STORE-03' ), array_values( $errors ) );
		$this->assertNull( $record['score'] );
		$this->assertFalse( $record['complete'] );
		$this->assertSame( AuditService::STATE_INCOMPLETE, $record['state'] );
		$this->assertSame( Status::PASS, $record['checks'][0]['status'] );
	}

	/**
	 * Memory abort: product checks error with scan_memory_limit, record incomplete, no score.
	 */
	public function test_memory_abort_marks_record_incomplete(): void {
		Products::complete();
		add_filter( 'x7velo_scan_memory_ceiling', static fn() => 1 );

		$record = self::service()->run();
		$codes  = array_column( $record['checks'], 'code', 'id' );

		$this->assertSame( 'scan_memory_limit', $codes['DATA-01'] );
		$this->assertSame( 'scan_memory_limit', $codes['STORE-03'] );
		$this->assertFalse( $record['complete'] );
		$this->assertSame( AuditService::STATE_INCOMPLETE, $record['state'] );
		$this->assertNull( $record['score'] );
	}

	/**
	 * A hostile third-party check cannot bloat the stored record past 64 KB.
	 */
	public function test_hostile_check_keeps_record_small(): void {
		Products::mix( 3, 2, array( 'image' => false ) );
		$hostile = new class() implements Check {
			/**
			 * ID.
			 */
			public function id(): string {
				return 'EVIL-01';
			}

			/**
			 * Category.
			 */
			public function category(): string {
				return Category::CRAWL;
			}

			/**
			 * Weight.
			 */
			public function weight(): int {
				return 5;
			}

			/**
			 * Run.
			 */
			public function run(): CheckResult {
				$details = array( 'examples' => range( 1, 50000 ) );
				for ( $i = 0; $i < 500; $i++ ) {
					$details[ 'key' . $i ] = array_fill( 0, 1000, str_repeat( 'z', 10000 ) );
				}
				return new CheckResult( Status::WARN, 'evil', str_repeat( 'x', 1000000 ), $details );
			}
		};
		add_filter(
			'x7velo_audit_checks',
			static function ( array $checks ) use ( $hostile ) {
				$checks[] = $hostile;
				return $checks;
			}
		);

		$record = self::service()->run();

		$this->assertCount( 19, $record['checks'] );
		$this->assertLessThan( 64 * KB_IN_BYTES, strlen( maybe_serialize( $record ) ) );
	}

	/**
	 * Presentation adds absolute guided URLs.
	 */
	public function test_present_adds_guided_url(): void {
		$service = self::service();
		$record  = $service->present( $service->run() );
		$this->assertSame( admin_url( 'options-reading.php' ), $record['checks'][0]['guided_url'] );
	}

	/**
	 * A record from another schema version is ignored.
	 */
	public function test_store_ignores_other_schema_versions(): void {
		update_option( AuditStore::OPTION, array( 'schema_version' => 1 ) );
		$this->assertNull( ( new AuditStore() )->get() );
	}

	/**
	 * Zero products: state no_products, no score, product checks not applicable.
	 */
	public function test_no_products_state(): void {
		$record = self::service()->run();
		$this->assertSame( AuditService::STATE_NO_PRODUCTS, $record['state'] );
		$this->assertNull( $record['score'] );
		$this->assertTrue( $record['complete'] );
		$codes = array_column( $record['checks'], 'code', 'id' );
		$this->assertSame( 'no_products', $codes['DATA-01'] );
		$this->assertSame( 'no_products', $codes['STORE-03'] );
	}

	/**
	 * Messages render in the viewer's locale (stub translation), not the locale of the run.
	 */
	public function test_messages_rendered_in_viewer_locale(): void {
		Products::mix( 1, 1, array( 'regular_price' => '' ) );
		$service = self::service();
		$record  = $service->run();

		add_filter( 'get_available_languages', static fn( $langs ) => array_merge( (array) $langs, array( 'pt_BR' ) ) );
		// WP_Locale_Switcher caches available languages when constructed; use a fresh one.
		$original_switcher             = $GLOBALS['wp_locale_switcher'];
		$GLOBALS['wp_locale_switcher'] = new \WP_Locale_Switcher(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test.
		$GLOBALS['wp_locale_switcher']->init();
		// Ignore the bundled pt_BR files (languages/): the markers below must wrap the English source.
		$skip_files = static fn( $override, $domain ) => 'x7velo' === $domain ? true : $override;
		add_filter( 'override_load_textdomain', $skip_files, 10, 2 );
		unload_textdomain( 'x7velo' );
		add_filter(
			'gettext_x7velo',
			static function ( $translation ) {
				return 'pt_BR' === get_locale() ? '[pt] ' . $translation : $translation;
			}
		);
		add_filter(
			'ngettext_x7velo',
			static function ( $translation ) {
				return 'pt_BR' === get_locale() ? '[pt] ' . $translation : $translation;
			}
		);

		$viewer = self::factory()->user->create(
			array(
				'role'   => 'shop_manager',
				'locale' => 'pt_BR',
			)
		);
		wp_set_current_user( $viewer );
		try {
			$presented = $service->present( $record );
		} finally {
			$GLOBALS['wp_locale_switcher'] = $original_switcher; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test.
			remove_filter( 'override_load_textdomain', $skip_files, 10 );
		}
		$messages = array_column( $presented['checks'], 'message', 'id' );

		$this->assertStringStartsWith( '[pt] ', $messages['VIS-01'] );
		$this->assertStringStartsWith( '[pt] 1 product has no price', $messages['DATA-01'] );
		$this->assertSame( 'en_US', get_locale(), 'Locale restored after presenting.' );

		wp_set_current_user( 0 );
		$cli = $service->present( $record );
		$this->assertStringStartsNotWith( '[pt]', $cli['checks'][0]['message'] );
	}

	/**
	 * Examples are presented as {id, title, edit_url}; variations link to the parent; deleted IDs are skipped.
	 */
	public function test_present_examples(): void {
		$service = self::service();
		$ids     = Products::mix( 0, 2, array( 'image' => false ) );
		$parent  = Products::variable( array( '5' ) );
		$kids    = get_posts(
			array(
				'post_type'   => 'product_variation',
				'post_parent' => $parent,
				'fields'      => 'ids',
			)
		);

		$record = array(
			'checks' => array(
				array(
					'id'      => 'DATA-02',
					'code'    => 'missing_image',
					'details' => array(
						'missing'  => 3,
						'examples' => array( $ids[0], 999999, (int) $kids[0], $ids[1] ),
					),
				),
			),
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
		$examples = $service->present( $record )['checks'][0]['details']['examples'];

		$this->assertSame( array( $ids[0], (int) $kids[0], $ids[1] ), array_column( $examples, 'id' ) );
		$this->assertSame( get_edit_post_link( $parent, 'raw' ), $examples[1]['edit_url'] );
		$this->assertSame( get_the_title( $ids[0] ), $examples[0]['title'] );
		$this->assertStringContainsString( 'post=' . $ids[0], $examples[0]['edit_url'] );
	}

	/**
	 * Points available = score if that check passed − current score (real formula, caps included).
	 */
	public function test_points_available(): void {
		$record = array(
			'score'  => 0,
			'checks' => array(
				array(
					'id'       => 'VIS-01',
					'weight'   => 10,
					'status'   => Status::FAIL,
					'critical' => true,
				),
				array(
					'id'       => 'CRAWL-01',
					'code'     => 'blocked_by_search_visibility',
					'weight'   => 10,
					'status'   => Status::FAIL,
					'critical' => true,
				),
				array(
					'id'       => 'DATA-01',
					'weight'   => 8,
					'status'   => Status::WARN,
					'critical' => false,
				),
				array(
					'id'       => 'DATA-02',
					'weight'   => 72,
					'status'   => Status::PASS,
					'critical' => false,
				),
			),
		);
		// Current: (4 + 72) / 100 = 76, capped to 20.
		$record['score'] = ( new \X7velo\Audit\Scorer() )->score( array_map( static fn( $c ) => $c, $record['checks'] ) )['score'];
		$this->assertSame( 20, $record['score'] );

		$points = AuditService::points_available( $record );

		$this->assertSame( 96 - 20, $points[0], 'VIS-01 also lifts CRAWL-01 (same root cause): 96 − 20.' );
		$this->assertSame( 0, $points[1], 'CRAWL-01 alone still leaves the VIS-01 cap.' );
		$this->assertSame( 0, $points[2], 'Warn → pass on a capped score adds nothing.' );
		$this->assertSame( 0, $points[3] );

		$record['score'] = null;
		$this->assertSame( array( null, null, null, null ), AuditService::points_available( $record ) );
	}

	/**
	 * The presented record includes points_available.
	 */
	public function test_present_includes_points(): void {
		Products::mix( 3, 1, array( 'regular_price' => '' ) );
		$service = self::service();
		$checks  = array_column( $service->present( $service->run() )['checks'], null, 'id' );
		$this->assertGreaterThan( 0, $checks['DATA-01']['points_available'] );
		$this->assertSame( 0, $checks['VIS-01']['points_available'] );
	}

	/**
	 * AC14: four errors → state incomplete, no score.
	 */
	public function test_four_errors_state_incomplete(): void {
		Products::complete();
		add_filter(
			'x7velo_audit_checks',
			function ( array $checks ) {
				for ( $i = 1; $i <= 4; $i++ ) {
					$checks[] = new class( $i ) implements Check {
						/**
						 * Constructor.
						 *
						 * @param int $n Number.
						 */
						public function __construct( private int $n ) {}

						/**
						 * ID.
						 */
						public function id(): string {
							return 'BOOM-0' . $this->n;
						}

						/**
						 * Category.
						 */
						public function category(): string {
							return Category::CRAWL;
						}

						/**
						 * Weight.
						 */
						public function weight(): int {
							return 1;
						}

						/**
						 * Run.
						 *
						 * @throws \RuntimeException Always.
						 */
						public function run(): CheckResult {
							throw new \RuntimeException( 'boom' );
						}
					};
				}
				return $checks;
			}
		);

		$record = self::service()->run();
		$this->assertSame( AuditService::STATE_INCOMPLETE, $record['state'] );
		$this->assertNull( $record['score'] );
		$this->assertSame( 4, $record['counts']['error'] );
	}

	/**
	 * GET_LOCK returning NULL (no named-lock support) → audit runs unlocked and the action fires.
	 */
	public function test_lock_unavailable_runs_unlocked(): void {
		$fired = 0;
		add_action(
			'x7velo_audit_lock_unavailable',
			static function () use ( &$fired ) {
				++$fired;
			}
		);
		add_filter( 'query', static fn( $sql ) => false !== strpos( (string) $sql, 'GET_LOCK' ) ? 'SELECT NULL' : $sql );

		$record = self::service()->run();

		$this->assertSame( 1, $fired );
		$this->assertSame( AuditService::SCHEMA_VERSION, $record['schema_version'] );
	}
}
