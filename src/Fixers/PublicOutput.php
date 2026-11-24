<?php
/**
 * Gate shared by x7velo's public endpoints (/llms.txt and the product feed).
 *
 * @package X7velo
 */

namespace X7velo\Fixers;

defined( 'ABSPATH' ) || exit;

/**
 * Public catalogue output is withheld while the store isn't public yet.
 *
 * - WooCommerce "Coming soon" (`woocommerce_coming_soon = yes`, whole site or store pages only)
 *   while WooCommerce is loaded: always blocked, not filterable (`x7velo_woocommerce_active`
 *   doesn't lift it).
 * - `x7velo_public_output_allowed` (default true): maintenance-mode / password-protection plugins
 *   can return false to withhold it too.
 *
 * Search visibility (`blog_public`) deliberately does not gate these endpoints (spec §8.2/§8.3).
 */
final class PublicOutput {

	public const FILTER = 'x7velo_public_output_allowed';

	public const REASON_COMING_SOON = 'coming_soon';
	public const REASON_FILTERED    = 'public_output_disallowed';

	/**
	 * Why public output is withheld for an area, '' when allowed.
	 *
	 * @param string $area `llms_txt` or `feed`.
	 */
	public static function blocked_reason( string $area ): string {
		// Woo's coming-soon option only means something while WooCommerce is loaded (spec §15.2:
		// a leftover `yes` is not enforced without WooCommerce, so it doesn't gate output either).
		// Checked on the class, not Plugin::is_woocommerce_active(): the `x7velo_woocommerce_active`
		// filter switches x7velo to site mode but must not lift WooCommerce's own coming-soon gate.
		if ( class_exists( 'WooCommerce' ) && 'yes' === get_option( 'woocommerce_coming_soon', 'no' ) ) {
			return self::REASON_COMING_SOON;
		}
		/**
		 * Filters whether x7velo may serve public catalogue output (/llms.txt, product feed).
		 * Return false while a maintenance or site-password plugin hides the store.
		 *
		 * @param bool   $allowed Default true.
		 * @param string $area    `llms_txt` or `feed`.
		 */
		$allowed = apply_filters( 'x7velo_public_output_allowed', true, $area );
		return false === $allowed ? self::REASON_FILTERED : '';
	}

	/**
	 * Whether public output is allowed for an area.
	 *
	 * @param string $area `llms_txt` or `feed`.
	 */
	public static function allowed( string $area ): bool {
		return '' === self::blocked_reason( $area );
	}

	/**
	 * `cannot_run` status row for a blocked area, or null when allowed.
	 *
	 * @param string $area `llms_txt` or `feed`.
	 * @return array<string, string>|null
	 */
	public static function status_row( string $area ): ?array {
		$reason = self::blocked_reason( $area );
		if ( '' === $reason ) {
			return null;
		}
		$row = array(
			'state'  => 'cannot_run',
			'reason' => $reason,
		);
		// The feed row's `url` is read as the feed address by the Fixes card, so only llms.txt
		// gets the Guided settings link.
		if ( self::REASON_COMING_SOON === $reason && 'llms_txt' === $area ) {
			$row['url'] = admin_url( 'admin.php?page=wc-settings&tab=site-visibility' );
		}
		return $row;
	}
}
