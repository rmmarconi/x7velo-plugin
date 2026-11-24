<?php
/**
 * CRAWL-02 tests.
 *
 * @package X7velo
 */

namespace X7velo\Tests\Audit\Checks;

use WP_UnitTestCase;
use X7velo\Audit\CheckResult;
use X7velo\Audit\Checks\Sitemap;
use X7velo\Audit\Fixer;
use X7velo\Audit\Status;
use X7velo\Detection\Detector;
use X7velo\Detection\RobotsRules;
use X7velo\Tests\Detection\FakeEnvironment;
use X7velo\Tests\Detection\SeoFixtures;

/**
 * Provider, product inclusion, robots reference.
 */
final class SitemapCheckTest extends WP_UnitTestCase {

	/**
	 * Setup.
	 */
	public function set_up(): void {
		parent::set_up();
		update_option( 'blog_public', '1' );
	}

	/**
	 * Runs CRAWL-02.
	 *
	 * @param FakeEnvironment $env Env.
	 */
	private function run_with( FakeEnvironment $env ): CheckResult {
		$detector = new Detector( $env );
		return ( new Sitemap( $detector, new RobotsRules( $detector ), 'store' ) )->run();
	}

	/**
	 * AC27: each provider resolves and passes (its own Sitemap: line is in the rules).
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public function provide_configs(): array {
		return array(
			'core'      => array( '', 'core', '/wp-sitemap.xml' ),
			'yoast'     => array( 'yoast', 'yoast', '/sitemap_index.xml' ),
			'rank-math' => array( 'rank-math', 'rank-math', '/sitemap_index.xml' ),
			'aioseo'    => array( 'aioseo', 'aioseo', '/sitemap.xml' ),
			'seopress'  => array( 'seopress', 'seopress', '/sitemaps.xml' ),
		);
	}

	/**
	 * AC27.
	 *
	 * @dataProvider provide_configs
	 *
	 * @param string $plugin   Simulated plugin.
	 * @param string $provider Expected provider.
	 * @param string $path     Expected path.
	 */
	public function test_provider_resolution( string $plugin, string $provider, string $path ): void {
		SeoFixtures::load( SeoFixtures::YOAST );
		SeoFixtures::load( SeoFixtures::RANK_MATH );
		SeoFixtures::load( SeoFixtures::AIOSEO );
		SeoFixtures::load( SeoFixtures::SEOPRESS );
		$env = new FakeEnvironment();
		if ( '' !== $plugin ) {
			$env->with( $plugin );
		}
		// Core, Rank Math, AIOSEO and SEOPress add their Sitemap: line in every context (verified
		// on the real plugins); the unit env doesn't load them, so simulate it. Yoast's line is
		// reconstructed by RobotsRules from its options.
		if ( 'yoast' !== $plugin ) {
			remove_all_filters( 'robots_txt' );
			add_filter( 'robots_txt', static fn( $out ) => $out . "\nSitemap: " . home_url( $path ) . "\n" );
		}
		$result = $this->run_with( $env );
		$this->assertSame( $provider, $result->details()['provider'] );
		$this->assertSame( $path, $result->details()['sitemap_path'] );
		$this->assertSame( Status::PASS, $result->status(), $result->message() );
	}

	/**
	 * AC27: Yoast noindex-product → fail, Guided to the product content-type settings.
	 */
	public function test_yoast_noindex_product_fails(): void {
		SeoFixtures::load( SeoFixtures::YOAST );
		SeoFixtures::merge( 'wpseo_titles', array( 'noindex-product' => true ) );
		$result = $this->run_with( ( new FakeEnvironment() )->with( 'yoast' ) );
		$this->assertSame( Status::FAIL, $result->status() );
		$this->assertSame( 'sitemap_excludes_products', $result->code() );
		$this->assertSame( 'admin.php?page=wpseo_page_settings#/post-type/products', $result->guided_path() );
		$this->assertStringContainsString( 'Yoast SEO', $result->message() );
	}

	/**
	 * AC28: SEOPress wins over core in admin even when wp_sitemaps_enabled says true.
	 */
	public function test_seopress_in_admin_context(): void {
		set_current_screen( 'dashboard' );
		add_filter( 'wp_sitemaps_enabled', '__return_true' );
		SeoFixtures::load( SeoFixtures::SEOPRESS );
		$result = $this->run_with( ( new FakeEnvironment() )->with( 'seopress' ) );
		$this->assertSame( 'seopress', $result->details()['provider'] );
		set_current_screen( 'front' );
	}

	/**
	 * Not referenced → warn with the robots fixer; physical file → Guided.
	 */
	public function test_not_referenced(): void {
		remove_all_filters( 'robots_txt' );
		$result = $this->run_with( new FakeEnvironment() );
		$this->assertSame( Status::WARN, $result->status() );
		$this->assertSame( 'sitemap_not_referenced', $result->code() );
		$this->assertSame( Fixer::ROBOTS, $result->fixer() );

		$env                            = new FakeEnvironment();
		$env->files['/site/robots.txt'] = "User-agent: *\nDisallow:\n";
		$this->assertSame( Fixer::GUIDED, $this->run_with( $env )->fixer() );
	}

	/**
	 * AC35: x7velo's Sitemap line makes it pass.
	 */
	public function test_x7velo_block_sitemap_line_passes(): void {
		remove_all_filters( 'robots_txt' );
		add_filter( 'x7velo_robots_block', static fn() => 'Sitemap: ' . home_url( '/wp-sitemap.xml' ) );
		$this->assertSame( Status::PASS, $this->run_with( new FakeEnvironment() )->status() );
	}

	/**
	 * Private site (blog_public = 0) → no sitemap, pointing at VIS-01.
	 */
	public function test_private_site(): void {
		update_option( 'blog_public', '0' );
		$result = $this->run_with( new FakeEnvironment() );
		$this->assertSame( 'no_sitemap_search_discouraged', $result->code() );
		$this->assertSame( 'options-reading.php', $result->guided_path() );
	}

	/**
	 * Reference matching: host case-insensitive, scheme ignored, other hosts rejected.
	 */
	public function test_referenced(): void {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$this->assertTrue( Sitemap::referenced( array( 'https://' . strtoupper( $host ) . '/sitemap.xml' ), '/sitemap.xml' ) );
		$this->assertTrue( Sitemap::referenced( array( 'http://' . $host . '/sitemap.xml/' ), '/sitemap.xml' ) );
		$this->assertFalse( Sitemap::referenced( array( 'https://other.example/sitemap.xml' ), '/sitemap.xml' ) );
		$this->assertFalse( Sitemap::referenced( array( 'https://' . $host . '/sitemap.rss' ), '/sitemap.xml' ) );
	}
}
