<?php
/**
 * Detector facade tests.
 *
 * @package X7velo
 */

namespace X7velo\Tests\Detection;

use WP_UnitTestCase;
use X7velo\Detection\Detector;

/**
 * Product source, sitemap resolution order, plugin lists, fingerprint.
 */
final class DetectorTest extends WP_UnitTestCase {

	/**
	 * AC17: Woo only → product source WooCommerce, nothing else claims anything.
	 */
	public function test_woo_only(): void {
		$detector = new Detector( new FakeEnvironment() );
		$this->assertSame( 'woocommerce', $detector->product_source()['id'] );
		$this->assertSame( array(), $detector->product_emitters() );
		$this->assertNull( $detector->woo_product_removed_by() );
		$this->assertSame( array(), $detector->organization_emitters() );
		$this->assertSame( array(), $detector->robots_modifiers() );
		$this->assertSame( array( 'woocommerce' ), array_map( static fn( $a ) => $a->id(), $detector->active() ) );
	}

	/**
	 * AC18/19: free Yoast, AIOSEO Lite and SEOPress free keep Woo as the product source.
	 */
	public function test_free_seo_plugins_keep_woo(): void {
		SeoFixtures::load( SeoFixtures::YOAST );
		SeoFixtures::load( SeoFixtures::AIOSEO );
		SeoFixtures::load( SeoFixtures::SEOPRESS );
		foreach ( array( 'yoast', 'aioseo', 'seopress' ) as $plugin ) {
			$detector = new Detector( ( new FakeEnvironment() )->with( $plugin ) );
			$this->assertSame( 'woocommerce', $detector->product_source()['id'], $plugin );
		}
	}

	/**
	 * AC27: provider per config, in priority order Yoast → Rank Math → AIOSEO → SEOPress → core.
	 *
	 * @return array<string, array{0: string[], 1: string, 2: string}>
	 */
	public function provide_providers(): array {
		return array(
			'core'              => array( array(), 'core', '/wp-sitemap.xml' ),
			'yoast'             => array( array( 'yoast' ), 'yoast', '/sitemap_index.xml' ),
			'rank math'         => array( array( 'rank-math' ), 'rank-math', '/sitemap_index.xml' ),
			'aioseo'            => array( array( 'aioseo' ), 'aioseo', '/sitemap.xml' ),
			'seopress'          => array( array( 'seopress' ), 'seopress', '/sitemaps.xml' ),
			'yoast + rank math' => array( array( 'rank-math', 'yoast' ), 'yoast', '/sitemap_index.xml' ),
			'aioseo + seopress' => array( array( 'seopress', 'aioseo' ), 'aioseo', '/sitemap.xml' ),
		);
	}

	/**
	 * AC27.
	 *
	 * @dataProvider provide_providers
	 *
	 * @param string[] $plugins  Simulated plugins.
	 * @param string   $provider Expected provider.
	 * @param string   $path     Expected path.
	 */
	public function test_provider_resolution( array $plugins, string $provider, string $path ): void {
		SeoFixtures::load( SeoFixtures::YOAST );
		SeoFixtures::load( SeoFixtures::RANK_MATH );
		SeoFixtures::load( SeoFixtures::AIOSEO );
		SeoFixtures::load( SeoFixtures::SEOPRESS );
		update_option( 'blog_public', '1' );
		$env = new FakeEnvironment();
		foreach ( $plugins as $plugin ) {
			$env->with( $plugin );
		}
		$sitemap = ( new Detector( $env ) )->sitemap();
		$this->assertSame( $provider, $sitemap['provider'] );
		$this->assertSame( $path, $sitemap['path'] );
		$this->assertTrue( $sitemap['includes_products'] );
	}

	/**
	 * Disabled plugin sitemap falls through; core needs blog_public = 1.
	 */
	public function test_sitemap_fallthrough_and_private_site(): void {
		SeoFixtures::load( SeoFixtures::YOAST );
		SeoFixtures::merge( 'wpseo', array( 'enable_xml_sitemap' => false ) );
		$detector = new Detector( ( new FakeEnvironment() )->with( 'yoast' ) );
		$this->assertSame( 'core', $detector->sitemap()['provider'] );

		update_option( 'blog_public', '0' );
		$this->assertNull( ( new Detector( ( new FakeEnvironment() )->with( 'yoast' ) ) )->sitemap() );
	}

	/**
	 * Known schema / feed plugin lists are exposed and filterable (AC25 testability).
	 */
	public function test_plugin_lists(): void {
		$this->assertArrayHasKey( 'wp-schema-pro/wp-schema-pro.php', Detector::KNOWN_SCHEMA_PLUGINS );
		$this->assertArrayHasKey( 'google-listings-and-ads/google-listings-and-ads.php', Detector::KNOWN_FEED_PLUGINS );

		$env          = new FakeEnvironment();
		$env->plugins = array( 'wp-schema-pro/wp-schema-pro.php', 'facebook-for-woocommerce/facebook-for-woocommerce.php' );
		$detector     = new Detector( $env );
		$this->assertSame( array( 'Schema Pro' ), $detector->known_schema_plugins_active() );
		$this->assertSame( array( 'Facebook for WooCommerce' ), $detector->feed_plugins_active() );

		add_filter( 'x7velo_known_feed_plugins', static fn() => array( 'my-feed/my-feed.php' => 'My Feed' ) );
		$env->plugins[] = 'my-feed/my-feed.php';
		$this->assertSame( array( 'My Feed' ), $detector->feed_plugins_active() );
	}

	/**
	 * Adapters filter: non-adapters are dropped.
	 */
	public function test_adapters_filter(): void {
		add_filter( 'x7velo_detection_adapters', static fn( $adapters ) => array_merge( array( 'junk' ), array_slice( $adapters, 0, 1 ) ) );
		$this->assertCount( 1, Detector::default_adapters( new FakeEnvironment() ) );
	}

	/**
	 * Fingerprint changes with active plugins and with emitter state.
	 */
	public function test_schema_fingerprint(): void {
		SeoFixtures::load( SeoFixtures::RANK_MATH );
		$woo = ( new Detector( new FakeEnvironment() ) )->schema_fingerprint();
		$rm  = ( new Detector( ( new FakeEnvironment() )->with( 'rank-math' ) ) )->schema_fingerprint();
		$this->assertNotSame( $woo, $rm );

		update_option( 'rank_math_modules', array( 'sitemap' ) );
		$rm_off = ( new Detector( ( new FakeEnvironment() )->with( 'rank-math' ) ) )->schema_fingerprint();
		$this->assertNotSame( $rm, $rm_off );
		$this->assertSame( $rm_off, ( new Detector( ( new FakeEnvironment() )->with( 'rank-math' ) ) )->schema_fingerprint() );
	}
}
