<?php
/**
 * Runs one audit in-process (the WP-CLI path) and prints a JSON line: wall time, peak and
 * retained memory, effective memory_limit, record state and score.
 *
 * Usage: wp eval-file wp-content/plugins/x7velo/tests/perf/time-audit.php
 *
 * @package X7velo
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Test script.

use X7velo\Audit\AuditService;
use X7velo\Audit\AuditStore;
use X7velo\Settings;

$service = new AuditService( new AuditStore(), new Settings(), X7VELO_VERSION );
$before  = memory_get_usage();
$t0      = microtime( true );
$record  = $service->run();
$wall    = microtime( true ) - $t0;

echo wp_json_encode(
	array(
		'path'         => 'wp-cli',
		'wall_s'       => round( $wall, 3 ),
		'peak_mb'      => round( memory_get_peak_usage( true ) / 1048576, 1 ),
		'retained_mb'  => round( ( memory_get_usage() - $before ) / 1048576, 2 ),
		'memory_limit' => (string) ini_get( 'memory_limit' ),
		'php'          => PHP_VERSION,
		'state'        => $record['state'] ?? null,
		'score'        => $record['score'] ?? null,
	)
) . "\n";
