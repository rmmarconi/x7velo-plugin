<?php
/**
 * Robots fixer integration (spec §6.3, §6.4; AC29–AC43).
 *
 * @package X7velo
 */

namespace X7velo\Tests\Fixers\Robots;

use WP_REST_Request;
use WP_UnitTestCase;
use X7velo\Audit\AuditService;
use X7velo\Audit\AuditStore;
use X7velo\Audit\Checks\AiSearchCrawlers;
use X7velo\Audit\Checks\Sitemap;
use X7velo\Audit\Status;
use X7velo\Detection\Detector;
use X7velo\Detection\RobotsRules;
use X7velo\Fixers\Robots\RobotsBlock;
use X7velo\Fixers\Robots\RobotsFixer;
use X7velo\FixerStatus;
use X7velo\Settings;

/**
 * Front-end filter, audit hook, fixer status lines, REST 409s.
 */
final class RobotsFixerTest extends WP_UnitTestCase {

	/**
	 * Admin user.
	 *
	 * @var int
	 */
	private int $admin = 0;

	/**
	 * Requires WooCommerce; readable permalinks; public site; all options off.
	 */
	public function set_up(): void {
		parent::set_up();
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is not loaded.' );
		}
		update_option( 'blog_public', '1' );
		$this->set_permalink_structure( '/%postname%/' );
		foreach ( array(
			'shop'      => 'shop',
			'cart'      => 'cart',
			'checkout'  => 'checkout',
			'myaccount' => 'my-account',
		) as $key => $slug ) {
			update_option(
				'woocommerce_' . $key . '_page_id',
				self::factory()->post->create(
					array(
						'post_type'   => 'page',
						'post_status' => 'publish',
						'post_name'   => $slug,
						'post_title'  => ucfirst( $slug ),
					)
				)
			);
		}
		delete_option( Settings::OPTION );
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * Clean up.
	 */
	public function tear_down(): void {
		delete_option( Settings::OPTION );
		parent::tear_down();
	}

