<?php
/**
 * Outbound-HTTP sentinel (spec AC 88, QA gate R12). Test-only: never shipped (tests/ is in
 * .distignore) and never loaded by the plugin itself.
 *
 * Hooks `pre_http_request` first (PHP_INT_MIN) and walks the backtrace. A request is "ours" when
 * any frame's file is inside the x7velo plugin directory but not inside its tests/ or vendor/
 * directory (a test may call wp_remote_get() on purpose, and PHPUnit itself lives in vendor/ on
 * dev installs; the release zip's vendor/ is Composer's autoloader only). Plugin code may not.
 * Ours are blocked with a WP_Error and recorded; everything else (core update checks, WooCommerce,
 * Yoast…) passes through untouched, because AC 88 is about x7velo, not the rest of the stack.
 *
 * Two consumers:
 * - e2e (wp-env mu-plugin `tests/e2e/env/mu-plugins/x7velo-e2e-sentinel.php`): one JSON line per
 *   request appended to the log file; `tests/e2e/run.sh` fails when the file is not empty.
 * - PHPUnit (`tests/bootstrap.php`): same log file, plus a shutdown check that prints the
 *   requests and turns the exit code into 1 even if every test passed (the audit runner catches
 *   Throwable per check, so throwing from here could be swallowed).
 *
 * @package X7velo
 */

if ( ! function_exists( 'x7velo_http_sentinel_register' ) ) {

	/**
	 * Requests attributed to x7velo in this process.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	function &x7velo_http_sentinel_hits(): array {
		static $hits = array();
		return $hits;
	}

	/**
	 * Installs the sentinel.
	 *
	 * @param string $log_file   File to append JSON lines to ('' = in-memory only).
	 * @param string $plugin_dir Absolute x7velo plugin directory (no trailing slash).
	 */
	function x7velo_http_sentinel_register( string $log_file, string $plugin_dir ): void {
		$plugin_dir = rtrim( str_replace( '\\', '/', $plugin_dir ), '/' ) . '/';
		$excluded   = array( $plugin_dir . 'tests/', $plugin_dir . 'vendor/' );

		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( $log_file, $plugin_dir, $excluded ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Test-only attribution.
				$trace  = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 80 );
				$frames = array();
				foreach ( $trace as $frame ) {
					$file = isset( $frame['file'] ) ? str_replace( '\\', '/', (string) $frame['file'] ) : '';
					if ( '' === $file || 0 !== strpos( $file, $plugin_dir ) || 0 === strpos( $file, $excluded[0] ) || 0 === strpos( $file, $excluded[1] ) ) {
						continue;
					}
					$frames[] = substr( $file, strlen( $plugin_dir ) ) . ':' . ( $frame['line'] ?? 0 );
				}
				if ( array() === $frames ) {
					return $pre;
				}

				$hit    = array(
					'time'   => gmdate( 'c' ),
					'url'    => (string) $url,
					'method' => is_array( $args ) && isset( $args['method'] ) ? (string) $args['method'] : 'GET',
					'frames' => array_slice( $frames, 0, 10 ),
				);
				$hits   = &x7velo_http_sentinel_hits();
				$hits[] = $hit;
				if ( '' !== $log_file ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test-only log.
					file_put_contents( $log_file, wp_json_encode( $hit ) . "\n", FILE_APPEND | LOCK_EX );
				}
				return new WP_Error( 'x7velo_http_sentinel', 'Outbound HTTP from x7velo is not allowed (AC 88).' );
			},
			PHP_INT_MIN,
			3
		);
	}
}
