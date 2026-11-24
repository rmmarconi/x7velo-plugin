<?php
/**
 * Detection-aware fixer states (spec §8), via `x7velo_fixer_status`.
 *
 * @package X7velo
 */

namespace X7velo\Detection;

use X7velo\Audit\Checks\ProductSchemaSource;
use X7velo\Fixers\PublicOutput;

defined( 'ABSPATH' ) || exit;

/**
 * Derives `managed` / `paused` / `cannot_run` per area from detection. Never stored, never
 * changes a stored toggle. Feed is never `managed` (AC65): our feed stays available alongside
 * Google for WooCommerce.
 */
final class FixerStates {

	/**
	 * `x7velo_fixer_status` callback.
	 *
	 * @param mixed $status   Area => {state, managed_by?, reason?, url?}.
	 * @param mixed $settings Sanitised settings.
	 * @return mixed
	 */
	public static function filter( $status, $settings = array() ) {
		if ( ! is_array( $status ) ) {
			return $status;
		}
		$settings = is_array( $settings ) ? $settings : array();
		$detector = new Detector();

		if ( isset( $status['jsonld'] ) ) {
			$status['jsonld'] = self::jsonld( $detector, $status['jsonld'], ! empty( $settings['fix_jsonld'] ) );
		}
		if ( isset( $status['llms_txt'] ) ) {
			$status['llms_txt'] = self::llms( $detector, $status['llms_txt'], ! empty( $settings['fix_llms_txt'] ) );
		}
		if ( isset( $status['robots'] ) ) {
			$status['robots'] = self::robots( $detector, $status['robots'] );
		}
		if ( isset( $status['feed'] ) && is_array( $status['feed'] ) ) {
			$status['feed'] = self::feed( $status['feed'] );
		}
		return $status;
	}

	/**
	 * JSON-LD: managed/paused when another emitter owns Product; mode augment|emit otherwise.
	 *
	 * @param Detector             $detector Detector.
	 * @param array<string, mixed> $row      Current row.
	 * @param bool                 $on       Toggle.
	 * @return array<string, mixed>
	 */
	public static function jsonld( Detector $detector, array $row, bool $on ): array {
		$d = ProductSchemaSource::decide( $detector, $on );
		if ( in_array( $d['outcome'], array( 'managed', 'duplicate', 'unverified' ), true ) ) {
			return self::taken( $on, $d['label'], 'product_schema_managed', $d['path'] );
		}
		$row['mode'] = in_array( $d['outcome'], array( 'none', 'x7velo' ), true ) ? 'emit' : 'augment';
		return $row;
	}

	/**
	 * The llms.txt area: managed/paused when another source exists; cannot_run with plain
	 * permalinks, in WooCommerce coming-soon mode, or when `x7velo_public_output_allowed` is false.
	 *
	 * @param Detector             $detector Detector.
	 * @param array<string, mixed> $row      Current row.
	 * @param bool                 $on       Toggle.
	 * @return array<string, mixed>
	 */
	public static function llms( Detector $detector, array $row, bool $on ): array {
		$others = $detector->llms_sources( false );
		if ( array() !== $others ) {
			$first = $others[0];
			$label = 'file' === $first['id'] ? __( 'a file on your server', 'x7velo' ) : $first['label'];
			$path  = '';
			$owner = $detector->get( $first['id'] );
			if ( null !== $owner ) {
				$path = $owner->settings_path( 'llms' );
			}
			return self::taken( $on, $label, 'llms_txt_managed', $path );
		}
		if ( '' === trim( (string) get_option( 'permalink_structure', '' ) ) ) {
			return array(
				'state'  => 'cannot_run',
				'reason' => 'plain_permalinks',
				'url'    => admin_url( 'options-permalink.php' ),
			);
		}
		return PublicOutput::status_row( 'llms_txt' ) ?? $row;
	}

	/**
	 * Feed: never managed (AC65); cannot_run while the store isn't public (coming soon / filter).
	 *
	 * @param array<string, mixed> $row Current row.
	 * @return array<string, mixed>
	 */
	public static function feed( array $row ): array {
		return PublicOutput::status_row( 'feed' ) ?? $row;
	}

	/**
	 * Robots: cannot_run on subfolder installs, with a physical robots.txt, or when VIS-01 fails.
	 *
	 * @param Detector             $detector Detector.
	 * @param array<string, mixed> $row      Current row.
	 * @return array<string, mixed>
	 */
	public static function robots( Detector $detector, array $row ): array {
		if ( RobotsRules::is_subfolder_install() ) {
			return array(
				'state'  => 'cannot_run',
				'reason' => 'subfolder_install',
			);
		}
		if ( ( new RobotsRules( $detector ) )->has_physical_file() ) {
			return array(
				'state'  => 'cannot_run',
				'reason' => 'physical_robots_file',
			);
		}
		if ( '0' === (string) get_option( 'blog_public', '1' ) ) {
			return array(
				'state'  => 'cannot_run',
				'reason' => 'search_engines_discouraged',
				'url'    => admin_url( 'options-reading.php' ),
			);
		}
		return $row;
	}

	/**
	 * Another source owns the area: `paused` if our toggle is on, else `managed`.
	 *
	 * @param bool   $on     Toggle.
	 * @param string $label  Owner label.
	 * @param string $reason Reason code.
	 * @param string $path   Owner settings path ('' if none).
	 * @return array<string, string>
	 */
	private static function taken( bool $on, string $label, string $reason, string $path ): array {
		$row = array(
			'state'      => $on ? 'paused' : 'managed',
			'managed_by' => $label,
			'reason'     => $reason,
		);
		if ( '' !== $path ) {
			$row['url'] = admin_url( $path );
		}
		return $row;
	}
}
