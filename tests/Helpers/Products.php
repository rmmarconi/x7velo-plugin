<?php
/**
 * Product fixtures for tests (uses WooCommerce CRUD).
 *
 * @package X7velo
 */

namespace X7velo\Tests\Helpers;

use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;

/**
 * Creates WooCommerce products for audit tests.
 */
final class Products {

	/**
	 * Sequence for unique SKUs/GTINs.
	 *
	 * @var int
	 */
	private static int $seq = 0;

	/**
	 * A description long enough for DATA-03.
	 */
	public static function long_text(): string {
		return str_repeat( 'Handmade from organic cotton. ', 8 );
	}

	/**
	 * Creates a published simple product that passes every DATA check unless overridden.
	 *
	 * Supported overrides: regular_price, image (bool), description, short_description, sku,
	 * gtin, brand (string|''), category (bool), virtual, status, type ('simple'|'external'|'grouped').
	 *
	 * @param array<string, mixed> $overrides Overrides.
	 * @return int Product ID.
	 */
	public static function complete( array $overrides = array() ): int {
		++self::$seq;
		$o = array_merge(
			array(
				'regular_price'     => '19.90',
				'image'             => true,
				'description'       => self::long_text(),
				'short_description' => '',
				'sku'               => 'SKU-' . self::$seq . '-' . wp_rand(),
				'gtin'              => (string) ( 4006381333900 + self::$seq ),
				'brand'             => 'Acme',
				'category'          => true,
				'virtual'           => false,
				'status'            => 'publish',
				'type'              => 'simple',
			),
			$overrides
		);

		$class   = 'WC_Product_' . ucfirst( $o['type'] );
		$product = new $class();
		$product->set_name( 'Product ' . self::$seq );
		$product->set_status( $o['status'] );
		$product->set_regular_price( $o['regular_price'] );
		$product->set_description( $o['description'] );
		$product->set_short_description( $o['short_description'] );
		$product->set_sku( $o['sku'] );
		if ( method_exists( $product, 'set_global_unique_id' ) ) {
			$product->set_global_unique_id( $o['gtin'] );
		}
		$product->set_virtual( (bool) $o['virtual'] );
		if ( $o['image'] ) {
			$product->set_image_id( self::attachment() );
		}
		if ( $o['category'] ) {
			$product->set_category_ids( array( self::category() ) );
		}
		$id = $product->save();

		if ( '' !== $o['brand'] && taxonomy_exists( 'product_brand' ) ) {
			wp_set_object_terms( $id, $o['brand'], 'product_brand' );
		}
		return $id;
	}

	/**
	 * Creates a published variable product with the given variation prices ('' = no price).
	 *
	 * @param array<int, string>   $prices    Variation regular prices.
	 * @param array<string, mixed> $overrides Parent overrides: virtual_variations (bool).
	 * @return int Parent product ID.
	 */
	public static function variable( array $prices, array $overrides = array() ): int {
		++self::$seq;
		$parent = new WC_Product_Variable();
		$parent->set_name( 'Variable ' . self::$seq );
		$parent->set_status( 'publish' );
		$parent->set_description( self::long_text() );
		$parent->set_sku( 'VAR-' . self::$seq . '-' . wp_rand() );
		$parent->set_image_id( self::attachment() );
		$parent->set_category_ids( array( self::category() ) );
		$parent_id = $parent->save();

		foreach ( $prices as $price ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $parent_id );
			$variation->set_status( 'publish' );
			$variation->set_regular_price( $price );
			$variation->set_virtual( ! empty( $overrides['virtual_variations'] ) );
			$variation->save();
		}
		\WC_Product_Variable::sync( $parent_id );
		return $parent_id;
	}

	/**
	 * Creates a set of products: $ok complete ones and $bad with the given overrides.
	 *
	 * @param int                  $ok   Number of complete products.
	 * @param int                  $bad  Number of products with $with overrides.
	 * @param array<string, mixed> $with Overrides for the bad ones.
	 * @return int[] Bad product IDs.
	 */
	public static function mix( int $ok, int $bad, array $with ): array {
		for ( $i = 0; $i < $ok; $i++ ) {
			self::complete();
		}
		$ids = array();
		for ( $i = 0; $i < $bad; $i++ ) {
			$ids[] = self::complete( $with );
		}
		return $ids;
	}

	/**
	 * A non-default product category term ID.
	 */
	public static function category(): int {
		$term = term_exists( 'Shirts', 'product_cat' );
		if ( ! $term ) {
			$term = wp_insert_term( 'Shirts', 'product_cat' );
		}
		return (int) $term['term_id'];
	}

	/**
	 * An image attachment ID.
	 */
	public static function attachment(): int {
		return (int) wp_insert_attachment(
			array(
				'post_title'     => 'Image',
				'post_mime_type' => 'image/jpeg',
				'post_status'    => 'inherit',
			),
			'image.jpg'
		);
	}
}
