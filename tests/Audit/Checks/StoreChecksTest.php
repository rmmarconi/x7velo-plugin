<?php
/**
 * STORE-01..03 tests.
 *
 * @package X7velo
 */

namespace X7velo\Tests\Audit\Checks;

use WC_Shipping_Zone;
use WP_UnitTestCase;
use X7velo\Audit\Catalog\ProductScanner;
use X7velo\Audit\Checks\ReturnsPolicy;
use X7velo\Audit\Checks\Shipping;
use X7velo\Audit\Checks\StoreIdentity;
use X7velo\Audit\Status;
use X7velo\Settings;
use X7velo\Tests\Helpers\Products;

/**
 * Store trust checks.
 */
final class StoreChecksTest extends WP_UnitTestCase {

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
	 * STORE-01: pass, warn (no address), fail (no currency / country).
	 */
	public function test_store_identity(): void {
		update_option( 'blogname', 'Shop' );
		update_option( 'woocommerce_store_address', 'Rua A, 1' );
		update_option( 'woocommerce_store_city', 'Lisboa' );
		update_option( 'woocommerce_default_country', 'PT' );
		update_option( 'woocommerce_currency', 'EUR' );
		update_option( 'woocommerce_email_from_address', 'shop@example.com' );
		$result = ( new StoreIdentity() )->run();
		$this->assertSame( Status::PASS, $result->status() );
		$this->assertStringNotContainsString( 'Lisboa', wp_json_encode( $result->to_array() ) );

		update_option( 'woocommerce_store_address', '' );
		$this->assertSame( Status::WARN, ( new StoreIdentity() )->run()->status() );

		update_option( 'woocommerce_currency', '' );
		$this->assertSame( Status::FAIL, ( new StoreIdentity() )->run()->status() );

		update_option( 'woocommerce_currency', 'EUR' );
		update_option( 'woocommerce_default_country', '' );
		$this->assertSame( Status::FAIL, ( new StoreIdentity() )->run()->status() );
	}

	/**
	 * STORE-02: fail → warn (terms page) → pass (setting chosen); suggestion never passes on its own.
	 */
	public function test_returns_policy(): void {
		update_option( 'woocommerce_terms_page_id', 0 );
		$suggested = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Política de Devoluções',
				'post_status' => 'publish',
			)
		);
		self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_title'  => 'About us',
				'post_status' => 'publish',
			)
		);

		$result = ( new ReturnsPolicy( new Settings() ) )->run();
		$this->assertSame( Status::FAIL, $result->status() );
		$this->assertSame( $suggested, $result->details()['suggested_page_id'] );

		$terms = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Terms',
				'post_status' => 'publish',
			)
		);
		update_option( 'woocommerce_terms_page_id', $terms );
		$this->assertSame( Status::WARN, ( new ReturnsPolicy( new Settings() ) )->run()->status() );

		( new Settings() )->update( array( 'returns_page_id' => $suggested ) );
		$this->assertSame( Status::PASS, ( new ReturnsPolicy( new Settings() ) )->run()->status() );

		// A draft page does not count.
		wp_update_post(
			array(
				'ID'          => $suggested,
				'post_status' => 'draft',
			)
		);
		$this->assertSame( Status::WARN, ( new ReturnsPolicy( new Settings() ) )->run()->status() );
	}

	/**
	 * STORE-03: n/a without physical products.
	 */
	public function test_shipping_not_applicable_for_virtual_store(): void {
		Products::complete( array( 'virtual' => true ) );
		Products::variable( array( '5' ), array( 'virtual_variations' => true ) );
		$this->assertSame( Status::NOT_APPLICABLE, ( new Shipping( new ProductScanner() ) )->run()->status() );
	}

	/**
	 * STORE-03: fail without zones, warn when no zone covers the base country, pass when one does.
	 */
	public function test_shipping_zones(): void {
		update_option( 'woocommerce_default_country', 'PT' );
		update_option( 'woocommerce_ship_to_countries', '' );
		Products::complete();

		$this->assertSame( Status::FAIL, ( new Shipping( new ProductScanner() ) )->run()->status() );

		$zone = new WC_Shipping_Zone();
		$zone->set_zone_name( 'Spain' );
		$zone->add_location( 'ES', 'country' );
		$zone->save();
		$zone->add_shipping_method( 'flat_rate' );
		$this->assertSame( Status::WARN, ( new Shipping( new ProductScanner() ) )->run()->status() );

		$europe = new WC_Shipping_Zone();
		$europe->set_zone_name( 'Europe' );
		$europe->add_location( 'EU', 'continent' );
		$europe->save();
		$this->assertSame( Status::WARN, ( new Shipping( new ProductScanner() ) )->run()->status(), 'Zone without methods does not count.' );

		$europe->add_shipping_method( 'free_shipping' );
		$result = ( new Shipping( new ProductScanner() ) )->run();
		$this->assertSame( Status::PASS, $result->status() );
		$this->assertSame( 2, $result->details()['zones_with_methods'] );
	}
}
