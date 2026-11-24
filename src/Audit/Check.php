<?php
/**
 * Audit check contract.
 *
 * @package X7velo
 */

namespace X7velo\Audit;

defined( 'ABSPATH' ) || exit;

/**
 * One local audit check.
 *
 * Implementations must be read-only, deterministic for a given store state and must not
 * make HTTP requests (spec §4).
 */
interface Check {

	/**
	 * Stable check ID, e.g. "VIS-01".
	 */
	public function id(): string;

	/**
	 * One of the Category constants.
	 */
	public function category(): string;

	/**
	 * Score weight (spec §5). Non-negative integer.
	 */
	public function weight(): int;

	/**
	 * Runs the check.
	 */
	public function run(): CheckResult;
}
