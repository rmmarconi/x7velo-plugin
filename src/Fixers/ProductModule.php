<?php
/**
 * Wiring for the product fixers: JSON-LD (spec §8.1) and product feed (spec §8.3).
 *
 * @package X7velo
 */

namespace X7velo\Fixers;

use X7velo\Fixers\Feed\FeedEndpoint;
use X7velo\Fixers\JsonLd\JsonLdFixer;
use X7velo\Fixers\JsonLd\JsonLdStatus;
use X7velo\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Call ProductModule::register() once from Plugin::boot() (WooCommerce active).
 *
 * Registering changes no public output: both fixers are off until the owner turns them on, and
 * nothing is written on activation.
 */
final class ProductModule {

	/**
	 * Hooks both fixers and the JSON-LD part status.
	 *
	 * @param Settings|null $settings Settings (shared instance from Plugin::boot()).
	 */
	public static function register( ?Settings $settings = null ): void {
		$settings = $settings ?? new Settings();

		( new JsonLdFixer( $settings ) )->register();
		( new FeedEndpoint( $settings ) )->register();
		add_filter( 'x7velo_fixer_status', array( JsonLdStatus::class, 'filter' ), 20, 2 );
	}
}
