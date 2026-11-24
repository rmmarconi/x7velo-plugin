<?php
/**
 * Check runner.
 *
 * @package X7velo
 */

namespace X7velo\Audit;

use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Runs checks, isolating failures so one broken check never stops the audit.
 */
final class Runner {

	/**
	 * Runs every check. Metadata comes from Registry::describe(), so a check is never called
	 * again after it throws.
	 *
	 * @param array<int, array{id: string, category: string, weight: int, check: Check}> $entries Described checks.
	 * @return array<int, array{id: string, category: string, weight: int, result: CheckResult}>
	 */
	public function run( array $entries ): array {
		$results = array();
		foreach ( $entries as $entry ) {
			try {
				$result = $entry['check']->run();
			} catch ( Throwable $e ) {
				/**
				 * Fires when a check throws. The audit continues and the check is reported as "error".
				 *
				 * @param string    $check_id Check ID.
				 * @param Throwable $e        What was thrown.
				 */
				do_action( 'x7velo_audit_check_error', $entry['id'], $e );
				$result = CheckResult::error();
			}

			$results[] = array(
				'id'       => $entry['id'],
				'category' => $entry['category'],
				'weight'   => $entry['weight'],
				'result'   => $result,
			);
		}
		return $results;
	}
}
