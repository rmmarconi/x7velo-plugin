<?php
/**
 * Runner and registry tests.
 *
 * @package X7velo
 */

namespace X7velo\Tests\Audit;

use RuntimeException;
use WP_UnitTestCase;
use X7velo\Audit\Catalog\ProductScanner;
use X7velo\Audit\Category;
use X7velo\Audit\Check;
use X7velo\Audit\CheckResult;
use X7velo\Audit\Registry;
use X7velo\Audit\Runner;
use X7velo\Audit\Status;
use X7velo\Audit\StoreOnlyCheck;
use X7velo\Plugin;
use X7velo\Settings;

/**
 * Runner error isolation (AC9) and registry filtering.
 */
final class RunnerTest extends WP_UnitTestCase {

	/**
	 * A configurable stub check.
	 *
	 * @param string        $id       ID.
	 * @param callable|null $behavior Returns a CheckResult or throws.
	 * @param string        $category Category.
	 * @param int           $weight   Weight.
	 */
	private static function stub( string $id, ?callable $behavior = null, string $category = Category::CRAWL, int $weight = 5 ): Check {
		return new class( $id, $behavior, $category, $weight ) implements Check {
			/**
			 * Constructor.
			 *
			 * @param string        $id       ID.
			 * @param callable|null $behavior Behaviour.
			 * @param string        $category Category.
			 * @param int           $weight   Weight.
			 */
			public function __construct( private string $id, private $behavior, private string $category, private int $weight ) {}

			/**
			 * ID.
			 */
			public function id(): string {
				return $this->id;
			}

			/**
			 * Category.
			 */
			public function category(): string {
				return $this->category;
			}

			/**
			 * Weight.
			 */
			public function weight(): int {
				return $this->weight;
			}

			/**
			 * Run.
			 */
			public function run(): CheckResult {
				if ( $this->behavior ) {
					return ( $this->behavior )();
				}
				return new CheckResult( Status::PASS, 'ok', 'OK' );
			}
		};
	}

	/**
	 * AC9: a throwing check becomes "error"; the others still run.
	 */
	public function test_throwing_check_is_isolated(): void {
		$errors = array();
		add_action(
			'x7velo_audit_check_error',
			static function ( $id ) use ( &$errors ) {
				$errors[] = $id;
			}
		);

		$entries = ( new Runner() )->run(
			Registry::describe(
				array(
					self::stub( 'A-01' ),
					self::stub(
						'A-02',
						static function () {
							throw new RuntimeException( 'boom' );
						}
					),
					self::stub(
						'A-03',
						static function () {
							return 'not a result';
						}
					),
					self::stub( 'A-04' ),
				)
			)
		);

		$statuses = array_map( static fn( $e ) => $e['result']->status(), $entries );
		$this->assertSame( array( Status::PASS, Status::ERROR, Status::ERROR, Status::PASS ), $statuses );
		$this->assertSame( array( 'A-02', 'A-03' ), $errors );
		$this->assertSame( 'check_error', $entries[1]['result']->code() );
		$this->assertStringNotContainsString( 'boom', $entries[1]['result']->message() );
	}

	/**
	 * The registry ships the 18 spec v2 checks, in spec order, with spec weights totalling 100,
	 * with and without WooCommerce (spec §15.2: store checks stay in the list as stand-ins).
	 */
	public function test_default_checks(): void {
		$entries = ( new Registry( new ProductScanner(), new Settings() ) )->checks();
		foreach ( $entries as $entry ) {
			$this->assertSame(
				! Plugin::is_woocommerce_active() && in_array( $entry['id'], StoreOnlyCheck::IDS, true ),
				$entry['check'] instanceof StoreOnlyCheck,
				$entry['id']
			);
		}
		$map = array_column( $entries, 'weight', 'id' );
		$this->assertSame(
			array(
				'VIS-01'    => 10,
				'VIS-02'    => 5,
				'VIS-03'    => 4,
				'VIS-04'    => 4,
				'CRAWL-01'  => 10,
				'CRAWL-02'  => 5,
				'CRAWL-03'  => 5,
				'CRAWL-04'  => 5,
				'SCHEMA-01' => 10,
				'DATA-01'   => 8,
				'DATA-02'   => 6,
				'DATA-03'   => 6,
				'DATA-04'   => 4,
				'DATA-05'   => 2,
				'STORE-01'  => 5,
				'STORE-02'  => 5,
				'STORE-03'  => 3,
				'STORE-04'  => 3,
			),
			$map
		);
		$this->assertSame( 100, array_sum( $map ) );
	}

