<?php
/**
 * The llms.txt fixer (spec §8.2; AC52–AC60).
 *
 * @package X7velo
 */

namespace X7velo\Tests\Fixers\LlmsTxt;

use WC_Product_Simple;
use WP_REST_Request;
use WP_UnitTestCase;
use X7velo\Fixers\CrawlModule;
use X7velo\Fixers\LlmsTxt\LlmsTxtBuilder;
use X7velo\Fixers\LlmsTxt\LlmsTxtEndpoint;
use X7velo\Fixers\PublicOutput;
use X7velo\FixerStatus;
use X7velo\Notices\NoticeState;
use X7velo\Notices\Notices;
use X7velo\Settings;

/**
 * Body, visibility rules, escaping, bounds, rewrite registration/flush, pause/resume, cache.
 */
final class LlmsTxtFixerTest extends WP_UnitTestCase {

	/**
	 * Admin user.
	 *
	 * @var int
	 */
	private int $admin = 0;

	/**
	 * Requires WooCommerce; readable permalinks; clean state.
	 */
	public function set_up(): void {
		parent::set_up();
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is not loaded.' );
		}
		StubLlmsAdapter::$active = false;
		StubLlmsAdapter::hook();
		update_option( 'blog_public', '1' );
		update_option( 'woocommerce_coming_soon', 'no' );
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
		delete_option( NoticeState::OPTION );
		delete_transient( LlmsTxtEndpoint::SYNC_GUARD );
		LlmsTxtEndpoint::remove_rule();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
	}

	/**
	 * Clean up.
	 */
	public function tear_down(): void {
		StubLlmsAdapter::$active = false;
		LlmsTxtEndpoint::remove_rule();
		delete_option( Settings::OPTION );
		delete_option( NoticeState::OPTION );
		parent::tear_down();
	}

	/**
	 * Creates a product.
	 *
	 * @param string               $name  Name.
	 * @param array<string, mixed> $props Extra props (setter => value).
	 */
	private function product( string $name, array $props = array() ): int {
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_status( 'publish' );
		$product->set_regular_price( '10' );
		foreach ( $props as $prop => $value ) {
			$product->{'set_' . $prop}( $value );
		}
		return $product->save();
	}

	/**
	 * Body with stored settings.
	 */
	private function body(): string {
		return LlmsTxtBuilder::build( ( new Settings() )->all() );
	}

	/**
	 * Lines of a `## <heading>` section.
	 *
	 * @param string $body    Body.
	 * @param string $heading Heading.
	 * @return string[]
	 */
	private function section( string $body, string $heading ): array {
		if ( ! preg_match( '/^## ' . preg_quote( $heading, '/' ) . "\n\n((?:- .*\n)+)/m", $body, $m ) ) {
			return array();
		}
		return explode( "\n", trim( $m[1] ) );
	}

	/**
	 * AC52: title, tagline, store line, Shop, Product categories, Products, Optional; no file.
	 */
	public function test_body_sections(): void {
		update_option( 'blogname', 'Loja & Co' );
		update_option( 'blogdescription', 'Handmade things' );
		$cat = self::factory()->term->create(
			array(
				'taxonomy' => 'product_cat',
				'name'     => 'Shoes',
			)
		);
		$this->product(
			'Red shoe',
			array(
				'short_description' => '<p>A <strong>red</strong> shoe.</p>',
				'category_ids'      => array( $cat ),
			)
		);
		( new Settings() )->update( array( 'fix_feed' => true ) );

		$body = $this->body();
		$this->assertStringStartsWith( "# Loja & Co\n\n> Handmade things\n\n", $body );
		$this->assertMatchesRegularExpression( '/^Online store (based in .+\. )?Prices in [A-Z]{3}\.$/m', $body );
		$this->assertSame( array( '- [Shop](' . get_permalink( wc_get_page_id( 'shop' ) ) . ')' ), $this->section( $body, 'Shop' ) );
		$this->assertSame( array( '- [Shoes](' . get_term_link( $cat ) . ')' ), $this->section( $body, 'Product categories' ) );
		$products = $this->section( $body, 'Products' );
		$this->assertCount( 1, $products );
		$this->assertMatchesRegularExpression( '#^- \[Red shoe\]\(http[^)]+\): A red shoe\.$#', $products[0] );
		$optional = implode( "\n", $this->section( $body, 'Optional' ) );
		$this->assertStringContainsString( '- [Product feed](' . add_query_arg( 'x7velo_feed', '1', home_url( '/' ) ) . ')', $optional );
		$this->assertStringContainsString( '[Sitemap](' . home_url( '/wp-sitemap.xml' ) . ')', $optional );
		$this->assertStringEndsWith( "\n", $body );
		$this->assertFileDoesNotExist( ABSPATH . 'llms.txt' );
		$this->assertFileDoesNotExist( dirname( WP_CONTENT_DIR ) . '/llms.txt' );
	}

	/**
	 * AC53: password-protected, private, draft and catalog-hidden products are absent;
	 * search-only visibility is still public.
	 */
	public function test_excludes_non_public(): void {
		$this->product( 'Visible one' );
		$this->product( 'Search only', array( 'catalog_visibility' => 'search' ) );
		$this->product( 'Catalog only', array( 'catalog_visibility' => 'catalog' ) );
		$this->product( 'Hidden one', array( 'catalog_visibility' => 'hidden' ) );
		$this->product( 'Secret one', array( 'post_password' => 'pw' ) );
		$this->product( 'Private one', array( 'status' => 'private' ) );
		$this->product( 'Draft one', array( 'status' => 'draft' ) );

		$products = implode( "\n", $this->section( $this->body(), 'Products' ) );
		foreach ( array( 'Visible one', 'Search only', 'Catalog only' ) as $name ) {
			$this->assertStringContainsString( '[' . $name . ']', $products );
		}
		foreach ( array( 'Hidden one', 'Secret one', 'Private one', 'Draft one' ) as $name ) {
			$this->assertStringNotContainsString( $name, $products );
		}
	}

	/**
	 * G2: messy names/descriptions stay on one escaped line each; no headings, links or HTML
	 * injected; reviews never appear.
	 */
	public function test_escaping_one_line_per_product(): void {
		$id = $this->product(
			"Evil\n# Heading [x](http://evil.example) <script>alert(1)</script> ]]> &amp; `code`",
			array( 'short_description' => "Line one\n\n## Injected\n&lt;img src=x onerror=alert(1)&gt; " . str_repeat( 'é', 300 ) )
		);
		wp_insert_comment(
			array(
				'comment_post_ID' => $id,
				'comment_author'  => 'Reviewer Name',
				'comment_content' => 'Review text',
				'comment_type'    => 'review',
			)
		);
		$body     = $this->body();
		$products = $this->section( $body, 'Products' );
		$this->assertCount( 1, $products );
		$line = $products[0];
		$this->assertStringStartsWith( '- [Evil # Heading \[x\](http://evil.example) ', $line );
		$this->assertStringNotContainsString( 'alert(1)</script', $line );
		$this->assertSame( 0, preg_match( '/(?<!\\\\)[<>]/', $line ), 'Every < and > is escaped.' );
		$this->assertStringContainsString( '\<img src=x onerror=alert(1)\>', $line );
		$this->assertStringContainsString( '\`code\`', $line );
		$this->assertSame( 0, preg_match( '/^## Injected/m', $body ) );
		$this->assertStringNotContainsString( 'Reviewer Name', $body );
		$this->assertStringNotContainsString( 'Review text', $body );
		// Description cut to 160 characters ("…" included).
		$desc = substr( $line, strpos( $line, '): ' ) + 3 );
		$this->assertLessThanOrEqual( LlmsTxtBuilder::DESCRIPTION_CHARS + 10, mb_strlen( $desc ) );
		$this->assertStringEndsWith( '…', $desc );
	}

	/**
	 * Bounded: at most 200 products, newest first, whatever the catalogue size.
	 */
	public function test_bounded_newest_first(): void {
		$ids = array();
		for ( $i = 0; $i < 205; $i++ ) {
			$ids[] = self::factory()->post->create(
				array(
					'post_type'   => 'product',
					'post_status' => 'publish',
					'post_title'  => 'P' . $i,
					'post_date'   => gmdate( 'Y-m-d H:i:s', 1700000000 + $i * 60 ),
				)
			);
		}
		$products = $this->section( $this->body(), 'Products' );
		$this->assertCount( LlmsTxtBuilder::MAX_PRODUCTS, $products );
		$this->assertStringStartsWith( '- [P204]', $products[0] );
		$this->assertStringStartsWith( '- [P5]', $products[199] );
	}

	/**
	 * AC52/AC54: rewrite registered only while serving; not when another source owns llms.txt.
	 */
	public function test_rewrite_only_while_serving(): void {
		global $wp_rewrite;
		LlmsTxtEndpoint::add_rule();
		$this->assertArrayNotHasKey( LlmsTxtEndpoint::REGEX, $wp_rewrite->extra_rules_top, 'Off by default.' );

		( new Settings() )->update( array( 'fix_llms_txt' => true ) );
		$this->assertTrue( LlmsTxtEndpoint::is_serving() );
		$this->assertSame( LlmsTxtEndpoint::TARGET, $wp_rewrite->extra_rules_top[ LlmsTxtEndpoint::REGEX ] ?? null, 'Registered on toggle change.' );
		$this->assertTrue( LlmsTxtEndpoint::rule_stored( (array) get_option( 'rewrite_rules' ) ), 'Flushed on toggle change.' );

		StubLlmsAdapter::$active = true;
		LlmsTxtEndpoint::sync_rules(); // wp-admin (admin_init) or a /llms.txt hit: full detection.
		$this->assertArrayNotHasKey( LlmsTxtEndpoint::REGEX, $wp_rewrite->extra_rules_top );
		$this->assertFalse( LlmsTxtEndpoint::rule_stored( (array) get_option( 'rewrite_rules' ) ) );
		LlmsTxtEndpoint::remove_rule();
		LlmsTxtEndpoint::add_rule();
		$this->assertArrayNotHasKey( LlmsTxtEndpoint::REGEX, $wp_rewrite->extra_rules_top, 'Front end follows the stored rules.' );
		$this->assertSame( 'paused', LlmsTxtEndpoint::state() );

		$status = FixerStatus::compute( ( new Settings() )->all() )['llms_txt'];
		$this->assertSame( 'paused', $status['state'] );
		$this->assertSame( 'Yoast SEO', $status['managed_by'] );
	}

	/**
	 * AC54 + 409: toggle off while managed → `managed`, PATCH on → 409; off always allowed.
	 */
	public function test_managed_returns_409(): void {
		StubLlmsAdapter::$active = true;
		$this->assertSame( 'managed', FixerStatus::compute( ( new Settings() )->all() )['llms_txt']['state'] );
		$this->assertSame( 409, $this->patch( array( 'fix_llms_txt' => true ) )->get_status() );
		$this->assertFalse( ( new Settings() )->get( 'fix_llms_txt' ) );
		$this->assertSame( 200, $this->patch( array( 'fix_llms_txt' => false ) )->get_status() );
	}

	/**
	 * AC59: plain permalinks → cannot_run, 409, nothing registered.
	 */
	public function test_cannot_run_with_plain_permalinks(): void {
		$this->set_permalink_structure( '' );
		$status = FixerStatus::compute( ( new Settings() )->all() )['llms_txt'];
		$this->assertSame( 'cannot_run', $status['state'] );
		$this->assertSame( admin_url( 'options-permalink.php' ), $status['url'] );
		$this->assertSame( 409, $this->patch( array( 'fix_llms_txt' => true ) )->get_status() );
		$this->assertFalse( LlmsTxtEndpoint::is_serving( array( 'fix_llms_txt' => true ) ) );
	}

	/**
	 * AC58: pause and resume keep the stored toggle; notices once per transition and surface.
	 */
	public function test_pause_and_resume_keep_stored_toggle(): void {
		$this->assertSame( 200, $this->patch( array( 'fix_llms_txt' => true ) )->get_status() );
		$this->assertSame( array(), Notices::page_notices( array() ), 'No conflict yet.' );

		StubLlmsAdapter::$active = true; // Yoast's llms.txt turned on later.
		$this->assertFalse( LlmsTxtEndpoint::is_serving() );
		$this->assertTrue( ( new Settings() )->get( 'fix_llms_txt' ), 'Stored toggle untouched.' );
		$notices = Notices::page_notices( array() );
		$this->assertCount( 1, $notices );
		$this->assertSame( 'taken_over', $notices[0]['type'] );
		$this->assertSame( 'llms_txt', $notices[0]['area'] );
		$this->assertSame( 'yoast', $notices[0]['source'] );
		$this->assertSame( array(), Notices::page_notices( array() ), 'Reload: not shown again.' );

		StubLlmsAdapter::$active = false; // Yoast's turned off again.
		$this->assertTrue( LlmsTxtEndpoint::is_serving(), 'Serves again with no owner action.' );
		$notices = Notices::page_notices( array() );
		$this->assertCount( 1, $notices );
		$this->assertSame( 'resumed', $notices[0]['type'] );
		$this->assertSame( 'yoast', $notices[0]['source'] );
		$this->assertSame( array(), Notices::page_notices( array() ) );
		$this->assertTrue( ( new Settings() )->get( 'fix_llms_txt' ) );

		// The Plugins screen still gets the latest transition once.
		$this->assertCount( 1, NoticeState::take( 'plugins' ) );
		$this->assertSame( array(), NoticeState::take( 'plugins' ) );
	}

	/**
	 * Stored rules re-synced once when they disagree (conflict appeared/went away), not per request.
	 */
	public function test_sync_rules_flushes_only_on_mismatch(): void {
		( new Settings() )->update( array( 'fix_llms_txt' => true ) );
		$flushes = 0;
		add_action(
			'generate_rewrite_rules',
			static function () use ( &$flushes ) {
				++$flushes;
			}
		);

		LlmsTxtEndpoint::add_rule();
		LlmsTxtEndpoint::sync_rules();
		$this->assertSame( 0, $flushes, 'In sync: no flush.' );

		StubLlmsAdapter::$active = true;
		LlmsTxtEndpoint::remove_rule();
		LlmsTxtEndpoint::add_rule();
		LlmsTxtEndpoint::sync_rules();
		$this->assertSame( 1, $flushes );
		$this->assertFalse( LlmsTxtEndpoint::rule_stored( (array) get_option( 'rewrite_rules' ) ) );
		LlmsTxtEndpoint::sync_rules();
		$this->assertSame( 1, $flushes, 'In sync again.' );

		StubLlmsAdapter::$active = false; // Resume right away: the guard doesn't block a new transition.
		LlmsTxtEndpoint::add_rule();
		LlmsTxtEndpoint::sync_rules();
		$this->assertSame( 2, $flushes );
		$this->assertTrue( LlmsTxtEndpoint::rule_stored( (array) get_option( 'rewrite_rules' ) ) );
	}

	/**
	 * AC60: deactivation removes the rule from the stored rules.
	 *
	 * @group multisite
	 */
	public function test_deactivation_removes_rewrites(): void {
		( new Settings() )->update( array( 'fix_llms_txt' => true ) );
		$this->assertTrue( LlmsTxtEndpoint::rule_stored( (array) get_option( 'rewrite_rules' ) ) );
		CrawlModule::deactivate();
		$this->assertFalse( LlmsTxtEndpoint::rule_stored( (array) get_option( 'rewrite_rules' ) ) );
		$this->assertTrue( ( new Settings() )->get( 'fix_llms_txt' ), 'Data kept.' );
	}

	/**
	 * AC60 through the real deactivation callback: Plugin::deactivate() flushes our rule out.
	 *
	 * @group multisite
	 */
	public function test_plugin_deactivate_flushes_rule(): void {
		( new Settings() )->update( array( 'fix_llms_txt' => true ) );
		$this->assertTrue( LlmsTxtEndpoint::rule_stored( (array) get_option( 'rewrite_rules' ) ) );
		\X7velo\Plugin::deactivate();
		$rules = (array) get_option( 'rewrite_rules' );
		$this->assertFalse( LlmsTxtEndpoint::rule_stored( $rules ) );
		$this->assertArrayNotHasKey( LlmsTxtEndpoint::REGEX, $rules );
	}

	/**
	 * L3: `init` on the front end never runs detection (no Detector, no file checks); it follows
	 * the toggle and the stored rules. Empty stored rules → full detection (core regenerates).
	 */
	public function test_add_rule_without_detection(): void {
		global $wp_rewrite;
		( new Settings() )->update( array( 'fix_llms_txt' => true ) );
		$detectors = 0;
		$count     = static function ( $adapters ) use ( &$detectors ) {
			++$detectors;
			return $adapters;
		};
		add_filter( 'x7velo_detection_adapters', $count );

		LlmsTxtEndpoint::remove_rule();
		LlmsTxtEndpoint::add_rule();
		$this->assertSame( 0, $detectors, 'No detection on init.' );
		$this->assertSame( LlmsTxtEndpoint::TARGET, $wp_rewrite->extra_rules_top[ LlmsTxtEndpoint::REGEX ] ?? null );

		( new Settings() )->update( array( 'fix_llms_txt' => false ) );
		$detectors = 0;
		LlmsTxtEndpoint::add_rule();
		$this->assertSame( 0, $detectors );
		$this->assertArrayNotHasKey( LlmsTxtEndpoint::REGEX, $wp_rewrite->extra_rules_top );

		// Stored rules empty: core regenerates them this request, so decide with detection.
		( new Settings() )->update( array( 'fix_llms_txt' => true ) );
		update_option( 'rewrite_rules', array() );
		LlmsTxtEndpoint::remove_rule();
		LlmsTxtEndpoint::add_rule();
		$this->assertGreaterThan( 0, $detectors );
		$this->assertSame( LlmsTxtEndpoint::TARGET, $wp_rewrite->extra_rules_top[ LlmsTxtEndpoint::REGEX ] ?? null );
		remove_filter( 'x7velo_detection_adapters', $count );
	}

	/**
	 * L2: the sync flush is single-flight: while another connection holds the sync lock, it's
	 * skipped (no flush, guard not set); once free, it flushes.
	 */
	public function test_sync_rules_skips_while_locked(): void {
		( new Settings() )->update( array( 'fix_llms_txt' => true ) );
		StubLlmsAdapter::$active = true; // Mismatch: stored rule present, conflict appeared.
		$flushes                 = 0;
		add_action(
			'generate_rewrite_rules',
			static function () use ( &$flushes ) {
				++$flushes;
			}
		);
		$other = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$this->assertSame( '1', (string) $other->get_var( $other->prepare( 'SELECT GET_LOCK( %s, 0 )', LlmsTxtEndpoint::sync_lock_name() ) ) );
		try {
			LlmsTxtEndpoint::sync_rules();
			$this->assertSame( 0, $flushes, 'Another request is flushing: skipped.' );
			$this->assertFalse( get_transient( LlmsTxtEndpoint::SYNC_GUARD ) );
			$this->assertTrue( LlmsTxtEndpoint::rule_stored( (array) get_option( 'rewrite_rules' ) ) );
		} finally {
			$other->get_var( $other->prepare( 'SELECT RELEASE_LOCK( %s )', LlmsTxtEndpoint::sync_lock_name() ) );
			$other->close();
		}
		LlmsTxtEndpoint::sync_rules();
		$this->assertSame( 1, $flushes );
		$this->assertFalse( LlmsTxtEndpoint::rule_stored( (array) get_option( 'rewrite_rules' ) ) );
		$this->assertLessThanOrEqual( 64, strlen( LlmsTxtEndpoint::sync_lock_name() ) );
	}

	/**
	 * M3: coming-soon → 404, cannot_run `coming_soon` (Guided link), rule kept so launch needs
	 * no flush; the x7velo_public_output_allowed filter does the same; blog_public does not.
	 */
	public function test_coming_soon_404(): void {
		( new Settings() )->update( array( 'fix_llms_txt' => true ) );
		$this->assertTrue( LlmsTxtEndpoint::is_serving() );

		update_option( 'woocommerce_coming_soon', 'yes' );
		$this->assertFalse( LlmsTxtEndpoint::is_serving() );
		$this->assertTrue( LlmsTxtEndpoint::wants_rule() );
		$status = FixerStatus::compute( ( new Settings() )->all() )['llms_txt'];
		$this->assertSame( 'cannot_run', $status['state'] );
		$this->assertSame( PublicOutput::REASON_COMING_SOON, $status['reason'] );
		$this->assertSame( admin_url( 'admin.php?page=wc-settings&tab=site-visibility' ), $status['url'] );
		$this->assertTrue( ( new Settings() )->get( 'fix_llms_txt' ), 'Stored toggle untouched.' );

		$this->go_to( home_url( '/' ) );
		set_query_var( LlmsTxtEndpoint::QUERY_VAR, '1' );
		LlmsTxtEndpoint::serve();
		$this->assertTrue( $GLOBALS['wp_query']->is_404() );
		$this->assertTrue( LlmsTxtEndpoint::rule_stored( (array) get_option( 'rewrite_rules' ) ), 'Rule kept for launch.' );

		update_option( 'woocommerce_coming_soon', 'no' );
		$this->assertTrue( LlmsTxtEndpoint::is_serving() );

		add_filter( PublicOutput::FILTER, '__return_false' );
		$this->assertFalse( LlmsTxtEndpoint::is_serving() );
		$this->assertSame( PublicOutput::REASON_FILTERED, FixerStatus::compute( ( new Settings() )->all() )['llms_txt']['reason'] );
		remove_filter( PublicOutput::FILTER, '__return_false' );

		update_option( 'blog_public', '0' );
		$this->assertTrue( LlmsTxtEndpoint::is_serving(), 'Search visibility does not gate llms.txt.' );
	}

	/**
	 * L4: forget_stored_rule() (network deactivation per site) drops stored rules only when they
	 * contain ours.
	 */
	public function test_forget_stored_rule(): void {
		update_option(
			'rewrite_rules',
			array(
				'^shop/?$'             => 'index.php?post_type=product',
				LlmsTxtEndpoint::REGEX => 'index.php?other_llms=1',
			)
		);
		set_transient( LlmsTxtEndpoint::SYNC_GUARD, 'on', HOUR_IN_SECONDS );
		LlmsTxtEndpoint::forget_stored_rule();
		$this->assertArrayHasKey( '^shop/?$', (array) get_option( 'rewrite_rules' ), "Someone else's rule: kept." );
		$this->assertFalse( get_transient( LlmsTxtEndpoint::SYNC_GUARD ) );

		update_option(
			'rewrite_rules',
			array(
				'^shop/?$'             => 'index.php?post_type=product',
				LlmsTxtEndpoint::REGEX => LlmsTxtEndpoint::TARGET,
			)
		);
		LlmsTxtEndpoint::forget_stored_rule();
		$this->assertFalse( get_option( 'rewrite_rules' ) );
	}

	/**
	 * A stale rule while not serving answers 404.
	 */
	public function test_query_var_404_when_not_serving(): void {
		$this->go_to( home_url( '/' ) );
		set_query_var( LlmsTxtEndpoint::QUERY_VAR, '1' );
		LlmsTxtEndpoint::serve();
		$this->assertTrue( $GLOBALS['wp_query']->is_404() );
	}

	/**
	 * Generated per request: no transient/option holds the body, and a change shows at once.
	 */
	public function test_body_not_cached(): void {
		global $wpdb;
		$id = $this->product( 'Fresh one' );
		( new Settings() )->update( array( 'fix_llms_txt' => true ) );
		$this->assertStringContainsString( '[Fresh one]', LlmsTxtEndpoint::body() );

		$product = wc_get_product( $id );
		$product->set_name( 'Renamed one' );
		$product->save();
		$this->assertStringContainsString( '[Renamed one]', LlmsTxtEndpoint::body() );
		$this->assertFalse( get_transient( 'x7velo_llms_txt' ) );
		$stored = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_value LIKE '%Renamed one%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( '0', (string) $stored );
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
