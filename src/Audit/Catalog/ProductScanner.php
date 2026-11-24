<?php
/**
 * Streaming product scan shared by the product checks.
 *
 * @package X7velo
 */

namespace X7velo\Audit\Catalog;

use LogicException;
use Throwable;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Streams every audited product once per audit run to subscribed ProductConsumers.
 *
 * Audited products = published products, excluding types that cannot be bought directly
 * (grouped, external; filterable). Variable products are evaluated through their published
 * variations for price/shipping/GTIN and through the parent for everything else (spec §5).
 *
 * Nothing per-product is kept: each consumer reduces the facts into running totals (Tally), so
 * memory is constant in catalogue size. Facts passed to consumers:
 * type (string), has_price, variations (int), variations_without_price (int), has_image,
 * has_description, has_sku, has_gtin, has_brand, uncategorized, needs_shipping (bools).
 *
 * Query plan (no hand-written SQL, no WC_Product objects):
 * 1. One `fields => ids` snapshot of the population, ordered by ID, not cached.
 * 2. Batches of BATCH_SIZE via `post__in` (primary-key lookups; meta and terms primed).
 * 3. Per batch, variation IDs of the variable parents (`id=>parent`, not cached), then variation
 *    meta primed in chunks of VARIATION_CHUNK.
 * After each batch/chunk the primed data is released from the in-request object cache, and the
 * scan stops (aborted()) if memory use passes 80 % of memory_limit.
 *
 * get_posts() is used (not WP_Query) because it suppresses query filters by default, so
 * multilingual/visibility plugins cannot change the audited population.
 */
final class ProductScanner {

	public const BATCH_SIZE      = 200;
	public const VARIATION_CHUNK = 500;

	/**
	 * Share of memory_limit at which the scan stops.
	 */
	public const MEMORY_CEILING_RATIO = 0.8;

	/**
	 * Meta keys that hold a GTIN/UPC/EAN/ISBN. `_global_unique_id` is WooCommerce core (9.2+);
	 * the others are common identifier plugins.
	 *
	 * @var string[]
	 */
	private const GTIN_META_KEYS = array( '_global_unique_id', 'hwp_product_gtin', '_wpm_gtin_code', '_alg_ean', '_ts_gtin' );

	/**
	 * Brand taxonomies: WooCommerce core Brands (9.6+) and common brand plugins.
	 *
	 * @var string[]
	 */
	private const BRAND_TAXONOMIES = array( 'product_brand', 'pwb-brand', 'yith_product_brand' );

	/**
	 * Product attribute names treated as brand.
	 */
	private const BRAND_ATTRIBUTE_PATTERN = '/^(pa_)?(brand|brands|marca|marcas|marke|marque)$/i';

	/**
	 * Subscribed consumers.
	 *
	 * @var ProductConsumer[]
	 */
	private array $consumers = array();

	/**
	 * Whether scan() has started.
	 *
	 * @var bool
	 */
	private bool $started = false;

	/**
	 * Memoised failure, so a broken scan is not repeated by every check.
	 *
	 * @var Throwable|null
	 */
	private ?Throwable $failure = null;

	/**
	 * Whether the scan stopped early at the memory ceiling.
	 *
	 * @var bool
	 */
	private bool $aborted = false;

	/**
	 * Products streamed.
	 *
	 * @var int
	 */
	private int $products = 0;

	/**
	 * Subscribes a consumer. Must happen before the first scan() call.
	 *
	 * @param ProductConsumer $consumer Consumer.
	 *
	 * @throws LogicException When the scan already started.
	 */
	public function subscribe( ProductConsumer $consumer ): void {
		if ( $this->started ) {
			throw new LogicException( 'ProductScanner: subscribe() after scan() started.' );
		}
		$this->consumers[] = $consumer;
	}

	/**
	 * Runs the scan once; later calls return immediately (or re-throw the first failure).
	 *
	 * @throws Throwable When the scan fails.
	 */
	public function scan(): void {
		if ( null !== $this->failure ) {
			throw $this->failure;
		}
		if ( $this->started ) {
			return;
		}
		// Never scans without WooCommerce (spec §15.2), even if a third-party check asks:
		// it stays "not started", so the audit can't turn into `no_products`.
		if ( ! \X7velo\Plugin::is_woocommerce_active() ) {
			return;
		}
		$this->started = true;
		try {
			$this->stream();
		} catch ( Throwable $e ) {
			$this->failure = $e;
			throw $e;
		}
	}

