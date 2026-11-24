<?php
/**
 * Visibility hardening of llms.txt: gated content, password, include filter, SEO noindex, Woo
 * leftover pages, caching headers, coming-soon gate (security review M1, M2, L1, L3).
 *
 * @package X7velo
 */

namespace X7velo\Tests\Fixers\LlmsTxt;

use WC_Product_Simple;
use WP_UnitTestCase;
use X7velo\Detection\Adapters\Aioseo;
use X7velo\Detection\Adapters\RankMath;
use X7velo\Detection\Adapters\Seopress;
use X7velo\Detection\Adapters\Yoast;
use X7velo\Detection\Environment;
use X7velo\Fixers\LlmsTxt\LlmsTxtBuilder;
use X7velo\Fixers\LlmsTxt\LlmsTxtEndpoint;
use X7velo\Fixers\PublicOutput;
use X7velo\Settings;
use X7velo\Tests\Detection\FakeEnvironment;

/**
 * Posts/pages mode runs with and without WooCommerce (store mode forced off); store-mode tests
 * need WooCommerce.
 */
final class LlmsTxtVisibilityTest extends WP_UnitTestCase {

	/**
	 * Admin user.
	 *
	 * @var int
	 */
	private int $admin = 0;

	/**
	 * Adapters injected for this test (null = defaults).
	 *
	 * @var array<int, object>|null
	 */
	private ?array $adapters = null;

	/**
	 * Clean state, logged in as an admin (the body must still be the visitor's view).
	 */
	public function set_up(): void {
		parent::set_up();
		update_option( 'blog_public', '1' );
		update_option( 'woocommerce_coming_soon', 'no' );
		$this->set_permalink_structure( '/%postname%/' );
		delete_option( Settings::OPTION );
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		add_filter( 'x7velo_detection_adapters', array( $this, 'adapters' ), 20 );
	}

	/**
	 * Clean up.
	 */
	public function tear_down(): void {
		remove_filter( 'x7velo_detection_adapters', array( $this, 'adapters' ), 20 );
		remove_all_filters( 'x7velo_woocommerce_active' );
		$this->adapters = null;
		delete_option( Settings::OPTION );
		parent::tear_down();
	}

	/**
	 * `x7velo_detection_adapters` callback.
	 *
	 * @param mixed $adapters Adapters.
	 * @return mixed
	 */
	public function adapters( $adapters ) {
		return $this->adapters ?? $adapters;
	}

	/**
	 * Simulates SEO plugins as active.
	 *
	 * @param string[]         $plugins yoast | rank-math | seopress | aioseo.
	 * @param Environment|null $env     Environment (default: fake with the plugins' constants).
	 */
	private function with_seo( array $plugins, ?Environment $env = null ): void {
		if ( null === $env ) {
			$env = new FakeEnvironment();
			foreach ( $plugins as $plugin ) {
				$env->with( $plugin );
			}
			$env->rank_math_invalid = false;
		}
		$map            = array(
			'yoast'     => Yoast::class,
			'rank-math' => RankMath::class,
			'seopress'  => Seopress::class,
			'aioseo'    => Aioseo::class,
		);
		$this->adapters = array_map( static fn( string $p ) => new $map[ $p ]( $env ), $plugins );
	}

