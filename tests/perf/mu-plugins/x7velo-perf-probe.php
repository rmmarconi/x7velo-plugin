<?php
/**
 * Plugin Name: x7velo perf probe (test only)
 * Description: One JSON line per x7velo audit REST request in wp-content/x7velo-perf.log (test plan §4.4). Perf env only.
 *
 * @package X7velo
 */

add_action(
	'shutdown',
	static function () {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( false === strpos( $uri, 'x7velo/v1/audit' ) || ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			return;
		}
		$line = array(
			'route'        => 'POST /x7velo/v1/audit',
			'wall_s'       => round( microtime( true ) - ( isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime( true ) ), 3 ),
			'peak_mb'      => round( memory_get_peak_usage( true ) / 1048576, 1 ),
			'end_mb'       => round( memory_get_usage( true ) / 1048576, 1 ),
			'memory_limit' => (string) ini_get( 'memory_limit' ),
			'php'          => PHP_VERSION,
			'status'       => http_response_code(),
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( WP_CONTENT_DIR . '/x7velo-perf.log', wp_json_encode( $line ) . "\n", FILE_APPEND | LOCK_EX );
	},
	PHP_INT_MAX
);