	/**
	 * Whether the scan stopped early because memory use reached the ceiling.
	 */
	public function aborted(): bool {
		return $this->aborted;
	}

	/**
	 * Whether a scan ran to completion (started, not aborted, no failure).
	 */
	public function completed(): bool {
		return $this->started && ! $this->aborted && null === $this->failure;
	}

	/**
	 * Products streamed so far.
	 */
	public function products(): int {
		return $this->products;
	}

	/**
	 * Streams all audited products to the consumers.
	 */
	private function stream(): void {
		/**
		 * Filters the product types excluded from the audit (not directly purchasable).
		 *
		 * @param string[] $types Product type slugs.
		 */
		$excluded = array_values( array_filter( array_map( 'sanitize_key', (array) apply_filters( 'x7velo_audit_excluded_product_types', array( 'grouped', 'external' ) ) ) ) );

		$snapshot = array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => -1,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'no_found_rows'  => true,
			'cache_results'  => false,
		);
		if ( $excluded ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- One NOT IN on product_type for the ID snapshot; defines the audited population.
			$snapshot['tax_query'] = array(
				array(
					'taxonomy' => 'product_type',
					'field'    => 'slug',
					'terms'    => $excluded,
					'operator' => 'NOT IN',
				),
			);
		}
		$ids = array_map( 'intval', get_posts( $snapshot ) );

		$default_cat = (int) get_option( 'default_product_cat', 0 );
		$brand_taxes = array_values( array_filter( self::BRAND_TAXONOMIES, 'taxonomy_exists' ) );
		$taxonomies  = get_object_taxonomies( 'product' );

