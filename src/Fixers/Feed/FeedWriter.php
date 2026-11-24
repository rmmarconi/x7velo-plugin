<?php
/**
 * Streams the product feed as Google Merchant–style RSS 2.0 (spec §8.3).
 *
 * @package X7velo
 */

namespace X7velo\Fixers\Feed;

use WC_Product;
use WC_Product_Variable;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Writes the feed to a sink (a callable taking a string chunk), so the endpoint can echo it
 * and tests can capture it. Never stores the body anywhere.
 *
 * Same plan as the audit's ProductScanner: one `fields => ids` snapshot of the candidate parents
 * (published, no password, simple or variable), ordered by ID and not cached; then batches of
 * BATCH_SIZE via `post__in` with meta and terms primed; variations of each batch's variable
 * parents loaded in chunks the same way. Everything primed for a batch is released from the
 * in-request object cache afterwards, so memory stays flat whatever the catalogue size.
 *
 * Products go through WooCommerce CRUD (`wc_get_product`) only; no SQL.
 */
final class FeedWriter {

	public const BATCH_SIZE      = 200;
	public const VARIATION_CHUNK = 200;
	public const GOOGLE_NS       = 'http://base.google.com/ns/1.0';

	/**
	 * Product types whose items are listed (variable → one item per published variation).
	 */
	public const PRODUCT_TYPES = array( 'simple', 'variable' );

	/**
	 * Field mapper.
	 *
	 * @var FeedItem
	 */
	private FeedItem $mapper;

	/**
	 * Items written so far.
	 *
	 * @var int
	 */
	private int $items = 0;

	/**
	 * Constructor.
	 *
	 * @param FeedItem|null $mapper Mapper (tests).
	 */
	public function __construct( ?FeedItem $mapper = null ) {
		$this->mapper = $mapper ?? new FeedItem();
	}

	/**
	 * Writes the whole feed.
	 *
	 * @param callable(string): void $sink        Receives output chunks in order.
	 * @param callable|null          $after_batch Called after each batch (e.g. flush()); returning
	 *                                            false stops after that batch (the XML is still
	 *                                            closed).
	 * @return int Items written.
	 */
	public function write( callable $sink, ?callable $after_batch = null ): int {
		$this->items = 0;
		$sink( $this->head() );

		foreach ( array_chunk( $this->snapshot(), self::BATCH_SIZE ) as $chunk ) {
			$out = '';
			foreach ( $this->load( 'product', $chunk ) as $post ) {
				$out .= $this->product_items( $post );
			}
			$this->release( $chunk );
			if ( '' !== $out ) {
				$sink( $out );
			}

			/**
			 * Fires after each feed batch (for performance measurement).
			 *
			 * @param int $items  Items written so far.
			 * @param int $memory memory_get_usage() after releasing the batch.
			 */
			do_action( 'x7velo_feed_batch_done', $this->items, memory_get_usage() );
			if ( null !== $after_batch && false === $after_batch() ) {
				break;
			}
		}

		$sink( "</channel>\n</rss>\n" );
		return $this->items;
	}

	/**
	 * XML declaration and channel header.
	 */
	private function head(): string {
		$name        = FeedItem::plain( (string) get_bloginfo( 'name' ) );
		$description = FeedItem::plain( (string) get_bloginfo( 'description' ) );
		return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<rss version="2.0" xmlns:g="' . self::GOOGLE_NS . '">' . "\n"
			. "<channel>\n"
			. self::element( 'title', '' === $name ? home_url( '/' ) : $name )
			. self::element( 'link', home_url( '/' ) )
			. self::element( 'description', '' === $description ? ( '' === $name ? home_url( '/' ) : $name ) : $description );
	}

