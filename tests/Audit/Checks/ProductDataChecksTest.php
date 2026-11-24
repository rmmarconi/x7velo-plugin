<?php
/**
 * DATA-01..05 tests.
 *
 * @package X7velo
 */

namespace X7velo\Tests\Audit\Checks;

use WP_UnitTestCase;
use X7velo\Audit\Catalog\ProductScanner;
use X7velo\Audit\CheckResult;
use X7velo\Audit\Checks\ProductCategories;
use X7velo\Audit\Checks\ProductDescription;
use X7velo\Audit\Checks\ProductIdentifiers;
use X7velo\Audit\Checks\ProductImage;
use X7velo\Audit\Checks\ProductPrice;
use X7velo\Audit\Status;
use X7velo\Settings;
use X7velo\Tests\Helpers\Products;

/**
 * Product data quality checks, with WooCommerce products.
 */
final class ProductDataChecksTest extends WP_UnitTestCase {

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
	 * Runs a DATA check class against a fresh scanner.
	 *
	 * @param string $check_class Class name.
	 */
	private static function run_check( string $check_class ): CheckResult {
		$scanner = new ProductScanner();
		if ( ProductIdentifiers::class === $check_class ) {
			return ( new ProductIdentifiers( $scanner, new Settings() ) )->run();
		}
		return ( new $check_class( $scanner ) )->run();
	}

	/**
	 * Every DATA check is not applicable without products.
	 */
	public function test_no_products_is_not_applicable(): void {
		foreach ( array( ProductPrice::class, ProductImage::class, ProductDescription::class, ProductIdentifiers::class, ProductCategories::class ) as $class ) {
			$this->assertSame( Status::NOT_APPLICABLE, self::run_check( $class )->status(), $class );
		}
	}

	/**
	 * DATA-01 thresholds: 20/20 pass, 19/20 pass (95 %), 15/20 warn (75 %), 14/20 fail.
	 *
	 * @dataProvider uniform_threshold_cases
	 *
	 * @param int    $bad      Number of products missing the field.
	 * @param string $expected Expected status.
	 */
	public function test_price_thresholds( int $bad, string $expected ): void {
		$ids    = Products::mix( 20 - $bad, $bad, array( 'regular_price' => '' ) );
		$result = self::run_check( ProductPrice::class );
		$this->assertSame( $expected, $result->status() );
		$this->assertSame( $bad, $result->details()['missing'] );
		$this->assertSame( array_slice( $ids, 0, 5 ), $result->details()['examples'] );
	}

	/**
	 * Uniform threshold cases.
	 *
	 * @return array<string, array{0: int, 1: string}>
	 */
	public function uniform_threshold_cases(): array {
		return array(
			'all ok'     => array( 0, Status::PASS ),
			'95 percent' => array( 1, Status::PASS ),
			'90 percent' => array( 2, Status::WARN ),
			'75 percent' => array( 5, Status::WARN ),
			'70 percent' => array( 6, Status::FAIL ),
		);
	}

	/**
	 * DATA-01 evaluates variable products through their variations.
	 */
	public function test_price_variable_products(): void {
		$priced   = Products::variable( array( '10', '12' ) );
		$unpriced = Products::variable( array( '10', '' ) );
		$empty    = Products::variable( array() );

		$result = self::run_check( ProductPrice::class );
		$this->assertSame( 3, $result->details()['products'] );
		$this->assertSame( 2, $result->details()['missing'] );
		$this->assertSame( 1, $result->details()['variations_without_price'] );
		$this->assertSame( array( $unpriced, $empty ), $result->details()['examples'] );
		$this->assertNotContains( $priced, $result->details()['examples'] );
		$this->assertSame( Status::FAIL, $result->status() );
	}

	/**
	 * Drafts, grouped and external products are not audited.
	 */
	public function test_population_excludes_drafts_grouped_external(): void {
		Products::complete();
		Products::complete(
			array(
				'status'        => 'draft',
				'regular_price' => '',
			)
		);
		Products::complete(
			array(
				'type'          => 'external',
				'regular_price' => '',
			)
		);
		Products::complete(
			array(
				'type'          => 'grouped',
				'regular_price' => '',
			)
		);

		$result = self::run_check( ProductPrice::class );
		$this->assertSame( 1, $result->details()['products'] );
		$this->assertSame( Status::PASS, $result->status() );
	}

