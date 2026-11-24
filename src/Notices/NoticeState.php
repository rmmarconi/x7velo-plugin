<?php
/**
 * Notice bookkeeping in `x7velo_notice_state` (spec §8, design §2.4 / §3.9).
 *
 * @package X7velo
 */

namespace X7velo\Notices;

use X7velo\FixerStatus;
use X7velo\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Once-per-transition conflict notices and the one-time activation notice.
 *
 * Option `x7velo_notice_state` (autoload off), written only when something changes:
 *
 *     v          int     schema version (1)
 *     areas      array   area => source we last announced as owning it ('' / missing = none)
 *     queue      array   pending notices: {id, type: taken_over|resumed, area, source, at, surfaces: string[]}
 *     activation int     activation time (0 = no activation notice)
 *
 * It is bookkeeping only: evaluated on admin page loads (Plugins screen, AI Readiness), it never
 * decides output, and it never touches a stored `fix_*` toggle.
 *
 * Public API for fixer areas (generic; track H's JSON-LD uses the same calls):
 *
 * - record_conflict( $area, $source ): the area's toggle is on and `$source` (adapter ID such as
 *   "yoast", "rank-math", "file", or a label) owns it now; '' = nobody else does any more.
 *   A change of source queues "taken_over"; going back to '' queues "resumed".
 * - record_off( $area ): the area's toggle is off; forget the source, drop its pending notices.
 * - evaluate( $settings ): does the above for every area, from the `x7velo_notice_sources`
 *   filter (area => {enabled, source}) or, for CONFLICT_AREAS no callback reports, from the
 *   derived fixer status (`paused` → its managed_by label; `on` → ''; other states skipped).
 * - take( $surface ): pending notices for "page" (AI Readiness) or "plugins" (Plugins screen),
 *   marked shown there, so each notice appears once per surface.
 */
final class NoticeState {

	public const OPTION   = 'x7velo_notice_state';
	public const SURFACES = array( 'page', 'plugins' );

	/**
	 * Areas another source can take over (robots is never paused; the feed is never managed).
	 */
	public const CONFLICT_AREAS = array( 'llms_txt', 'jsonld' );

	/**
	 * Store-only areas: while WooCommerce is inactive no notice for them is queued, shown or
	 * cleared (spec §15.3), so nothing spurious appears when WooCommerce comes back.
	 */
	public const STORE_AREAS = array( 'jsonld', 'feed' );

	/**
	 * Whether an area is frozen for notices right now (store area, WooCommerce inactive).
	 *
	 * @param string $area Area.
	 */
	private static function frozen( string $area ): bool {
		return in_array( $area, self::STORE_AREAS, true ) && ! Plugin::is_woocommerce_active();
	}

	public const MAX_QUEUE      = 10;
	public const MAX_AGE        = 30 * DAY_IN_SECONDS;
	public const ACTIVATION_TTL = 30 * DAY_IN_SECONDS;

