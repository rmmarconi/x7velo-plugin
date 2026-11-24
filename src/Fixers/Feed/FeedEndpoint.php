<?php
/**
 * Public product feed endpoint at /?x7velo_feed=1 (spec §8.3).
 *
 * @package X7velo
 */

namespace X7velo\Fixers\Feed;

use X7velo\Detection\Detector;
use X7velo\Fixers\PublicOutput;
use X7velo\NamedLock;
use X7velo\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Serves the feed when `fix_feed` is on and the store is public, 404 otherwise. Works with any
 * permalink setting (query var on the home URL; no rewrite rules).
 *
 * Request handling (spec §8.3, v2.2), in order:
 * 1. Context is pinned before anything is computed: logged-out user, and a WooCommerce customer
 *    located at the store base (pin_context()). Prices never depend on who asks.
 * 2. 404 when the toggle is off, in coming-soon mode or `x7velo_public_output_allowed` is false.
 * 3. 301 to the canonical URL (url()) for any other query argument or path, so cache-busting
 *    variants all collapse onto one cacheable URL.
 * 4. `304 Not Modified` when If-None-Match matches the ETag (newest product/variation
 *    modification, published count, WooCommerce product cache version, tax rates and the
 *    feed-relevant settings; a few cheap queries, no product loaded). If-Modified-Since alone
 *    never produces a 304. Lock-free.
 * 5. Site-wide single-flight for generation: a non-blocking MySQL named lock. While another
 *    request is generating the feed, the answer is `503` + `Retry-After: 120`, no body. If the
 *    database has no named locks, the feed is generated unlocked and
 *    `x7velo_feed_lock_unavailable` fires.
 * 6. Streamed batch by batch; generation stops as soon as the client has gone away.
 *
 * The body is never stored server-side.
 */
final class FeedEndpoint {

	public const QUERY_VAR = 'x7velo_feed';

	/**
	 * Bump when the feed format changes so cached copies are revalidated.
	 */
	public const FORMAT_VERSION = '2';

	public const MAX_AGE = 3600;

	public const CACHE_PUBLIC  = 'public, max-age=3600, s-maxage=3600, stale-while-revalidate=86400, stale-if-error=86400';
	public const CACHE_PRIVATE = 'private, max-age=0';

	public const RETRY_AFTER = 120;

	/**
	 * Default wall-clock budget of one feed response (filter `x7velo_feed_deadline`).
	 */
	public const DEADLINE_SECONDS = 120;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Builds a Detector (tests inject a fake environment).
	 *
	 * @var callable(): Detector
	 */
	private $detector_factory;

	/**
	 * Store base address [country, state, postcode, city] pinned for this request.
	 *
	 * @var string[]|null
	 */
	private ?array $base_address = null;

	/**
	 * Constructor.
	 *
	 * @param Settings      $settings         Settings.
	 * @param callable|null $detector_factory Returns a Detector; null = default Detector.
	 */
	public function __construct( Settings $settings, ?callable $detector_factory = null ) {
		$this->settings         = $settings;
		$this->detector_factory = $detector_factory ?? static fn(): Detector => new Detector();
	}

	/**
	 * Hooks the query var and the request handler.
	 */
	public function register(): void {
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		// Priority 0: before redirect_canonical (10) and themes' template_redirect callbacks.
		add_action( 'template_redirect', array( $this, 'template_redirect' ), 0 );
	}

	/**
	 * `query_vars` callback.
	 *
	 * @param mixed $vars Public query vars.
	 * @return mixed
	 */
	public function query_vars( $vars ) {
		if ( is_array( $vars ) ) {
			$vars[] = self::QUERY_VAR;
		}
		return $vars;
	}

