<?php
/**
 * Test-only mu-plugin for the WordPress.org screenshot site (plugin/.wordpress-org/README.md, "Re-shooting").
 * Never shipped (plugin/.distignore excludes /tests).
 *
 * - Trusts X-Forwarded-Proto from the local TLS proxy so the demo store runs on https.
 * - Blocks all outbound HTTP from web requests (WP-CLI may still install language packs).
 * - Keeps the admin chrome neutral: no update counts, no visibility badge, no third-party notices.
 *
 * @package X7velo
 */

if ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO'] ) {
	$_SERVER['HTTPS'] = 'on';
}

if ( ! defined( 'WP_CLI' ) ) {
	add_filter(
		'pre_http_request',
		static fn() => new WP_Error( 'x7velo_demo_offline', 'Outbound HTTP disabled on the screenshot site.' ),
		1
	);
}

// Report "nothing to update" so no counts show in the admin bar or menu.
$x7velo_demo_no_updates = static function () {
	return (object) array(
		'last_checked'    => time(),
		'updates'         => array(),
		'response'        => array(),
		'translations'    => array(),
		'version_checked' => get_bloginfo( 'version' ),
	);
};
add_filter( 'pre_site_transient_update_core', $x7velo_demo_no_updates );
add_filter( 'pre_site_transient_update_plugins', $x7velo_demo_no_updates );
add_filter( 'pre_site_transient_update_themes', $x7velo_demo_no_updates );

add_action(
	'admin_bar_menu',
	static function ( $bar ) {
		foreach ( array( 'updates', 'woocommerce-site-visibility-badge', 'comments' ) as $node ) {
			$bar->remove_node( $node );
		}
	},
	999
);

// Third-party admin notices (WooCommerce setup nags etc.). x7velo's own UI is in its React app.
add_action(
	'in_admin_header',
	static function () {
		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'all_admin_notices' );
	},
	999
);

add_filter( 'woocommerce_allow_marketplace_suggestions', '__return_false' );
add_filter( 'woocommerce_show_admin_notice', '__return_false' );
