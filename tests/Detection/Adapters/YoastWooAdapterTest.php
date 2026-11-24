<?php
/**
 * Yoast WooCommerce SEO adapter tests.
 *
 * @package X7velo
 */

namespace X7velo\Tests\Detection\Adapters;

use WP_UnitTestCase;
use X7velo\Detection\Adapters\YoastWooSeo;
use X7velo\Detection\Detector;
use X7velo\Tests\Detection\FakeEnvironment;

/**
 * Best effort (unverified add-on): constant or class means "emits Product".
 */
final class YoastWooAdapterTest extends WP_UnitTestCase {

	/**
	 * AC24: WPSEO_WOO_VERSION stub → Product source is Yoast WooCommerce SEO.
	 */
	public function test_constant_stub_is_managed(): void {
		$env = ( new FakeEnvironment() )->with( 'yoast' )->with( 'yoast-woo' );
		$this->assertTrue( ( new YoastWooSeo( $env ) )->is_active() );
		$this->assertSame(
			array(
				'id'    => 'yoast-woocommerce-seo',
				'label' => 'Yoast WooCommerce SEO',
			),
			( new Detector( $env ) )->product_source()
		);
	}

	/**
	 * The class alone is enough; removes_woo_product stays false (unverified).
	 */
	public function test_class_signal(): void {
		$env            = new FakeEnvironment();
		$env->classes[] = 'Yoast_WooCommerce_SEO';
		$adapter        = new YoastWooSeo( $env );
		$this->assertTrue( $adapter->is_active() );
		$this->assertTrue( $adapter->emits_product() );
		$this->assertFalse( $adapter->removes_woo_product() );
		$this->assertFalse( ( new YoastWooSeo( new FakeEnvironment() ) )->is_active() );
	}
}
