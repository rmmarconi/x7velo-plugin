<?php
/**
 * Plugin bootstrap.
 *
 * @package X7velo
 */

namespace X7velo;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use X7velo\Audit\AuditService;
use X7velo\Audit\AuditStore;
use X7velo\Cli\AuditCommand;
use X7velo\Rest\AuditController;
use X7velo\Rest\SettingsController;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin into WordPress and WooCommerce.
 *
 * Instantiated once from the main plugin file; no singleton.
 */
final class Plugin {

	/**
	 * Option holding the installed plugin version (autoload on; read on every load).
	 */
	public const VERSION_OPTION = 'x7velo_version';

	/**
	 * Absolute path to the main plugin file.
	 *
	 * @var string
	 */
	private string $file;

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	private string $version;

	/**
	 * Constructor.
	 *
	 * @param string $file    Absolute path to the main plugin file.
	 * @param string $version Plugin version.
	 */
	public function __construct( string $file, string $version ) {
		$this->file    = $file;
		$this->version = $version;
	}

	/**
	 * Registers the hooks the plugin needs at load time.
	 */
	public function register(): void {
		add_action( 'before_woocommerce_init', array( $this, 'declare_wc_compatibility' ) );
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'plugins_loaded', array( $this, 'boot' ) );
	}

	/**
	 * Registers the bundled translations in languages/ (on `init`, never earlier: WP 6.7+ warns
	 * about translations loaded before it).
	 *
	 * Language packs from translate.wordpress.org (wp-content/languages/plugins) take precedence
	 * over the bundled files when both exist.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'x7velo', false, dirname( plugin_basename( $this->file ) ) . '/languages' );
	}

	/**
	 * Declares compatibility with WooCommerce features (HPOS).
	 */
	public function declare_wc_compatibility(): void {
		if ( class_exists( FeaturesUtil::class ) ) {
			FeaturesUtil::declare_compatibility( 'custom_order_tables', $this->file, true );
		}
	}

	/**
	 * Boots plugin modules once all plugins are loaded.
	 *
	 * Works with or without WooCommerce (spec §15, ADR-0005). The store-only modules (JSON-LD and
	 * feed fixers) are registered only while WooCommerce is active, decided on every request.
	 */
	public function boot(): void {
		$this->maybe_upgrade();

		$settings = new Settings();
		$audit    = new AuditService( new AuditStore(), $settings, $this->version );
		( new AuditController( $audit ) )->register();
		Admin\AdminPage::register( $audit );
		( new SettingsController( $settings ) )->register();
		\X7velo\Detection\Module::register();
		\X7velo\Fixers\CrawlModule::register();
		if ( self::is_woocommerce_active() ) {
			\X7velo\Fixers\ProductModule::register( $settings );
		}

		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'x7velo', new AuditCommand( $audit ) );
		}
	}

	/**
	 * Whether WooCommerce is active for this request ("store" mode). Never stored.
	 *
	 * The `x7velo_woocommerce_active` filter can only turn store mode off (it is ignored when
	 * WooCommerce isn't loaded); it lets tests and support exercise site mode on a store.
	 */
	public static function is_woocommerce_active(): bool {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return false;
		}
		/**
		 * Filters whether x7velo treats WooCommerce as active. Returning false makes x7velo behave
		 * as on a site without WooCommerce; it can't turn store mode on without WooCommerce.
		 *
		 * @param bool $active Default true (WooCommerce is loaded).
		 */
		return false !== apply_filters( 'x7velo_woocommerce_active', true );
	}

	/**
	 * Site kind recorded in check details: `store` with WooCommerce, `site` without (spec §15.2).
	 */
	public static function site_kind(): string {
		return self::is_woocommerce_active() ? 'store' : 'site';
	}

	/**
	 * Returns the plugin version.
	 */
	public function get_version(): string {
		return $this->version;
	}

	/**
	 * Returns the absolute path to the main plugin file.
	 */
	public function get_file(): string {
		return $this->file;
	}

	/**
	 * Runs idempotent install/upgrade steps when the stored version differs from the code.
	 *
	 * Activation hooks don't run on plugin updates, so this runs on every load (one autoloaded
	 * option read). Steps must be ordered and idempotent; renamed/removed settings keys need a
	 * step here because Settings::sanitize() drops unknown keys.
	 */
	public function maybe_upgrade(): void {
		if ( get_option( self::VERSION_OPTION ) === $this->version ) {
			return;
		}
		self::install( $this->version );
	}

	/**
	 * Install steps shared by activation and upgrade. Writes defaults only; nothing is applied.
	 *
	 * @param string $version Plugin version to record.
	 */
	private static function install( string $version ): void {
		Settings::add_defaults();
		( new AuditStore() )->discard_incompatible();
		update_option( self::VERSION_OPTION, $version, true );
	}

	/**
	 * Activation hook callback.
	 */
	public static function activate(): void {
		self::install( defined( 'X7VELO_VERSION' ) ? X7VELO_VERSION : '0' );
		\X7velo\Fixers\CrawlModule::activate();
	}

	/**
	 * Deactivation hook callback.
	 *
	 * @param bool|mixed $network_wide Network deactivation (multisite).
	 */
	public static function deactivate( $network_wide = false ): void {
		\X7velo\Fixers\CrawlModule::deactivate( true === $network_wide || '1' === (string) $network_wide );
	}
}