	/**
	 * Site mode even when WooCommerce is loaded.
	 */
	private function site_mode(): void {
		add_filter( 'x7velo_woocommerce_active', '__return_false' );
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
	 * The list line of a post title, or null.
	 *
	 * @param string $body  Body.
	 * @param string $title Title.
	 */
	private static function line( string $body, string $title ): ?string {
		foreach ( explode( "\n", $body ) as $line ) {
			if ( str_starts_with( $line, '- [' . $title . '](' ) ) {
				return $line;
			}
		}
		return null;
	}

	/**
	 * M1: no description from post content; content hidden by a membership filter never appears;
	 * a filtered excerpt is the visitor's version; the current user is restored.
	 */
	public function test_gated_content_never_leaks(): void {
		$this->site_mode();
		$locked = $this->post(
			array(
				'post_title'   => 'Members post',
				'post_content' => 'SECRET-CONTENT for members only.',
				'post_excerpt' => '',
			)
		);
		$this->post(
			array(
				'post_title'   => 'Teaser post',
				'post_content' => 'SECRET-BODY text.',
				'post_excerpt' => 'MEMBER-EXCERPT details',
			)
		);
		$hide_content = static fn( $content ) => is_user_logged_in() ? $content : 'Join to read.';
		$hide_excerpt = static fn( $excerpt ) => is_user_logged_in() ? $excerpt : 'Members <b>only</b>.';
		add_filter( 'the_content', $hide_content );
		add_filter( 'get_the_excerpt', $hide_excerpt, 20 );

		$body = LlmsTxtBuilder::build_site();
		remove_filter( 'the_content', $hide_content );
		remove_filter( 'get_the_excerpt', $hide_excerpt, 20 );

		foreach ( array( 'SECRET-CONTENT', 'SECRET-BODY', 'MEMBER-EXCERPT', 'Join to read' ) as $hidden ) {
			$this->assertStringNotContainsString( $hidden, $body );
		}
		$this->assertSame( '- [Members post](' . get_permalink( $locked ) . ')', self::line( $body, 'Members post' ), 'No excerpt → title and link only.' );
		$this->assertStringEndsWith( '): Members only.', (string) self::line( $body, 'Teaser post' ) );
		$this->assertSame( $this->admin, get_current_user_id(), 'Current user restored.' );
	}

	/**
	 * Follow-up A: a `the_excerpt` gate that reads the global post (PMPro style) hides the text;
	 * the global post is restored afterwards.
	 */
	public function test_the_excerpt_gate_with_global_post(): void {
		$this->site_mode();
		$gated = $this->post(
			array(
				'post_title'   => 'Gated post',
				'post_excerpt' => 'GATED-EXCERPT text',
			)
		);
		$this->post(
			array(
				'post_title'   => 'Open post',
				'post_excerpt' => 'Open excerpt.',
			)
		);
		$seen = array();
		$gate = static function ( $text ) use ( $gated, &$seen ) {
			$current = $GLOBALS['post'] ?? null;
			$seen[]  = $current instanceof \WP_Post ? (int) $current->ID : 0;
			return $current instanceof \WP_Post && $current->ID === $gated && ! is_user_logged_in() ? '<p>Members only.</p>' : $text;
		};

		$before          = $this->post( array( 'post_title' => 'Caller post' ) );
		$GLOBALS['post'] = get_post( $before ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test.
		add_filter( 'the_excerpt', $gate );
		$body = LlmsTxtBuilder::build_site();
		remove_filter( 'the_excerpt', $gate );

		$this->assertStringNotContainsString( 'GATED-EXCERPT', $body );
		$this->assertStringEndsWith( '): Members only.', (string) self::line( $body, 'Gated post' ) );
		$this->assertStringEndsWith( '): Open excerpt.', (string) self::line( $body, 'Open post' ) );
		$this->assertContains( $gated, $seen, 'Global post set per item.' );
		$this->assertSame( $before, (int) $GLOBALS['post']->ID, 'Caller global post restored.' );
	}

	/**
	 * M1: password-protected posts are absent, including ones a plugin protects through
	 * post_password_required(); the include filter removes posts and pages.
	 */
	public function test_password_and_include_filter(): void {
		$this->site_mode();
		$this->post(
			array(
				'post_title'    => 'Locked post',
				'post_password' => 'pw',
			)
		);
		$guarded = $this->post( array( 'post_title' => 'Guarded post' ) );
		$this->post( array( 'post_title' => 'Excluded post' ) );
		$this->post(
			array(
				'post_type'  => 'page',
				'post_title' => 'Excluded page',
			)
		);
		$this->post( array( 'post_title' => 'Normal post' ) );

		$required = static fn( $req, $post ) => $req || ( $post instanceof \WP_Post && $post->ID === $guarded );
		$include  = static function ( $listed, $post ) {
			return $listed && ! str_starts_with( $post->post_title, 'Excluded' );
		};
		add_filter( 'post_password_required', $required, 10, 2 );
		add_filter( 'x7velo_llms_txt_include_post', $include, 10, 2 );
		$body = LlmsTxtBuilder::build_site();
		remove_filter( 'post_password_required', $required, 10 );
		remove_filter( 'x7velo_llms_txt_include_post', $include, 10 );

		foreach ( array( 'Locked post', 'Guarded post', 'Excluded post', 'Excluded page' ) as $absent ) {
			$this->assertStringNotContainsString( $absent, $body );
		}
		$this->assertNotNull( self::line( $body, 'Normal post' ) );
	}

	/**
	 * M2: Yoast-noindex page and Rank Math-noindex post are absent in posts mode; Yoast's
	 * post-type default applies unless the post overrides it.
	 */
	public function test_noindex_posts_mode(): void {
		$this->site_mode();
		$this->with_seo( array( 'yoast', 'rank-math' ) );
		$page = $this->post(
			array(
				'post_type'  => 'page',
				'post_title' => 'Hidden page',
			)
		);
		update_post_meta( $page, '_yoast_wpseo_meta-robots-noindex', '1' );
		$this->post(
			array(
				'post_type'  => 'page',
				'post_title' => 'Shown page',
			)
		);
		$post = $this->post( array( 'post_title' => 'Hidden post' ) );
		update_post_meta( $post, 'rank_math_robots', array( 'noindex', 'nofollow' ) );
		$this->post( array( 'post_title' => 'Shown post' ) );

		$body = LlmsTxtBuilder::build_site();
		$this->assertStringNotContainsString( 'Hidden page', $body );
		$this->assertStringNotContainsString( 'Hidden post', $body );
		$this->assertNotNull( self::line( $body, 'Shown page' ) );
		$this->assertNotNull( self::line( $body, 'Shown post' ) );

		// Yoast "Show posts in search results: off", one post overriding it.
		update_option( 'wpseo_titles', array( 'noindex-post' => true ) );
		$kept = $this->post( array( 'post_title' => 'Override post' ) );
		update_post_meta( $kept, '_yoast_wpseo_meta-robots-noindex', '2' );
		$body = LlmsTxtBuilder::build_site();
		$this->assertNull( self::line( $body, 'Shown post' ) );
		$this->assertNotNull( self::line( $body, 'Override post' ) );
		$this->assertNotNull( self::line( $body, 'Shown page' ) );
	}

	/**
	 * M2: SEOPress per-post noindex and AIOSEO rows (one batched lookup per list).
	 */
	public function test_noindex_seopress_and_aioseo(): void {
		$this->site_mode();
		$a = $this->post( array( 'post_title' => 'SEOPress hidden' ) );
		update_post_meta( $a, '_seopress_robots_index', 'yes' );
		$b = $this->post( array( 'post_title' => 'AIOSEO hidden' ) );
		$c = $this->post( array( 'post_title' => 'AIOSEO default' ) );
		$this->post( array( 'post_title' => 'Plain post' ) );

		$env = new class() extends FakeEnvironment {
			/**
			 * Rows by post ID.
			 *
			 * @var array<int, array{default: bool, noindex: bool}>
			 */
			public array $rows = array();

			/**
			 * Lookups made.
			 *
			 * @var int
			 */
			public int $calls = 0;

			/**
			 * {@inheritDoc}
			 *
			 * @param int[] $ids IDs.
			 */
			public function aioseo_post_robots( array $ids ): array {
				++$this->calls;
				return array_intersect_key( $this->rows, array_flip( $ids ) );
			}
		};
		$env->with( 'seopress' )->with( 'aioseo' );
		$env->rows = array(
			$b => array(
				'default' => false,
				'noindex' => true,
			),
			$c => array(
				'default' => true,
				'noindex' => true,
			),
		);
		$this->with_seo( array( 'seopress', 'aioseo' ), $env );

		$body = LlmsTxtBuilder::build_site();
		$this->assertStringNotContainsString( 'SEOPress hidden', $body );
		$this->assertStringNotContainsString( 'AIOSEO hidden', $body );
		$this->assertNotNull( self::line( $body, 'AIOSEO default' ), '"Use default settings" ignores the stored flag.' );
		$this->assertNotNull( self::line( $body, 'Plain post' ) );
		$this->assertLessThanOrEqual( 3, $env->calls, 'Batched: special pages, key pages, posts.' );
	}

	/**
	 * The real AIOSEO lookup is a no-op without its table.
	 */
	public function test_aioseo_lookup_without_table(): void {
		$this->assertSame( array(), ( new Environment() )->aioseo_post_robots( array( 1, 2, 3 ) ) );
		$this->assertSame( array(), ( new Environment() )->aioseo_post_robots( array() ) );
	}

	/**
	 * M2: limits still fill when newer posts are left out; queried rows are capped.
	 */
	public function test_fills_limit_and_caps_rows(): void {
		$this->site_mode();
		$this->with_seo( array( 'yoast' ) );
		for ( $i = 0; $i < 55; $i++ ) {
			$id = $this->post(
				array(
					'post_title' => "Post $i",
					'post_date'  => gmdate( 'Y-m-d H:i:s', 1700000000 + $i * 60 ),
				)
			);
			if ( $i >= 45 ) {
				update_post_meta( $id, '_yoast_wpseo_meta-robots-noindex', '1' );
			}
		}
		preg_match_all( '/^- \[Post (\d+)\]/m', LlmsTxtBuilder::build_site(), $m );
		$this->assertCount( 45, $m[1], 'All 45 indexable posts (fewer than the limit exist).' );
		$this->assertLessThan( 45, max( array_map( 'intval', $m[1] ) ) );

		for ( $i = 55; $i < 70; $i++ ) {
			$this->post(
				array(
					'post_title' => "Post $i",
					'post_date'  => gmdate( 'Y-m-d H:i:s', 1600000000 + $i * 60 ),
				)
			);
		}
		preg_match_all( '/^- \[Post (\d+)\]/m', LlmsTxtBuilder::build_site(), $m );
		$this->assertCount( LlmsTxtBuilder::MAX_POSTS, $m[1], 'Second batch fills the limit.' );

		// Everything noindex: at most SCAN_BATCHES queries of the post list.
		update_option( 'wpseo_titles', array( 'noindex-post' => true ) );
		for ( $i = 0; $i < 100; $i++ ) {
			$this->post( array( 'post_title' => "Bulk $i" ) );
		}
		$queries = 0;
		$count   = static function ( $query ) use ( &$queries ): void {
			if ( 'post' === $query->get( 'post_type' ) ) {
				++$queries;
			}
		};
		add_action( 'pre_get_posts', $count );
		$body = LlmsTxtBuilder::build_site();
		remove_action( 'pre_get_posts', $count );
		$this->assertSame( 0, preg_match_all( '/^- \[(Post|Bulk) /m', $body ) );
		$this->assertSame( LlmsTxtBuilder::SCAN_BATCHES, $queries );
	}

	/**
	 * Site mode: leftover WooCommerce cart, checkout and account pages are never key pages.
	 */
	public function test_woo_leftover_pages_absent(): void {
		$this->site_mode();
		foreach ( array(
			'cart'      => 'Cart',
			'checkout'  => 'Checkout',
			'myaccount' => 'My account',
		) as $key => $title ) {
			update_option(
				'woocommerce_' . $key . '_page_id',
				$this->post(
					array(
						'post_type'  => 'page',
						'post_title' => $title,
					)
				)
			);
		}
		$this->post(
			array(
				'post_type'  => 'page',
				'post_title' => 'About',
			)
		);
		$body = LlmsTxtBuilder::build_site();
		$this->assertNotNull( self::line( $body, 'About' ) );
		foreach ( array( 'Cart', 'Checkout', 'My account' ) as $title ) {
			$this->assertNull( self::line( $body, $title ) );
		}
	}

	/**
	 * L1: a served body is cacheable for an hour.
	 */
	public function test_cache_control(): void {
		$headers = LlmsTxtEndpoint::headers();
		$this->assertSame( 'public, max-age=3600', $headers['Cache-Control'] );
		$this->assertSame( 'text/plain; charset=utf-8', $headers['Content-Type'] );
		$this->assertSame( 'noindex', $headers['X-Robots-Tag'] );
		$this->assertSame( 'nosniff', $headers['X-Content-Type-Options'] );
	}

	/**
	 * L3: coming soon blocks output while WooCommerce is loaded even when
	 * `x7velo_woocommerce_active` returns false.
	 */
	public function test_coming_soon_gate_ignores_mode_filter(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is not loaded.' );
		}
		$this->site_mode();
		update_option( 'woocommerce_coming_soon', 'yes' );
		$this->assertSame( PublicOutput::REASON_COMING_SOON, PublicOutput::blocked_reason( 'llms_txt' ) );
		( new Settings() )->update( array( 'fix_llms_txt' => true ) );
		$this->assertFalse( LlmsTxtEndpoint::is_serving() );
	}

	/**
	 * Creates a product.
	 *
	 * @param string               $name  Name.
	 * @param array<string, mixed> $props Setter => value.
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
	 * Store mode: noindex products absent; descriptions from the short description only.
	 */
	public function test_store_mode_noindex_and_description(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is not loaded.' );
		}
		$this->with_seo( array( 'yoast' ) );
		$hidden = $this->product( 'Noindex product', array( 'short_description' => 'Shh.' ) );
		update_post_meta( $hidden, '_yoast_wpseo_meta-robots-noindex', '1' );
		$this->product( 'Long only', array( 'description' => 'LONG-SECRET description.' ) );
		$this->product(
			'Short one',
			array(
				'short_description' => 'A short one.',
				'description'       => 'LONG-OTHER text.',
			)
		);
		$body = LlmsTxtBuilder::build( ( new Settings() )->all() );
		$this->assertStringContainsString( '## Products', $body );
		$this->assertStringNotContainsString( 'Noindex product', $body );
		$this->assertStringNotContainsString( 'LONG-', $body );
		$this->assertMatchesRegularExpression( '/^- \[Long only\]\(http[^)]+\)$/m', $body );
		$this->assertMatchesRegularExpression( '/^- \[Short one\]\(http[^)]+\): A short one\.$/m', $body );
		$this->assertSame( $this->admin, get_current_user_id() );
	}
}
