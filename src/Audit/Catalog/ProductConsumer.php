<?php
/**
 * Contract for code that consumes products during the audit scan.
 *
 * @package X7velo
 */

namespace X7velo\Audit\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * Receives each audited product once, in ascending ID order, while ProductScanner streams.
 *
 * Consumers must reduce what they need into small running totals (see Tally) and must not
 * keep the facts array or any personal data.
 */
interface ProductConsumer {

	/**
	 * Consumes one product.
	 *
	 * @param int                  $product_id Product ID.
	 * @param array<string, mixed> $facts      Facts (keys documented on ProductScanner).
	 */
	public function consume( int $product_id, array $facts ): void;
}
