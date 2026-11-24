<?php
/**
 * Uninstall handler: runs when the plugin is deleted from wp-admin.
 *
 * Deletes every x7velo option on every site (multisite included). Deactivation keeps data.
 * Keep this list in sync with what the plugin writes (see docs/specs/m1-storage-review.md §10):
 * X7velo\Settings::OPTION, X7velo\Audit\AuditStore::OPTION, X7velo\Plugin::VERSION_OPTION,
 * x7velo_notice_state (conflict notices, spec §8), x7velo_schema_sample (detection), transients
 * x7velo_llms_txt and x7velo_rewrite_sync (llms.txt fixer), user meta x7velo_dismissed_notices,
 * and the llms.txt rewrite rule (stored rules dropped when they contain it).
 * The audit lock is a MySQL named lock (nothing stored).
 *
 * Each site's options table is cleaned with prepared queries, without switch_to_blog() (which
 * would load every site's autoloaded options), and that site's option caches are dropped after
 * (v2.3 N3). Sites are read in pages, so every query stays bounded.
 *
 * @package X7velo
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! function_exists( 'x7velo_uninstall_blog' ) ) {
	/**
	 * Deletes x7velo data from one site's options table.
	 *
	 * @param int $blog_id Blog ID (the current blog on single site).
	 */
	function x7velo_uninstall_blog( int $blog_id ): void {
		global $wpdb;
		$table = $wpdb->get_blog_prefix( $blog_id ) . 'options';
		$names = array(
			'x7velo_settings',
			'x7velo_last_audit',
			'x7velo_version',
			'x7velo_notice_state',
			'x7velo_schema_sample',
			'_transient_x7velo_llms_txt',
			'_transient_timeout_x7velo_llms_txt',
			'_transient_x7velo_rewrite_sync',
			'_transient_timeout_x7velo_rewrite_sync',
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall; caches are dropped below.
		foreach ( $names as $name ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s', $table, $name ) );
		}

		// Our llms.txt rewrite rule, if still stored: drop the stored rules so core regenerates
		// them without it on the site's next request (no flush from another site's context).
		$raw   = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $table, 'rewrite_rules' ) );
		$rules = is_string( $raw ) ? maybe_unserialize( $raw ) : null;
		if ( is_array( $rules ) && isset( $rules['^llms\.txt$'] ) && 'index.php?x7velo_llms_txt=1' === $rules['^llms\.txt$'] ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s', $table, 'rewrite_rules' ) );
			$names[] = 'rewrite_rules';
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$current = get_current_blog_id();
		$switch  = $blog_id !== $current && function_exists( 'wp_cache_switch_to_blog' );
		if ( $switch ) {
			wp_cache_switch_to_blog( $blog_id );
		}
		foreach ( array_merge( $names, array( 'alloptions', 'notoptions' ) ) as $key ) {
			wp_cache_delete( $key, 'options' );
		}
		wp_cache_delete( 'x7velo_llms_txt', 'transient' );
		wp_cache_delete( 'x7velo_rewrite_sync', 'transient' );
		if ( $switch ) {
			wp_cache_switch_to_blog( $current );
		}
	}
}

// Notice dismissals (user meta is network-wide).
delete_metadata( 'user', 0, 'x7velo_dismissed_notices', '', true );

if ( ! is_multisite() ) {
	x7velo_uninstall_blog( get_current_blog_id() );
} else {
	$x7velo_page = 0;
	do {
		$x7velo_sites = get_sites(
			array(
				'fields' => 'ids',
				'number' => 100,
				'offset' => 100 * $x7velo_page,
			)
		);
		foreach ( $x7velo_sites as $x7velo_site_id ) {
			x7velo_uninstall_blog( (int) $x7velo_site_id );
		}
		++$x7velo_page;
		$x7velo_count = count( $x7velo_sites );
	} while ( 100 === $x7velo_count );
}
