<?php
/**
 * Check categories.
 *
 * @package X7velo
 */

namespace X7velo\Audit;

defined( 'ABSPATH' ) || exit;

/**
 * Check categories (spec §5 sections A–E).
 */
final class Category {

	public const VISIBILITY = 'visibility';
	public const CRAWL      = 'crawl';
	public const SCHEMA     = 'schema';
	public const DATA       = 'data';
	public const STORE      = 'store';

	/**
	 * All categories in display order.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array( self::VISIBILITY, self::CRAWL, self::SCHEMA, self::DATA, self::STORE );
	}
}
