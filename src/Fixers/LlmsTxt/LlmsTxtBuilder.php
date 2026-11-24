<?php
/**
 * Body of x7velo's llms.txt (spec §8.2).
 *
 * @package X7velo
 */

namespace X7velo\Fixers\LlmsTxt;

use WP_Query;
use WP_Term;
use X7velo\Detection\Detector;
use X7velo\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the Markdown body (llms.txt convention). Returns a string: no headers, no exit, so tests
 * read the body directly. Only what a logged-out visitor already sees: the body is generated as
 * a logged-out visitor (current user pinned to 0, restored afterwards), and every listed post,
 * page or product must be published, not password-protected (query and post_password_required()),
 * not `noindex` in the active SEO plugin, and not excluded by `x7velo_llms_txt_include_post`.
 * Products whose catalog visibility is "hidden" are left out; categories must be non-empty.
 * Never reviews, customers or orders.
 *
 * Descriptions come only from the hand-written excerpt (a product's short description) as
 * filtered by `get_the_excerpt` for that logged-out visitor, never from the post content, so
 * content a membership plugin hides from visitors can't leak through llms.txt.
 *
 * Bounded: at most MAX_PRODUCTS products and MAX_CATEGORIES categories, whatever the catalogue
 * size; lists that lose items to the rules above read further rows, at most SCAN_BATCHES times
 * their limit. Every value is reduced to one line of plain text and Markdown-escaped, so product
 * data can't add headings, lines or links.
 */
final class LlmsTxtBuilder {

	public const MAX_PRODUCTS      = 200;
	public const MAX_CATEGORIES    = 100;
	public const DESCRIPTION_CHARS = 160;
	public const NAME_CHARS        = 200;

	/**
	 * Posts/pages mode bounds (spec §15.3).
	 */
	public const MAX_KEY_PAGES     = 20;
	public const MAX_POSTS         = 50;
	public const MAX_POST_SECTIONS = 10;

	/**
	 * A list reads at most this many batches of its limit (hard cap on queried rows when items are
	 * left out: 60 pages, 150 posts, 600 products).
	 */
	public const SCAN_BATCHES = 3;

	/**
	 * WooCommerce pages never listed in posts/pages mode (leftovers from a removed store).
	 */
	public const WOO_PRIVATE_PAGES = array( 'woocommerce_cart_page_id', 'woocommerce_checkout_page_id', 'woocommerce_myaccount_page_id' );

	/**
	 * Detector for the body being built (one per build).
	 *
	 * @var Detector|null
	 */
	private static ?Detector $detector = null;

	/**
	 * The llms.txt body.
	 *
	 * @param array<string, mixed> $settings Sanitised settings (returns_page_id, fix_feed).
	 */
	public static function build( array $settings ): string {
		if ( ! Plugin::is_woocommerce_active() ) {
			return self::build_site();
		}
		return self::as_visitor( static fn(): string => self::build_store( $settings ) );
	}

	/**
	 * Runs $build as a logged-out visitor with a fresh Detector, restoring the current user after
	 * (mirrors FeedEndpoint::pin_context(), which can skip the restore because it exits).
	 *
	 * @param callable(): string $build Body builder.
	 */
	private static function as_visitor( callable $build ): string {
		if ( null !== self::$detector ) {
			return $build(); // Already pinned (build() → build_site()).
		}
		$user           = get_current_user_id();
		self::$detector = new Detector();
		wp_set_current_user( 0 );
		try {
			return $build();
		} finally {
			self::$detector = null;
			wp_set_current_user( $user );
		}
	}

	/**
	 * The Detector of the current build (a fresh one outside a build).
	 */
	private static function detector(): Detector {
		return self::$detector ?? new Detector();
	}

	/**
	 * Store mode body.
	 *
	 * @param array<string, mixed> $settings Settings.
	 */
	private static function build_store( array $settings ): string {
		$out   = self::header();
		$store = self::store_line();
		if ( '' !== $store ) {
			$out[] = $store;
			$out[] = '';
		}

		$sections = array(
			__( 'Shop', 'x7velo' )               => self::shop_links( $settings ),
			__( 'Product categories', 'x7velo' ) => self::category_links(),
			__( 'Products', 'x7velo' )           => self::product_links(),
			'Optional'                           => self::optional_links( $settings ),
		);
		return self::finish( $out, $sections );
	}

	/**
	 * `# <title>` and `> <tagline>` lines.
	 *
	 * @return string[]
	 */
	private static function header(): array {
		$title = self::text( (string) get_bloginfo( 'name' ), self::NAME_CHARS );
		if ( '' === $title ) {
			$title = self::text( (string) wp_parse_url( home_url(), PHP_URL_HOST ), self::NAME_CHARS );
		}
		$out = array( '# ' . $title, '' );

		$tagline = self::text( (string) get_bloginfo( 'description' ), 300 );
		if ( '' !== $tagline ) {
			$out[] = '> ' . $tagline;
			$out[] = '';
		}
		return $out;
	}

	/**
	 * Appends non-empty `## <heading>` sections and returns the body.
	 *
	 * @param string[]                $out      Lines so far.
	 * @param array<string, string[]> $sections Heading => list items.
	 */
	private static function finish( array $out, array $sections ): string {
		foreach ( $sections as $heading => $items ) {
			if ( array() === $items ) {
				continue;
			}
			$out[] = '## ' . $heading;
			$out[] = '';
			foreach ( $items as $item ) {
				$out[] = $item;
			}
			$out[] = '';
		}
		return rtrim( implode( "\n", $out ) ) . "\n";
	}

	/**
	 * Posts/pages mode, used without WooCommerce (spec §15.3): key pages, the newest posts
	 * grouped by category, and the sitemap. Same escaping and visibility rules as store mode.
	 */
	public static function build_site(): string {
		return self::as_visitor(
			static function (): string {
				$out      = self::header();
				$sections = array( __( 'Key pages', 'x7velo' ) => self::key_page_links() );
				foreach ( self::post_sections() as $heading => $items ) {
					$sections[ $heading ] = $items;
				}
				$sections['Optional'] = self::sitemap_links();
				return self::finish( $out, $sections );
			}
		);
	}

	/**
	 * Whether a published post/page/product may be listed: not password-protected for a
	 * visitor, not noindex in the active SEO plugin, not excluded by the filter.
	 *
	 * @param \WP_Post $post Candidate (noindex data primed by the caller for lists).
	 */
	private static function eligible( \WP_Post $post ): bool {
		if ( 'publish' !== $post->post_status || post_password_required( $post ) ) {
			return false;
		}
		if ( self::detector()->is_post_noindex( (int) $post->ID ) ) {
			return false;
		}
		/**
		 * Filters whether a post, page or product is listed in x7velo's /llms.txt.
		 *
		 * Runs after x7velo's own rules (published, not password-protected, not noindex in the
		 * active SEO plugin) while the body is generated as a logged-out visitor. Membership and
		 * content-restriction plugins can return false for members-only content, so not even its
		 * title and link are listed.
		 *
		 * @since 0.1.0
		 *
		 * @param bool     $include Whether to list the item. Default true.
		 * @param \WP_Post $post    The post, page or product.
		 */
		return (bool) apply_filters( 'x7velo_llms_txt_include_post', true, $post );
	}

	/**
	 * Reads $args in batches of $limit rows and keeps eligible() posts with a non-empty line,
	 * until $limit are kept, the rows run out, or SCAN_BATCHES batches were read. Noindex data
	 * is primed per batch (no N+1).
	 *
	 * @param array<string, mixed>       $args  WP_Query args (paging and meta cache set here).
	 * @param int                        $limit Items wanted.
	 * @param callable(\WP_Post): string $line  List item for a post ('' to skip it).
	 * @return array<int, array{0: \WP_Post, 1: string}> Post and its line, in query order.
	 */
	private static function collect( array $args, int $limit, callable $line ): array {
		$kept = array();
		if ( $limit <= 0 ) {
			return $kept;
		}
		$args['posts_per_page']         = $limit;
		$args['no_found_rows']          = true;
		$args['ignore_sticky_posts']    = true;
		$args['update_post_meta_cache'] = false;
		for ( $page = 1; $page <= self::SCAN_BATCHES; $page++ ) {
			$args['paged'] = $page;
			$posts         = array_values( array_filter( ( new WP_Query( $args ) )->posts, static fn( $p ): bool => $p instanceof \WP_Post ) );
			self::detector()->prime_noindex( array_map( static fn( \WP_Post $p ): int => (int) $p->ID, $posts ) );
			foreach ( $posts as $post ) {
				if ( ! self::eligible( $post ) ) {
					continue;
				}
				$item = $line( $post );
				if ( '' === $item ) {
					continue;
				}
				$kept[] = array( $post, $item );
				if ( count( $kept ) >= $limit ) {
					return $kept;
				}
			}
			if ( count( $posts ) < $limit ) {
				break;
			}
		}
		return $kept;
	}

	/**
	 * A single page by ID if it is public and eligible(), else null.
	 *
	 * @param int   $id   Page ID.
	 * @param int[] $skip IDs never listed.
	 */
	private static function listable_page( int $id, array $skip = array() ): ?\WP_Post {
		if ( $id <= 0 || in_array( $id, $skip, true ) || ! self::is_public_page( $id ) ) {
			return null;
		}
		$post = get_post( $id );
		return $post instanceof \WP_Post && self::eligible( $post ) ? $post : null;
	}

	/**
	 * Key pages (≤ MAX_KEY_PAGES): static front page, posts page, published top-level pages
	 * (menu_order, then title), privacy policy page last. No descriptions. Leftover WooCommerce
	 * cart, checkout and account pages are never listed.
	 *
	 * @return string[]
	 */
	private static function key_page_links(): array {
		$front   = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front', 0 ) : 0;
		$posts   = $front > 0 ? (int) get_option( 'page_for_posts', 0 ) : 0;
		$privacy = (int) get_option( 'wp_page_for_privacy_policy', 0 );
		$skip    = array_values( array_filter( array_map( static fn( string $o ): int => (int) get_option( $o, 0 ), self::WOO_PRIVATE_PAGES ) ) );

		self::detector()->prime_noindex( array_values( array_filter( array( $front, $posts, $privacy ) ) ) );
		$first = array();
		if ( null !== self::listable_page( $front, $skip ) ) {
			$first[] = self::page_link( $front, home_url( '/' ) );
		}
		if ( $posts !== $front && null !== self::listable_page( $posts, $skip ) ) {
			$first[] = self::page_link( $posts, (string) get_permalink( $posts ) );
		}
		$last = array();
		if ( ! in_array( $privacy, array( $front, $posts ), true ) && null !== self::listable_page( $privacy, $skip ) ) {
			$last[] = self::page_link( $privacy, (string) get_permalink( $privacy ) );
		}
		$first = array_values( array_filter( $first ) );
		$last  = array_values( array_filter( $last ) );

		$pages = self::collect(
			array(
				'post_type'              => 'page',
				'post_status'            => 'publish',
				'has_password'           => false,
				'post_parent'            => 0,
				'post__not_in'           => array_values( array_unique( array_filter( array_merge( array( $front, $posts, $privacy ), $skip ) ) ) ), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- At most 6 IDs.
				'orderby'                => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				),
				'update_post_term_cache' => false,
			),
			self::MAX_KEY_PAGES - count( $first ) - count( $last ),
			static fn( \WP_Post $page ): string => self::page_link( (int) $page->ID, (string) get_permalink( $page ) )
		);
		return array_values( array_merge( $first, array_column( $pages, 1 ), $last ) );
	}

	/**
	 * `- [title](url)` for a page ('' when it has no usable title or URL).
	 *
	 * @param int    $id  Page ID.
	 * @param string $url Link target.
	 */
	private static function page_link( int $id, string $url ): string {
		return self::link( self::text( (string) get_post_field( 'post_title', $id ), self::NAME_CHARS ), $url );
	}

	/**
	 * The MAX_POSTS newest public posts, each once, grouped under `Posts: <category>` (its first
	 * non-default category by name; at most MAX_POST_SECTIONS sections, ordered by their newest
	 * post); the rest under `Recent posts`. The `Posts:` prefix keeps a category named
	 * "Optional" from becoming the llms.txt `## Optional` section.
	 *
	 * @return array<string, string[]> Heading => items.
	 */
	private static function post_sections(): array {
		$posts = self::collect(
			array(
				'post_type'              => 'post',
				'post_status'            => 'publish',
				'has_password'           => false,
				'orderby'                => array(
					'date' => 'DESC',
					'ID'   => 'DESC',
				),
				'update_post_term_cache' => true,
			),
			self::MAX_POSTS,
			array( self::class, 'post_line' )
		);

		$default  = (int) get_option( 'default_category', 0 );
		$groups   = array();
		$headings = array();
		$recent   = array();
		foreach ( $posts as list( $post, $line ) ) {
			$term = self::primary_category( $post, $default );
			if ( null === $term ) {
				$recent[] = $line;
				continue;
			}
			$key = (int) $term->term_id;
			if ( ! isset( $groups[ $key ] ) ) {
				$name = self::text( $term->name, self::NAME_CHARS );
				if ( '' === $name || count( $groups ) >= self::MAX_POST_SECTIONS ) {
					$recent[] = $line;
					continue;
				}
				$groups[ $key ]   = array();
				$headings[ $key ] = $name;
			}
			$groups[ $key ][] = $line;
		}

		$sections = array();
		foreach ( $groups as $key => $items ) {
			/* translators: %s: category name. */
			$sections[ sprintf( __( 'Posts: %s', 'x7velo' ), $headings[ $key ] ) ] = $items;
		}
		$recent_heading = __( 'Recent posts', 'x7velo' );
		if ( isset( $sections[ $recent_heading ] ) ) {
			$recent = array_merge( $sections[ $recent_heading ], $recent );
		}
		$sections[ $recent_heading ] = $recent;
		return $sections;
	}

	/**
	 * First non-default category of a post by name (term ID breaks ties), or null.
	 *
	 * @param \WP_Post $post        Post.
	 * @param int      $default_cat Default category ID.
	 */
	private static function primary_category( \WP_Post $post, int $default_cat ): ?WP_Term {
		$terms = get_the_terms( $post, 'category' );
		if ( ! is_array( $terms ) ) {
			return null;
		}
		$terms = array_values(
			array_filter(
				$terms,
				static fn( $t ): bool => $t instanceof WP_Term && (int) $t->term_id !== $default_cat
			)
		);
		if ( array() === $terms ) {
			return null;
		}
		usort(
			$terms,
			static function ( WP_Term $a, WP_Term $b ): int {
				$d = strcasecmp( $a->name, $b->name );
				return 0 !== $d ? $d : $a->term_id <=> $b->term_id;
			}
		);
		return $terms[0];
	}

	/**
	 * `- [title](url): <start of the excerpt>` (title and link only without an excerpt; '' without
	 * a title or usable URL). Used for posts and products.
	 *
	 * @param \WP_Post $post Post or product.
	 */
	private static function post_line( \WP_Post $post ): string {
		$name = self::text( $post->post_title, self::NAME_CHARS );
		if ( '' === $name ) {
			return '';
		}
		$link = self::link( $name, (string) get_permalink( $post ) );
		if ( '' === $link ) {
			return '';
		}
		$desc = self::description( $post );
		return '' === $desc ? $link : $link . ': ' . $desc;
	}

	/**
	 * Description: the hand-written excerpt (a product's short description) only, as a logged-out
	 * visitor's theme would print it: with the item set up as the global post, through
	 * `get_the_excerpt` and then `the_excerpt` (membership plugins gate on either and may read the
	 * global $post), then reduced by text(). Never the post content: with an empty excerpt no
	 * filter runs at all (core's wp_trim_excerpt() would build one from the content). '' when
	 * nothing is left. The previous global post is restored afterwards.
	 *
	 * @param \WP_Post $post Post or product.
	 */
	private static function description( \WP_Post $post ): string {
		$excerpt = (string) $post->post_excerpt;
		if ( '' === trim( $excerpt ) ) {
			return '';
		}
		$previous = $GLOBALS['post'] ?? null;
		try {
			$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Set up like the loop does; restored in finally.
			setup_postdata( $post );
			$filtered = apply_filters( 'get_the_excerpt', $excerpt, $post ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter, applied as the theme would.
			$filtered = is_string( $filtered ) ? apply_filters( 'the_excerpt', $filtered ) : ''; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter, applied as the theme would.
		} finally {
			wp_reset_postdata();
			$GLOBALS['post'] = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores the caller's global post.
		}
		return is_string( $filtered ) ? self::text( wp_strip_all_tags( $filtered ), self::DESCRIPTION_CHARS ) : '';
	}

	/**
	 * The active sitemap (posts/pages mode `## Optional`).
	 *
	 * @return string[]
	 */
	private static function sitemap_links(): array {
		$sitemap = self::detector()->sitemap();
		if ( null === $sitemap ) {
			return array();
		}
		return array_values( array_filter( array( self::link( __( 'Sitemap', 'x7velo' ), home_url( $sitemap['path'] ) ) ) ) );
	}

	/**
	 * "Online store based in <country>. Prices in <currency>." ('' without WooCommerce).
	 */
	private static function store_line(): string {
		if ( ! function_exists( 'WC' ) || ! function_exists( 'get_woocommerce_currency' ) ) {
			return '';
		}
		$currency  = self::text( (string) get_woocommerce_currency(), 10 );
		$countries = WC()->countries;
		$country   = '';
		if ( $countries ) {
			$code    = (string) $countries->get_base_country();
			$all     = (array) $countries->get_countries();
			$country = isset( $all[ $code ] ) ? self::text( (string) $all[ $code ], 100 ) : '';
		}
		if ( '' === $country ) {
			/* translators: %s: currency code, e.g. "EUR". */
			return sprintf( __( 'Online store. Prices in %s.', 'x7velo' ), $currency );
		}
		/* translators: 1: country name, 2: currency code, e.g. "EUR". */
		return sprintf( __( 'Online store based in %1$s. Prices in %2$s.', 'x7velo' ), $country, $currency );
	}

	/**
	 * Shop page, returns page (if set), terms page.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @return string[]
	 */
	private static function shop_links( array $settings ): array {
		$pages = array();
		if ( function_exists( 'wc_get_page_id' ) ) {
			$pages[] = array( (int) wc_get_page_id( 'shop' ), __( 'Shop', 'x7velo' ) );
		}
		$pages[] = array( absint( $settings['returns_page_id'] ?? 0 ), __( 'Returns policy', 'x7velo' ) );
		if ( function_exists( 'wc_terms_and_conditions_page_id' ) ) {
			$pages[] = array( (int) wc_terms_and_conditions_page_id(), __( 'Terms and conditions', 'x7velo' ) );
		}

		self::detector()->prime_noindex( array_values( array_filter( array_column( $pages, 0 ) ) ) );
		$links = array();
		$seen  = array();
		foreach ( $pages as list( $id, $fallback ) ) {
			if ( $id <= 0 || isset( $seen[ $id ] ) || null === self::listable_page( $id ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$name        = self::text( (string) get_post_field( 'post_title', $id ), self::NAME_CHARS );
			$links[]     = self::link( '' === $name ? $fallback : $name, (string) get_permalink( $id ) );
		}
		return array_values( array_filter( $links ) );
	}

	/**
	 * Non-empty product categories (name + link), by name. The default category is skipped.
	 *
	 * @return string[]
	 */
	private static function category_links(): array {
		if ( ! taxonomy_exists( 'product_cat' ) ) {
			return array();
		}
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
				'number'     => self::MAX_CATEGORIES,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);
		if ( ! is_array( $terms ) ) {
			return array();
		}
		$default = (int) get_option( 'default_product_cat', 0 );
		$links   = array();
		foreach ( $terms as $term ) {
			if ( ! $term instanceof WP_Term || $term->term_id === $default ) {
				continue;
			}
			$url = get_term_link( $term );
			if ( is_string( $url ) ) {
				$links[] = self::link( self::text( $term->name, self::NAME_CHARS ), $url );
			}
		}
		return array_values( array_filter( $links ) );
	}

	/**
	 * Up to MAX_PRODUCTS public products, newest first: name, link, start of the short
	 * description.
	 *
	 * @return string[]
	 */
	private static function product_links(): array {
		if ( ! post_type_exists( 'product' ) ) {
			return array();
		}
		$args   = array(
			'post_type'    => 'product',
			'post_status'  => 'publish',
			'has_password' => false,
			'orderby'      => array(
				'date' => 'DESC',
				'ID'   => 'DESC',
			),
		);
		$hidden = self::hidden_tax_query();
		if ( array() !== $hidden ) {
			$args['tax_query'] = $hidden; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Bounded (≤ 3 × 200 rows), same filter Woo's catalog uses.
		}
		return array_column( self::collect( $args, self::MAX_PRODUCTS, array( self::class, 'post_line' ) ), 1 );
	}

	/**
	 * Feed URL (when the feed fixer is on) and the active sitemap.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @return string[]
	 */
	private static function optional_links( array $settings ): array {
		$links = array();
		if ( ! empty( $settings['fix_feed'] ) ) {
			$links[] = self::link( __( 'Product feed', 'x7velo' ), add_query_arg( 'x7velo_feed', '1', home_url( '/' ) ) );
		}
		$sitemap = self::detector()->sitemap();
		if ( null !== $sitemap ) {
			$links[] = self::link( __( 'Sitemap', 'x7velo' ), home_url( $sitemap['path'] ) );
		}
		return array_values( array_filter( $links ) );
	}

	/**
	 * Tax query excluding catalog-hidden products (both visibility terms set), or [] without
	 * WooCommerce's visibility taxonomy.
	 *
	 * @return array<int|string, mixed>
	 */
	private static function hidden_tax_query(): array {
		if ( ! taxonomy_exists( 'product_visibility' ) ) {
			return array();
		}
		// "Hidden" = in both terms, so keep products missing either one. Slugs, not cached IDs.
		return array(
			'relation' => 'OR',
			array(
				'taxonomy' => 'product_visibility',
				'field'    => 'slug',
				'terms'    => array( 'exclude-from-catalog' ),
				'operator' => 'NOT IN',
			),
			array(
				'taxonomy' => 'product_visibility',
				'field'    => 'slug',
				'terms'    => array( 'exclude-from-search' ),
				'operator' => 'NOT IN',
			),
		);
	}

	/**
	 * Whether a page is published and not password-protected.
	 *
	 * @param int $id Post ID.
	 */
	private static function is_public_page( int $id ): bool {
		return 'publish' === get_post_status( $id ) && '' === (string) get_post_field( 'post_password', $id );
	}

	/**
	 * Markdown list item `- [name](url)`, '' when the URL isn't http(s).
	 *
	 * @param string $name Escaped name.
	 * @param string $url  URL.
	 */
	public static function link( string $name, string $url ): string {
		$url = self::url( $url );
		if ( '' === $url || '' === $name ) {
			return '';
		}
		return '- [' . $name . '](' . $url . ')';
	}

	/**
	 * An http(s) URL safe inside a Markdown link destination ('' otherwise).
	 *
	 * @param string $url URL.
	 */
	public static function url( string $url ): string {
		$url = esc_url_raw( $url, array( 'http', 'https' ) );
		if ( '' === $url ) {
			return '';
		}
		return str_replace( array( '(', ')', '<', '>', ' ' ), array( '%28', '%29', '%3C', '%3E', '%20' ), $url );
	}

	/**
	 * One line of plain, Markdown-escaped text, at most $max characters ("…" when cut).
	 *
	 * Strips shortcodes, tags (incl. script/style content) and entities; removes control
	 * characters; collapses all whitespace (so no new lines or headings can be injected);
	 * escapes `\ [ ] < > \``.
	 *
	 * @param string $value Raw value.
	 * @param int    $max   Maximum characters (before escaping).
	 */
	public static function text( string $value, int $max ): string {
		$value = strip_shortcodes( $value );
		$value = wp_strip_all_tags( $value );
		$value = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$value = wp_check_invalid_utf8( $value, true );
		$value = (string) preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $value );
		$value = trim( (string) preg_replace( '/\s+/u', ' ', $value ) );
		if ( mb_strlen( $value, 'UTF-8' ) > $max ) {
			$value = rtrim( mb_substr( $value, 0, max( 1, $max - 1 ), 'UTF-8' ) ) . '…';
		}
		return (string) preg_replace( '/([\\\\\[\]<>`])/u', '\\\\$1', $value );
	}
}
