<?php
/**
 * Plugin Name: x7velo e2e outbound-HTTP sentinel (test only)
 * Description: Blocks and logs any HTTP request made from x7velo code (AC 88). Lives only in the e2e/perf wp-env.
 *
 * @package X7velo
 */

$x7velo_sentinel = WP_CONTENT_DIR . '/plugins/x7velo/tests/support/http-sentinel.php';
if ( is_readable( $x7velo_sentinel ) ) {
	require_once $x7velo_sentinel;
	x7velo_http_sentinel_register( WP_CONTENT_DIR . '/x7velo-http-sentinel.log', WP_CONTENT_DIR . '/plugins/x7velo' );
}
unset( $x7velo_sentinel );