	/**
	 * The feed query var value ('' when absent or not a scalar).
	 */
	private static function query_value(): string {
		$value = get_query_var( self::QUERY_VAR, '' );
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Whether the current request asks for the feed.
	 */
	public static function requested(): bool {
		return '' !== self::query_value();
	}

	/**
	 * Public (canonical) feed URL.
	 */
	public static function url(): string {
		return add_query_arg( self::QUERY_VAR, '1', home_url( '/' ) );
	}

	/**
	 * Named-lock name for feed generation on this site.
	 */
	public static function lock_name(): string {
		return NamedLock::site_name( 'x7velo_feed_' );
	}

	/**
	 * `template_redirect` callback. Thin: decide (dispatch() pins the context only on the
	 * 200/304/HEAD path), send headers, stream, exit.
	 */
	public function template_redirect(): void {
		// Without WooCommerce the request is left to WordPress untouched (spec §15.3, AC 118).
		if ( ! \X7velo\Plugin::is_woocommerce_active() || ! self::requested() ) {
			return;
		}

		$response = $this->dispatch(
			array(
				'method'        => strtoupper( self::server_header( 'REQUEST_METHOD' ) ),
				'if_none_match' => self::server_header( 'HTTP_IF_NONE_MATCH' ),
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public read-only endpoint; only the parameter names are read, to redirect to the canonical URL.
				'query_keys'    => array_map( 'strval', array_keys( $_GET ) ),
				'path'          => self::request_path(),
			)
		);

		if ( 404 === $response['status'] ) {
			global $wp_query;
			if ( $wp_query instanceof \WP_Query ) {
				$wp_query->set_404();
			}
			status_header( 404 );
			nocache_headers();
			return;
		}

		if ( 301 === $response['status'] ) {
			wp_safe_redirect( $response['location'], 301, 'x7velo' );
			exit;
		}

		// Public, cacheable response: never carry a cookie set earlier in the request (v2.3 N8).
		if ( function_exists( 'header_remove' ) && in_array( $response['status'], array( 200, 304 ), true ) ) {
			header_remove( 'Set-Cookie' );
		}
		status_header( $response['status'] );
		foreach ( $response['headers'] as $name => $value ) {
			header( $name . ': ' . $value );
		}
		if ( $response['stream'] ) {
			// Output buffers (themes, page caches) would hold the whole feed in memory (v2.3 N5).
			while ( ob_get_level() > 0 ) {
				ob_end_flush();
			}
			$this->stream( $response['lock'] );
		}
		if ( $response['lock'] instanceof NamedLock ) {
			$response['lock']->release();
		}
		exit;
	}

	/**
	 * Decides the response for a feed request (no output, no exit; testable). On a 200 that streams,
	 * the caller owns `lock` and must release it.
	 *
	 * @param array{method?: string, if_none_match?: string, query_keys?: string[], path?: string} $request Request facts.
	 * @return array{status: int, headers: array<string, string>, stream: bool, lock: NamedLock|null, location?: string}
	 */
	public function dispatch( array $request ): array {
		$method = strtoupper( (string) ( $request['method'] ?? 'GET' ) );
		$none   = array(
			'status'  => 404,
			'headers' => array(),
			'stream'  => false,
			'lock'    => null,
		);

		if ( ! $this->available() || '1' !== self::query_value() ) {
			return $none;
		}
		if ( self::needs_redirect( (array) ( $request['query_keys'] ?? array() ), (string) ( $request['path'] ?? '' ) ) ) {
			return array(
				'status'   => 301,
				'headers'  => array(),
				'stream'   => false,
				'lock'     => null,
				'location' => self::url(),
			);
		}

		// Only now (after the 404/301 decisions, v2.3 N1), before any validator or price is
		// computed: the feed is the same for everyone.
		$this->pin_context();

		$response = $this->respond( (string) ( $request['if_none_match'] ?? '' ) );
		if ( 200 !== $response['status'] || 'HEAD' === $method ) {
			return $response + array(
				'stream' => false,
				'lock'   => null,
			);
		}

		$lock = new NamedLock( self::lock_name() );
		$got  = $lock->acquire();
		if ( NamedLock::BUSY === $got ) {
			return array(
				'status'  => 503,
				'headers' => array(
					'Retry-After'            => (string) self::RETRY_AFTER,
					'Cache-Control'          => 'no-store',
					'X-Content-Type-Options' => 'nosniff',
				),
				'stream'  => false,
				'lock'    => null,
			);
		}
		if ( NamedLock::UNSUPPORTED === $got ) {
			/**
			 * Fires when the database can't provide a named lock (GET_LOCK returned NULL), so the
			 * feed is generated without the single-flight guard.
			 */
			do_action( 'x7velo_feed_lock_unavailable' );
		}
		return $response + array(
			'stream' => true,
			'lock'   => NamedLock::ACQUIRED === $got ? $lock : null,
		);
	}

	/**
	 * Status and headers from the validators: 304 when If-None-Match matches, else 200.
	 *
	 * @param string $if_none_match If-None-Match request header ('' if absent).
	 * @return array{status: int, headers: array<string, string>}
	 */
	public function respond( string $if_none_match ): array {
		if ( ! $this->available() ) {
			return array(
				'status'  => 404,
				'headers' => array(),
			);
		}

		$validators = $this->validators();
		$headers    = array(
			'Content-Type'           => 'application/xml; charset=utf-8',
			'X-Robots-Tag'           => 'noindex',
			'X-Content-Type-Options' => 'nosniff',
			'Cache-Control'          => $this->cache_control(),
			'ETag'                   => $validators['etag'],
		);
		if ( $validators['last_modified'] > 0 ) {
			$headers['Last-Modified'] = gmdate( 'D, d M Y H:i:s', $validators['last_modified'] ) . ' GMT';
		}

		// L1: only the ETag decides a 304 (it covers settings and tax changes; a date can't).
		if ( '' !== trim( $if_none_match ) && self::etag_matches( $if_none_match, $validators['etag'] ) ) {
			unset( $headers['Content-Type'] );
			return array(
				'status'  => 304,
				'headers' => $headers,
			);
		}
		return array(
			'status'  => 200,
			'headers' => $headers,
		);
	}

	/**
	 * Whether a request must be redirected to the canonical URL: any query argument other than
	 * the feed's, or a path other than the home path.
	 *
	 * @param string[] $query_keys Query argument names.
	 * @param string   $path       Request path ('' = unknown, not checked).
	 */
	public static function needs_redirect( array $query_keys, string $path ): bool {
		// Every argument the canonical URL itself carries is allowed (WPML/Polylang put the
		// language in home_url(), e.g. `/?lang=fr`), so the redirect target never redirects
		// again (v2.3 N2).
		$canonical = self::url();
		$args      = array();
		wp_parse_str( (string) wp_parse_url( $canonical, PHP_URL_QUERY ), $args );
		$allowed   = array_map( 'strval', array_keys( $args ) );
		$allowed[] = self::QUERY_VAR;
		foreach ( $query_keys as $key ) {
			if ( ! in_array( (string) $key, $allowed, true ) ) {
				return true;
			}
		}
		if ( '' === $path ) {
			return false;
		}
		$home = (string) wp_parse_url( $canonical, PHP_URL_PATH );
		// Compare decoded paths: one side may be percent-encoded (non-ASCII home paths).
		return rawurldecode( '' === $home ? '/' : $home ) !== rawurldecode( $path );
	}

	/**
	 * Cache-Control for 200/304: public, unless a multi-currency or multilingual plugin can make
	 * the output differ per visitor (then private, revalidated every time).
	 */
	public function cache_control(): string {
		$detector = ( $this->detector_factory )();
		return array() === $detector->context_dependent_plugins() ? self::CACHE_PUBLIC : self::CACHE_PRIVATE;
	}

	/**
	 * Whether the feed may be served: toggle on and public output allowed (not in coming-soon
	 * mode, `x7velo_public_output_allowed` not false).
	 */
	public function available(): bool {
		if ( true !== $this->settings->get( 'fix_feed' ) ) {
			return false;
		}
		return PublicOutput::allowed( 'feed' );
	}

	/**
	 * Makes the request context deterministic (spec §8.3 v2.2, M1): logged-out user and a
	 * WooCommerce customer at the store base address, not VAT-exempt.
	 *
	 * A fresh non-session `WC_Customer` replaces the visitor's for this request, so every code
	 * path that reads the customer (taxable address, VAT exemption, billing/shipping country)
	 * sees the base. The `woocommerce_customer_taxable_address` filter at PHP_INT_MAX additionally
	 * pins the taxable address against plugins that filter it per visitor. WooCommerce's shutdown
	 * save stays bound to the visitor's original customer object, so nothing about the visitor's
	 * session changes; the replacement is never saved.
	 */
	public function pin_context(): void {
		wp_set_current_user( 0 );
		if ( ! function_exists( 'WC' ) || ! class_exists( 'WC_Customer' ) ) {
			return;
		}
		$wc = WC();
		if ( ! isset( $wc->countries ) || ! is_object( $wc->countries ) ) {
			return;
		}
		$countries                                 = $wc->countries;
		$this->base_address                        = array(
			(string) $countries->get_base_country(),
			(string) $countries->get_base_state(),
			(string) $countries->get_base_postcode(),
			(string) $countries->get_base_city(),
		);
		list( $country, $state, $postcode, $city ) = $this->base_address;

		$customer = new \WC_Customer( 0, false );
		$customer->set_billing_location( $country, $state, $postcode, $city );
		$customer->set_shipping_location( $country, $state, $postcode, $city );
		$customer->set_is_vat_exempt( false );
		$wc->customer = $customer;

		if ( false === has_filter( 'woocommerce_customer_taxable_address', array( $this, 'base_taxable_address' ) ) ) {
			add_filter( 'woocommerce_customer_taxable_address', array( $this, 'base_taxable_address' ), PHP_INT_MAX );
		}
	}

	/**
	 * `woocommerce_customer_taxable_address` (feed requests only): the store base.
	 *
	 * @param mixed $address Address.
	 * @return mixed
	 */
	public function base_taxable_address( $address ) {
		return null === $this->base_address ? $address : $this->base_address;
	}

	/**
	 * Streams the body to the client, batch by batch. Stops (and releases the lock) as soon as
	 * the client disconnects; never ignores user aborts.
	 *
	 * @param NamedLock|null $lock Generation lock held by this request.
	 */
	public function stream( ?NamedLock $lock = null ): void {
		if ( function_exists( 'wc_set_time_limit' ) ) {
			wc_set_time_limit( 300 );
		}
		$deadline = microtime( true ) + self::deadline_seconds();
		( new FeedWriter() )->write(
			static function ( string $chunk ): void {
				echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML built by FeedWriter; every text value goes through FeedWriter::escape().
			},
			static function () use ( $lock, $deadline ): bool {
				flush();
				if ( connection_aborted() ) {
					if ( null !== $lock ) {
						$lock->release();
					}
					exit;
				}
				if ( microtime( true ) >= $deadline ) {
					/**
					 * Fires when the feed stops at its wall-clock deadline (the XML is closed
					 * cleanly, with the items written so far).
					 */
					do_action( 'x7velo_feed_deadline_reached' );
					return false;
				}
				return true;
			}
		);
	}

	/**
	 * Wall-clock budget for one feed response, in seconds (default DEADLINE_SECONDS).
	 */
	public static function deadline_seconds(): int {
		/**
		 * Filters the feed's wall-clock budget in seconds. After it, the feed ends cleanly and the
		 * generation lock is released.
		 *
		 * @param int $seconds Default 120.
		 */
		$seconds = (int) apply_filters( 'x7velo_feed_deadline', self::DEADLINE_SECONDS );
		return max( 1, $seconds );
	}

	/**
	 * ETag and Last-Modified (Unix time) for the current catalogue, taxes and settings.
	 *
	 * Queries: newest product/variation modification (1), product counts (1, cached),
	 * WooCommerce product cache version (0–1), tax rate checksum (0–1, only with taxes on).
	 * No product is loaded.
	 *
	 * @return array{etag: string, last_modified: int}
	 */
	public function validators(): array {
		$newest    = get_posts(
			array(
				'post_type'              => array( 'product', 'product_variation' ),
				'post_status'            => array( 'publish', 'private', 'draft', 'pending', 'future', 'trash' ),
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'posts_per_page'         => 1,
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		$modified  = 0;
		$newest_id = 0;
		if ( isset( $newest[0] ) ) {
			$modified  = (int) strtotime( $newest[0]->post_modified_gmt . ' UTC' );
			$newest_id = (int) $newest[0]->ID;
		}
		$counts = wp_count_posts( 'product' );

		$parts = array(
			self::FORMAT_VERSION,
			defined( 'X7VELO_VERSION' ) ? X7VELO_VERSION : '',
			$modified,
			$newest_id,
			isset( $counts->publish ) ? (int) $counts->publish : 0,
			class_exists( 'WC_Cache_Helper' ) ? \WC_Cache_Helper::get_transient_version( 'product' ) : '',
			self::tax_fingerprint(),
			home_url( '/' ),
			get_bloginfo( 'name' ),
			get_bloginfo( 'description' ),
			get_woocommerce_currency(),
			wc_get_price_decimals(),
			get_option( 'woocommerce_tax_display_shop', '' ),
			get_option( 'woocommerce_prices_include_tax', '' ),
			get_option( 'woocommerce_calc_taxes', '' ),
			get_option( 'woocommerce_tax_based_on', '' ),
			get_option( 'woocommerce_default_country', '' ),
			get_option( 'woocommerce_store_postcode', '' ),
			get_option( 'woocommerce_store_city', '' ),
			get_option( 'woocommerce_hide_out_of_stock_items', '' ),
			get_option( 'default_product_cat', '' ),
		);

		return array(
			'etag'          => '"x7v-' . md5( implode( '|', array_map( 'strval', $parts ) ) ) . '"',
			'last_modified' => $modified > 0 ? $modified : 0,
		);
	}

	/**
	 * Checksum of WooCommerce's tax rates and their locations ('' when taxes are off).
	 *
	 * WC_Cache_Helper::get_cache_prefix( 'taxes' ) is not used: in WooCommerce 11.3 it lives
	 * only in the object cache and is regenerated (microtime + random bytes) whenever it is
	 * missing, i.e. on every request without a persistent object cache, which would defeat every
	 * 304. The tax tables are small; one aggregate query.
	 */
	public static function tax_fingerprint(): string {
		if ( 'yes' !== get_option( 'woocommerce_calc_taxes', 'no' ) ) {
			return '';
		}
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WooCommerce tax tables (not posts/postmeta); must reflect the current rates.
		$sum = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT CONCAT_WS( ':',
					( SELECT CONCAT_WS( ',', COUNT(*), COALESCE( SUM( CRC32( CONCAT_WS( '|', tax_rate_id, tax_rate_country, tax_rate_state, tax_rate, tax_rate_priority, tax_rate_compound, tax_rate_shipping, tax_rate_order, tax_rate_class ) ) ), 0 ) ) FROM %i ),
					( SELECT CONCAT_WS( ',', COUNT(*), COALESCE( SUM( CRC32( CONCAT_WS( '|', location_id, location_code, tax_rate_id, location_type ) ) ), 0 ) ) FROM %i )
				)",
				$wpdb->prefix . 'woocommerce_tax_rates',
				$wpdb->prefix . 'woocommerce_tax_rate_locations'
			)
		);
		$wpdb->suppress_errors( $suppress );
		return is_scalar( $sum ) ? (string) $sum : '';
	}

	/**
	 * Weak comparison of an If-None-Match header (list, `*`, `W/` prefixes and the `-gzip`/`-br`
	 * suffixes some servers append when compressing) against our ETag.
	 *
	 * @param string $header If-None-Match.
	 * @param string $etag   Current ETag (quoted).
	 */
	public static function etag_matches( string $header, string $etag ): bool {
		$header = trim( $header );
		if ( '*' === $header ) {
			return true;
		}
		$ours = trim( $etag, '"' );
		foreach ( explode( ',', $header ) as $candidate ) {
			$candidate = trim( $candidate );
			if ( 0 === stripos( $candidate, 'W/' ) ) {
				$candidate = substr( $candidate, 2 );
			}
			$candidate = (string) preg_replace( '/-(gzip|br|zstd)\z/', '', trim( $candidate, '"' ) );
			if ( '' !== $candidate && hash_equals( $ours, $candidate ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Path of the current request URI ('' if unknown).
	 */
	private static function request_path(): string {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}
		$uri  = esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) );
		$path = wp_parse_url( $uri, PHP_URL_PATH );
		return is_string( $path ) ? $path : '';
	}

	/**
	 * A request header from $_SERVER, sanitised ('' if absent).
	 *
	 * @param string $key $_SERVER key.
	 */
	private static function server_header( string $key ): string {
		return isset( $_SERVER[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER[ $key ] ) ) : '';
	}
}
