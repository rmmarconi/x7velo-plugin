<?php
/**
 * Check registry.
 *
 * @package X7velo
 */

namespace X7velo\Audit;

use X7velo\Audit\Catalog\ProductScanner;
use X7velo\Plugin;
use X7velo\Settings;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the list of checks for one audit run.
 */
final class Registry {

	/**
	 * Maximum number of checks per audit (built-ins come first).
	 */
	public const MAX_CHECKS = 100;

	/**
	 * Maximum weight of one check.
	 */
	public const MAX_WEIGHT = 100;

	/**
	 * Shared per-run product scanner.
	 *
	 * @var ProductScanner
	 */
	private ProductScanner $scanner;

	/**
	 * Plugin settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param ProductScanner $scanner  Per-run product scanner (shared by the DATA/STORE checks).
	 * @param Settings       $settings Plugin settings.
	 */
	public function __construct( ProductScanner $scanner, Settings $settings ) {
		$this->scanner  = $scanner;
		$this->settings = $settings;
	}

	/**
	 * Built-in checks, in display order.
	 *
	 * @return Check[]
	 */
	public function default_checks(): array {
		return array(
			new Checks\SearchVisibility(),
			new Checks\Https(),
			new Checks\Permalinks(),
			new Checks\ProductPrice( $this->scanner ),
			new Checks\ProductImage( $this->scanner ),
			new Checks\ProductDescription( $this->scanner ),
			new Checks\ProductIdentifiers( $this->scanner, $this->settings ),
			new Checks\ProductCategories( $this->scanner ),
			new Checks\StoreIdentity(),
			new Checks\ReturnsPolicy( $this->settings ),
			new Checks\Shipping( $this->scanner ),
			new Checks\GuestCheckout(),
		);
	}

	/**
	 * Checks after the `x7velo_audit_checks` filter, validated, with metadata captured once.
	 *
	 * Dropped: entries that are not a Check, whose id()/category()/weight() throw, with an empty
	 * ID, an unknown category or a weight outside 0–MAX_WEIGHT, duplicate IDs (first one wins,
	 * so built-ins can't be replaced) and anything beyond MAX_CHECKS.
	 *
	 * @return array<int, array{id: string, category: string, weight: int, check: Check}>
	 */
	public function checks(): array {
		/**
		 * Filters the list of audit checks.
		 *
		 * Checks that need product data implement ProductConsumer and call
		 * `$scanner->subscribe( $this )` before the audit runs (e.g. in their constructor).
		 * A check's message and details must never contain personal data (customer or order
		 * data, names, emails, addresses) or interpolated option values; details are for counts,
		 * flags and at most 5 product IDs, and are size-capped by CheckResult.
		 *
		 * @param Check[]        $checks   Checks in display order.
		 * @param ProductScanner $scanner  Per-run product scanner to subscribe to.
		 * @param Settings       $settings Plugin settings.
		 */
		$checks = apply_filters( 'x7velo_audit_checks', $this->default_checks(), $this->scanner, $this->settings );

		$entries = self::describe( is_array( $checks ) ? $checks : array() );
		return Plugin::is_woocommerce_active() ? $entries : self::without_store_checks( $entries );
	}

	/**
	 * Site mode (spec §15.2): store checks are replaced by StoreOnlyCheck stand-ins, so they stay
	 * in the record as `not_applicable` / `woocommerce_inactive` and are never run.
	 *
	 * @param array<int, array{id: string, category: string, weight: int, check: Check}> $entries Described checks.
	 * @return array<int, array{id: string, category: string, weight: int, check: Check}>
	 */
	public static function without_store_checks( array $entries ): array {
		foreach ( $entries as $i => $entry ) {
			if ( in_array( $entry['id'], StoreOnlyCheck::IDS, true ) ) {
				$entries[ $i ]['check'] = new StoreOnlyCheck( $entry['id'], $entry['category'], $entry['weight'] );
			}
		}
		return $entries;
	}

	/**
	 * Validates checks and captures their metadata (see checks()).
	 *
	 * @param array<int, mixed> $checks Candidate checks.
	 * @return array<int, array{id: string, category: string, weight: int, check: Check}>
	 */
	public static function describe( array $checks ): array {
		$valid = array();
		foreach ( $checks as $check ) {
			if ( count( $valid ) >= self::MAX_CHECKS ) {
				break;
			}
			if ( ! $check instanceof Check ) {
				continue;
			}
			try {
				$id       = (string) $check->id();
				$category = (string) $check->category();
				$weight   = (int) $check->weight();
			} catch ( Throwable $e ) {
				continue;
			}
			if ( '' === $id || strlen( $id ) > 32 || isset( $valid[ $id ] ) || $weight < 0 || $weight > self::MAX_WEIGHT || ! in_array( $category, Category::all(), true ) ) {
				continue;
			}
			$valid[ $id ] = array(
				'id'       => $id,
				'category' => $category,
				'weight'   => $weight,
				'check'    => $check,
			);
		}
		return array_values( $valid );
	}
}
