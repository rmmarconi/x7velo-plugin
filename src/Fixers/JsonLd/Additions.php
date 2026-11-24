<?php
/**
 * The JSON-LD additions: hasMerchantReturnPolicy and shippingDetails (spec §8.1).
 *
 * @package X7velo
 */

namespace X7velo\Fixers\JsonLd;

use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the two offer properties x7velo adds to Product markup, from the owner's answers in
 * x7velo settings and WooCommerce's own settings. Each part is all-or-nothing: when any required
 * input is missing the part is omitted, never emitted partially.
 *
 * Only adds keys an offer does not already have, so it is idempotent and never overwrites what
 * WooCommerce or another plugin put there. Never adds Organization or WebSite nodes.
 */
final class Additions {

	public const SCHEMA = 'https://schema.org/';

	/**
	 * Return-policy categories by `return_policy` setting.
	 */
	private const RETURN_CATEGORIES = array(
		'finite'    => 'MerchantReturnFiniteReturnWindow',
		'unlimited' => 'MerchantReturnUnlimitedWindow',
		'none'      => 'MerchantReturnNotPermitted',
	);

	/**
	 * Sanitised x7velo settings.
	 *
	 * @var array<string, mixed>
	 */
	private array $settings;

	/**
	 * Memoised return policy node (false = not computed yet).
	 *
	 * @var array<string, mixed>|null|false
	 */
	private $return_policy = false;

	/**
	 * Memoised base-country plain rate (false = not computed yet).
	 *
	 * @var float|null|false
	 */
	private $rate = false;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $settings Sanitised settings (Settings::all()).
	 */
	public function __construct( array $settings ) {
		$this->settings = $settings;
	}

	/**
	 * `woocommerce_structured_data_product` callback: adds the parts to every offer.
	 *
	 * @param mixed $markup  Product markup.
	 * @param mixed $product Product.
	 * @return mixed
	 */
	public function augment( $markup, $product = null ) {
		if ( ! is_array( $markup ) || empty( $markup['offers'] ) || ! is_array( $markup['offers'] ) ) {
			return $markup;
		}
		$add     = array();
		$returns = $this->return_policy();
		if ( null !== $returns ) {
			$add['hasMerchantReturnPolicy'] = $returns;
		}
		$shipping = $product instanceof WC_Product ? $this->shipping_details( $product ) : null;
		if ( null !== $shipping ) {
			$add['shippingDetails'] = $shipping;
		}
		if ( array() === $add ) {
			return $markup;
		}

		if ( isset( $markup['offers']['@type'] ) ) {
			// A single offer object rather than a list.
			$markup['offers'] += $add;
			return $markup;
		}
		foreach ( $markup['offers'] as $i => $offer ) {
			if ( is_array( $offer ) ) {
				$markup['offers'][ $i ] = $offer + $add;
			}
		}
		return $markup;
	}

	/**
	 * Which parts would be output (for the Fixes card): not product-specific.
	 *
	 * @return array{returns: bool, shipping: bool}
	 */
	public function parts(): array {
		return array(
			'returns'  => null !== $this->return_policy(),
			'shipping' => $this->delivery_days_set() && null !== $this->base_rate(),
		);
	}

	/**
	 * MerchantReturnPolicy node, or null unless the returns page is published and the return
	 * policy answered (Google: applicableCountry + returnPolicyCategory required;
	 * merchantReturnDays required for a finite window).
	 *
	 * @return array<string, mixed>|null
	 */
	public function return_policy(): ?array {
		if ( false !== $this->return_policy ) {
			return $this->return_policy;
		}
		$this->return_policy = null;

		$page    = absint( $this->settings['returns_page_id'] ?? 0 );
		$policy  = (string) ( $this->settings['return_policy'] ?? '' );
		$days    = $this->settings['return_days'] ?? null;
		$country = self::base_country();
		if ( $page <= 0 || 'page' !== get_post_type( $page ) || 'publish' !== get_post_status( $page ) || ! isset( self::RETURN_CATEGORIES[ $policy ] ) || '' === $country ) {
			return null;
		}
		if ( 'finite' === $policy && ( ! is_int( $days ) || $days < 1 ) ) {
			return null;
		}
		$link = get_permalink( $page );
		if ( ! is_string( $link ) || '' === $link ) {
			return null;
		}

		$node = array(
			'@type'                => 'MerchantReturnPolicy',
			'applicableCountry'    => $country,
			'returnPolicyCategory' => self::SCHEMA . self::RETURN_CATEGORIES[ $policy ],
		);
		if ( 'finite' === $policy ) {
			$node['merchantReturnDays'] = $days;
		}
		$node['merchantReturnLink'] = $link;

		$this->return_policy = $node;
		return $node;
	}

	/**
	 * OfferShippingDetails node for a product, or null unless the product needs shipping, both
	 * delivery days are set and the base country has a plain-number rate.
	 *
	 * @param WC_Product $product Product.
	 * @return array<string, mixed>|null
	 */
	public function shipping_details( WC_Product $product ): ?array {
		if ( ! $this->delivery_days_set() || ! $product->needs_shipping() ) {
			return null;
		}
		$rate = $this->base_rate();
		if ( null === $rate ) {
			return null;
		}
		return array(
			'@type'               => 'OfferShippingDetails',
			'shippingRate'        => array(
				'@type'    => 'MonetaryAmount',
				'value'    => wc_format_decimal( $rate, wc_get_price_decimals() ),
				'currency' => get_woocommerce_currency(),
			),
			'shippingDestination' => array(
				'@type'          => 'DefinedRegion',
				'addressCountry' => self::base_country(),
			),
			'deliveryTime'        => array(
				'@type'       => 'ShippingDeliveryTime',
				'transitTime' => array(
					'@type'    => 'QuantitativeValue',
					'minValue' => (int) $this->settings['delivery_days_min'],
					'maxValue' => (int) $this->settings['delivery_days_max'],
					'unitCode' => 'DAY',
				),
			),
		);
	}

	/**
	 * Whether both delivery-day answers are set (and consistent).
	 */
	private function delivery_days_set(): bool {
		$min = $this->settings['delivery_days_min'] ?? null;
		$max = $this->settings['delivery_days_max'] ?? null;
		return is_int( $min ) && is_int( $max ) && $min >= 0 && $min <= $max;
	}

	/**
	 * Memoised plain-number base-country rate.
	 */
	private function base_rate(): ?float {
		if ( false === $this->rate ) {
			$this->rate = ShippingRate::for_country( self::base_country() );
		}
		return $this->rate;
	}

	/**
	 * Store base country (ISO code, no state).
	 */
	public static function base_country(): string {
		if ( function_exists( 'WC' ) && is_object( WC()->countries ) ) {
			return (string) WC()->countries->get_base_country();
		}
		return (string) strtok( (string) get_option( 'woocommerce_default_country', '' ), ':' );
	}
}
