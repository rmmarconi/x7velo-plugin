<?php
/**
 * WooCommerce core adapter.
 *
 * @package X7velo
 */

namespace X7velo\Detection\Adapters;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce core: the default Product emitter (WC_Structured_Data). The Detector decides
 * whether another adapter removes its output; this adapter only states the default.
 */
final class WooCore extends BaseAdapter {

	public const ID = 'woocommerce';

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'WooCommerce';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_active(): bool {
		return $this->env->class_exists( 'WooCommerce' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function emits_product(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $area Area.
	 */
	public function settings_path( string $area ): string {
		return 'site_visibility' === $area ? 'admin.php?page=wc-settings&tab=site-visibility' : '';
	}
}
