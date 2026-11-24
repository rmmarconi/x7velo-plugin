<?php
/**
 * DATA-03 description.
 *
 * @package X7velo
 */

namespace X7velo\Audit\Checks;

use X7velo\Audit\CheckResult;
use X7velo\Audit\Status;

defined( 'ABSPATH' ) || exit;

/**
 * Products whose long or short description has at least 150 characters of text.
 */
final class ProductDescription extends ProductDataCheck {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'DATA-03';
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
		return (bool) $facts['has_description'];
	}

	/**
	 * {@inheritDoc}
	 */
	protected function evaluate(): CheckResult {
		if ( 0 === $this->tally->missing() ) {
			return $this->result( Status::PASS, 'all_described' );
		}

		return $this->result( $this->uniform_status(), 'thin_description', array( 'min_characters' => 150 ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $code    Code.
	 * @param array<string, mixed> $details Details.
	 * @param int                  $missing Products failing the check.
	 */
	protected function render( string $code, array $details, int $missing ): string {
		if ( 'all_described' === $code ) {
			return __( 'All your products have a useful description.', 'x7velo' );
		}
		return sprintf(
			/* translators: %d: number of products. */
			_n( '%d product has little or no description. Assistants recommend products they can describe.', '%d products have little or no description. Assistants recommend products they can describe.', $missing, 'x7velo' ),
			$missing
		);
	}
}
