<?php
/**
 * JSON-LD fixer runtime: augment WooCommerce's Product or emit one (spec §7.3, §8.1).
 *
 * @package X7velo
 */

namespace X7velo\Fixers\JsonLd;

use WC_Product;
use X7velo\Audit\Checks\ProductSchemaSource;
use X7velo\Detection\Detector;
use X7velo\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Front-end only. Does nothing unless the owner turned `fix_jsonld` on.
 *
 * Mode comes from ProductSchemaSource::decide() (the single §7.3 decision):
 * - `woocommerce` → augment: our additions go into WooCommerce's own Product through
 *   `woocommerce_structured_data_product` (priority 30, after WC_Brands at 20). No second node.
 * - `x7velo` (nobody emits) → emit: one Product node per product page at `wp_footer` 20, built
 *   with WooCommerce's own generator, printed only if WooCommerce's output is not hooked.
 * - anything else (managed, duplicate, unverified) → nothing is attached or printed. The stored
 *   toggle is never changed; the derived state is `paused` (Detection\FixerStates).
 *
 * Decided at `wp` (filter) and again at `wp_footer` 20, after the runtime sampler (priority 9)
 * has recorded this request's hook state, so emit mode follows the page actually rendered.
 */
final class JsonLdFixer {

	public const PRODUCT_FILTER          = 'woocommerce_structured_data_product';
	public const PRODUCT_FILTER_PRIORITY = 30;
	public const EMIT_PRIORITY           = 20;

	/**
	 * JSON flags for our script tag: `<`, `>`, `&`, `'` and `"` inside strings are \u-escaped,
	 * so no value can close the script element or break out of it.
	 */
	public const JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

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
	 * Additions for this request (lazy).
	 *
	 * @var Additions|null
	 */
	private ?Additions $additions = null;

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
	 * Hooks the front-end callbacks. Nothing runs in wp-admin, REST, cron or CLI.
	 */
	public function register(): void {
		if ( is_admin() ) {
			return;
		}
		add_action( 'wp', array( $this, 'on_wp' ) );
		add_action( 'wp_footer', array( $this, 'on_footer' ), self::EMIT_PRIORITY );
	}

	/**
	 * Whether the owner turned the fixer on.
	 */
	public function enabled(): bool {
		// Store mode only: output stops on the request WooCommerce stops being active (spec §15.4).
		if ( ! \X7velo\Plugin::is_woocommerce_active() ) {
			return false;
		}
		// Hot path on every product page: read the raw (autoloaded) option instead of the fully
		// sanitised settings, which also parse the crawler data file.
		$raw = get_option( Settings::OPTION, array() );
		return is_array( $raw ) && true === rest_sanitize_boolean( $raw['fix_jsonld'] ?? false );
	}

	/**
	 * Additions built from the current settings (one instance per request).
	 */
	public function additions(): Additions {
		if ( null === $this->additions ) {
			$this->additions = new Additions( $this->settings->all() );
		}
		return $this->additions;
	}

	/**
	 * `wp` callback: on a single product page, attach the additions filter if allowed.
	 */
	public function on_wp(): void {
		if ( ! function_exists( 'is_product' ) || ! is_product() || ! $this->enabled() ) {
			return;
		}
		$this->attach();
	}

	/**
	 * The §7.3 outcome for an enabled fixer.
	 *
	 * @return string woocommerce | managed | duplicate | unverified | x7velo.
	 */
	public function outcome(): string {
		$decision = ProductSchemaSource::decide( ( $this->detector_factory )(), true );
		return $decision['outcome'];
	}

	/**
	 * Attaches the additions filter when x7velo may touch Product markup (augment or emit).
	 * Never attached when another plugin owns Product (AC49).
	 *
	 * @return bool Whether the filter is attached.
	 */
	public function attach(): bool {
		if ( ! in_array( $this->outcome(), array( 'woocommerce', 'x7velo' ), true ) ) {
			return false;
		}
		if ( false === has_filter( self::PRODUCT_FILTER, array( $this->additions(), 'augment' ) ) ) {
			add_filter( self::PRODUCT_FILTER, array( $this->additions(), 'augment' ), self::PRODUCT_FILTER_PRIORITY, 2 );
		}
		return true;
	}

	/**
	 * Detaches the additions filter.
	 */
	public function detach(): void {
		if ( null !== $this->additions ) {
			remove_filter( self::PRODUCT_FILTER, array( $this->additions, 'augment' ), self::PRODUCT_FILTER_PRIORITY );
		}
	}

