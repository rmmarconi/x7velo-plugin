<?php
/**
 * Site mode: x7velo without WooCommerce (spec §15, AC 104–129).
 *
 * Runs in both suites: without WooCommerce loaded (`composer test:no-wc`) site mode is real; with
 * WooCommerce loaded it is forced through the `x7velo_woocommerce_active` filter, which also
 * proves the per-request switch (spec §15.4).
 *
 * @package X7velo
 */

namespace X7velo\Tests;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use X7velo\Admin\AdminPage;
use X7velo\Audit\AuditService;
use X7velo\Audit\AuditStore;
use X7velo\Audit\Catalog\ProductScanner;
use X7velo\Audit\Checks\AiSearchCrawlers;
use X7velo\Audit\Checks\Https;
use X7velo\Audit\Checks\LlmsTxt;
use X7velo\Audit\Checks\Permalinks;
use X7velo\Audit\Checks\Sitemap;
use X7velo\Audit\Scorer;
use X7velo\Audit\Status;
use X7velo\Audit\StoreOnlyCheck;
use X7velo\Capabilities;
use X7velo\Detection\Detector;
use X7velo\Detection\RobotsRules;
use X7velo\FixerStatus;
use X7velo\Fixers\Feed\FeedEndpoint;
use X7velo\Fixers\LlmsTxt\LlmsTxtBuilder;
use X7velo\Fixers\LlmsTxt\LlmsTxtEndpoint;
use X7velo\Fixers\PublicOutput;
use X7velo\Fixers\Robots\RobotsBlock;
use X7velo\Fixers\Robots\RobotsFixer;
use X7velo\Notices\Notices;
use X7velo\Notices\NoticeState;
use X7velo\Plugin;
use X7velo\Settings;
use X7velo\Tests\Detection\FakeEnvironment;
use X7velo\Tests\Detection\SeoFixtures;

/**
 * Site mode behaviour.
 */
final class SiteModeTest extends WP_UnitTestCase {

	/**
	 * Forces site mode when WooCommerce is loaded; clean state; readable permalinks.
	 */
	public function set_up(): void {
		parent::set_up();
		add_filter( 'x7velo_woocommerce_active', '__return_false' );
		update_option( 'blog_public', '1' );
		update_option( 'home', 'https://example.org' );
		update_option( 'siteurl', 'https://example.org' );
		$this->set_permalink_structure( '/%postname%/' );
		delete_option( Settings::OPTION );
		delete_option( NoticeState::OPTION );
		delete_option( AuditStore::OPTION );
		LlmsTxtEndpoint::remove_rule();
	}

