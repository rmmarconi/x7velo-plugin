<?php
/**
 * STORE-03 shipping.
 *
 * @package X7velo
 */

namespace X7velo\Audit\Checks;

use WC_Shipping_Zone;
use WC_Shipping_Zones;
use X7velo\Audit\Catalog\ProductConsumer;
use X7velo\Audit\Catalog\ProductScanner;
use X7velo\Audit\Category;
use X7velo\Audit\Check;
use X7velo\Audit\CheckResult;
use X7velo\Audit\Fixer;
use X7velo\Audit\MessageRenderer;
use X7velo\Audit\Status;

defined( 'ABSPATH' ) || exit;

/**
 * At least one shipping zone with an enabled method must cover the store's base country.
 *
 * Not applicable when no audited product needs shipping (all virtual) or there are no products.
 * Methods exist but none covers the base country → warn (e.g. export-only stores).
 */
final class Shipping implements Check, ProductConsumer, MessageRenderer {
	use BuildsResults;

	/**
	 * Product scanner.
	 *
	 * @var ProductScanner
	 */
	private ProductScanner $scanner;

	/**
	 * Products that need shipping.
	 *
	 * @var int
	 */
	private int $physical = 0;

	/**
	 * Constructor: subscribes to the scanner.
	 *
	 * @param ProductScanner $scanner Product scanner.
	 */
	public function __construct( ProductScanner $scanner ) {
		$this->scanner = $scanner;
		$scanner->subscribe( $this );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int                  $product_id Product ID.
	 * @param array<string, mixed> $facts      Facts.
	 */
	public function consume( int $product_id, array $facts ): void {
		if ( $facts['needs_shipping'] ) {
			++$this->physical;
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'STORE-03';
	}

	/**
	 * {@inheritDoc}
	 */
	public function category(): string {
		return Category::STORE;
	}

	/**
	 * {@inheritDoc}
	 */
	public function weight(): int {
		return 3;
	}

	/**
	 * {@inheritDoc}
	 */
	public function run(): CheckResult {
		$path = 'admin.php?page=wc-settings&tab=shipping';
		$this->scanner->scan();
		if ( $this->scanner->aborted() ) {
			return ProductDataCheck::scan_aborted();
		}
		if ( 0 === $this->scanner->products() ) {
			return $this->make( Status::NOT_APPLICABLE, 'no_products', array( 'physical_products' => 0 ) );
		}
		$physical = $this->physical;

		if ( 0 === $physical ) {
			return $this->make(
				Status::NOT_APPLICABLE,
				'no_physical_products',
				array( 'physical_products' => 0 )
			);
		}

		$base_country = (string) strtok( (string) get_option( 'woocommerce_default_country', '' ), ':' );
		$zones_with   = 0;
		$covers_base  = false;

		if ( 'disabled' !== get_option( 'woocommerce_ship_to_countries', '' ) ) {
			foreach ( $this->zones() as $zone ) {
				if ( ! $zone->get_shipping_methods( true ) ) {
					continue;
				}
				++$zones_with;
				if ( self::zone_covers_country( $zone, $base_country ) ) {
					$covers_base = true;
				}
			}
		}

		$details = array(
			'physical_products'  => $physical,
			'zones_with_methods' => $zones_with,
			'covers_base'        => $covers_base,
		);

		if ( $covers_base ) {
			return $this->make(
				Status::PASS,
				'shipping_ready',
				$details,
				Fixer::GUIDED,
				$path
			);
		}

		if ( $zones_with > 0 ) {
			return $this->make(
				Status::WARN,
				'shipping_not_base_country',
				$details,
				Fixer::GUIDED,
				$path
			);
		}

		return $this->make(
			Status::FAIL,
			'no_shipping',
			$details,
			Fixer::GUIDED,
			$path
		);
	}

	/**
	 * All shipping zones including "Rest of the world" (zone 0), in a stable order.
	 *
	 * @return WC_Shipping_Zone[]
	 */
	private function zones(): array {
		$zones = array();
		foreach ( WC_Shipping_Zones::get_zones() as $data ) {
			$zones[] = new WC_Shipping_Zone( (int) $data['id'] );
		}
		$zones[] = new WC_Shipping_Zone( 0 );
		return $zones;
	}

	/**
	 * Whether a zone (with enabled methods) applies to a country.
	 *
	 * Zone 0 (rest of the world) covers everything; state- and postcode-level zones count as
	 * covering their country.
	 *
	 * @param WC_Shipping_Zone $zone    Zone.
	 * @param string           $country ISO country code.
	 */
	private static function zone_covers_country( WC_Shipping_Zone $zone, string $country ): bool {
		if ( 0 === (int) $zone->get_id() ) {
			return true;
		}
		if ( '' === $country ) {
			return false;
		}
		$continent = function_exists( 'WC' ) ? (string) WC()->countries->get_continent_code_for_country( $country ) : '';
		foreach ( $zone->get_zone_locations() as $location ) {
			$code = (string) $location->code;
			switch ( $location->type ) {
				case 'country':
					if ( $code === $country ) {
						return true;
					}
					break;
				case 'state':
					if ( 0 === strpos( $code, $country . ':' ) ) {
						return true;
					}
					break;
				case 'continent':
					if ( '' !== $continent && $code === $continent ) {
						return true;
					}
					break;
			}
		}
		return false;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $code    Code.
	 * @param array<string, mixed> $details Details.
	 */
	public function render_message( string $code, array $details ): string {
		switch ( $code ) {
			case 'no_products':
				return __( 'You have no published products yet, so there is nothing to check.', 'x7velo' );
			case 'no_physical_products':
				return __( 'You only sell virtual products, so shipping doesn’t apply.', 'x7velo' );
			case 'shipping_ready':
				return __( 'You have shipping options set up for your own country.', 'x7velo' );
			case 'shipping_not_base_country':
				return __( 'You have shipping options, but none for your store’s own country. Check your shipping zones so assistants can tell local shoppers you deliver to them.', 'x7velo' );
			case 'no_shipping':
				return __( 'No shipping options are set up, so assistants can’t tell shoppers if you deliver to them. Add a shipping zone in WooCommerce → Settings → Shipping.', 'x7velo' );
		}
		return '';
	}
}
