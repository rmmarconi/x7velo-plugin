<?php
/**
 * Rank Math adapter tests.
 *
 * @package X7velo
 */

namespace X7velo\Tests\Detection\Adapters;

use WP_UnitTestCase;
use X7velo\Detection\Adapters\RankMath;
use X7velo\Detection\Detector;
use X7velo\Tests\Detection\FakeEnvironment;
use X7velo\Tests\Detection\SeoFixtures;

/**
 * Rank Math free, options from the captured 1.0.282 fixture (wizard defaults + skip).
 * The real `\RankMath\Helper` is not loaded, so registration comes from the same constant/option
 * rules Rank Math uses (verified against includes/helpers/class-conditional.php).
 */
final class RankMathAdapterTest extends WP_UnitTestCase {

	/**
	 * Adapter.
	 *
	 * @param FakeEnvironment|null $env Env.
	 */
	private function adapter( ?FakeEnvironment $env = null ): RankMath {
		return new RankMath( $env ?? ( new FakeEnvironment() )->with( 'rank-math' ) );
	}

	/**
	 * AC20: unregistered → absent for schema, llms, sitemap and robots.
	 */
	public function test_unregistered_is_absent(): void {
		SeoFixtures::load( SeoFixtures::RANK_MATH );
		delete_option( 'rank_math_registration_skip' );
		SeoFixtures::merge( 'rank_math_modules', array( 'llms-txt' ) );
		SeoFixtures::merge( 'rank-math-options-general', array( 'robots_txt_content' => "User-agent: *\nDisallow: /" ) );

		$env = ( new FakeEnvironment() )->with( 'rank-math' );
		$this->assertFalse( $this->adapter( $env )->is_active() );

		$detector = new Detector( $env );
		$this->assertNull( $detector->get( 'rank-math' ) );
		$this->assertSame( 'woocommerce', $detector->product_source()['id'] );
		$this->assertSame( array(), $detector->llms_sources() );
		$this->assertSame( 'core', $detector->sitemap()['provider'] );
		$this->assertSame( array(), $detector->robots_modifiers() );
	}

	/**
	 * Registration valid via the skip option, the constant, or a connected account.
	 */
	public function test_registration_signals(): void {
		SeoFixtures::load( SeoFixtures::RANK_MATH );
		$this->assertTrue( $this->adapter()->is_active(), 'Skip option.' );

		delete_option( 'rank_math_registration_skip' );
		$env = ( new FakeEnvironment() )->with( 'rank-math' );
		$env->constants['RANK_MATH_REGISTRATION_SKIP'] = true;
		$this->assertTrue( $this->adapter( $env )->is_active(), 'Constant.' );

		update_option( 'rank_math_connect_data', array( 'access_token' => 'x' ) );
		$this->assertTrue( $this->adapter()->is_active(), 'Connected.' );

		$this->assertFalse( ( new RankMath( new FakeEnvironment() ) )->is_active(), 'Not installed.' );
	}

	/**
	 * AC21 (unit half): wizard defaults emit Product and remove Woo's output.
	 */
	public function test_registered_wizard_defaults_emits_product(): void {
		SeoFixtures::load( SeoFixtures::RANK_MATH );
		$adapter = $this->adapter();
		$this->assertTrue( $adapter->emits_product() );
		$this->assertTrue( $adapter->removes_woo_product() );
		$this->assertTrue( $adapter->emits_organization() );
		$this->assertSame( 'rank-math', ( new Detector( ( new FakeEnvironment() )->with( 'rank-math' ) ) )->product_source()['id'] );
	}

	/**
	 * AC22: each switch restores Woo core.
	 *
	 * @return array<string, array{0: callable}>
	 */
	public function provide_switches(): array {
		return array(
			'rich-snippet module removed'  => array(
				static function (): void {
					update_option( 'rank_math_modules', array_values( array_diff( get_option( 'rank_math_modules' ), array( 'rich-snippet' ) ) ) );
				},
			),
			'product default snippet off'  => array(
				static function (): void {
					SeoFixtures::merge( 'rank-math-options-titles', array( 'pt_product_default_rich_snippet' => 'off' ) );
				},
			),
			'product default snippet book' => array(
				static function (): void {
					SeoFixtures::merge( 'rank-math-options-titles', array( 'pt_product_default_rich_snippet' => 'book' ) );
				},
			),
		);
	}

	/**
	 * AC22.
	 *
	 * @dataProvider provide_switches
	 *
	 * @param callable $change Option change.
	 */
	public function test_product_schema_switches( callable $change ): void {
		SeoFixtures::load( SeoFixtures::RANK_MATH );
		$change();
		$this->assertFalse( $this->adapter()->emits_product() );
		$this->assertSame( 'woocommerce', ( new Detector( ( new FakeEnvironment() )->with( 'rank-math' ) ) )->product_source()['id'] );
	}

	/**
	 * Sitemap: module on → /sitemap_index.xml with products; pt_product_sitemap off or noindex excludes.
	 */
	public function test_sitemap(): void {
		SeoFixtures::load( SeoFixtures::RANK_MATH );
		$this->assertSame(
			array(
				'path'              => '/sitemap_index.xml',
				'includes_products' => true,
			),
			$this->adapter()->sitemap()
		);

		SeoFixtures::merge( 'rank-math-options-titles', array( 'pt_product_robots' => array( 'noindex' ) ) );
		$this->assertFalse( $this->adapter()->sitemap()['includes_products'] );

		SeoFixtures::load( SeoFixtures::RANK_MATH );
		SeoFixtures::merge( 'rank-math-options-sitemap', array( 'pt_product_sitemap' => 'off' ) );
		$this->assertFalse( $this->adapter()->sitemap()['includes_products'] );

		update_option( 'rank_math_modules', array( 'rich-snippet' ) );
		$this->assertNull( $this->adapter()->sitemap() );
	}

	/**
	 * The llms.txt module → dynamic; robots editor content → replace (not when site is private).
	 */
	public function test_llms_and_robots(): void {
		SeoFixtures::load( SeoFixtures::RANK_MATH );
		$this->assertNull( $this->adapter()->llms_source() );
		$this->assertNull( $this->adapter()->robots() );

		SeoFixtures::merge( 'rank_math_modules', array( 'llms-txt' ) );
		$this->assertSame( array( 'kind' => 'dynamic' ), $this->adapter()->llms_source() );

		SeoFixtures::merge( 'rank-math-options-general', array( 'robots_txt_content' => "User-agent: *\nDisallow: /x" ) );
		$this->assertSame( 'replace', $this->adapter()->robots()['mode'] );
		$this->assertSame( "User-agent: *\nDisallow: /x", $this->adapter()->robots()['content'] );

		update_option( 'blog_public', '0' );
		$this->assertNull( $this->adapter()->robots() );
	}

	/**
	 * Brand taxonomy setting.
	 */
	public function test_product_brand_taxonomy(): void {
		SeoFixtures::load( SeoFixtures::RANK_MATH );
		$this->assertSame( '', $this->adapter()->product_brand_taxonomy() );
		SeoFixtures::merge( 'rank-math-options-general', array( 'product_brand' => 'product_brand' ) );
		$this->assertSame( 'product_brand', $this->adapter()->product_brand_taxonomy() );
	}
}