	/**
	 * `wp_footer` callback: emit mode output.
	 */
	public function on_footer(): void {
		if ( ! function_exists( 'is_product' ) || ! is_product() || ! $this->enabled() ) {
			return;
		}
		$product_id = (int) get_queried_object_id();
		if ( ! self::is_public_product( $product_id ) ) {
			return;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		echo $this->emit_markup( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON encoded with JSON_HEX_TAG|AMP|APOS|QUOT inside a fixed script tag (see script_tag()).
	}

	/**
	 * Emit-mode script tag for a product, or '' when x7velo must not print (anyone else emits,
	 * WooCommerce's output is still hooked, or no Product could be built).
	 *
	 * @param WC_Product $product Product of the current page.
	 */
	public function emit_markup( WC_Product $product ): string {
		if ( ! self::is_public_product( $product->get_id() ) || 'publish' !== $product->get_status() ) {
			return '';
		}
		if ( 'x7velo' !== $this->outcome() ) {
			return '';
		}
		$structured_data = function_exists( 'WC' ) ? ( WC()->structured_data ?? null ) : null;
		if ( ! is_object( $structured_data ) || ! method_exists( $structured_data, 'generate_product_data' ) ) {
			return '';
		}
		// WooCommerce prints its own Product at wp_footer 10: never a second node.
		if ( false !== has_action( 'wp_footer', array( $structured_data, 'output_structured_data' ) ) ) {
			return '';
		}

		$node = self::find_product_node( (array) $structured_data->get_data(), $product );
		if ( null === $node ) {
			$structured_data->generate_product_data( $product );
			$node = self::find_product_node( (array) $structured_data->get_data(), $product );
		}
		if ( null === $node ) {
			return '';
		}

		$node = $this->additions()->augment( $node, $product );
		return self::script_tag( array( '@context' => Additions::SCHEMA ) + self::without_organizations( $node ) );
	}

	/**
	 * Whether a product's page may carry x7velo's Product markup: published and not behind a
	 * post password (spec §8.1, v2.2). WooCommerce 11.3.1's generate_product_data() checks
	 * neither; WooCommerce only avoids calling it on protected pages because its single-product
	 * template returns the password form before `woocommerce_single_product_summary`. Emit mode
	 * calls the generator directly, so it must check itself.
	 *
	 * @param int $product_id Product ID.
	 */
	public static function is_public_product( int $product_id ): bool {
		if ( $product_id <= 0 || 'publish' !== get_post_status( $product_id ) ) {
			return false;
		}
		// Any password excludes the product, even for a visitor who entered it (AC 99, v2.3 N6).
		return '' === (string) get_post_field( 'post_password', $product_id );
	}

	/**
	 * The Product node WooCommerce generated for a product, if any.
	 *
	 * @param array<int, mixed> $data    WC_Structured_Data::get_data().
	 * @param WC_Product        $product Product.
	 * @return array<string, mixed>|null
	 */
	public static function find_product_node( array $data, WC_Product $product ): ?array {
		$id = get_permalink( $product->get_id() ) . '#product';
		foreach ( $data as $node ) {
			if ( is_array( $node ) && self::is_type( $node, 'Product' ) && ( $node['@id'] ?? '' ) === $id ) {
				return $node;
			}
		}
		return null;
	}

	/**
	 * Whether a node has a schema type (`@type` string or, since Woo 11.2, list).
	 *
	 * @param array<string, mixed> $node Node.
	 * @param string               $type Type.
	 */
	public static function is_type( array $node, string $type ): bool {
		$types = $node['@type'] ?? '';
		return is_array( $types ) ? in_array( $type, $types, true ) : $type === $types;
	}

	/**
	 * Removes nested Organization/WebSite objects (Woo's offer `seller`): x7velo never outputs
	 * Organization or WebSite (spec §7.3).
	 *
	 * @param array<mixed> $node Node.
	 * @return array<mixed>
	 */
	public static function without_organizations( array $node ): array {
		$list = array_is_list( $node );
		foreach ( $node as $key => $value ) {
			if ( ! is_array( $value ) ) {
				continue;
			}
			if ( self::is_type( $value, 'Organization' ) || self::is_type( $value, 'WebSite' ) ) {
				unset( $node[ $key ] );
				continue;
			}
			$node[ $key ] = self::without_organizations( $value );
		}
		return $list ? array_values( $node ) : $node;
	}

	/**
	 * Encodes a node into a JSON-LD script element.
	 *
	 * @param array<string, mixed> $data Data.
	 */
	public static function script_tag( array $data ): string {
		$json = wp_json_encode( $data, self::JSON_FLAGS );
		if ( ! is_string( $json ) || '' === $json ) {
			return '';
		}
		return '<script type="application/ld+json" class="x7velo-schema">' . $json . '</script>' . "\n";
	}
}
