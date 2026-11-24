<?php
/**
 * DATA-02 image.
 *
 * @package X7velo
 */

namespace X7velo\Audit\Checks;

use X7velo\Audit\CheckResult;
use X7velo\Audit\Status;

defined( 'ABSPATH' ) || exit;

/**
 * Products with a main (featured) image.
 */
final class ProductImage extends ProductDataCheck {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'DATA-02';
	}

	/**
	 * {@inheritDoc}
	 */
	public function weight(): int {
		return 6;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $facts Facts.
	 */
	protected function is_ok( array $facts ): bool {
		return (bool) $facts['has_image'];
	}

	/**
	 * {@inheritDoc}
	 */
	protected function evaluate(): CheckResult {
		if ( 0 === $this->tally->missing() ) {
			return $this->result( Status::PASS, 'all_have_image' );
		}

		return $this->result( $this->uniform_status(), 'missing_image' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $code    Code.
	 * @param array<string, mixed> $details Details.
	 * @param int                  $missing Products failing the check.
	 */
	protected function render( string $code, array $details, int $missing ): string {
		if ( 'all_have_image' === $code ) {
			return __( 'All your products have a main image.', 'x7velo' );
		}
		return sprintf(
			/* translators: %d: number of products. */
			_n( '%d product has no main image.', '%d products have no main image.', $missing, 'x7velo' ),
			$missing
		);
	}
}
