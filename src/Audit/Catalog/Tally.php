<?php
/**
 * Bounded running totals for one product predicate.
 *
 * @package X7velo
 */

namespace X7velo\Audit\Catalog;

use X7velo\Audit\CheckResult;

defined( 'ABSPATH' ) || exit;

/**
 * Counts products that pass/fail a predicate, keeps the first failing IDs (≤ 5) and named
 * counters. Size is constant regardless of catalogue size.
 */
final class Tally {

	/**
	 * Products seen.
	 *
	 * @var int
	 */
	private int $total = 0;

	/**
	 * Products that passed.
	 *
	 * @var int
	 */
	private int $ok = 0;

	/**
	 * First failing product IDs (ascending, because the scan is ordered by ID).
	 *
	 * @var int[]
	 */
	private array $examples = array();

	/**
	 * Extra named counters.
	 *
	 * @var array<string, int>
	 */
	private array $counters = array();

	/**
	 * Records one product.
	 *
	 * @param int  $product_id Product ID.
	 * @param bool $ok         Whether it passed.
	 */
	public function record( int $product_id, bool $ok ): void {
		++$this->total;
		if ( $ok ) {
			++$this->ok;
		} elseif ( count( $this->examples ) < CheckResult::MAX_EXAMPLES ) {
			$this->examples[] = $product_id;
		}
	}

	/**
	 * Adds to a named counter.
	 *
	 * @param string $name   Counter name.
	 * @param int    $amount Amount.
	 */
	public function add( string $name, int $amount = 1 ): void {
		$this->counters[ $name ] = ( $this->counters[ $name ] ?? 0 ) + $amount;
	}

	/**
	 * Products seen.
	 */
	public function total(): int {
		return $this->total;
	}

	/**
	 * Products that passed.
	 */
	public function ok(): int {
		return $this->ok;
	}

	/**
	 * Products that failed.
	 */
	public function missing(): int {
		return $this->total - $this->ok;
	}

	/**
	 * A named counter (0 if never added).
	 *
	 * @param string $name Counter name.
	 */
	public function counter( string $name ): int {
		return $this->counters[ $name ] ?? 0;
	}

	/**
	 * Whether passing products are at least $percent % of the total (integer arithmetic).
	 *
	 * @param int $percent Threshold percentage.
	 */
	public function at_least( int $percent ): bool {
		return $this->ok * 100 >= $percent * $this->total;
	}

	/**
	 * Details for a CheckResult: products, ok, missing, percent, examples, then counters.
	 *
	 * @return array<string, mixed>
	 */
	public function to_details(): array {
		return array(
			'products' => $this->total,
			'ok'       => $this->ok,
			'missing'  => $this->missing(),
			'percent'  => $this->total > 0 ? round( 100 * $this->ok / $this->total, 1 ) : 0.0,
			'examples' => $this->examples,
		) + $this->counters;
	}
}