	/**
	 * What core's do_robots() would print (default + every robots_txt callback).
	 */
	private function robots(): string {
		$output  = "User-agent: *\n";
		$output .= "Disallow: /wp-admin/\n";
		$output .= "Allow: /wp-admin/admin-ajax.php\n";
		return (string) apply_filters( 'robots_txt', $output, get_option( 'blog_public' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.
	}

	/**
	 * Owner rule that blocks everyone (another plugin at a normal priority).
	 */
	private function block_everyone(): void {
		add_filter( 'robots_txt', static fn() => "User-agent: *\nDisallow: /\nDisallow: /?s=\n", 20 );
	}

	/**
	 * Settings update.
	 *
	 * @param array<string, mixed> $values Values.
	 */
	private function set( array $values ): void {
		( new Settings() )->update( $values );
	}

	/**
	 * Priority contract with track E.
	 */
	public function test_registered_at_contract_priority(): void {
		$this->assertSame( PHP_INT_MAX - 10, has_filter( 'robots_txt', array( RobotsFixer::class, 'filter_robots_txt' ) ) );
	}

	/**
	 * AC41: all options off after on → byte-identical output; nothing when everything is off.
	 */
	public function test_all_off_restores_bytes(): void {
		$this->block_everyone();
		$baseline = $this->robots();
		$this->assertStringNotContainsString( 'x7velo', $baseline );

		$this->set(
			array(
				'fix_robots_search'         => true,
				'fix_robots_block_training' => true,
				'block_google_extended'     => true,
				'block_experimental'        => array( 'Bytespider' ),
			)
		);
		$on = $this->robots();
		$this->assertStringStartsWith( rtrim( $baseline ) . "\n\n" . RobotsBlock::BEGIN, $on, 'Owner lines are never edited; our block is appended.' );
		$this->assertStringEndsWith( RobotsBlock::END . "\n", $on );

		$this->set(
			array(
				'fix_robots_search'         => false,
				'fix_robots_block_training' => false,
				'block_google_extended'     => false,
				'block_experimental'        => array(),
			)
		);
		$this->assertSame( $baseline, $this->robots() );
	}

	/**
	 * AC29 end to end: one group with the 17 scored tokens, owner disallows minus "/", the real
	 * Woo page paths, admin lines.
	 */
	public function test_ac29_block_on_front_end(): void {
		$this->block_everyone();
		$this->set( array( 'fix_robots_search' => true ) );
		$out   = $this->robots();
		$block = substr( $out, (int) strpos( $out, RobotsBlock::BEGIN ) );
		$this->assertSame( 17, substr_count( $block, 'User-agent: ' ) );
		$this->assertStringContainsString( "Allow: /\nDisallow: /?s=\n", $block );
		foreach ( RobotsFixer::private_paths() as $path ) {
			$this->assertStringContainsString( 'Disallow: ' . $path . "\n", $block );
		}
		$this->assertNotEmpty( RobotsFixer::private_paths(), 'Woo pages exist in the test install.' );
		$this->assertStringContainsString( "Disallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n", $block );
		$this->assertDoesNotMatchRegularExpression( '/^Disallow: \/$/m', $block );
	}

	/**
	 * Our callback returns its input while owner rules are computed; served() adds the block.
	 */
	public function test_excluded_from_owner_rules(): void {
		$this->block_everyone();
		$this->set( array( 'fix_robots_search' => true ) );
		$rules = new RobotsRules( new Detector() );
		$this->assertStringNotContainsString( 'x7velo', $rules->owner()['text'] );
		$served = $rules->served();
		$this->assertTrue( $served['x7velo_block'] );
		$this->assertSame( $this->robots(), $served['text'], 'The audit sees exactly what the front end serves.' );
	}

	/**
	 * AC33 front-end half: after Yoast's block, no second Sitemap line for its sitemap.
	 */
	public function test_after_yoast_block(): void {
		add_filter(
			'robots_txt',
			static fn() => "User-agent: *\nDisallow: /\n# START YOAST BLOCK\n# ---------------------------\nUser-agent: *\nDisallow:\n\nSitemap: " . home_url( '/wp-sitemap.xml' ) . "\n# ---------------------------\n# END YOAST BLOCK",
			99999
		);
		$this->set( array( 'fix_robots_search' => true ) );
		$out = $this->robots();
		$this->assertGreaterThan( strpos( $out, '# END YOAST BLOCK' ), strpos( $out, RobotsBlock::BEGIN ) );
		$this->assertSame( 1, substr_count( $out, 'Sitemap: ' . home_url( '/wp-sitemap.xml' ) ) );
	}

	/**
	 * AC35 / brief: CRAWL-01 and CRAWL-02 (and the score) rise after enabling.
	 */
	public function test_crawl_checks_and_score_rise_after_enabling(): void {
		$this->block_everyone();
		$detector = new Detector();
		$crawl01  = static fn() => ( new AiSearchCrawlers( new RobotsRules( new Detector() ) ) )->run();
		$crawl02  = static fn() => ( new Sitemap( new Detector(), new RobotsRules( new Detector() ) ) )->run();
		$audit    = new AuditService( new AuditStore(), new Settings(), X7VELO_VERSION );
		self::factory()->post->create(
			array(
				'post_type'   => 'product',
				'post_status' => 'publish',
				'post_title'  => 'Sample',
			)
		);

		$this->assertSame( Status::FAIL, $crawl01()->status() );
		$this->assertSame( Status::WARN, $crawl02()->status(), 'The owner rules drop core\'s Sitemap line.' );
		$before = $audit->run();
		$this->assertNotNull( $detector->sitemap() );

		$this->set( array( 'fix_robots_search' => true ) );
		$this->assertSame( Status::PASS, $crawl01()->status() );
		$this->assertSame( Status::PASS, $crawl02()->status() );
		$after = $audit->run();
		$this->assertGreaterThan( (int) $before['score'], (int) $after['score'] );
	}

	/**
	 * AC39: training options never change CRAWL-01's status.
	 */
	public function test_training_options_never_change_crawl01(): void {
		add_filter( 'robots_txt', static fn( $out ) => $out . "\nUser-agent: OAI-SearchBot\nDisallow: /\n", 20 );
		$statuses = array();
		foreach ( array( false, true ) as $search ) {
			foreach ( array( array(), array( 'fix_robots_block_training' => true ), array( 'block_google_extended' => true ), array( 'block_experimental' => array( 'Bytespider' ) ) ) as $training ) {
				$this->set(
					array_merge(
						array(
							'fix_robots_search'         => $search,
							'fix_robots_block_training' => false,
							'block_google_extended'     => false,
							'block_experimental'        => array(),
						),
						$training
					)
				);
				$statuses[ $search ? 'on' : 'off' ][] = ( new AiSearchCrawlers( new RobotsRules( new Detector() ) ) )->run()->status();
			}
		}
		$this->assertSame( array( Status::WARN ), array_values( array_unique( $statuses['off'] ) ) );
		$this->assertSame( array( Status::WARN ), array_values( array_unique( $statuses['on'] ) ), 'OAI-SearchBot is owner-named, so it stays blocked.' );
	}

	/**
	 * AC42: blog_public = 0 → no block, x7velo_robots_block empty, PATCH on → 409, off allowed.
	 */
	public function test_search_engines_discouraged(): void {
		$this->set( array( 'fix_robots_block_training' => true ) );
		update_option( 'blog_public', '0' );
		$before = "User-agent: *\nDisallow: /\n";
		$this->assertSame( $before, RobotsFixer::filter_robots_txt( $before, '0' ) );
		$this->assertSame( '', apply_filters( 'x7velo_robots_block', '', $before ) );
		$this->assertSame( 'cannot_run', FixerStatus::compute( ( new Settings() )->all() )['robots']['state'] );

		wp_set_current_user( $this->admin );
		$this->assertSame( 409, $this->patch( array( 'fix_robots_search' => true ) )->get_status() );
		$this->assertSame( 200, $this->patch( array( 'fix_robots_block_training' => false ) )->get_status() );
	}

	/**
	 * Plain permalinks: no virtual robots.txt in core → cannot_run, no block.
	 */
	public function test_plain_permalinks_cannot_run(): void {
		$this->set_permalink_structure( '' );
		$this->set( array( 'fix_robots_search' => true ) );
		$status = FixerStatus::compute( ( new Settings() )->all() )['robots'];
		$this->assertSame( 'cannot_run', $status['state'] );
		$this->assertSame( 'plain_permalinks', $status['reason'] );
		$this->assertSame( "User-agent: *\nDisallow: /\n", RobotsFixer::filter_robots_txt( "User-agent: *\nDisallow: /\n", '1' ) );
	}

	/**
	 * AC44: subfolder install → cannot_run.
	 */
	public function test_subfolder_install_cannot_run(): void {
		add_filter( 'option_home', static fn( $home ) => untrailingslashit( (string) $home ) . '/shop', 99 );
		$this->assertTrue( RobotsRules::is_subfolder_install() );
		$this->assertSame( 'cannot_run', FixerStatus::compute( ( new Settings() )->all() )['robots']['state'] );
		$this->assertSame( "x\n", RobotsFixer::filter_robots_txt( "x\n", '1' ) );
	}

	/**
	 * Fixer status `lines` (strings) for "Show the exact lines", sanitised by FixerStatus.
	 */
	public function test_status_lines_preview(): void {
		$this->block_everyone();
		$status = FixerStatus::compute( ( new Settings() )->all() )['robots'];
		$this->assertSame( 'off', $status['state'] );
		$this->assertStringStartsWith( "User-agent: OAI-SearchBot\n", $status['lines']['allow'] );
		$this->assertStringStartsWith( "User-agent: GPTBot\n", $status['lines']['training'] );
		$this->assertStringEndsWith( 'Disallow: /', $status['lines']['training'] );
		$this->assertSame( 'Sitemap: ' . home_url( '/wp-sitemap.xml' ), $status['lines']['sitemap'] );

		// GET /settings carries them.
		wp_set_current_user( $this->admin );
		$response = rest_do_request( new WP_REST_Request( 'GET', '/x7velo/v1/settings' ) );
		$this->assertSame( $status['lines'], $response->get_data()['fixer_status']['robots']['lines'] );
	}

	/**
	 * The `lines` sanitiser is strict: anything that isn't a robots record is dropped.
	 */
	public function test_lines_sanitiser_is_strict(): void {
		add_filter(
			'x7velo_fixer_status',
			static function ( $status ) {
				$status['robots']['lines'] = array(
					'allow'    => "User-agent: X\n<script>alert(1)</script>",
					'training' => array( 'nope' ),
					'sitemap'  => 'Sitemap: https://example.org/s.xml',
					'extra'    => 'Disallow: /',
				);
				$status['feed']['lines']   = array( 'allow' => 'Allow: /' );
				return $status;
			},
			99
		);
		$status = FixerStatus::compute( ( new Settings() )->all() );
		$this->assertSame(
			array(
				'allow'    => '',
				'training' => '',
				'sitemap'  => 'Sitemap: https://example.org/s.xml',
			),
			$status['robots']['lines']
		);
		$this->assertArrayNotHasKey( 'lines', $status['feed'] );
	}

	/**
	 * AC43: physical robots.txt → cannot_run, copy-paste lines from the file, file never touched.
	 */
	public function test_physical_file_copy_paste_and_untouched(): void {
		$path = ABSPATH . 'robots.txt';
		if ( file_exists( $path ) ) {
			$this->markTestSkipped( 'A real robots.txt exists in the test install.' );
		}
		file_put_contents( $path, "User-agent: *\nDisallow: /\nDisallow: /private/\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		$hash  = hash_file( 'sha256', $path );
		$mtime = filemtime( $path );
		try {
			$status = FixerStatus::compute( ( new Settings() )->all() )['robots'];
			$this->assertSame( 'cannot_run', $status['state'] );
			$this->assertSame( 'physical_robots_file', $status['reason'] );
			$this->assertStringContainsString( "Allow: /\nDisallow: /private/\n", $status['lines']['allow'] );
			$this->assertStringStartsWith( "User-agent: GPTBot\n", $status['lines']['training'] );

			// Same algorithm as the golden "physical-file".
			$built = RobotsBlock::build(
				"User-agent: *\nDisallow: /\nDisallow: /private/\n",
				array(
					'fix_robots_search'         => true,
					'fix_robots_block_training' => true,
				),
				\X7velo\Data\AiCrawlers::agents(),
				RobotsFixer::site()
			);
			$this->assertSame( $built['allow'], $status['lines']['allow'] );

			wp_set_current_user( $this->admin );
			$this->assertSame( 409, $this->patch( array( 'fix_robots_search' => true ) )->get_status() );
			$this->assertSame( 200, $this->patch( array( 'fix_robots_search' => false ) )->get_status() );
			$this->robots();
			( new AiSearchCrawlers( new RobotsRules( new Detector() ) ) )->run();
			clearstatcache();
			$this->assertSame( $hash, hash_file( 'sha256', $path ) );
			$this->assertSame( $mtime, filemtime( $path ) );
		} finally {
			unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture.
		}
	}

	/**
	 * Nothing is applied by default (activation writes defaults only).
	 */
	public function test_off_by_default(): void {
		$this->block_everyone();
		$this->assertStringNotContainsString( 'x7velo', $this->robots() );
		$this->assertSame( '', apply_filters( 'x7velo_robots_block', '', "User-agent: *\nDisallow: /\n" ) );
	}

	/**
	 * PATCH /settings.
	 *
	 * @param array<string, mixed> $body Body.
	 */
	private function patch( array $body ): \WP_REST_Response {
		$request = new WP_REST_Request( 'PATCH', '/x7velo/v1/settings' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );
		return rest_do_request( $request );
	}
}
