<?php
/**
 * Streaming scanner tests.
 *
 * @package X7velo
 */

namespace X7velo\Tests\Audit;

use LogicException;
use WP_UnitTestCase;
use X7velo\Audit\Catalog\ProductConsumer;
use X7velo\Audit\Catalog\ProductScanner;
use X7velo\Audit\Checks\ProductPrice;
use X7velo\Audit\Checks\Shipping;
use X7velo\Audit\Status;
use X7velo\Tests\Helpers\Products;

/**
 * Scanner streaming, memory guard and cache handling.
 */
final class ProductScannerTest extends WP_UnitTestCase {

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
	 * A consumer that records IDs only.
	 */
	private static function recorder(): ProductConsumer {
		return new class() implements ProductConsumer {
			/**
			 * IDs seen.
			 *
			 * @var int[]
			 */
			public array $ids = array();

			/**
			 * Consume.
			 *
			 * @param int                  $product_id ID.
			 * @param array<string, mixed> $facts      Facts.
			 */
			public function consume( int $product_id, array $facts ): void {
				$this->ids[] = $product_id;
			}
		};
	}

	/**
	 * Products are streamed once each, ascending, across several batches; scan() is idempotent.
	 */
	public function test_streams_in_id_order_across_batches(): void {
		$expected = array();
		for ( $i = 0; $i < ProductScanner::BATCH_SIZE + 5; $i++ ) {
			$expected[] = (int) wp_insert_post(
				array(
					'post_type'   => 'product',
					'post_status' => 'publish',
					'post_title'  => 'P' . $i,
				)
			);
		}
		$scanner  = new ProductScanner();
		$recorder = self::recorder();
		$scanner->subscribe( $recorder );
		$scanner->scan();
		$scanner->scan();

		sort( $expected );
		$this->assertSame( $expected, $recorder->ids );
		$this->assertSame( count( $expected ), $scanner->products() );
		$this->assertFalse( $scanner->aborted() );
	}

	/**
	 * Subscribing after the scan started is a programming error.
	 */
	public function test_subscribe_after_scan_throws(): void {
		$scanner = new ProductScanner();
		$scanner->scan();
		$this->expectException( LogicException::class );
		$scanner->subscribe( self::recorder() );
	}

	/**
	 * A variable product with 300 variations is evaluated correctly and memory stays bounded.
	 */
	public function test_many_variations_bounded_memory(): void {
		$prices   = array_fill( 0, 300, '10' );
		$prices[] = '';
		$parent   = Products::variable( $prices );
		wp_cache_flush();

		// Warm-up scan loads options/terms; the measured scan then shows only what it retains.
		( new ProductPrice( new ProductScanner() ) )->run();
		$price  = new ProductPrice( new ProductScanner() );
		$before = memory_get_usage();
		$result = $price->run();
		$delta  = memory_get_usage() - $before;

		$this->assertSame( Status::FAIL, $result->status() );
		$this->assertSame( 301 - 300, $result->details()['variations_without_price'] );
		$this->assertSame( array( $parent ), $result->details()['examples'] );
		$this->assertLessThan( 512 * KB_IN_BYTES, $delta, 'Variation meta must be released after each chunk.' );
		$this->assertLessThan( 256 * MB_IN_BYTES, memory_get_peak_usage() );
	}

	/**
	 * Memory guard: the scan stops and product checks report scan_memory_limit.
	 */
	public function test_memory_guard_aborts_scan(): void {
		Products::complete();
		add_filter( 'x7velo_scan_memory_ceiling', static fn() => 1 );

		$scanner  = new ProductScanner();
		$price    = new ProductPrice( $scanner );
		$shipping = new Shipping( $scanner );

		$this->assertSame( 'scan_memory_limit', $price->run()->code() );
		$this->assertSame( Status::ERROR, $shipping->run()->status() );
		$this->assertTrue( $scanner->aborted() );
		$this->assertSame( 0, $scanner->products() );
	}

	/**
	 * Unlimited memory (-1) means no ceiling.
	 */
	public function test_unlimited_memory_has_no_ceiling(): void {
		Products::complete();
		$seen = null;
		add_filter(
			'x7velo_scan_memory_ceiling',
			static function ( $ceiling, $limit ) use ( &$seen ) {
				$seen = array( $ceiling, $limit );
				return $ceiling;
			},
			10,
			2
		);
		$previous = ini_set( 'memory_limit', '-1' ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- Test.
		( new ProductScanner() )->scan();
		ini_set( 'memory_limit', (string) $previous ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- Test.

		$this->assertSame( array( 0, -1 ), $seen );
	}

	/**
	 * With a persistent object cache the scan uses flush_runtime and gives identical results.
	 */
	public function test_persistent_cache_path(): void {
		Products::mix( 3, 2, array( 'regular_price' => '' ) );
		Products::variable( array( '5', '' ) );

		$plain = ( new ProductPrice( new ProductScanner() ) )->run()->to_array();

		$was = wp_using_ext_object_cache( true );
		try {
			$this->assertTrue( wp_cache_supports( 'flush_runtime' ) );
			$cached = ( new ProductPrice( new ProductScanner() ) )->run()->to_array();
		} finally {
			wp_using_ext_object_cache( (bool) $was ); // $was is null when never set; passing null would be a no-op.
		}

		$this->assertSame( $plain, $cached );
	}
}
