<?php
/**
 * DATA-05 categories.
 *
 * @package X7velo
 */

namespace X7velo\Audit\Checks;

use X7velo\Audit\CheckResult;
use X7velo\Audit\Status;

defined( 'ABSPATH' ) || exit;

/**
 * Share of products only in the default "Uncategorized" category: pass ≤ 5 %, warn ≤ 25 %, fail > 25 %.
 */
final class ProductCategories extends ProductDataCheck {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'DATA-05';
	}

	/**
	 * {@inheritDoc}
	 */
	public function weight(): int {
		return 2;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $facts Facts.
	 */
	protected function is_ok( array $facts ): bool {
		return ! $facts['uncategorized'];
	}

	/**
	 * {@inheritDoc}
	 */
	protected function evaluate(): CheckResult {
		// Uncategorised share ≤ 5 % ⇔ categorised ≥ 95 %; ≤ 25 % ⇔ ≥ 75 %.
		if ( $this->tally->at_least( 95 ) ) {
			$status = Status::PASS;
		} elseif ( $this->tally->at_least( 75 ) ) {
			$status = Status::WARN;
		} else {
			$status = Status::FAIL;
		}

		if ( 0 === $this->tally->missing() ) {
			return $this->result( $status, 'all_categorized' );
		}

		return $this->result( $status, 'uncategorized_products' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $code    Code.
	 * @param array<string, mixed> $details Details.
	 * @param int                  $missing Products failing the check.
	 */
	protected function render( string $code, array $details, int $missing ): string {
		if ( 'all_categorized' === $code ) {
			return __( 'All your products are in a category.', 'x7velo' );
		}
		return sprintf(
			/* translators: %d: number of products. */
			_n( '%d product is “Uncategorized”. Categories help assistants understand what you sell.', '%d products are “Uncategorized”. Categories help assistants understand what you sell.', $missing, 'x7velo' ),
			$missing
		);
	}
}
