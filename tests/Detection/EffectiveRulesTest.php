<?php
/**
 * Effective robots rules (spec §6.2).
 *
 * @package X7velo
 */

namespace X7velo\Tests\Detection;

use WP_UnitTestCase;
use X7velo\Detection\Detector;
use X7velo\Detection\RobotsRules;

/**
 * Physical file, in-process filter, reconstruction of front-end-only plugin lines.
 */
final class EffectiveRulesTest extends WP_UnitTestCase {

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
	 * Rules for an env.
	 *
	 * @param FakeEnvironment $env Env.
	 */
	private function rules( FakeEnvironment $env ): RobotsRules {
		return new RobotsRules( new Detector( $env ) );
	}

	/**
	 * Woo only: WP default + filters (Woo lines, core Sitemap) in-process.
	 */
	public function test_filter_output(): void {
		update_option( 'blog_public', '1' );
		$owner = $this->rules( new FakeEnvironment() )->owner();
		$this->assertSame( 'filter', $owner['source'] );
		$this->assertStringContainsString( "User-agent: *\n", $owner['text'] );
		$this->assertStringContainsString( 'Disallow: /wp-admin/', $owner['text'] );
		// WooCommerce's own lines differ by version: recent releases add `add-to-cart` rules, the
		// declared floor (9.6, matrix cell A) only the uploads/logs folders.
		$this->assertMatchesRegularExpression( '/add-to-cart|woocommerce_uploads/', $owner['text'], 'WooCommerce robots lines.' );
	}

	/**
	 * Physical robots.txt wins and is read as-is.
	 */
	public function test_physical_file_wins(): void {
		$env                            = ( new FakeEnvironment() )->with( 'yoast' );
		$env->files['/site/robots.txt'] = "User-agent: *\nDisallow: /private/\n";
		$rules                          = $this->rules( $env );
		$this->assertTrue( $rules->has_physical_file() );
		$this->assertSame(
			array(
				'source'    => 'file',
				'text'      => "User-agent: *\nDisallow: /private/\n",
				'modifiers' => array(),
			),
			$rules->owner()
		);
		add_filter( 'x7velo_robots_block', static fn() => "User-agent: X\nAllow: /" );
		$this->assertFalse( $rules->served()['x7velo_block'], 'The filter has no effect on a physical file.' );
	}

	/**
	 * AC33 (admin half): Yoast's block is appended in wp-admin, once; skipped if already there.
	 */
	public function test_yoast_block_reconstructed_in_admin(): void {
		SeoFixtures::load( SeoFixtures::YOAST );
		$owner = $this->rules( ( new FakeEnvironment() )->with( 'yoast' ) )->owner();
		$this->assertSame( array( 'yoast' ), $owner['modifiers'] );
		$this->assertSame( 1, substr_count( $owner['text'], '# START YOAST BLOCK' ) );
		$this->assertStringEndsWith( 'Sitemap: ' . home_url( '/sitemap_index.xml' ) . "\n# ---------------------------\n# END YOAST BLOCK", $owner['text'] );

		// Front end / CLI: Yoast's own callback already ran → no second block.
		add_filter( 'robots_txt', static fn( $out ) => $out . "\n# START YOAST BLOCK\n# END YOAST BLOCK", 99999 );
		$owner = $this->rules( ( new FakeEnvironment() )->with( 'yoast' ) )->owner();
		$this->assertSame( array(), $owner['modifiers'] );
		$this->assertSame( 1, substr_count( $owner['text'], '# START YOAST BLOCK' ) );
	}

	/**
	 * Yoast strips WP's exact default group before appending (as its filter does).
	 */
	public function test_yoast_strips_wp_default_group(): void {
		SeoFixtures::load( SeoFixtures::YOAST );
		add_filter( 'robots_txt', static fn() => "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n", 1000 );
		$text = $this->rules( ( new FakeEnvironment() )->with( 'yoast' ) )->owner()['text'];
		$this->assertStringStartsWith( '# START YOAST BLOCK', $text );
	}

	/**
	 * AC34 (admin half): Rank Math editor content replaces the base (Woo lines gone).
	 */
	public function test_rank_math_editor_replaces_base(): void {
		SeoFixtures::load( SeoFixtures::RANK_MATH );
		SeoFixtures::merge( 'rank-math-options-general', array( 'robots_txt_content' => "User-agent: *\nDisallow: /secret/" ) );
		$owner = $this->rules( ( new FakeEnvironment() )->with( 'rank-math' ) )->owner();
		$this->assertSame( "User-agent: *\nDisallow: /secret/", $owner['text'] );
		$this->assertStringNotContainsString( 'add-to-cart', $owner['text'] );

		// Rank Math + Yoast: replace first, then Yoast's block is appended after it.
		SeoFixtures::load( SeoFixtures::YOAST );
		$text = $this->rules( ( new FakeEnvironment() )->with( 'rank-math' )->with( 'yoast' ) )->owner()['text'];
		$this->assertStringStartsWith( "User-agent: *\nDisallow: /secret/\n# START YOAST BLOCK", $text );
	}

	/**
	 * Our own robots_txt callback is excluded via computing_owner_rules(); served() adds the block.
	 */
	public function test_own_callback_excluded_and_block_served(): void {
		$seen = null;
		add_filter(
			'robots_txt',
			static function ( $out ) use ( &$seen ) {
				$seen = RobotsRules::computing_owner_rules();
				return $seen ? $out : $out . "\n# x7velo";
			},
			PHP_INT_MAX - 10
		);
		$rules = $this->rules( new FakeEnvironment() );
		$this->assertStringNotContainsString( '# x7velo', $rules->owner()['text'] );
		$this->assertTrue( $seen );
		$this->assertFalse( RobotsRules::computing_owner_rules() );

		add_filter( 'x7velo_robots_block', static fn( $block, $owner ) => '' === $owner ? '' : "# BEGIN x7velo\n# END x7velo", 10, 2 );
		$served = $rules->served();
		$this->assertTrue( $served['x7velo_block'] );
		$this->assertStringEndsWith( "\n\n# BEGIN x7velo\n# END x7velo", $served['text'] );
	}

	/**
	 * Subfolder detection.
	 */
	public function test_subfolder_install(): void {
		$this->assertFalse( RobotsRules::is_subfolder_install() );
		add_filter( 'home_url', static fn() => 'https://example.com/shop', 99 );
		$this->assertTrue( RobotsRules::is_subfolder_install() );
	}
}
