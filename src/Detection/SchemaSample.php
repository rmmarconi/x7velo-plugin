<?php
/**
 * Front-end runtime sample of Woo's Product JSON-LD hook (spec §7.2).
 *
 * @package X7velo
 */

namespace X7velo\Detection;

defined( 'ABSPATH' ) || exit;

/**
 * The only place x7velo reads hook state: on a front-end single-product request, at `wp_footer`
 * priority 9 (after SEO plugins have unhooked Woo during the render, before Woo prints at 10),
 * records whether WC_Structured_Data::output_structured_data is still hooked at 10.
 *
 * Stored in a non-autoloaded option, written only when the value or the detection fingerprint
 * changes (no write per page view). SCHEMA-01 reads it via read(); a sample taken under a
 * different plugin/option state (fingerprint mismatch) is ignored.
 */
final class SchemaSample {

	public const OPTION = 'x7velo_schema_sample';

	/**
	 * Hooks the sampler on front-end requests only.
	 */
	public static function register(): void {
		if ( is_admin() ) {
			return;
		}
		add_action( 'wp_footer', array( self::class, 'record' ), 9 );
	}

	/**
	 * `wp_footer` callback.
	 */
	public static function record(): void {
		if ( ! \X7velo\Plugin::is_woocommerce_active() || ! function_exists( 'is_product' ) || ! is_product() || ! function_exists( 'WC' ) ) {
			return;
		}
		$structured_data = WC()->structured_data ?? null;
		if ( ! is_object( $structured_data ) ) {
			return;
		}
		$hooked = 10 === has_action( 'wp_footer', array( $structured_data, 'output_structured_data' ) );
		self::store( $hooked, ( new Detector() )->schema_fingerprint() );
	}

	/**
	 * Stores a sample if it differs from the stored one.
	 *
	 * @param bool   $woo_hooked  Whether Woo's output was still hooked.
	 * @param string $fingerprint Detector::schema_fingerprint() at sampling time.
	 * @return bool Whether the option was written.
	 */
	public static function store( bool $woo_hooked, string $fingerprint ): bool {
		$stored = get_option( self::OPTION, null );
		if ( is_array( $stored ) && ( $stored['woo_hooked'] ?? null ) === $woo_hooked && ( $stored['fingerprint'] ?? null ) === $fingerprint ) {
			return false;
		}
		return update_option(
			self::OPTION,
			array(
				'woo_hooked'  => $woo_hooked,
				'fingerprint' => $fingerprint,
				'time'        => time(),
			),
			false
		);
	}

	/**
	 * The current sample, or null if none or taken under another plugin/option state.
	 *
	 * @param string $fingerprint Current Detector::schema_fingerprint().
	 * @return array{woo_hooked: bool, time: int}|null
	 */
	public static function read( string $fingerprint ): ?array {
		$stored = get_option( self::OPTION, null );
		if ( ! is_array( $stored ) || ! is_bool( $stored['woo_hooked'] ?? null ) || ( $stored['fingerprint'] ?? '' ) !== $fingerprint ) {
			return null;
		}
		return array(
			'woo_hooked' => $stored['woo_hooked'],
			'time'       => (int) ( $stored['time'] ?? 0 ),
		);
	}
}
