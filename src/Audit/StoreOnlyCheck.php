<?php
/**
 * Stand-in for a store check while WooCommerce is inactive.
 *
 * @package X7velo
 */

namespace X7velo\Audit;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the wrapped check's ID, category and weight (so the record always has every built-in
 * check and `check.id` + `code` stay stable) and returns `not_applicable` / `woocommerce_inactive`
 * without ever calling the wrapped check (spec §15.2): no `wc_*` call, no product query.
 */
final class StoreOnlyCheck implements Check {

	public const CODE = 'woocommerce_inactive';

	/**
	 * Store check IDs that don't apply without WooCommerce (spec §15.2).
	 */
	public const IDS = array(
		'VIS-04',
		'CRAWL-04',
		'SCHEMA-01',
		'DATA-01',
		'DATA-02',
		'DATA-03',
		'DATA-04',
		'DATA-05',
		'STORE-01',
		'STORE-02',
		'STORE-03',
		'STORE-04',
	);

	/**
	 * Wrapped check's ID.
	 *
	 * @var string
	 */
	private string $id;

	/**
	 * Wrapped check's category.
	 *
	 * @var string
	 */
	private string $category;

	/**
	 * Wrapped check's weight.
	 *
	 * @var int
	 */
	private int $weight;

	/**
	 * Constructor.
	 *
	 * @param string $id       Check ID.
	 * @param string $category Category.
	 * @param int    $weight   Weight.
	 */
	public function __construct( string $id, string $category, int $weight ) {
		$this->id       = $id;
		$this->category = $category;
		$this->weight   = $weight;
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * {@inheritDoc}
	 */
	public function category(): string {
		return $this->category;
	}

	/**
	 * {@inheritDoc}
	 */
	public function weight(): int {
		return $this->weight;
	}

	/**
	 * {@inheritDoc}
	 */
	public function run(): CheckResult {
		return self::result();
	}

	/**
	 * The `not_applicable` result.
	 */
	public static function result(): CheckResult {
		return new CheckResult( Status::NOT_APPLICABLE, self::CODE, self::message() );
	}

	/**
	 * Message (dashboard key `check.na.woocommerce_inactive`).
	 */
	public static function message(): string {
		return __( 'Doesn’t apply: this check is for WooCommerce stores.', 'x7velo' );
	}
}
