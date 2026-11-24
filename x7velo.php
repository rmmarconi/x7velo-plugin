<?php
/**
 * Plugin Name:          x7velo
 * Description:          Shoppers ask AI what to buy. See how easily AI assistants can read your store or site, and fix common problems. Free, private, no account.
 * Version:              1.0.0
 * Requires at least:    6.6
 * Requires PHP:         8.1
 * Author:               x7velo
 * Author URI:           https://x7velo.com
 * License:              GPLv2 or later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          x7velo
 * Domain Path:          /languages
 * WC requires at least: 9.6
 *
 * @package X7velo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'X7VELO_VERSION', '1.0.0' );
define( 'X7VELO_PLUGIN_FILE', __FILE__ );

if ( is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
} else {
	// Fallback PSR-4 autoloader so a build without vendor/ never fatals.
	spl_autoload_register(
		static function ( string $class_name ): void {
			$prefix = 'X7velo\\';
			if ( 0 !== strpos( $class_name, $prefix ) ) {
				return;
			}
			$relative = substr( $class_name, strlen( $prefix ) );
			$path     = __DIR__ . '/src/' . str_replace( '\\', '/', $relative ) . '.php';
			if ( is_readable( $path ) ) {
				require_once $path;
			}
		}
	);
}

register_activation_hook( __FILE__, array( X7velo\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( X7velo\Plugin::class, 'deactivate' ) );

( new X7velo\Plugin( __FILE__, X7VELO_VERSION ) )->register();