	/**
	 * The filter can add checks; invalid entries and duplicate IDs are dropped.
	 */
	public function test_filter_adds_and_validates(): void {
		$filter = static function ( array $checks ): array {
			$checks[] = self::stub( 'CRAWL-01' );
			$checks[] = self::stub( 'VIS-01' ); // Duplicate: dropped.
			$checks[] = 'not a check';
			$checks[] = self::stub( 'BAD-01', null, 'nope' );
			$checks[] = self::stub( 'BAD-02', null, Category::CRAWL, -1 );
			return $checks;
		};
		add_filter( 'x7velo_audit_checks', $filter );

		$ids = array_column( ( new Registry( new ProductScanner(), new Settings() ) )->checks(), 'id' );

		$this->assertContains( 'CRAWL-01', $ids );
		$this->assertNotContains( 'BAD-01', $ids );
		$this->assertNotContains( 'BAD-02', $ids );
		$this->assertCount( 18, $ids, 'Stub CRAWL-01 duplicates the built-in one and is dropped.' );
		$this->assertSame( 'VIS-01', $ids[0] );
	}

	/**
	 * Checks whose metadata throws are skipped, and never called again.
	 */
	public function test_broken_metadata_is_skipped(): void {
		$broken = new class() implements Check {
			/**
			 * Times run() was called.
			 *
			 * @var int
			 */
			public int $runs = 0;

			/**
			 * ID that throws.
			 *
			 * @throws RuntimeException Always.
			 */
			public function id(): string {
				throw new RuntimeException( 'no id' );
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
			 */
			public function run(): CheckResult {
				++$this->runs;
				return new CheckResult( Status::PASS, 'ok', 'OK' );
			}
		};

		$entries = Registry::describe( array( $broken, self::stub( 'A-01' ) ) );
		$this->assertSame( array( 'A-01' ), array_column( $entries, 'id' ) );

		( new Runner() )->run( $entries );
		$this->assertSame( 0, $broken->runs );
	}

	/**
	 * At most MAX_CHECKS checks, weights capped at MAX_WEIGHT.
	 */
	public function test_registry_caps(): void {
		$checks = array( self::stub( 'HEAVY', null, Category::CRAWL, Registry::MAX_WEIGHT + 1 ) );
		for ( $i = 0; $i < 150; $i++ ) {
			$checks[] = self::stub( 'X-' . $i );
		}
		$entries = Registry::describe( $checks );
		$this->assertCount( Registry::MAX_CHECKS, $entries );
		$this->assertNotContains( 'HEAVY', array_column( $entries, 'id' ) );
		$this->assertSame( 'X-0', $entries[0]['id'] );
	}

	/**
	 * CheckResult enforces the no-PII details shape.
	 */
	public function test_check_result_sanitizes_details(): void {
		$result  = new CheckResult(
			Status::WARN,
			'x',
			'msg',
			array(
				'examples' => array( 1, 2, 3, 4, 5, 6, 7 ),
				'count'    => 3,
				'object'   => new \stdClass(),
				'nested'   => array( 'a', array( 'b' ) ),
			)
		);
		$details = $result->details();
		$this->assertSame( array( 1, 2, 3, 4, 5 ), $details['examples'] );
		$this->assertSame( 3, $details['count'] );
		$this->assertArrayNotHasKey( 'object', $details );
		$this->assertSame( array( 'a' ), $details['nested'] );
	}

	/**
	 * Message is plain text and capped; strings, lists and key counts in details are capped.
	 */
	public function test_check_result_size_caps(): void {
		$details = array();
		for ( $i = 0; $i < 40; $i++ ) {
			$details[ 'k' . $i ] = str_repeat( '<b>x</b>', 500 );
		}
		$details = array( 'list' => range( 1, 50 ) ) + $details;

		$result = new CheckResult( Status::WARN, str_repeat( 'c', 300 ), '<script>alert(1)</script>' . str_repeat( 'm', 5000 ), $details );

		$this->assertSame( CheckResult::MAX_MESSAGE_CHARS, mb_strlen( $result->message() ) );
		$this->assertStringNotContainsString( '<script>', $result->message() );
		$this->assertLessThanOrEqual( CheckResult::MAX_CODE_CHARS, strlen( $result->code() ) );
		$this->assertCount( CheckResult::MAX_DETAIL_KEYS, $result->details() );
		$this->assertCount( CheckResult::MAX_LIST_ITEMS, $result->details()['list'] );
		$this->assertSame( CheckResult::MAX_STRING_CHARS, mb_strlen( $result->details()['k0'] ) );
		$this->assertStringNotContainsString( '<b>', $result->details()['k0'] );
	}

	/**
	 * Critical is only kept on failures; unknown statuses are rejected.
	 */
	public function test_check_result_critical_and_validation(): void {
		$this->assertFalse( ( new CheckResult( Status::PASS, 'x', 'm', array(), 'manual', '', true ) )->is_critical() );
		$this->assertTrue( ( new CheckResult( Status::FAIL, 'x', 'm', array(), 'manual', '', true ) )->is_critical() );
		$this->expectException( \InvalidArgumentException::class );
		new CheckResult( 'great', 'x', 'm' );
	}
}
