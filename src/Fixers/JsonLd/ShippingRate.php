<?php
/**
 * Plain-number shipping rate for the store's base country (spec §8.1).
 *
 * @package X7velo
 */

namespace X7velo\Fixers\JsonLd;

use WC_Shipping_Method;
use WC_Shipping_Zones;

defined( 'ABSPATH' ) || exit;

/**
 * Finds the cheapest shipping rate that is a plain number for a package sent anywhere in the
 * base country, or null when there is none.
 *
 * Plain number means: free shipping that needs no minimum amount or coupon (rate 0), or a flat
 * rate whose cost is a bare number and that has no shipping-class costs. Formulas (`[qty] * 2`),
 * class costs, table rates and every other method are skipped: we never publish a rate the
 * checkout might not charge.
 *
 * The zone is the one WooCommerce would pick for a package addressed to the base country with no
 * state or postcode, so a state- or postcode-only zone never stands in for the whole country.
 */
final class ShippingRate {

	/**
	 * Cheapest plain-number rate (store currency, as configured) or null.
	 *
	 * @param string $country ISO country code (the store base country).
	 */
	public static function for_country( string $country ): ?float {
		if ( '' === $country || ! class_exists( WC_Shipping_Zones::class ) || ! function_exists( 'WC' ) ) {
			return null;
		}
		if ( 'disabled' === get_option( 'woocommerce_ship_to_countries', '' ) ) {
			return null;
		}
		$countries = WC()->countries;
		if ( ! is_object( $countries ) || ! array_key_exists( $country, (array) $countries->get_shipping_countries() ) ) {
			return null;
		}

		$zone = WC_Shipping_Zones::get_zone_matching_package(
			array(
				'destination' => array(
					'country'  => $country,
					'state'    => '',
					'postcode' => '',
				),
			)
		);

		$best = null;
		foreach ( $zone->get_shipping_methods( true ) as $method ) {
			if ( ! $method instanceof WC_Shipping_Method ) {
				continue;
			}
			$rate = self::plain_rate( $method );
			if ( null !== $rate && ( null === $best || $rate < $best ) ) {
				$best = $rate;
			}
		}
		return $best;
	}

	/**
	 * The method's rate when it is a plain number, else null.
	 *
	 * @param WC_Shipping_Method $method Enabled zone method.
	 */
	public static function plain_rate( WC_Shipping_Method $method ): ?float {
		switch ( $method->id ) {
			case 'free_shipping':
				return '' === (string) $method->get_option( 'requires', '' ) ? 0.0 : null;

			case 'flat_rate':
				$cost = trim( (string) $method->get_option( 'cost', '' ) );
				if ( '' === $cost || ! is_numeric( $cost ) || (float) $cost < 0 ) {
					return null;
				}
				foreach ( (array) $method->instance_settings as $key => $value ) {
					$key = (string) $key;
					if ( ( 0 === strpos( $key, 'class_cost_' ) || 'no_class_cost' === $key ) && '' !== trim( (string) $value ) ) {
						return null;
					}
				}
				return (float) $cost;
		}
		return null;
	}
}