	/**
	 * Clean up.
	 */
	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test REST server.
		delete_option( Settings::OPTION );
		LlmsTxtEndpoint::remove_rule();
		parent::tear_down();
	}

	/**
	 * Runs the audit.
	 *
	 * @return array<string, mixed>
	 */
	private function audit(): array {
		return ( new AuditService( new AuditStore(), new Settings(), X7VELO_VERSION ) )->run();
	}

	/**
	 * Checks of a record by ID.
	 *
	 * @param array<string, mixed> $record Record.
	 * @return array<string, array<string, mixed>>
	 */
	private static function by_id( array $record ): array {
		return array_column( $record['checks'], null, 'id' );
	}

	/**
	 * A published post.
	 *
	 * @param array<string, mixed> $args Overrides.
	 */
	private function post( array $args = array() ): int {
		return self::factory()->post->create( $args + array( 'post_status' => 'publish' ) );
	}

	/**
	 * Lines under a `## heading` of an llms.txt body.
	 *
	 * @param string $body    Body.
	 * @param string $heading Heading text.
	 * @return string[]
	 */
	private static function section( string $body, string $heading ): array {
		$parts = preg_split( '/^## /m', $body );
		foreach ( (array) $parts as $part ) {
			if ( str_starts_with( $part, $heading . "\n" ) ) {
				return array_values( array_filter( array_slice( explode( "\n", $part ), 1 ), static fn( $l ) => '' !== $l ) );
			}
		}
		return array();
	}

	/**
	 * The mode switch itself: no store mode without WooCommerce, filter can only turn it off.
	 */
	public function test_mode_is_site(): void {
		$this->assertFalse( Plugin::is_woocommerce_active() );
		$this->assertSame( 'site', Plugin::site_kind() );
		remove_filter( 'x7velo_woocommerce_active', '__return_false' );
		add_filter( 'x7velo_woocommerce_active', '__return_true' );
		$this->assertSame( class_exists( 'WooCommerce' ), Plugin::is_woocommerce_active(), 'The filter cannot enable store mode without WooCommerce.' );
	}

	/**
	 * AC 104 (W1): no Requires Plugins header; activation writes defaults and version; no
	 * WooCommerce notice; the plugin booted its modules.
	 *
	 * @group multisite
	 */
	public function test_ac104_activation_without_woocommerce(): void {
		$header = (string) file_get_contents( X7VELO_PLUGIN_FILE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local plugin file.
		$this->assertStringNotContainsString( 'Requires Plugins', $header );
		$this->assertFalse( method_exists( Plugin::class, 'render_missing_woocommerce_notice' ) );

		delete_option( Plugin::VERSION_OPTION );
		Plugin::activate();
		$this->assertSame( X7VELO_VERSION, get_option( Plugin::VERSION_OPTION ) );
		$this->assertSame( Settings::defaults(), ( new Settings() )->all() );

		$this->assertNotFalse( has_filter( 'x7velo_audit_checks' ), 'Detection module boots in both modes.' );
		$this->assertNotFalse( has_filter( 'robots_txt', array( RobotsFixer::class, 'filter_robots_txt' ) ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'plugins' );
		ob_start();
		do_action( 'admin_notices' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
		$out = (string) ob_get_clean();
		set_current_screen( 'front' );
		$this->assertStringNotContainsString( 'WooCommerce', $out );
		$this->assertStringContainsString( 'See how ready your site is for AI assistants.', $out );
		$this->assertStringContainsString( 'Check my site', $out );
		$this->assertStringContainsString( 'tools.php?page=x7velo', $out );
	}

	/**
	 * AC 105 + 106 (W2, W3): six real checks, twelve `woocommerce_inactive`; 18 in the record;
	 * `woocommerce_active: false`; state complete; no product query.
	 */
	public function test_ac105_ac106_audit_without_woocommerce(): void {
		update_option( 'woocommerce_coming_soon', 'yes' );
		$queries = array();
		$log     = static function ( $sql ) use ( &$queries ) {
			$queries[] = (string) $sql;
			return $sql;
		};
		add_filter( 'query', $log );
		$record = $this->audit();
		remove_filter( 'query', $log );

		$this->assertFalse( $record['woocommerce_active'] );
		$this->assertSame( AuditService::STATE_COMPLETE, $record['state'] );
		$this->assertCount( 18, $record['checks'] );
		$checks = self::by_id( $record );
		foreach ( array( 'VIS-01', 'VIS-02', 'VIS-03', 'CRAWL-01', 'CRAWL-02', 'CRAWL-03' ) as $id ) {
			$this->assertNotSame( Status::NOT_APPLICABLE, $checks[ $id ]['status'], $id );
			$this->assertNotSame( StoreOnlyCheck::CODE, $checks[ $id ]['code'], $id );
		}
		foreach ( StoreOnlyCheck::IDS as $id ) {
			$this->assertSame( Status::NOT_APPLICABLE, $checks[ $id ]['status'], $id );
			$this->assertSame( StoreOnlyCheck::CODE, $checks[ $id ]['code'], $id );
		}
		foreach ( $queries as $sql ) {
			$this->assertDoesNotMatchRegularExpression( "/post_type\\W+(IN \\()?'product(_variation)?'/", $sql );
		}
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->assertFalse( function_exists( 'wc_get_products' ), 'No wc_* function can have been called.' );
		}

		$presented = ( new AuditService( new AuditStore(), new Settings(), X7VELO_VERSION ) )->present( $record );
		$vis04     = self::by_id( $presented )['VIS-04'];
		$this->assertSame( 'Doesn’t apply: this check is for WooCommerce stores.', $vis04['message'] );
	}

	/**
	 * The product scanner refuses to run without WooCommerce, whoever asks.
	 */
	public function test_scanner_never_runs(): void {
		$scanner = new ProductScanner();
		$scanner->scan();
		$this->assertFalse( $scanner->completed() );
		$this->assertSame( 0, $scanner->products() );
	}

	/**
	 * AC 107 (W4): scores over the 39 applicable points.
	 */
	public function test_ac107_scores(): void {
		$weights = array(
			'VIS-01'   => 10,
			'VIS-02'   => 5,
			'VIS-03'   => 4,
			'CRAWL-01' => 10,
			'CRAWL-02' => 5,
			'CRAWL-03' => 5,
		);
		$score   = static function ( array $statuses ) use ( $weights ): ?int {
			$entries = array();
			foreach ( $weights as $id => $weight ) {
				$entries[] = array(
					'weight' => $weight,
					'status' => $statuses[ $id ] ?? Status::PASS,
				);
			}
			foreach ( StoreOnlyCheck::IDS as $id ) {
				$entries[] = array(
					'weight' => 5,
					'status' => Status::NOT_APPLICABLE,
				);
			}
			return ( new Scorer() )->score( $entries )['score'];
		};
		$this->assertSame( 100, $score( array() ) );
		$this->assertSame( 87, $score( array( 'CRAWL-03' => Status::FAIL ) ) );
		$this->assertSame(
			81,
			$score(
				array(
					'VIS-02'   => Status::FAIL,
					'CRAWL-02' => Status::WARN,
				)
			)
		);

		update_option( 'blog_public', '0' );
		$record = $this->audit();
		$this->assertLessThanOrEqual( 20, $record['score'] );
	}

	/**
	 * AC 108 (W5): a leftover coming-soon option neither fails VIS-04 nor gates public output.
	 */
	public function test_ac108_leftover_coming_soon(): void {
		update_option( 'woocommerce_coming_soon', 'yes' );
		$checks = self::by_id( $this->audit() );
		$this->assertSame( Status::NOT_APPLICABLE, $checks['VIS-04']['status'] );
		$this->assertFalse( $checks['VIS-04']['critical'] );
		// Without WooCommerce a leftover option doesn't gate output. With WooCommerce loaded (site
		// mode only forced by the filter) WooCommerce enforces coming soon, so the gate holds (L3).
		$this->assertSame( ! class_exists( 'WooCommerce' ), PublicOutput::allowed( 'llms_txt' ) );
	}

	/**
	 * AC 109 (W6): sample paths.
	 */
	public function test_ac109_sample_paths(): void {
		$front = $this->post(
			array(
				'post_type' => 'page',
				'post_name' => 'home-page',
			)
		);
		$blog  = $this->post(
			array(
				'post_type' => 'page',
				'post_name' => 'blog',
			)
		);
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $front );
		update_option( 'page_for_posts', $blog );
		$this->post(
			array(
				'post_name' => 'older',
				'post_date' => '2024-01-01 00:00:00',
			)
		);
		$this->post(
			array(
				'post_name'     => 'secret',
				'post_password' => 'x',
				'post_date'     => '2026-01-01 00:00:00',
			)
		);
		$this->post(
			array(
				'post_name' => 'newest',
				'post_date' => '2025-06-01 00:00:00',
			)
		);
		$this->assertSame( array( '/', '/blog/', '/newest/' ), AiSearchCrawlers::sample_paths() );

		// No posts: "/" and the newest page other than the front page.
		foreach ( get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => -1,
			)
		) as $id ) {
			wp_delete_post( $id, true );
		}
		update_option( 'page_for_posts', 0 );
		wp_delete_post( $blog, true );
		$this->post(
			array(
				'post_type' => 'page',
				'post_name' => 'about',
				'post_date' => '2025-07-01 00:00:00',
			)
		);
		wp_update_post(
			array(
				'ID'        => $front,
				'post_date' => '2026-02-01 00:00:00',
			)
		);
		$this->assertSame( array( '/', '/about/' ), AiSearchCrawlers::sample_paths() );
	}

	/**
	 * AC 110 (W7): core sitemap with `post` viewable passes when referenced and warns when not;
	 * Yoast with noindex-post fails; per-provider post/page signals.
	 */
	public function test_ac110_sitemap_content(): void {
		$this->post();
		// Core adds its own Sitemap: line; drop it to see the "not referenced" case.
		remove_all_filters( 'robots_txt' );
		\X7velo\Fixers\Robots\RobotsFixer::register();
		$detector = new Detector();
		$check    = new Sitemap( $detector, new RobotsRules( $detector ) );
		$result   = $check->run();
		$this->assertSame( 'sitemap_not_referenced', $result->code() );
		$this->assertSame( Status::WARN, $result->status() );
		$this->assertSame( 'post', $result->details()['content_type'] );
		$this->assertSame( 'site', $result->details()['site_kind'] );

		$sitemap  = static fn( $out ) => $out . "\nSitemap: " . home_url( '/wp-sitemap.xml' ) . "\n";
		add_filter( 'robots_txt', $sitemap, 50 );
		$detector = new Detector();
		$result   = ( new Sitemap( $detector, new RobotsRules( $detector ) ) )->run();
		remove_filter( 'robots_txt', $sitemap, 50 );
		$this->assertSame( Status::PASS, $result->status() );
		$this->assertSame( 'Your sitemap lists your posts and assistants can find it.', $result->message() );

		// Yoast noindex-post → fail.
		SeoFixtures::load( SeoFixtures::YOAST );
		SeoFixtures::merge( 'wpseo_titles', array( 'noindex-post' => true ) );
		$detector = new Detector( ( new FakeEnvironment() )->with( 'yoast' ) );
		$result   = ( new Sitemap( $detector, new RobotsRules( $detector ) ) )->run();
		$this->assertSame( Status::FAIL, $result->status() );
		$this->assertSame( 'sitemap_excludes_products', $result->code() );
		$this->assertStringContainsString( 'leaves out your posts', $result->message() );

		$this->assertFalse( $detector->sitemap( 'post' )['includes_products'] );
		$this->assertTrue( $detector->sitemap( 'page' )['includes_products'] );

		SeoFixtures::load( SeoFixtures::RANK_MATH );
		$rank = new Detector( ( new FakeEnvironment() )->with( 'rank-math' ) );
		SeoFixtures::merge( 'rank-math-options-sitemap', array( 'pt_post_sitemap' => 'off' ) );
		$this->assertFalse( $rank->sitemap( 'post' )['includes_products'] );
		SeoFixtures::merge( 'rank-math-options-sitemap', array( 'pt_post_sitemap' => 'on' ) );
		SeoFixtures::merge( 'rank-math-options-titles', array( 'pt_post_robots' => array( 'noindex' ) ) );
		$this->assertFalse( ( new Detector( ( new FakeEnvironment() )->with( 'rank-math' ) ) )->sitemap( 'post' )['includes_products'] );
		SeoFixtures::merge( 'rank-math-options-titles', array( 'pt_post_robots' => array() ) );
		$this->assertTrue( ( new Detector( ( new FakeEnvironment() )->with( 'rank-math' ) ) )->sitemap( 'post' )['includes_products'] );
	}

	/**
	 * AC 111 (W8): site_kind and site copy without "store", "product" or "shop" for every code.
	 */
	public function test_ac111_site_copy(): void {
		$site    = array( 'site_kind' => 'site' );
		$cases   = array(
			array( new Https(), array( 'https_on', 'https_off' ) ),
			array( new Permalinks(), array( 'readable_links', 'plain_links' ) ),
			array(
				new AiSearchCrawlers( new RobotsRules( new Detector() ) ),
				array( 'subfolder_install', 'blocked_by_search_visibility', 'all_search_crawlers_blocked', 'some_search_crawlers_blocked', 'search_crawlers_allowed' ),
			),
			array(
				new Sitemap( new Detector(), new RobotsRules( new Detector() ) ),
				array( 'no_sitemap_search_discouraged', 'no_sitemap', 'sitemap_excludes_products', 'sitemap_not_referenced', 'sitemap_ok' ),
			),
			array(
				new LlmsTxt( new Detector(), new Settings() ),
				array( 'no_llms_txt_plugin_available', 'no_llms_txt', 'llms_txt_x7velo', 'llms_txt_managed', 'llms_txt_multiple' ),
			),
		);
		$details = $site + array(
			'blocked'                => array( 'OAI-SearchBot' ),
			'blocked_count'          => 3,
			'may_still_visit'        => array( 'Perplexity-User' ),
			'training_blocked_count' => 2,
			'source'                 => 'yoast',
			'source_label'           => 'Yoast SEO',
			'suggested_label'        => 'Yoast SEO',
			'content_type'           => 'page',
		);
		foreach ( $cases as list( $check, $codes ) ) {
			foreach ( $codes as $code ) {
				$message = $check->render_message( $code, $details );
				$this->assertNotSame( '', $message );
				$this->assertDoesNotMatchRegularExpression( '/store|product|shop/i', $message, $check->id() . ' ' . $code );
			}
		}

		$checks = self::by_id( $this->audit() );
		foreach ( array( 'VIS-02', 'VIS-03', 'CRAWL-01', 'CRAWL-02', 'CRAWL-03' ) as $id ) {
			$this->assertSame( 'site', $checks[ $id ]['details']['site_kind'], $id );
		}
		$this->assertSame( 'Your site address uses https://.', ( new Https() )->render_message( 'https_on', $site ) );
		$this->assertSame( 'Your page links are readable.', ( new Permalinks() )->render_message( 'readable_links', $site ) );
	}

	/**
	 * AC 112 (W9): robots block has no Woo private paths, keeps the admin lines; AC 41 holds.
	 */
	public function test_ac112_robots_without_woo_paths(): void {
		foreach ( array( 'cart', 'checkout', 'my-account' ) as $slug ) {
			$id = $this->post(
				array(
					'post_type' => 'page',
					'post_name' => $slug,
				)
			);
			update_option( 'woocommerce_' . str_replace( '-', '', $slug ) . '_page_id', $id );
		}
		$this->assertSame( array(), RobotsFixer::private_paths() );

		$robots = static function (): string {
			return (string) apply_filters( 'robots_txt', "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n", '1' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.
		};
		add_filter( 'robots_txt', static fn() => "User-agent: *\nDisallow: /\n", 20 );
		$baseline = $robots();
		( new Settings() )->update( array( 'fix_robots_search' => true ) );
		$out   = $robots();
		$block = substr( $out, (int) strpos( $out, RobotsBlock::BEGIN ) );
		$this->assertStringContainsString( RobotsBlock::BEGIN, $out );
		$this->assertDoesNotMatchRegularExpression( '#Disallow: /(cart|checkout|my-account)/#', $block );
		$this->assertStringContainsString( "Disallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n", $block );
		( new Settings() )->update( array( 'fix_robots_search' => false ) );
		$this->assertSame( $baseline, $robots() );
	}

	/**
	 * AC 113 (W10): posts/pages mode structure; served as text/plain by the endpoint state.
	 */
	public function test_ac113_llms_posts_mode(): void {
		update_option( 'blogname', 'My Blog' );
		update_option( 'blogdescription', 'Notes on things' );
		$cat = self::factory()->category->create( array( 'name' => 'Travel' ) );
		$this->post(
			array(
				'post_title'    => 'Lisbon in a day',
				'post_excerpt'  => 'Where to <b>walk</b> [gallery] and eat.',
				'post_category' => array( $cat ),
			)
		);
		$this->post( array( 'post_title' => 'Uncategorized note' ) );
		$this->post(
			array(
				'post_type'  => 'page',
				'post_title' => 'About',
			)
		);
		( new Settings() )->update( array( 'fix_llms_txt' => true ) );
		$this->assertTrue( LlmsTxtEndpoint::is_serving() );

		$body = LlmsTxtEndpoint::body();
		$this->assertStringStartsWith( "# My Blog\n\n> Notes on things\n\n## Key pages\n", $body );
		$this->assertContains( '- [About](' . get_permalink( get_page_by_path( 'about' ) ) . ')', self::section( $body, 'Key pages' ) );
		$this->assertSame( array( '- [Lisbon in a day](' . get_permalink( get_page_by_path( 'lisbon-in-a-day', OBJECT, 'post' ) ) . '): Where to walk and eat.' ), self::section( $body, 'Posts: Travel' ) );
		$this->assertCount( 1, self::section( $body, 'Recent posts' ) );
		$this->assertSame( array( '- [Sitemap](' . home_url( '/wp-sitemap.xml' ) . ')' ), self::section( $body, 'Optional' ) );
		foreach ( array( '## Shop', '## Product categories', '## Products', 'Online store' ) as $store ) {
			$this->assertStringNotContainsString( $store, $body );
		}
	}

	/**
	 * AC 113: key pages order (front, posts page, pages by menu order then title, privacy last),
	 * no repeats, and the endpoint headers via serve().
	 */
	public function test_key_pages_order(): void {
		$front   = $this->post(
			array(
				'post_type'  => 'page',
				'post_title' => 'Welcome',
			)
		);
		$blog    = $this->post(
			array(
				'post_type'  => 'page',
				'post_title' => 'Blog',
			)
		);
		$privacy = $this->post(
			array(
				'post_type'  => 'page',
				'post_title' => 'Privacy',
			)
		);
		$this->post(
			array(
				'post_type'  => 'page',
				'post_title' => 'Zeta',
				'menu_order' => 1,
			)
		);
		$this->post(
			array(
				'post_type'  => 'page',
				'post_title' => 'Alpha',
				'menu_order' => 2,
			)
		);
		$this->post(
			array(
				'post_type'  => 'page',
				'post_title' => 'Beta',
				'menu_order' => 1,
			)
		);
		$this->post(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Child',
				'post_parent' => $front,
			)
		);
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $front );
		update_option( 'page_for_posts', $blog );
		update_option( 'wp_page_for_privacy_policy', $privacy );

		$titles = array_map(
			static fn( $l ) => (string) preg_replace( '/^- \[([^\]]*)\].*$/', '$1', $l ),
			self::section( LlmsTxtBuilder::build_site(), 'Key pages' )
		);
		$this->assertSame( array( 'Welcome', 'Blog', 'Beta', 'Zeta', 'Alpha', 'Privacy' ), $titles );
		$this->assertStringContainsString( '- [Welcome](' . home_url( '/' ) . ')', LlmsTxtBuilder::build_site() );
	}

	/**
	 * AC 114 (W11): non-public content excluded; bounds 50 posts / 10 sections / 20 key pages,
	 * each post once.
	 */
	public function test_ac114_visibility_and_bounds(): void {
		foreach ( array( 'post', 'page' ) as $type ) {
			$this->post(
				array(
					'post_type'   => $type,
					'post_title'  => "Draft $type",
					'post_status' => 'draft',
				)
			);
			$this->post(
				array(
					'post_type'   => $type,
					'post_title'  => "Scheduled $type",
					'post_status' => 'future',
					'post_date'   => '2099-01-01 00:00:00',
				)
			);
			$this->post(
				array(
					'post_type'   => $type,
					'post_title'  => "Private $type",
					'post_status' => 'private',
				)
			);
			$this->post(
				array(
					'post_type'     => $type,
					'post_title'    => "Locked $type",
					'post_password' => 'secret',
				)
			);
		}
		$body = LlmsTxtBuilder::build_site();
		foreach ( array( 'Draft', 'Scheduled', 'Private', 'Locked' ) as $word ) {
			$this->assertStringNotContainsString( $word, $body );
		}

		$cats = array();
		for ( $i = 0; $i < 30; $i++ ) {
			$cats[] = self::factory()->category->create( array( 'name' => sprintf( 'Cat %02d', $i ) ) );
		}
		for ( $i = 0; $i < 120; $i++ ) {
			$this->post(
				array(
					'post_title'    => "Post $i",
					'post_date'     => gmdate( 'Y-m-d H:i:s', 1700000000 + $i * 60 ),
					'post_category' => array( $cats[ $i % 30 ], $cats[ ( $i + 1 ) % 30 ] ),
				)
			);
		}
		for ( $i = 0; $i < 25; $i++ ) {
			$this->post(
				array(
					'post_type'  => 'page',
					'post_title' => "Page $i",
				)
			);
		}
		$body = LlmsTxtBuilder::build_site();
		$this->assertSame( 10, preg_match_all( '/^## Posts: /m', $body ) );
		preg_match_all( '/^- \[Post (\d+)\]/m', $body, $m );
		$this->assertCount( 50, $m[1] );
		$this->assertSame( $m[1], array_values( array_unique( $m[1] ) ), 'Each post once.' );
		$this->assertGreaterThanOrEqual( 70, (int) min( $m[1] ), 'The 50 newest.' );
		$this->assertCount( 20, self::section( $body, 'Key pages' ) );
		$this->assertNotEmpty( self::section( $body, 'Recent posts' ), 'Posts beyond the 10th section.' );
	}

	/**
	 * AC 115 (W12): titles become one escaped line.
	 */
	public function test_ac115_escaping(): void {
		$this->post( array( 'post_title' => "[x](javascript:alert(1)) <script>bad()</script>\nTwo" ) );
		$body  = LlmsTxtBuilder::build_site();
		$lines = array_values( array_filter( explode( "\n", $body ), static fn( $l ) => str_contains( $l, 'javascript' ) ) );
		$this->assertCount( 1, $lines );
		$this->assertStringStartsWith( '- [\\[x\\](javascript:alert(1)) ', $lines[0] );
		$this->assertMatchesRegularExpression( '/^- \[[^\n]* Two\]\(http/', $lines[0], 'The newline became a space.' );
		$this->assertStringNotContainsString( '<', $lines[0] );
		$this->assertStringNotContainsString( '<script', $body );
	}

	/**
	 * AC 116 (W13): a category named "Optional" stays under the Posts: prefix.
	 */
	public function test_ac116_optional_category(): void {
		$cat = self::factory()->category->create( array( 'name' => 'Optional' ) );
		$this->post( array( 'post_category' => array( $cat ) ) );
		$body = LlmsTxtBuilder::build_site();
		$this->assertStringContainsString( "\n## Posts: Optional\n", $body );
		$this->assertSame( 1, preg_match_all( '/^## Optional$/m', $body ) );
	}

	/**
	 * AC 117 (W14): fixer_status without jsonld/feed; 409 on enable with reason; off allowed;
	 * stored store inputs untouched.
	 */
	public function test_ac117_rest_settings(): void {
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test REST server.
		do_action( 'rest_api_init', $wp_rest_server );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$call = static function ( string $method, ?array $body = null ) use ( $wp_rest_server ) {
			$request = new WP_REST_Request( $method, '/x7velo/v1/settings' );
			if ( null !== $body ) {
				$request->set_header( 'content-type', 'application/json' );
				$request->set_body( (string) wp_json_encode( $body ) );
			}
			return $wp_rest_server->dispatch( $request );
		};

		$get = $call( 'GET' );
		$this->assertSame( 200, $get->get_status() );
		$this->assertSame( array( 'llms_txt', 'robots' ), array_keys( $get->get_data()['fixer_status'] ) );
		$this->assertArrayHasKey( 'fix_jsonld', $get->get_data() );

		foreach ( array(
			'fix_jsonld' => 'jsonld',
			'fix_feed'   => 'feed',
		) as $key => $area ) {
			$response = $call( 'PATCH', array( $key => true ) );
			$this->assertSame( 409, $response->get_status(), $key );
			$this->assertSame( 'x7velo_fixer_unavailable', $response->as_error()->get_error_code() );
			$data = $response->as_error()->get_error_data();
			$this->assertSame( 'woocommerce_inactive', $data['reason'] );
			$this->assertSame( $area, $data['area'] );
			$this->assertSame( 200, $call( 'PATCH', array( $key => false ) )->get_status() );
		}
		$this->assertSame( 200, $call( 'PATCH', array( 'return_days' => 30 ) )->get_status() );
		$this->assertFalse( ( new Settings() )->get( 'fix_jsonld' ) );
	}

	/**
	 * AC 118 (W15): no feed without WooCommerce, even with fix_feed stored on.
	 */
	public function test_ac118_no_feed(): void {
		( new Settings() )->update( array( 'fix_feed' => true ) );
		$this->go_to( home_url( '/?x7velo_feed=1' ) );
		if ( class_exists( 'WooCommerce' ) ) {
			// Registered at boot in this suite; the per-request guard leaves the request alone.
			( new FeedEndpoint( new Settings() ) )->template_redirect();
			$this->addToAssertionCount( 1 );
		} else {
			global $wp;
			$this->assertNotContains( FeedEndpoint::QUERY_VAR, $wp->public_query_vars );
			$this->assertSame( '', (string) get_query_var( FeedEndpoint::QUERY_VAR ) );
			$this->assertFalse( has_filter( 'woocommerce_structured_data_product' ) );
		}
	}

	/**
	 * AC 122–124 (W19–W21): Tools › AI Readiness, manage_options; a leftover manage_woocommerce
	 * user gets no menu and 403 from both REST routes; links come from menu_page_url().
	 */
	public function test_ac122_location_and_capability(): void {
		$this->assertSame( 'manage_options', Capabilities::manage() );
		$this->assertSame( 'tools.php', Capabilities::parent() );

		$shop = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_userdata( $shop )->add_cap( 'manage_woocommerce' );
		wp_set_current_user( $shop );
		global $wp_rest_server, $submenu, $_parent_pages, $_registered_pages;
		$wp_rest_server = new WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test REST server.
		do_action( 'rest_api_init', $wp_rest_server );
		foreach ( array( '/x7velo/v1/audit', '/x7velo/v1/settings' ) as $route ) {
			$this->assertSame( 403, $wp_rest_server->dispatch( new WP_REST_Request( 'GET', $route ) )->get_status(), $route );
		}
		AdminPage::add_menu();
		$this->assertFalse( isset( $_parent_pages['x7velo'] ), 'No menu for a leftover shop manager.' );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		set_current_screen( 'dashboard' );
		AdminPage::add_menu();
		$this->assertSame( 'tools.php', $_parent_pages['x7velo'] ?? '' );
		$this->assertContains( 'x7velo', array_column( $submenu['tools.php'] ?? array(), 2 ) );
		$this->assertSame( admin_url( 'tools.php?page=x7velo' ), menu_page_url( 'x7velo', false ) );
		$this->assertSame( admin_url( 'tools.php?page=x7velo' ), AdminPage::url() );
		$this->assertSame( admin_url( 'tools.php?page=x7velo&tab=fixes' ), AdminPage::url( 'fixes' ) );
		$links = AdminPage::action_links( array() );
		$this->assertStringContainsString( esc_url( admin_url( 'tools.php?page=x7velo' ) ), $links['x7velo-open'] );
		$this->assertSame( 200, $wp_rest_server->dispatch( new WP_REST_Request( 'GET', '/x7velo/v1/settings' ) )->get_status() );
		$this->assertFalse( AdminPage::page_data()['woocommerceActive'] );
		$this->assertSame( admin_url( 'tools.php?page=x7velo' ), AdminPage::page_data()['urls']['page'] );
		unset( $_parent_pages['x7velo'], $_registered_pages['tools_page_x7velo'] );
		set_current_screen( 'front' );
	}

	/**
	 * AC 124 (W21): the notice-dismiss ajax uses the same capability.
	 */
	public function test_ac124_dismiss_capability(): void {
		$shop = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_userdata( $shop )->add_cap( 'manage_woocommerce' );
		wp_set_current_user( $shop );
		$this->assertFalse( Capabilities::current_user_can_manage() );
		ob_start();
		Notices::render_plugins_notices();
		$this->assertSame( '', (string) ob_get_clean() );
	}

	/**
	 * Store-only notice areas are frozen: nothing queued, shown or cleared while in site mode.
	 */
	public function test_jsonld_notices_frozen(): void {
		update_option(
			NoticeState::OPTION,
			array(
				'areas' => array( 'jsonld' => 'rank-math' ),
				'queue' => array(
					array(
						'id'       => 'jsonld-1',
						'type'     => 'taken_over',
						'area'     => 'jsonld',
						'source'   => 'rank-math',
						'at'       => time(),
						'surfaces' => array( 'page', 'plugins' ),
					),
				),
			),
			false
		);
		$before = get_option( NoticeState::OPTION );
		NoticeState::evaluate( ( new Settings() )->all() );
		$this->assertSame( array(), NoticeState::take( 'plugins' ) );
		$this->assertSame( array(), NoticeState::pending( 'page' ) );
		$after = NoticeState::get();
		$this->assertSame( 'jsonld-1', $after['queue'][0]['id'] ?? '', 'Not cleared either.' );
		$this->assertSame( $before['queue'][0]['surfaces'], $after['queue'][0]['surfaces'] );
	}

	/**
	 * FixerStatus without WooCommerce: only llms_txt and robots.
	 */
	public function test_fixer_status_areas(): void {
		$this->assertSame( array( 'llms_txt', 'robots' ), array_keys( FixerStatus::areas() ) );
		$this->assertSame( array( 'llms_txt', 'robots' ), array_keys( FixerStatus::compute( ( new Settings() )->all() ) ) );
	}

	/**
	 * The llms.txt "taken over" notice says "site", not "store", without WooCommerce.
	 */
	public function test_llms_taken_over_notice_says_site(): void {
		$text = \X7velo\Notices\Notices::conflict_text(
			array(
				'type'   => 'paused',
				'area'   => 'llms_txt',
				'source' => 'yoast',
			)
		);
		$this->assertStringContainsString( 'Your site has one llms.txt', $text );
		$this->assertStringNotContainsString( 'store', $text );

		remove_filter( 'x7velo_woocommerce_active', '__return_false' );
		if ( Plugin::is_woocommerce_active() ) {
			$store = \X7velo\Notices\Notices::conflict_text(
				array(
					'type'   => 'paused',
					'area'   => 'llms_txt',
					'source' => 'yoast',
				)
			);
			$this->assertStringContainsString( 'Your store has one llms.txt', $store );
		}
	}
}
