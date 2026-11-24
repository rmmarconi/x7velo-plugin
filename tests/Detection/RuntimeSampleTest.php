<?php
/**
 * Front-end runtime schema sample tests (spec §7.2, AC26).
 *
 * @package X7velo
 */

namespace X7velo\Tests\Detection;

use WP_UnitTestCase;
use X7velo\Detection\Detector;
use X7velo\Detection\SchemaSample;
use X7velo\Tests\Helpers\Products;

/**
 * Written only on change; ignored under a different fingerprint.
 */
final class RuntimeSampleTest extends WP_UnitTestCase {

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
	 * AC26: 1,000 product views with unchanged state → at most one write.
	 */
	public function test_written_at_most_once_per_change(): void {
		delete_option( SchemaSample::OPTION );
		$product = Products::complete();
		$this->go_to( get_permalink( $product ) );
		$this->assertTrue( is_product() );

		$writes = 0;
		$count  = static function () use ( &$writes ): void {
			++$writes;
		};
		add_action( 'add_option_' . SchemaSample::OPTION, $count );
		add_action( 'update_option_' . SchemaSample::OPTION, $count );

		for ( $i = 0; $i < 1000; $i++ ) {
			SchemaSample::record();
		}
		$this->assertSame( 1, $writes );

		$sample = SchemaSample::read( ( new Detector() )->schema_fingerprint() );
		$this->assertTrue( $sample['woo_hooked'] );

		// Woo's output removed (as Rank Math does at render) → exactly one more write.
		remove_action( 'wp_footer', array( WC()->structured_data, 'output_structured_data' ), 10 );
		for ( $i = 0; $i < 10; $i++ ) {
			SchemaSample::record();
		}
		add_action( 'wp_footer', array( WC()->structured_data, 'output_structured_data' ), 10 );
		$this->assertSame( 2, $writes );
		$this->assertFalse( SchemaSample::read( ( new Detector() )->schema_fingerprint() )['woo_hooked'] );
		wp_cache_delete( 'alloptions', 'options' );
		$this->assertArrayNotHasKey( SchemaSample::OPTION, wp_load_alloptions(), 'Not autoloaded.' );
	}

	/**
	 * Not a product page → nothing recorded.
	 */
	public function test_ignores_non_product_pages(): void {
		delete_option( SchemaSample::OPTION );
		$this->go_to( home_url( '/' ) );
		SchemaSample::record();
		$this->assertFalse( get_option( SchemaSample::OPTION ) );
	}

	/**
	 * A sample taken under another fingerprint is ignored.
	 */
	public function test_stale_fingerprint_ignored(): void {
		SchemaSample::store( false, 'old' );
		$this->assertNull( SchemaSample::read( 'new' ) );
		$this->assertFalse( SchemaSample::read( 'old' )['woo_hooked'] );
		$this->assertFalse( SchemaSample::store( false, 'old' ), 'Unchanged → no write.' );
	}
}
