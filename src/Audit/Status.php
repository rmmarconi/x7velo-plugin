<?php
/**
 * Check status values.
 *
 * @package X7velo
 */

namespace X7velo\Audit;

defined( 'ABSPATH' ) || exit;

/**
 * Allowed check statuses (spec §5).
 */
final class Status {

	public const PASS           = 'pass';
	public const WARN           = 'warn';
	public const FAIL           = 'fail';
	public const NOT_APPLICABLE = 'not_applicable';
	public const ERROR          = 'error';

	/**
	 * All valid statuses.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array( self::PASS, self::WARN, self::FAIL, self::NOT_APPLICABLE, self::ERROR );
	}

	/**
	 * Whether a status counts towards the score.
	 *
	 * @param string $status Status.
	 */
	public static function is_scored( string $status ): bool {
		return in_array( $status, array( self::PASS, self::WARN, self::FAIL ), true );
	}
}
