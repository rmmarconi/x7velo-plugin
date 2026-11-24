<?php
/**
 * Smoke tests for the plugin bootstrap.
 *
 * @package X7velo
 */

namespace X7velo\Tests;

use WP_UnitTestCase;
use X7velo\Plugin;

/**
 * Smoke tests for X7velo\Plugin.
 */
final class PluginTest extends WP_UnitTestCase {

	/**
	 * The plugin file was loaded by the test bootstrap.
	 */
	public function test_plugin_is_loaded(): void {
		$this->assertTrue( defined( 'X7VELO_VERSION' ) );
		$this->assertTrue( class_exists( Plugin::class ) );
		$this->assertSame( X7VELO_VERSION, ( new Plugin( X7VELO_PLUGIN_FILE, X7VELO_VERSION ) )->get_version() );
	}

	/**
	 * HPOS compatibility is declared on before_woocommerce_init.
	 */
	public function test_hpos_compatibility_hook_is_registered(): void {
		$plugin = new Plugin( X7VELO_PLUGIN_FILE, X7VELO_VERSION );
		$plugin->register();

		$this->assertSame( 10, has_action( 'before_woocommerce_init', array( $plugin, 'declare_wc_compatibility' ) ) );
	}

	/**
	 * Bundled translations are registered on `init`, never earlier (WP 6.7+ warns about
	 * translations loaded before it), from the plugin's languages/ folder.
	 */
	public function test_textdomain_is_registered_on_init_from_languages_dir(): void {
		$plugin = new Plugin( X7VELO_PLUGIN_FILE, X7VELO_VERSION );
		$plugin->register();

		$this->assertSame( 10, has_action( 'init', array( $plugin, 'load_textdomain' ) ) );
		$this->assertFalse( has_action( 'plugins_loaded', array( $plugin, 'load_textdomain' ) ) );

		$plugin->load_textdomain();

		// Core keeps the path in a protected property (no getter); reflection is readable on PHP 8.1+.
		global $wp_textdomain_registry;
		$paths = ( new \ReflectionProperty( $wp_textdomain_registry, 'custom_paths' ) )->getValue( $wp_textdomain_registry );
		$this->assertArrayHasKey( 'x7velo', $paths );
		$this->assertSame(
			untrailingslashit( WP_PLUGIN_DIR . '/' . dirname( plugin_basename( X7VELO_PLUGIN_FILE ) ) . '/languages' ),
			untrailingslashit( $paths['x7velo'] )
		);
	}

	/**
	 * The plugin header points WordPress at the bundled languages/ folder, which holds the POT.
	 */
	public function test_header_declares_text_domain_and_domain_path(): void {
		$data = get_file_data(
			X7VELO_PLUGIN_FILE,
			array(
				'domain' => 'Text Domain',
				'path'   => 'Domain Path',
			)
		);
		$this->assertSame( 'x7velo', $data['domain'] );
		$this->assertSame( '/languages', $data['path'] );
		$this->assertFileExists( dirname( X7VELO_PLUGIN_FILE ) . '/languages/x7velo.pot' );
	}

	/**
	 * Declaring compatibility must not fatal when WooCommerce is absent.
	 */
	public function test_declare_wc_compatibility_is_safe_without_woocommerce(): void {
		if ( class_exists( 'Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			$this->markTestSkipped( 'WooCommerce is loaded in this test run.' );
		}

		( new Plugin( X7VELO_PLUGIN_FILE, X7VELO_VERSION ) )->declare_wc_compatibility();

		$this->addToAssertionCount( 1 );
	}

	/**
	 * The plugin boots fully in both modes (spec §15, AC 104); the store fixers only with
	 * WooCommerce; the mode follows WooCommerce on every request.
	 */
	public function test_boots_in_both_modes(): void {
		$this->assertSame( class_exists( 'WooCommerce' ), Plugin::is_woocommerce_active() );
		$this->assertNotFalse( has_action( 'rest_api_init' ) );
		$this->assertNotFalse( has_action( 'admin_menu', array( \X7velo\Admin\AdminPage::class, 'add_menu' ) ) );
		$this->assertNotFalse( has_filter( 'x7velo_audit_checks', array( \X7velo\Detection\Module::class, 'add_checks' ) ) );
		$this->assertSame( class_exists( 'WooCommerce' ), false !== has_filter( 'x7velo_fixer_status', array( \X7velo\Fixers\JsonLd\JsonLdStatus::class, 'filter' ) ) );
		$this->assertSame( class_exists( 'WooCommerce' ) ? 'manage_woocommerce' : 'manage_options', \X7velo\Capabilities::manage() );
	}
}
