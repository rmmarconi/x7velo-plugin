<?php
/**
 * DATA-04 identifiers.
 *
 * @package X7velo
 */

namespace X7velo\Audit\Checks;

use X7velo\Audit\Catalog\ProductScanner;
use X7velo\Audit\CheckResult;
use X7velo\Audit\Status;
use X7velo\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Products with a SKU and a GTIN or brand. Pass ≥ 80 %, otherwise warn; never fails.
 *
 * With the "I make my own products" setting the GTIN/brand part is not applicable and only the
 * SKU is required.
 */
final class ProductIdentifiers extends ProductDataCheck {

	/**
	 * Whether the owner marked "I make my own products" (read once per run).
	 *
	 * @var bool
	 */
	private bool $own_brand;

	/**
	 * Constructor.
	 *
	 * @param ProductScanner $scanner  Product scanner.
	 * @param Settings       $settings Settings.
	 */
	public function __construct( ProductScanner $scanner, Settings $settings ) {
		parent::__construct( $scanner );
		$this->own_brand = (bool) $settings->get( 'own_brand_products' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'DATA-04';
	}

	/**
	 * {@inheritDoc}
	 */
	public function weight(): int {
		return 4;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $facts Facts.
	 */
	protected function is_ok( array $facts ): bool {
		return $facts['has_sku'] && ( $this->own_brand || $facts['has_gtin'] || $facts['has_brand'] );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $facts Facts.
	 */
	protected function count( array $facts ): void {
		$this->tally->add( 'with_sku', (int) $facts['has_sku'] );
		$this->tally->add( 'with_gtin', (int) $facts['has_gtin'] );
		$this->tally->add( 'with_brand', (int) $facts['has_brand'] );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function evaluate(): CheckResult {
		$extra = array( 'own_brand_products' => $this->own_brand );

		if ( $this->tally->at_least( 80 ) ) {
			return $this->result( Status::PASS, 'identifiers_ok', $extra );
		}

		return $this->result( Status::WARN, 'missing_identifiers', $extra );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $code    Code.
	 * @param array<string, mixed> $details Details.
	 * @param int                  $missing Products failing the check.
	 */
	protected function render( string $code, array $details, int $missing ): string {
		if ( 'identifiers_ok' === $code ) {
			return __( 'Most of your products have the codes assistants use to match them.', 'x7velo' );
		}
		if ( ! empty( $details['own_brand_products'] ) ) {
			return sprintf(
				/* translators: %d: number of products. */
				_n( '%d product has no SKU. A SKU helps assistants tell your products apart.', '%d products have no SKU. A SKU helps assistants tell your products apart.', $missing, 'x7velo' ),
				$missing
			);
		}
		return sprintf(
			/* translators: %d: number of products. */
			_n( '%d product has no SKU, or has neither a barcode (GTIN/EAN) nor a brand. Codes like these help assistants match your product to what shoppers ask for. If you make your own products, tell x7velo in its settings.', '%d products have no SKU, or have neither a barcode (GTIN/EAN) nor a brand. Codes like these help assistants match your products to what shoppers ask for. If you make your own products, tell x7velo in its settings.', $missing, 'x7velo' ),
			$missing
		);
	}
}
