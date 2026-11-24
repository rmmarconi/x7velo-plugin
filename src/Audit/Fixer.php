<?php
/**
 * Fixer types.
 *
 * @package X7velo
 */

namespace X7velo\Audit;

defined( 'ABSPATH' ) || exit;

/**
 * Which kind of fix resolves a finding (spec §5 "Auto-fixer" column).
 */
final class Fixer {

	public const JSON_LD  = 'json-ld';
	public const LLMS_TXT = 'llms-txt';
	public const FEED     = 'feed';
	public const ROBOTS   = 'robots';
	public const GUIDED   = 'guided';
	public const MANUAL   = 'manual';

	/**
	 * All fixer types.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array( self::JSON_LD, self::LLMS_TXT, self::FEED, self::ROBOTS, self::GUIDED, self::MANUAL );
	}
}