	/**
	 * Current state, normalised (expired entries dropped).
	 *
	 * @return array{v: int, areas: array<string, string>, queue: array<int, array{id: string, type: string, area: string, source: string, at: int, surfaces: string[]}>, activation: int}
	 */
	public static function get(): array {
		$raw   = get_option( self::OPTION, array() );
		$raw   = is_array( $raw ) ? $raw : array();
		$areas = array();
		foreach ( (array) ( $raw['areas'] ?? array() ) as $area => $source ) {
			if ( is_string( $area ) && is_string( $source ) && '' !== $source ) {
				$areas[ sanitize_key( $area ) ] = $source;
			}
		}
		$queue = array();
		$now   = time();
		foreach ( (array) ( $raw['queue'] ?? array() ) as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['id'], $entry['type'], $entry['area'], $entry['at'] ) ) {
				continue;
			}
			$surfaces = array_values( array_intersect( self::SURFACES, (array) ( $entry['surfaces'] ?? array() ) ) );
			if ( array() === $surfaces || (int) $entry['at'] < $now - self::MAX_AGE ) {
				continue;
			}
			$queue[] = array(
				'id'       => (string) $entry['id'],
				'type'     => 'resumed' === $entry['type'] ? 'resumed' : 'taken_over',
				'area'     => sanitize_key( (string) $entry['area'] ),
				'source'   => (string) ( $entry['source'] ?? '' ),
				'at'       => (int) $entry['at'],
				'surfaces' => $surfaces,
			);
		}
		$activation = (int) ( $raw['activation'] ?? 0 );
		if ( $activation > 0 && $activation < $now - self::ACTIVATION_TTL ) {
			$activation = 0;
		}
		return array(
			'v'          => 1,
			'areas'      => $areas,
			'queue'      => array_slice( $queue, -self::MAX_QUEUE ),
			'activation' => $activation,
		);
	}

	/**
	 * Saves the state if it differs from what is stored (autoload off).
	 *
	 * @param array<string, mixed> $state State.
	 */
	private static function save( array $state ): void {
		if ( get_option( self::OPTION ) === $state ) {
			return;
		}
		if ( false === get_option( self::OPTION ) ) {
			add_option( self::OPTION, $state, '', false );
			return;
		}
		update_option( self::OPTION, $state, false );
	}

	/**
	 * Records who owns an area whose toggle is on.
	 *
	 * @param string $area   Fixer area (e.g. "llms_txt", "jsonld").
	 * @param string $source Owning source ID or label; '' = nobody else.
	 * @return array<string, mixed>|null The queued notice, or null when nothing changed.
	 */
	public static function record_conflict( string $area, string $source ): ?array {
		$area   = sanitize_key( $area );
		$source = substr( sanitize_text_field( $source ), 0, 100 );
		if ( '' === $area ) {
			return null;
		}
		$state = self::get();
		$last  = $state['areas'][ $area ] ?? '';
		if ( $last === $source ) {
			return null; // No transition: no write.
		}

		$type = '' === $source ? 'resumed' : 'taken_over';
		$name = '' === $source ? $last : $source;

		// An opposite notice nobody has seen yet cancels out (taken over and back before anyone looked).
		$cancelled = false;
		foreach ( $state['queue'] as $i => $entry ) {
			if ( $entry['area'] !== $area ) {
				continue;
			}
			$unseen = count( $entry['surfaces'] ) === count( self::SURFACES );
			if ( $unseen && 'resumed' === $type && 'taken_over' === $entry['type'] ) {
				$cancelled = true;
			}
			unset( $state['queue'][ $i ] ); // Superseded either way.
		}
		$state['queue'] = array_values( $state['queue'] );

		$notice = null;
		if ( ! $cancelled ) {
			$notice           = array(
				'id'       => $area . '-' . $type . '-' . time() . '-' . substr( md5( $name . wp_rand() ), 0, 6 ),
				'type'     => $type,
				'area'     => $area,
				'source'   => $name,
				'at'       => time(),
				'surfaces' => self::SURFACES,
			);
			$state['queue'][] = $notice;
			$state['queue']   = array_slice( $state['queue'], -self::MAX_QUEUE );
		}

		if ( '' === $source ) {
			unset( $state['areas'][ $area ] );
		} else {
			$state['areas'][ $area ] = $source;
		}
		self::save( $state );
		return $notice;
	}

	/**
	 * The area's toggle is off: no notices for it.
	 *
	 * @param string $area Fixer area.
	 */
	public static function record_off( string $area ): void {
		$area  = sanitize_key( $area );
		$state = self::get();
		$had   = isset( $state['areas'][ $area ] );
		unset( $state['areas'][ $area ] );
		$before         = count( $state['queue'] );
		$state['queue'] = array_values( array_filter( $state['queue'], static fn( array $e ): bool => $e['area'] !== $area ) );
		if ( $had || count( $state['queue'] ) !== $before ) {
			self::save( $state );
		}
	}

	/**
	 * Evaluates every area and records transitions.
	 *
	 * @param array<string, mixed> $settings Sanitised settings.
	 */
	public static function evaluate( array $settings ): void {
		/**
		 * Reports, per fixer area, whether its toggle is on and which other source owns it now
		 * ('' = none), for the once-per-transition conflict notices. Omit an area to skip it
		 * this time (e.g. it can't run for a reason that isn't a conflict).
		 *
		 * @param array<string, array{enabled: bool, source: string}> $sources  Area => report.
		 * @param array<string, mixed>                                $settings Settings.
		 */
		$sources = apply_filters( 'x7velo_notice_sources', array(), $settings );
		$sources = is_array( $sources ) ? $sources : array();

		$status = null;
		foreach ( self::CONFLICT_AREAS as $area ) {
			if ( array_key_exists( $area, $sources ) || self::frozen( $area ) ) {
				continue;
			}
			if ( ! FixerStatus::area_enabled( $area, $settings ) ) {
				$sources[ $area ] = array(
					'enabled' => false,
					'source'  => '',
				);
				continue;
			}
			$status = $status ?? FixerStatus::compute( $settings );
			$row    = $status[ $area ] ?? array();
			$state  = (string) ( $row['state'] ?? '' );
			if ( 'paused' === $state ) {
				$sources[ $area ] = array(
					'enabled' => true,
					'source'  => (string) ( $row['managed_by'] ?? '' ),
				);
			} elseif ( 'on' === $state ) {
				$sources[ $area ] = array(
					'enabled' => true,
					'source'  => '',
				);
			}
		}

		foreach ( $sources as $area => $report ) {
			if ( ! is_string( $area ) || ! is_array( $report ) || self::frozen( $area ) ) {
				continue;
			}
			if ( empty( $report['enabled'] ) ) {
				self::record_off( $area );
				continue;
			}
			self::record_conflict( $area, is_string( $report['source'] ?? null ) ? $report['source'] : '' );
		}
	}

	/**
	 * Pending notices for a surface, without marking them shown.
	 *
	 * @param string $surface "page" or "plugins".
	 * @return array<int, array{id: string, type: string, area: string, source: string, at: int, surfaces: string[]}>
	 */
	public static function pending( string $surface ): array {
		return array_values( array_filter( self::get()['queue'], static fn( array $e ): bool => in_array( $surface, $e['surfaces'], true ) && ! self::frozen( (string) $e['area'] ) ) );
	}

	/**
	 * Pending notices for a surface, marked shown there (each appears once per surface).
	 *
	 * @param string $surface "page" or "plugins".
	 * @return array<int, array{id: string, type: string, area: string, source: string, at: int, surfaces: string[]}>
	 */
	public static function take( string $surface ): array {
		$state = self::get();
		$taken = array();
		foreach ( $state['queue'] as $i => $entry ) {
			if ( ! in_array( $surface, $entry['surfaces'], true ) || self::frozen( (string) $entry['area'] ) ) {
				continue;
			}
			$taken[]                          = $entry;
			$state['queue'][ $i ]['surfaces'] = array_values( array_diff( $entry['surfaces'], array( $surface ) ) );
			if ( array() === $state['queue'][ $i ]['surfaces'] ) {
				unset( $state['queue'][ $i ] );
			}
		}
		if ( array() !== $taken ) {
			$state['queue'] = array_values( $state['queue'] );
			self::save( $state );
		}
		return $taken;
	}

	/**
	 * Plugin activated: (re)arm the one-time activation notice.
	 */
	public static function activated(): void {
		$state               = self::get();
		$state['activation'] = time();
		self::save( $state );
	}

	/**
	 * Activation notice ID ('' = none pending). Changes on each activation, so a re-activation
	 * shows it again.
	 */
	public static function activation_id(): string {
		$at = self::get()['activation'];
		return $at > 0 ? 'activation-' . $at : '';
	}
}
