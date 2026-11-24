<?php
/**
 * PHPUnit bootstrap for the WordPress test suite.
 *
 * Under wp-env, WP_TESTS_DIR points at the bundled test library. Elsewhere it
 * falls back to the wp-phpunit/wp-phpunit Composer package, which needs
 * WP_PHPUNIT__TESTS_CONFIG to point at a wp-tests-config.php.
 *
 * @package X7velo
 */

$x7velo_root = dirname( __DIR__ );

if ( ! is_readable( $x7velo_root . '/vendor/autoload.php' ) ) {
	echo 'Run "composer install" in the plugin directory first.' . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

// Loads the plugin's classes and the Yoast PHPUnit Polyfills required by the WP test suite.
require_once $x7velo_root . '/vendor/autoload.php';

$x7velo_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $x7velo_tests_dir ) {
	$x7velo_tests_dir = $x7velo_root . '/vendor/wp-phpunit/wp-phpunit';
}

if ( ! is_readable( $x7velo_tests_dir . '/includes/functions.php' ) ) {
	echo 'Could not find the WordPress test library in ' . $x7velo_tests_dir . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

require_once $x7velo_tests_dir . '/includes/functions.php';

/*
 * G9 log hygiene (QA gate R11): with X7VELO_G9_LOG set, every PHP notice, warning and deprecation
 * goes to that file. Use with tests/g9/phpunit.xml, which stops PHPUnit from converting them to
 * exceptions (an exception inside an audit check is caught by the runner and would hide the
 * notice); tests/g9/scan-debug-log.sh then fails on any line from x7velo. Set again after the WP
 * bootstrap, which may point error_log at wp-content/debug.log.
 */
$x7velo_g9_log = (string) getenv( 'X7VELO_G9_LOG' );
$x7velo_g9     = static function () use ( $x7velo_g9_log ): void {
	if ( '' === $x7velo_g9_log ) {
		return;
	}
	// phpcs:disable WordPress.PHP.IniSet, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting, WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting -- Test-only logging.
	error_reporting( E_ALL );
	ini_set( 'log_errors', '1' );
	ini_set( 'display_errors', '0' );
	ini_set( 'error_log', $x7velo_g9_log );
	// phpcs:enable
};
$x7velo_g9();

// Outbound-HTTP sentinel (AC 88, R12): any request made from x7velo code fails the run.
require_once __DIR__ . '/support/http-sentinel.php';
tests_add_filter(
	'muplugins_loaded',
	static function () use ( $x7velo_root ): void {
		x7velo_http_sentinel_register( (string) getenv( 'X7VELO_HTTP_SENTINEL_LOG' ), $x7velo_root );
	},
	1
);
register_shutdown_function(
	static function (): void {
		$hits = x7velo_http_sentinel_hits();
		if ( array() === $hits ) {
			return;
		}
		fwrite( STDERR, PHP_EOL . 'x7velo HTTP sentinel (AC 88): x7velo code attempted ' . count( $hits ) . ' outbound request(s):' . PHP_EOL ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		foreach ( $hits as $hit ) {
			fwrite( STDERR, '  ' . $hit['method'] . ' ' . $hit['url'] . ' from ' . implode( ' < ', $hit['frames'] ) . PHP_EOL ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		}
		exit( 1 );
	}
);

// WooCommerce, when present next to the plugin (wp-env maps it to wp-content/plugins/woocommerce).
$x7velo_wc_file = getenv( 'X7VELO_WC_FILE' );
if ( ! $x7velo_wc_file ) {
	$x7velo_wc_file = dirname( $x7velo_root ) . '/woocommerce/woocommerce.php';
}
$x7velo_load_wc = is_readable( $x7velo_wc_file ) && 'no' !== getenv( 'X7VELO_LOAD_WC' );

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $x7velo_root, $x7velo_wc_file, $x7velo_load_wc ): void {
		if ( $x7velo_load_wc ) {
			require $x7velo_wc_file;
		}
		require $x7velo_root . '/x7velo.php';
	}
);

if ( $x7velo_load_wc ) {
	// Same approach as WooCommerce's own test bootstrap: create tables, roles and default terms.
	tests_add_filter(
		'setup_theme',
		static function (): void {
			WC_Install::install();
			// Reload capabilities so the shop_manager role created above is usable.
			$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test bootstrap.
			wp_roles();
		}
	);
}

require $x7velo_tests_dir . '/includes/bootstrap.php';

$x7velo_g9();
