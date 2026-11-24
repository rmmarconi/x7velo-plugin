<?php
/**
 * WP-CLI command for the local audit.
 *
 * @package X7velo
 */

namespace X7velo\Cli;

use WP_CLI;
use X7velo\Audit\AuditRunningException;
use X7velo\Audit\AuditService;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the x7velo agent-readiness audit.
 */
final class AuditCommand {

	/**
	 * Audit service.
	 *
	 * @var AuditService
	 */
	private AuditService $service;

	/**
	 * Constructor.
	 *
	 * @param AuditService $service Audit service.
	 */
	public function __construct( AuditService $service ) {
		$this->service = $service;
	}

	/**
	 * Runs the local audit, stores the result and prints it.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp x7velo audit
	 *     wp x7velo audit --format=json
	 *
	 * @param string[]              $args       Positional arguments (unused).
	 * @param array<string, string> $assoc_args Associative arguments.
	 */
	public function audit( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- WP-CLI signature.
		$format = $assoc_args['format'] ?? 'table';
		try {
			$record = $this->service->present( $this->service->run() );
		} catch ( AuditRunningException $e ) {
			WP_CLI::error( __( 'An audit is already running for this site. Try again in a moment.', 'x7velo' ) );
			return;
		}

		if ( 'json' === $format ) {
			WP_CLI::line( (string) wp_json_encode( $record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			return;
		}

		$rows = array();
		foreach ( $record['checks'] as $check ) {
			$rows[] = array(
				'id'      => $check['id'],
				'status'  => $check['status'],
				'weight'  => $check['weight'],
				'message' => $check['message'],
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'status', 'weight', 'message' ) );

		if ( AuditService::STATE_NO_PRODUCTS === $record['state'] ) {
			WP_CLI::warning( __( 'No score: the store has no published products yet.', 'x7velo' ) );
			return;
		}
		if ( null === $record['score'] ) {
			WP_CLI::warning( __( 'Couldn’t complete the audit (too many checks failed to run). Run it again.', 'x7velo' ) );
			return;
		}
		$band = \X7velo\Audit\Scorer::band_label( (string) $record['band'] );
		$text = $record['capped']
			/* translators: 1: score 0–100, 2: band label, e.g. "Partly readable". */
			? __( 'Score: %1$d/100 (%2$s) — capped by a critical issue', 'x7velo' )
			/* translators: 1: score 0–100, 2: band label, e.g. "Partly readable". */
			: __( 'Score: %1$d/100 (%2$s)', 'x7velo' );
		WP_CLI::success( sprintf( $text, $record['score'], $band ) );
	}
}
