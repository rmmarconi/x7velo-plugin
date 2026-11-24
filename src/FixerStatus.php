<?php
/**
 * Derived fixer states for the settings API.
 *
 * @package X7velo
 */

namespace X7velo;

defined( 'ABSPATH' ) || exit;

/**
 * Computes the per-request state of each fixer area (never stored).
 *
 * This class only knows the owner's toggles (off/on). Detection-aware states (managed, paused,
 * cannot_run) are supplied by the modules that own each area through the `x7velo_fixer_status`
 * filter.
 */
final class FixerStatus {

	public const STATES = array( 'off', 'on', 'managed', 'paused', 'cannot_run' );

	public const ROBOTS_LINES_MAX   = 100;
	public const ROBOTS_LINES_BYTES = 8192;

	/**
	 * JSON-LD parts reported on the Fixes card (spec §8.1) and their states.
	 */
	public const JSONLD_PARTS = array( 'returns', 'shipping' );
	public const PART_STATES  = array( 'included', 'not_yet' );

	/**
	 * Settings keys that turn each area on.
	 *
	 * @var array<string, string[]>
	 */
	public const AREA_KEYS = array(
		'jsonld'   => array( 'fix_jsonld' ),
		'llms_txt' => array( 'fix_llms_txt' ),
		'feed'     => array( 'fix_feed' ),
		'robots'   => array( 'fix_robots_search', 'fix_robots_block_training', 'block_google_extended', 'block_experimental' ),
	);

	/**
	 * Areas that only exist while WooCommerce is active (spec §15.3: hidden without it).
	 */
	public const STORE_AREAS = array( 'jsonld', 'feed' );

	/**
	 * Reason given when a store-only area is turned on without WooCommerce.
	 */
	public const REASON_WOOCOMMERCE_INACTIVE = 'woocommerce_inactive';

	/**
	 * Areas available on this request: all four with WooCommerce, llms_txt + robots without.
	 *
	 * @return array<string, string[]>
	 */
	public static function areas(): array {
		if ( Plugin::is_woocommerce_active() ) {
			return self::AREA_KEYS;
		}
		return array_diff_key( self::AREA_KEYS, array_flip( self::STORE_AREAS ) );
	}

	/**
	 * Status of every available area: {state, managed_by?, reason?, url?}; jsonld also
	 * {mode?, parts?}; robots also `lines` {allow, training, sitemap}. Without WooCommerce,
	 * `jsonld` and `feed` are omitted.
	 *
	 * @param array<string, mixed> $settings Sanitised settings.
	 * @return array<string, array<string, mixed>>
	 */
	public static function compute( array $settings ): array {
		$areas  = self::areas();
		$status = array();
		foreach ( $areas as $area => $keys ) {
			$status[ $area ] = array( 'state' => self::area_enabled( $area, $settings ) ? 'on' : 'off' );
		}

		/**
		 * Filters the derived fixer states. Modules owning an area set `managed` / `paused` /
		 * `cannot_run` (with `managed_by`, `reason`, `url`). Never persisted.
		 *
		 * @param array<string, array<string, string>> $status   Area => status.
		 * @param array<string, mixed>                 $settings Sanitised settings.
		 */
		$filtered = apply_filters( 'x7velo_fixer_status', $status, $settings );

		$clean = array();
		foreach ( $areas as $area => $keys ) {
			$item  = is_array( $filtered[ $area ] ?? null ) ? $filtered[ $area ] : $status[ $area ];
			$state = in_array( $item['state'] ?? '', self::STATES, true ) ? $item['state'] : $status[ $area ]['state'];
			$row   = array( 'state' => $state );
			if ( ! empty( $item['managed_by'] ) ) {
				$row['managed_by'] = sanitize_text_field( (string) $item['managed_by'] );
			}
			if ( ! empty( $item['reason'] ) ) {
				$row['reason'] = sanitize_key( (string) $item['reason'] );
			}
			if ( ! empty( $item['url'] ) ) {
				$row['url'] = esc_url_raw( (string) $item['url'] );
			}
			if ( 'jsonld' === $area && isset( $item['mode'] ) && in_array( $item['mode'], array( 'augment', 'emit' ), true ) ) {
				$row['mode'] = $item['mode'];
			}
			if ( 'robots' === $area && isset( $item['lines'] ) && is_array( $item['lines'] ) ) {
				$row['lines'] = self::robots_lines( $item['lines'] );
			}
			if ( 'jsonld' === $area && isset( $item['parts'] ) && is_array( $item['parts'] ) ) {
				$parts = self::jsonld_parts( $item['parts'] );
				if ( array() !== $parts ) {
					$row['parts'] = $parts;
				}
			}
			$clean[ $area ] = $row;
		}
		return $clean;
	}

	/**
	 * Robots `lines` {allow, training, sitemap}: strings made only of robots.txt records
	 * (`User-agent`, `Allow`, `Disallow`, `Sitemap`) without control characters, at most
	 * ROBOTS_LINES_MAX lines and ROBOTS_LINES_BYTES bytes each; anything else becomes ''.
	 *
	 * @param array<mixed> $lines Raw lines.
	 * @return array{allow: string, training: string, sitemap: string}
	 */
	private static function robots_lines( array $lines ): array {
		$clean = array();
		foreach ( array( 'allow', 'training', 'sitemap' ) as $key ) {
			$value = is_string( $lines[ $key ] ?? null ) ? $lines[ $key ] : '';
			$rows  = '' === $value ? array() : explode( "\n", $value );
			$valid = strlen( $value ) <= self::ROBOTS_LINES_BYTES && count( $rows ) <= self::ROBOTS_LINES_MAX && wp_check_invalid_utf8( $value ) === $value;
			foreach ( $rows as $row ) {
				$valid = $valid && 1 === preg_match( '/^(?:User-agent|Allow|Disallow|Sitemap): [^\x00-\x1F\x7F]*$/', $row );
			}
			$clean[ $key ] = $valid ? $value : '';
		}
		return $clean;
	}

	/**
	 * JSON-LD per-part status: only `returns` / `shipping`, each `included` | `not_yet`.
	 *
	 * @param array<mixed> $parts Raw parts.
	 * @return array<string, string>
	 */
	private static function jsonld_parts( array $parts ): array {
		$clean = array();
		foreach ( self::JSONLD_PARTS as $part ) {
			if ( isset( $parts[ $part ] ) && in_array( $parts[ $part ], self::PART_STATES, true ) ) {
				$clean[ $part ] = $parts[ $part ];
			}
		}
		return $clean;
	}

	/**
	 * Whether any of an area's toggles is on.
	 *
	 * @param string               $area     Area.
	 * @param array<string, mixed> $settings Settings.
	 */
	public static function area_enabled( string $area, array $settings ): bool {
		foreach ( self::AREA_KEYS[ $area ] ?? array() as $key ) {
			if ( ! empty( $settings[ $key ] ) ) {
				return true;
			}
		}
		return false;
	}
}