	/**
	 * DATA-02 image: warn and fail.
	 */
	public function test_image(): void {
		Products::mix( 8, 2, array( 'image' => false ) );
		$this->assertSame( Status::WARN, self::run_check( ProductImage::class )->status() );

		Products::mix( 0, 2, array( 'image' => false ) );
		$this->assertSame( Status::FAIL, self::run_check( ProductImage::class )->status() );
	}

	/**
	 * DATA-03 description: 150 visible characters in long OR short description; HTML stripped.
	 */
	public function test_description(): void {
		Products::complete();
		Products::complete(
			array(
				'description'       => '',
				'short_description' => Products::long_text(),
			)
		);
		$this->assertSame( Status::PASS, self::run_check( ProductDescription::class )->status() );

		// 140 visible characters wrapped in lots of markup → too short.
		Products::mix(
			0,
			2,
			array( 'description' => '<p><strong>' . str_repeat( 'a', 70 ) . '</strong></p><div class="x">' . str_repeat( 'b', 70 ) . '</div>' )
		);
		$result = self::run_check( ProductDescription::class );
		$this->assertSame( Status::FAIL, $result->status() );
		$this->assertSame( 2, $result->details()['missing'] );
	}

	/**
	 * DATA-04: SKU + (GTIN or brand); ≥ 80 % pass, else warn, never fail.
	 */
	public function test_identifiers(): void {
		// No SKU or GTIN/brand on any product → warn, not fail.
		Products::mix(
			0,
			5,
			array(
				'sku'   => '',
				'gtin'  => '',
				'brand' => '',
			)
		);
		$result = self::run_check( ProductIdentifiers::class );
		$this->assertSame( Status::WARN, $result->status() );
		$this->assertSame( 5, $result->details()['missing'] );

		// 20 more complete products → 20/25 = 80 % → pass.
		Products::mix( 20, 0, array() );
		$this->assertSame( Status::PASS, self::run_check( ProductIdentifiers::class )->status() );
	}

	/**
	 * DATA-04: brand alone (no GTIN) counts; brand attribute counts.
	 */
	public function test_identifiers_brand_sources(): void {
		Products::complete( array( 'gtin' => '' ) );
		$id      = Products::complete(
			array(
				'gtin'  => '',
				'brand' => '',
			)
		);
		$product = wc_get_product( $id );
		$attr    = new \WC_Product_Attribute();
		$attr->set_name( 'Brand' );
		$attr->set_options( array( 'Acme' ) );
		$product->set_attributes( array( $attr ) );
		$product->save();

		$result = self::run_check( ProductIdentifiers::class );
		$this->assertSame( 0, $result->details()['missing'] );
		$this->assertSame( 2, $result->details()['with_brand'] );
	}

	/**
	 * DATA-04: "I make my own products" → only SKU required.
	 */
	public function test_identifiers_own_brand(): void {
		Products::mix(
			0,
			3,
			array(
				'gtin'  => '',
				'brand' => '',
			)
		);
		$this->assertSame( Status::WARN, self::run_check( ProductIdentifiers::class )->status() );

		( new Settings() )->update( array( 'own_brand_products' => true ) );
		$result = self::run_check( ProductIdentifiers::class );
		$this->assertSame( Status::PASS, $result->status() );
		$this->assertTrue( $result->details()['own_brand_products'] );
	}

	/**
	 * DATA-05: ≤ 5 % pass, ≤ 25 % warn, > 25 % fail.
	 */
	public function test_categories(): void {
		Products::mix( 19, 1, array( 'category' => false ) );
		$this->assertSame( Status::PASS, self::run_check( ProductCategories::class )->status() );

		Products::mix( 0, 4, array( 'category' => false ) ); // 5/24 ≈ 20.8 %.
		$this->assertSame( Status::WARN, self::run_check( ProductCategories::class )->status() );

		Products::mix( 0, 4, array( 'category' => false ) ); // 9/28 ≈ 32 %.
		$result = self::run_check( ProductCategories::class );
		$this->assertSame( Status::FAIL, $result->status() );
		$this->assertSame( 9, $result->details()['missing'] );
	}
}
