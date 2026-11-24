<?php
/**
 * JSON-LD per-part status for the Fixes card, and conflict bookkeeping.
 *
 * @package X7velo
 */

namespace X7velo\Fixers\JsonLd;

defined( 'ABSPATH' ) || exit;

/**
 * `x7velo_fixer_status` callback (priority 20, after Detection\FixerStates at 10).
 *
 * Adds `parts` to the jsonld row: `returns` and `shipping`, each `included` | `not_yet`, from
 * the same Additions code that builds the markup, so the card never claims a part the page
 * does not carry. Derived per request, never stored.
 *
 * When the row is `paused` (toggle on, another emitter owns Product), the conflict is recorded
 * through track G's notice bookkeeping (`X7velo\Notices\NoticeState::record_conflict( $area,
 * $managed_by )`) if that class is present. The status filter runs in admin/REST context only,
 * so nothing is written on front-end requests.
 */
final class JsonLdStatus {

	public const PART_INCLUDED = 'included';
	public const PART_NOT_YET  = 'not_yet';

	/**
	 * Filter callback.
	 *
	 * @param mixed $status   Area => row.
	 * @param mixed $settings Sanitised settings.
	 * @return mixed
	 */
	public static function filter( $status, $settings = array() ) {
		if ( ! is_array( $status ) || ! isset( $status['jsonld'] ) || ! is_array( $status['jsonld'] ) ) {
			return $status;
		}
		$settings = is_array( $settings ) ? $settings : array();

		$parts                     = ( new Additions( $settings ) )->parts();
		$status['jsonld']['parts'] = array(
			'returns'  => $parts['returns'] ? self::PART_INCLUDED : self::PART_NOT_YET,
			'shipping' => $parts['shipping'] ? self::PART_INCLUDED : self::PART_NOT_YET,
		);

		if ( 'paused' === ( $status['jsonld']['state'] ?? '' ) ) {
			self::record_conflict( (string) ( $status['jsonld']['managed_by'] ?? '' ) );
		}
		return $status;
	}

	/**
	 * Records a JSON-LD takeover with track G's notice state, when available.
	 *
	 * @param string $managed_by Label of the plugin that owns Product.
	 */
	private static function record_conflict( string $managed_by ): void {
		$class = '\X7velo\Notices\NoticeState';
		if ( '' === $managed_by || ! class_exists( $class ) || ! is_callable( array( $class, 'record_conflict' ) ) ) {
			return;
		}
		call_user_func( array( $class, 'record_conflict' ), 'jsonld', $managed_by );
	}
}
