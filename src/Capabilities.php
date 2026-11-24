<?php
/**
 * Where the AI Readiness page lives and who may use it (spec §15.5).
 *
 * @package X7velo
 */

namespace X7velo;

defined( 'ABSPATH' ) || exit;

/**
 * The only place the capability and the page location are decided. Used by the menu, the REST
 * `permission_callback`s, the notice-dismiss ajax, the Plugins-screen row action and notices.
 *
 * | | WooCommerce active | WooCommerce inactive |
 * |---|---|---|
 * | Menu | WooCommerce › AI Readiness | Tools › AI Readiness |
 * | Capability | manage_woocommerce | manage_options |
 *
 * WooCommerce leaves `manage_woocommerce` on roles after deactivation; it is deliberately ignored
 * while WooCommerce is inactive. Evaluated per request, never stored.
 */
final class Capabilities {

	public const SLUG = 'x7velo';

	public const STORE_CAPABILITY = 'manage_woocommerce';
	public const SITE_CAPABILITY  = 'manage_options';

	public const STORE_PARENT = 'woocommerce';
	public const SITE_PARENT  = 'tools.php';

	/**
	 * Capability needed to see the page and use its REST routes.
	 */
	public static function manage(): string {
		return Plugin::is_woocommerce_active() ? self::STORE_CAPABILITY : self::SITE_CAPABILITY;
	}

	/**
	 * Whether the current user may use AI Readiness.
	 */
	public static function current_user_can_manage(): bool {
		return current_user_can( self::manage() );
	}

	/**
	 * Parent menu slug for the page.
	 */
	public static function parent(): string {
		return Plugin::is_woocommerce_active() ? self::STORE_PARENT : self::SITE_PARENT;
	}

	/**
	 * URL of the page, optionally on a tab. Built with menu_page_url() once the menu is registered;
	 * before that (or for users without the menu) from the same parent rule.
	 *
	 * @param string $tab Tab name (overview|fixes|settings) or ''.
	 */
	public static function page_url( string $tab = '' ): string {
		$url = function_exists( 'menu_page_url' ) ? (string) menu_page_url( self::SLUG, false ) : '';
		// Outside a full admin request (REST, WP-CLI) WooCommerce's parent menu may be missing, and
		// menu_page_url() then builds `wp-admin/woocommerce?page=…`; only trust a *.php screen.
		if ( '' !== $url && ! str_ends_with( (string) wp_parse_url( $url, PHP_URL_PATH ), '.php' ) ) {
			$url = '';
		}
		if ( '' === $url ) {
			$url = Plugin::is_woocommerce_active()
				? admin_url( 'admin.php?page=' . self::SLUG )
				: admin_url( self::SITE_PARENT . '?page=' . self::SLUG );
		}
		$url = html_entity_decode( $url, ENT_QUOTES, 'UTF-8' );
		return '' === $tab ? $url : add_query_arg( 'tab', $tab, $url );
	}
}
