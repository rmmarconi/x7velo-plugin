<?php
/**
 * SCHEMA-01 tests.
 *
 * @package X7velo
 */

namespace X7velo\Tests\Audit\Checks;

use WP_UnitTestCase;
use X7velo\Audit\CheckResult;
use X7velo\Audit\Checks\ProductSchemaSource;
use X7velo\Audit\Fixer;
use X7velo\Audit\Status;
use X7velo\Detection\Detector;
use X7velo\Detection\SchemaSample;
use X7velo\Settings;
use X7velo\Tests\Detection\FakeEnvironment;
use X7velo\Tests\Detection\SeoFixtures;

/**
 * Options + runtime sample decision table (spec §7.3).
 */
final class SchemaSourceTest extends WP_UnitTestCase {

	/**
	 * Setup.
	 */
	public function set_up(): void {
		parent::set_up();
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is not loaded.' );
		}
		delete_option( SchemaSample::OPTION );
	}

	/**
	 * Runs SCHEMA-01, optionally storing a runtime sample for the same env first.
	 *
	 * @param FakeEnvironment $env        Env.
	 * @param bool|null       $woo_hooked Sample (null = none).
	 */
	private function run_with( FakeEnvironment $env, ?bool $woo_hooked = null ): CheckResult {
		$detector = new Detector( $env );
		if ( null !== $woo_hooked ) {
			SchemaSample::store( $woo_hooked, $detector->schema_fingerprint() );
		}
		return ( new ProductSchemaSource( $detector, new Settings() ) )->run();
	}

	/**
	 * AC17: Woo only → pass from WooCommerce, augment mode; same with a "hooked" sample.
	 */
	public function test_woo_only(): void {
		$result = $this->run_with( new FakeEnvironment() );
		$this->assertSame( 'product_schema_woocommerce', $result->code() );
		$this->assertSame( 'augment', $result->details()['jsonld_mode'] );
		$this->assertSame( Fixer::JSON_LD, $result->fixer() );
		$this->assertSame( 'woo_hooked', $this->run_with( new FakeEnvironment(), true )->details()['runtime'] );
	}

	/**
	 * AC18/19: free Yoast (schema on/off), AIOSEO Lite, SEOPress free → from WooCommerce.
	 */
	public function test_free_seo_plugins(): void {
		SeoFixtures::load( SeoFixtures::YOAST );
		SeoFixtures::load( SeoFixtures::AIOSEO );
		SeoFixtures::load( SeoFixtures::SEOPRESS );
		foreach ( array( 'yoast', 'aioseo', 'seopress' ) as $plugin ) {
			$this->assertSame( 'product_schema_woocommerce', $this->run_with( ( new FakeEnvironment() )->with( $plugin ), true )->code(), $plugin );
		}
		SeoFixtures::merge( 'wpseo', array( 'enable_schema' => false ) );
		$this->assertSame( 'product_schema_woocommerce', $this->run_with( ( new FakeEnvironment() )->with( 'yoast' ) )->code() );
	}

	/**
	 * AC21 (unit half): Rank Math registered → managed, even with a "hooked" (admin-like) sample.
	 */
	public function test_rank_math_managed(): void {
		SeoFixtures::load( SeoFixtures::RANK_MATH );
		SeoFixtures::merge( 'rank-math-options-general', array( 'product_brand' => 'product_brand' ) );
		foreach ( array( null, true, false ) as $sample ) {
			$result = $this->run_with( ( new FakeEnvironment() )->with( 'rank-math' ), $sample );
			$this->assertSame( Status::PASS, $result->status() );
			$this->assertSame( 'product_schema_managed', $result->code() );
			$this->assertSame( 'rank-math', $result->details()['source'] );
			$this->assertStringContainsString( 'managed by Rank Math', $result->message() );
		}
	}

	/**
	 * AC23: Rank Math managing, brand terms in use, product_brand unset → pass + Guided hint.
	 */
	public function test_rank_math_brand_hint(): void {
		SeoFixtures::load( SeoFixtures::RANK_MATH );
		$product = self::factory()->post->create( array( 'post_type' => 'product' ) );
		wp_set_object_terms( $product, array( 'Acme' ), 'product_brand' );

		$result = $this->run_with( ( new FakeEnvironment() )->with( 'rank-math' ) );
		$this->assertSame( Status::PASS, $result->status() );
		$this->assertSame( 'managed_by_rank_math_brand_hint', $result->code() );
		$this->assertSame( 'admin.php?page=rank-math-options-general', $result->guided_path() );
	}

	/**
	 * AC24: Yoast WooCommerce SEO stub → managed; with Woo still hooked at render → duplicate warn.
	 */
	public function test_yoast_woo(): void {
		$env = ( new FakeEnvironment() )->with( 'yoast' )->with( 'yoast-woo' );
		$this->assertSame( 'product_schema_managed', $this->run_with( $env )->code() );

		$result = $this->run_with( $env, true );
		$this->assertSame( Status::WARN, $result->status() );
		$this->assertSame( 'duplicate_product_schema', $result->code() );
		$this->assertSame( 'admin.php?page=wpseo_woo', $result->guided_path() );
		$this->assertStringContainsString( 'Yoast WooCommerce SEO', $result->message() );
	}

	/**
	 * AC25: Woo removed + no emitter → fail offering emit; with a known schema plugin → unverified pass.
	 */
	public function test_runtime_removed_no_emitter_fails_and_offers_emit(): void {
		$result = $this->run_with( new FakeEnvironment(), false );
		$this->assertSame( Status::FAIL, $result->status() );
		$this->assertSame( 'no_product_schema', $result->code() );
		$this->assertSame( 'emit', $result->details()['jsonld_mode'] );
		$this->assertSame( Fixer::JSON_LD, $result->fixer() );

		( new Settings() )->update( array( 'fix_jsonld' => true ) );
		$this->assertSame( 'product_schema_x7velo', $this->run_with( new FakeEnvironment(), false )->code() );
	}

	/**
	 * AC25 second half.
	 */
	public function test_runtime_removed_known_schema_plugin_is_unverified_pass(): void {
		$env          = new FakeEnvironment();
		$env->plugins = array( array_keys( Detector::KNOWN_SCHEMA_PLUGINS )[4] );
		$result       = $this->run_with( $env, false );
		$this->assertSame( Status::PASS, $result->status() );
		$this->assertSame( 'managed_by_unverified_plugin', $result->code() );
		$this->assertSame( 'managed', $result->details()['jsonld_mode'] );
		$this->assertNotSame( Fixer::JSON_LD, $result->fixer() );
	}

	/**
	 * A stale sample (other plugin state) is ignored → options only.
	 */
	public function test_stale_sample_ignored(): void {
		SchemaSample::store( false, 'some-other-state' );
		$this->assertSame( 'product_schema_woocommerce', $this->run_with( new FakeEnvironment() )->code() );
	}
}
