<?php
/**
 * DATA-01 price.
 *
 * @package X7velo
 */

namespace X7velo\Audit\Checks;

use X7velo\Audit\CheckResult;
use X7velo\Audit\Status;

defined( 'ABSPATH' ) || exit;

/**
 * Products with a price. Variable products need every published variation priced.
 */
final class ProductPrice extends ProductDataCheck {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'DATA-01';
	}

	/**
	 * {@inheritDoc}
	 */
	public function weight(): int {
		return 8;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $facts Facts.
	 */
	protected function is_ok( array $facts ): bool {
		return (bool) $facts['has_price'];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $facts Facts.
	 */
	protected function count( array $facts ): void {
		$this->tally->add( 'variations_without_price', (int) $facts['variations_without_price'] );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function evaluate(): CheckResult {
		if ( 0 === $this->tally->missing() ) {
			return $this->result( Status::PASS, 'all_priced' );
		}

		return $this->result( $this->uniform_status(), 'missing_price' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $code    Code.
	 * @param array<string, mixed> $details Details.
	 * @param int                  $missing Products failing the check.
	 */
	protected function render( string $code, array $details, int $missing ): string {
		if ( 'all_priced' === $code ) {
			return __( 'All your products have a price.', 'x7velo' );
		}
		return sprintf(
			/* translators: %d: number of products. */
			_n( '%d product has no price. Assistants skip products they can’t quote.', '%d products have no price. Assistants skip products they can’t quote.', $missing, 'x7velo' ),
			$missing
		);
	}
}
