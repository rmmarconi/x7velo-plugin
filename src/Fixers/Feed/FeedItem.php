<?php
/**
 * Maps one product or variation to Google Merchant feed fields (spec §8.3).
 *
 * @package X7velo
 */

namespace X7velo\Fixers\Feed;

use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Pure mapping from WooCommerce CRUD objects to an ordered list of feed fields. Values are plain
 * text; XML escaping happens in FeedWriter.
 */
final class FeedItem {

	public const MAX_TITLE       = 150;
	public const MAX_DESCRIPTION = 5000;

	/**
	 * Stock status → g:availability.
	 */
	private const AVAILABILITY = array(
		'instock'     => 'in_stock',
		'outofstock'  => 'out_of_stock',
		'onbackorder' => 'backorder',
	);

	/**
	 * Memoised category paths by term ID.
	 *
	 * @var array<int, string>
	 */
	private array $category_paths = array();

	/**
	 * Store currency.
	 *
	 * @var string
	 */
	private string $currency;

	/**
	 * Whether out-of-stock products are hidden from the catalogue.
	 *
	 * @var bool
	 */
	private bool $hide_out_of_stock;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->currency          = get_woocommerce_currency();
		$this->hide_out_of_stock = 'yes' === get_option( 'woocommerce_hide_out_of_stock_items', 'no' );
	}

	/**
	 * Whether a parent product (simple or variable) may appear: published, no password, not
	 * catalogue-hidden.
	 *
	 * @param WC_Product $product Product.
	 */
	public function parent_eligible( WC_Product $product ): bool {
		return 'publish' === $product->get_status()
			&& '' === (string) $product->get_post_password()
			&& 'hidden' !== $product->get_catalog_visibility();
	}

	/**
	 * Fields for a sellable item, or null when it is not eligible (no price, hidden, out of stock
	 * while the store hides those, unpublished variation).
	 *
	 * @param WC_Product      $product Simple product or variation.
	 * @param WC_Product|null $parent_product  Variable parent for variations.
	 * @return array<int, array{0: string, 1: string}>|null [tag, value] pairs in output order.
	 */
	public function fields( WC_Product $product, ?WC_Product $parent_product = null ): ?array {
		if ( null !== $parent_product && 'publish' !== $product->get_status() ) {
			return null;
		}
		if ( '' === (string) $product->get_price() ) {
			return null;
		}
		if ( $this->hide_out_of_stock && ! $product->is_in_stock() ) {
			return null;
		}
		$base = $parent_product ?? $product;

		$fields   = array();
		$fields[] = array( 'g:id', (string) $product->get_id() );
		$fields[] = array( 'title', self::truncate( self::plain( $product->get_name() ), self::MAX_TITLE ) );
		$fields[] = array( 'link', (string) $product->get_permalink() );

		$description = self::plain( (string) $product->get_description() );
		if ( '' === $description && null !== $parent_product ) {
			$description = self::plain( (string) ( $parent_product->get_description() ? $parent_product->get_description() : $parent_product->get_short_description() ) );
		}
		if ( '' === $description ) {
			$description = self::plain( (string) $product->get_short_description() );
		}
		$fields[] = array( 'description', self::truncate( '' === $description ? self::plain( $product->get_name() ) : $description, self::MAX_DESCRIPTION ) );

		$image_id = (int) $product->get_image_id();
		if ( $image_id <= 0 && null !== $parent_product ) {
			$image_id = (int) $parent_product->get_image_id();
		}
		$image = $image_id > 0 ? wp_get_attachment_url( $image_id ) : false;
		if ( is_string( $image ) && '' !== $image ) {
			$fields[] = array( 'g:image_link', $image );
		}

		$regular = (string) $product->get_regular_price();
		if ( '' === $regular ) {
			$regular = (string) $product->get_price();
		}
		$fields[] = array( 'g:price', $this->money( $product, $regular ) );
		$sale     = (string) $product->get_sale_price();
		if ( $product->is_on_sale() && '' !== $sale ) {
			$fields[] = array( 'g:sale_price', $this->money( $product, $sale ) );
		}

		$fields[] = array( 'g:availability', self::AVAILABILITY[ $product->get_stock_status() ] ?? ( $product->is_in_stock() ? 'in_stock' : 'out_of_stock' ) );
		$fields[] = array( 'g:condition', 'new' );

		$brand = self::brand( $base->get_id() );
		if ( '' !== $brand ) {
			$fields[] = array( 'g:brand', $brand );
		}
		$gtin = method_exists( $product, 'get_global_unique_id' ) ? trim( (string) $product->get_global_unique_id( 'edit' ) ) : '';
		if ( '' !== $gtin ) {
			$fields[] = array( 'g:gtin', $gtin );
		} else {
			$sku = trim( (string) $product->get_sku( 'edit' ) );
			if ( '' !== $sku ) {
				$fields[] = array( 'g:mpn', $sku );
			}
		}
		if ( '' === $gtin && '' === $brand ) {
			$fields[] = array( 'g:identifier_exists', 'no' );
		}

		$type = $this->category_path( $base->get_id() );
		if ( '' !== $type ) {
			$fields[] = array( 'g:product_type', $type );
		}
		if ( null !== $parent_product ) {
			$fields[] = array( 'g:item_group_id', (string) $parent_product->get_id() );
		}

		/**
		 * Filters one feed item's fields. Values are plain text (escaped on output).
		 *
		 * @param array<int, array{0: string, 1: string}> $fields  [tag, value] pairs.
		 * @param WC_Product                              $product Simple product or variation.
		 * @param WC_Product|null                         $parent_product  Variable parent, if any.
		 */
		$filtered = apply_filters( 'x7velo_feed_item', $fields, $product, $parent_product );
		return is_array( $filtered ) ? $filtered : $fields;
	}

	/**
	 * "19.99 EUR", using the shop's tax display setting.
	 *
	 * @param WC_Product $product Product.
	 * @param string     $price   Raw price.
	 */
	private function money( WC_Product $product, string $price ): string {
		$display = wc_get_price_to_display( $product, array( 'price' => $price ) );
		return number_format( (float) $display, wc_get_price_decimals(), '.', '' ) . ' ' . $this->currency;
	}

	/**
	 * First brand term name (WooCommerce core Brands), or ''.
	 *
	 * @param int $product_id Product (parent) ID.
	 */
	private static function brand( int $product_id ): string {
		if ( ! taxonomy_exists( 'product_brand' ) ) {
			return '';
		}
		$terms = get_the_terms( $product_id, 'product_brand' );
		return is_array( $terms ) && $terms ? self::plain( $terms[0]->name ) : '';
	}

	/**
	 * Category path of the first non-default category ("Clothing > Shirts"), or '' when the
	 * product only has the default category.
	 *
	 * @param int $product_id Product (parent) ID.
	 */
	private function category_path( int $product_id ): string {
		$terms = get_the_terms( $product_id, 'product_cat' );
		if ( ! is_array( $terms ) || ! $terms ) {
			return '';
		}
		$default = (int) get_option( 'default_product_cat', 0 );
		$term    = null;
		foreach ( $terms as $candidate ) {
			if ( (int) $candidate->term_id !== $default ) {
				$term = $candidate;
				break;
			}
		}
		if ( null === $term ) {
			// Only the default ("Uncategorized") category: no useful product type.
			return '';
		}
		$id = (int) $term->term_id;
		if ( ! isset( $this->category_paths[ $id ] ) ) {
			$names = array();
			foreach ( array_reverse( get_ancestors( $id, 'product_cat', 'taxonomy' ) ) as $ancestor ) {
				$parent_term = get_term( (int) $ancestor, 'product_cat' );
				if ( $parent_term instanceof \WP_Term ) {
					$names[] = self::plain( $parent_term->name );
				}
			}
			$names[] = self::plain( $term->name );
			if ( count( $this->category_paths ) >= 1000 ) {
				$this->category_paths = array();
			}
			$this->category_paths[ $id ] = implode( ' > ', $names );
		}
		return $this->category_paths[ $id ];
	}

	/**
	 * Plain text: shortcodes, tags and entities removed, whitespace collapsed.
	 *
	 * @param string $html HTML.
	 */
	public static function plain( string $html ): string {
		if ( '' === $html ) {
			return '';
		}
		$text = wp_strip_all_tags( strip_shortcodes( $html ), true );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Truncates to a number of characters.
	 *
	 * @param string $text Text.
	 * @param int    $max  Characters.
	 */
	private static function truncate( string $text, int $max ): string {
		return mb_strlen( $text ) > $max ? rtrim( mb_substr( $text, 0, $max - 1 ) ) . '…' : $text;
	}
}
