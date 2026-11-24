<?php
/**
 * Yoast WooCommerce SEO (paid add-on) adapter, best effort.
 *
 * @package X7velo
 */

namespace X7velo\Detection\Adapters;

defined( 'ABSPATH' ) || exit;

/**
 * Yoast WooCommerce SEO. UNVERIFIED (no licence to test): the detection constant/class come
 * from vendor docs, and whether the add-on removes Woo core's Product output is unknown, so
 * removes_woo_product() is false and a still-hooked Woo output is reported as a duplicate by
 * SCHEMA-01 (spec §5, §7.3).
 */
final class YoastWooSeo extends BaseAdapter {

	public const ID = 'yoast-woocommerce-seo';

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
		return 'Yoast WooCommerce SEO';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_active(): bool {
		return $this->env->defined( 'WPSEO_WOO_VERSION' ) || $this->env->class_exists( 'Yoast_WooCommerce_SEO' );
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
		return 'schema' === $area ? 'admin.php?page=wpseo_woo' : '';
	}
}