		foreach ( array_chunk( $ids, self::BATCH_SIZE ) as $chunk ) {
			if ( $this->over_memory_ceiling() ) {
				$this->aborted = true;
				return;
			}

			$posts      = get_posts(
				array(
					'post_type'              => 'product',
					'post_status'            => 'publish',
					'post__in'               => $chunk,
					'orderby'                => 'post__in',
					'posts_per_page'         => count( $chunk ),
					'no_found_rows'          => true,
					'update_post_meta_cache' => true,
					'update_post_term_cache' => true,
				)
			);
			$variations = $this->variation_stats( $posts );

			foreach ( $posts as $post ) {
				$facts = $this->product_facts( $post, $variations[ $post->ID ] ?? null, $default_cat, $brand_taxes );
				foreach ( $this->consumers as $consumer ) {
					$consumer->consume( $post->ID, $facts );
				}
				++$this->products;
			}

			$this->release( wp_list_pluck( $posts, 'ID' ), $taxonomies );

			/**
			 * Fires after each product batch (for performance measurement).
			 *
			 * @param int $products Products streamed so far.
			 * @param int $memory   memory_get_usage() after releasing the batch.
			 */
			do_action( 'x7velo_scanner_batch_done', $this->products, memory_get_usage() );
		}
	}

	/**
	 * Aggregated variation facts per variable parent in a batch.
	 *
	 * @param WP_Post[] $posts Batch of products (term cache primed).
	 * @return array<int, array{total: int, without_price: int, physical: bool, gtin: int}>
	 */
	private function variation_stats( array $posts ): array {
		$parent_ids = array();
		foreach ( $posts as $post ) {
			if ( 'variable' === $this->product_type( $post->ID ) ) {
				$parent_ids[] = $post->ID;
			}
		}
		if ( ! $parent_ids ) {
			return array();
		}

		$stats = array_fill_keys(
			$parent_ids,
			array(
				'total'         => 0,
				'without_price' => 0,
				'physical'      => false,
				'gtin'          => 0,
			)
		);

		// IDs and parents only; `cache_results => false` also bypasses the WP_Query cache, whose
		// cached form of `id=>parent` results is keyed wrongly.
		$map = get_posts(
			array(
				'post_type'       => 'product_variation',
				'post_status'     => 'publish',
				'post_parent__in' => $parent_ids,
				'fields'          => 'id=>parent',
				'posts_per_page'  => -1,
				'orderby'         => 'ID',
				'order'           => 'ASC',
				'no_found_rows'   => true,
				'cache_results'   => false,
			)
		);

		$parents = array();
		foreach ( $map as $key => $value ) {
			// WP returns objects {ID, post_parent} keyed by position for id=>parent.
			if ( is_object( $value ) ) {
				$parents[ (int) $value->ID ] = (int) $value->post_parent;
			} else {
				$parents[ (int) $key ] = (int) $value;
			}
		}

		foreach ( array_chunk( array_keys( $parents ), self::VARIATION_CHUNK ) as $chunk ) {
			update_meta_cache( 'post', $chunk );
			foreach ( $chunk as $variation_id ) {
				$parent = $parents[ $variation_id ];
				if ( ! isset( $stats[ $parent ] ) ) {
					continue;
				}
				++$stats[ $parent ]['total'];
				if ( ! self::has_meta_value( $variation_id, '_price' ) ) {
					++$stats[ $parent ]['without_price'];
				}
				if ( 'yes' !== get_post_meta( $variation_id, '_virtual', true ) ) {
					$stats[ $parent ]['physical'] = true;
				}
				if ( $this->has_gtin( $variation_id ) ) {
					++$stats[ $parent ]['gtin'];
				}
			}
			$this->release( $chunk, array() );
		}

		return $stats;
	}

	/**
	 * Releases primed data for processed posts from the in-request object cache.
	 *
	 * Persistent cache: wp_cache_flush_runtime() when the drop-in supports it (clears only this
	 * request's copy; never wp_cache_flush(), which would empty the shared cache). Default cache:
	 * targeted deletes (flush_runtime would also drop alloptions and the current user).
	 *
	 * @param int[]    $ids        Post IDs.
	 * @param string[] $taxonomies Taxonomies whose relationship cache was primed.
	 */
	private function release( array $ids, array $taxonomies ): void {
		if ( wp_using_ext_object_cache() ) {
			if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_runtime' ) ) {
				wp_cache_flush_runtime();
			}
			return;
		}
		foreach ( $ids as $id ) {
			wp_cache_delete( $id, 'posts' );
			wp_cache_delete( $id, 'post_meta' );
			foreach ( $taxonomies as $taxonomy ) {
				wp_cache_delete( $id, $taxonomy . '_relationships' );
			}
		}
	}

	/**
	 * Whether memory use is above the ceiling (80 % of memory_limit; none when unlimited).
	 */
	private function over_memory_ceiling(): bool {
		$limit   = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		$ceiling = $limit > 0 ? (int) ( $limit * self::MEMORY_CEILING_RATIO ) : 0;

		/**
		 * Filters the memory use (bytes) above which the product scan stops. 0 = no limit.
		 *
		 * @param int $ceiling Ceiling in bytes.
		 * @param int $limit   PHP memory_limit in bytes (-1 = unlimited).
		 */
		$ceiling = (int) apply_filters( 'x7velo_scan_memory_ceiling', $ceiling, $limit );

		return $ceiling > 0 && memory_get_usage() > $ceiling;
	}

	/**
	 * Facts for one product.
	 *
	 * @param WP_Post                                                               $post        Product post (meta and terms cached).
	 * @param array{total: int, without_price: int, physical: bool, gtin: int}|null $variations  Variation stats (variable products).
	 * @param int                                                                   $default_cat Default product category term ID.
	 * @param string[]                                                              $brand_taxes Existing brand taxonomies.
	 * @return array<string, mixed>
	 */
	private function product_facts( WP_Post $post, ?array $variations, int $default_cat, array $brand_taxes ): array {
		$id   = $post->ID;
		$type = $this->product_type( $id );

		if ( 'variable' === $type ) {
			$total          = $variations['total'] ?? 0;
			$without_price  = $variations['without_price'] ?? 0;
			$has_price      = $total > 0 && 0 === $without_price;
			$has_gtin       = $this->has_gtin( $id ) || ( $total > 0 && ( $variations['gtin'] ?? 0 ) === $total );
			$needs_shipping = $total > 0 ? (bool) $variations['physical'] : 'yes' !== get_post_meta( $id, '_virtual', true );
		} else {
			$total          = 0;
			$without_price  = 0;
			$has_price      = self::has_meta_value( $id, '_price' );
			$has_gtin       = $this->has_gtin( $id );
			$needs_shipping = 'yes' !== get_post_meta( $id, '_virtual', true );
		}

		return array(
			'type'                     => $type,
			'has_price'                => $has_price,
			'variations'               => $total,
			'variations_without_price' => $without_price,
			'has_image'                => absint( get_post_meta( $id, '_thumbnail_id', true ) ) > 0,
			'has_description'          => self::text_length( $post->post_content ) >= 150 || self::text_length( $post->post_excerpt ) >= 150,
			'has_sku'                  => self::has_meta_value( $id, '_sku' ),
			'has_gtin'                 => $has_gtin,
			'has_brand'                => $this->has_brand( $id, $brand_taxes ),
			'uncategorized'            => $this->is_uncategorized( $id, $default_cat ),
			'needs_shipping'           => $needs_shipping,
		);
	}

	/**
	 * Product type slug from the (cached) product_type term; "simple" if none.
	 *
	 * @param int $id Product ID.
	 */
	private function product_type( int $id ): string {
		$terms = get_the_terms( $id, 'product_type' );
		if ( is_array( $terms ) && $terms ) {
			return (string) $terms[0]->slug;
		}
		return 'simple';
	}

	/**
	 * Whether a meta value is a non-empty string.
	 *
	 * @param int    $id  Post ID.
	 * @param string $key Meta key.
	 */
	private static function has_meta_value( int $id, string $key ): bool {
		$value = get_post_meta( $id, $key, true );
		return is_scalar( $value ) && '' !== trim( (string) $value );
	}

	/**
	 * Whether a product/variation has a GTIN-type identifier.
	 *
	 * @param int $id Post ID.
	 */
	private function has_gtin( int $id ): bool {
		/**
		 * Filters the meta keys that hold a GTIN/UPC/EAN/ISBN.
		 *
		 * @param string[] $keys Meta keys.
		 */
		$keys = (array) apply_filters( 'x7velo_gtin_meta_keys', self::GTIN_META_KEYS );
		foreach ( $keys as $key ) {
			if ( self::has_meta_value( $id, (string) $key ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a product has a brand (brand taxonomy term, or a "brand" attribute with a value).
	 *
	 * @param int      $id          Product ID.
	 * @param string[] $brand_taxes Existing brand taxonomies.
	 */
	private function has_brand( int $id, array $brand_taxes ): bool {
		foreach ( $brand_taxes as $taxonomy ) {
			$terms = get_the_terms( $id, $taxonomy );
			if ( is_array( $terms ) && $terms ) {
				return true;
			}
		}

		$attributes = get_post_meta( $id, '_product_attributes', true );
		if ( ! is_array( $attributes ) ) {
			return false;
		}
		foreach ( $attributes as $key => $attribute ) {
			$name = is_array( $attribute ) && isset( $attribute['name'] ) ? (string) $attribute['name'] : (string) $key;
			if ( ! preg_match( self::BRAND_ATTRIBUTE_PATTERN, $name ) && ! preg_match( self::BRAND_ATTRIBUTE_PATTERN, (string) $key ) ) {
				continue;
			}
			if ( ! empty( $attribute['is_taxonomy'] ) ) {
				if ( taxonomy_exists( $name ) ) {
					$terms = get_the_terms( $id, $name );
					if ( is_array( $terms ) && $terms ) {
						return true;
					}
				}
			} elseif ( isset( $attribute['value'] ) && '' !== trim( (string) $attribute['value'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a product has no category other than the default ("Uncategorized").
	 *
	 * @param int $id          Product ID.
	 * @param int $default_cat Default category term ID.
	 */
	private function is_uncategorized( int $id, int $default_cat ): bool {
		$terms = get_the_terms( $id, 'product_cat' );
		if ( ! is_array( $terms ) || ! $terms ) {
			return true;
		}
		foreach ( $terms as $term ) {
			if ( (int) $term->term_id !== $default_cat ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Visible text length of a description (shortcodes, HTML and extra whitespace removed).
	 *
	 * @param string $html Description HTML.
	 */
	private static function text_length( string $html ): int {
		if ( '' === $html ) {
			return 0;
		}
		$text = wp_strip_all_tags( strip_shortcodes( $html ), true );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
		return mb_strlen( $text );
	}
}