	/**
	 * IDs of candidate parents: published, no password, listed types. Visibility and price are
	 * checked per product.
	 *
	 * @return int[]
	 */
	private function snapshot(): array {
		/**
		 * Filters the product types listed in the feed.
		 *
		 * @param string[] $types Product type slugs (default simple, variable).
		 */
		$types = array_values( array_filter( array_map( 'sanitize_key', (array) apply_filters( 'x7velo_feed_product_types', self::PRODUCT_TYPES ) ) ) );
		if ( array() === $types ) {
			return array();
		}
		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'has_password'   => false,
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				'cache_results'  => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- One IN on product_type for the ID snapshot; defines the feed population.
				'tax_query'      => array(
					array(
						'taxonomy' => 'product_type',
						'field'    => 'slug',
						'terms'    => $types,
						'operator' => 'IN',
					),
				),
			)
		);
		return array_map( 'intval', $ids );
	}

	/**
	 * Loads posts by ID with meta and terms primed.
	 *
	 * @param string $post_type Post type.
	 * @param int[]  $ids       IDs.
	 * @return WP_Post[]
	 */
	private function load( string $post_type, array $ids ): array {
		if ( array() === $ids ) {
			return array();
		}
		$posts = get_posts(
			array(
				'post_type'              => $post_type,
				'post_status'            => 'any',
				'post__in'               => $ids,
				'orderby'                => 'post__in',
				'posts_per_page'         => count( $ids ),
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => 'product' === $post_type,
			)
		);
		$this->prime_images( $posts );
		return $posts;
	}

	/**
	 * Primes the attachment posts and meta behind the products' images (one query each).
	 *
	 * @param WP_Post[] $posts Posts with meta primed.
	 */
	private function prime_images( array $posts ): void {
		$ids = array();
		foreach ( $posts as $post ) {
			$image = absint( get_post_meta( $post->ID, '_thumbnail_id', true ) );
			if ( $image > 0 ) {
				$ids[] = $image;
			}
		}
		if ( array() === $ids ) {
			return;
		}
		get_posts(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'post__in'               => array_values( array_unique( $ids ) ),
				'posts_per_page'         => count( $ids ),
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
			)
		);
	}

	/**
	 * Items for one parent product.
	 *
	 * @param WP_Post $post Product post.
	 */
	private function product_items( WP_Post $post ): string {
		$product = wc_get_product( $post );
		if ( ! $product instanceof WC_Product || ! $this->mapper->parent_eligible( $product ) ) {
			return '';
		}
		if ( ! $product instanceof WC_Product_Variable ) {
			return $this->item( $product, null );
		}

		$out      = '';
		$children = array_map( 'intval', $product->get_children() );
		foreach ( array_chunk( $children, self::VARIATION_CHUNK ) as $chunk ) {
			$variations = $this->load( 'product_variation', $chunk );
			foreach ( $variations as $variation_post ) {
				if ( 'publish' !== $variation_post->post_status ) {
					continue;
				}
				$variation = wc_get_product( $variation_post );
				if ( $variation instanceof WC_Product ) {
					$out .= $this->item( $variation, $product );
				}
			}
			$this->release( $chunk );
		}
		return $out;
	}

	/**
	 * One `<item>`, or '' when the product is not eligible.
	 *
	 * @param WC_Product      $product Product or variation.
	 * @param WC_Product|null $parent_product  Variable parent.
	 */
	private function item( WC_Product $product, ?WC_Product $parent_product ): string {
		$fields = $this->mapper->fields( $product, $parent_product );
		if ( null === $fields ) {
			return '';
		}
		$xml = "<item>\n";
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) || ! isset( $field[0], $field[1] ) || ! self::valid_tag( (string) $field[0] ) ) {
				continue;
			}
			$xml .= self::element( (string) $field[0], (string) $field[1] );
		}
		++$this->items;
		return $xml . "</item>\n";
	}

	/**
	 * Whether a tag is an RSS item element or a g: element with a simple name.
	 *
	 * @param string $tag Tag.
	 */
	private static function valid_tag( string $tag ): bool {
		return 1 === preg_match( '/^(g:)?[a-z][a-z0-9_]{0,63}$/', $tag );
	}

	/**
	 * One escaped element line.
	 *
	 * @param string $tag   Tag (validated).
	 * @param string $value Plain text.
	 */
	public static function element( string $tag, string $value ): string {
		return '<' . $tag . '>' . self::escape( $value ) . '</' . $tag . ">\n";
	}

	/**
	 * XML text escaping: characters not allowed in XML 1.0 are removed, then & < > " ' escaped.
	 *
	 * @param string $text Text (UTF-8).
	 */
	public static function escape( string $text ): string {
		$text = wp_check_invalid_utf8( $text, true );
		$text = (string) preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text );
		return htmlspecialchars( $text, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}

	/**
	 * Releases everything primed for these posts from the in-request object cache.
	 *
	 * Persistent cache: wp_cache_flush_runtime() when supported (this request's copy only; never
	 * wp_cache_flush()). Default cache: targeted deletes of the posts, meta, term relationships
	 * and images; WooCommerce's per-product cache groups (`product_<id>`) and the runtime copies
	 * of its `wc_product_children_<id>` transients; and the per-query result caches
	 * (`post-queries`, `term-queries`), which otherwise grow by one entry per lookup.
	 *
	 * @param int[] $ids Post IDs.
	 */
	private function release( array $ids ): void {
		if ( wp_using_ext_object_cache() ) {
			if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_runtime' ) ) {
				wp_cache_flush_runtime();
			}
			return;
		}
		$flush_group = function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' );
		$taxonomies  = get_object_taxonomies( 'product' );
		foreach ( $ids as $id ) {
			$image = absint( get_post_meta( $id, '_thumbnail_id', true ) );
			if ( $image > 0 ) {
				wp_cache_delete( $image, 'posts' );
				wp_cache_delete( $image, 'post_meta' );
			}
			wp_cache_delete( $id, 'posts' );
			wp_cache_delete( $id, 'post_meta' );
			foreach ( $taxonomies as $taxonomy ) {
				wp_cache_delete( $id, $taxonomy . '_relationships' );
			}
			wp_cache_delete( '_transient_wc_product_children_' . $id, 'options' );
			wp_cache_delete( '_transient_timeout_wc_product_children_' . $id, 'options' );
			if ( $flush_group ) {
				wp_cache_flush_group( 'product_' . $id );
			} else {
				wp_cache_delete( 'wc_product_' . $id . '_cache_prefix', 'product_' . $id );
			}
		}
		if ( $flush_group ) {
			foreach ( array( 'products', 'post-queries', 'term-queries' ) as $group ) {
				wp_cache_flush_group( $group );
			}
		}
	}
}
