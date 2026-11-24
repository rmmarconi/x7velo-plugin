<?php
/**
 * REST endpoint for the local audit.
 *
 * @package X7velo
 */

namespace X7velo\Rest;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use X7velo\Audit\AuditRunningException;
use X7velo\Audit\AuditService;

defined( 'ABSPATH' ) || exit;

/**
 * GET  /x7velo/v1/audit  → last stored audit (404 if none yet).
 * POST /x7velo/v1/audit  → run the audit now and return it (409 if one is already running).
 *
 * Both require Capabilities::manage() (manage_woocommerce, or manage_options without WooCommerce). Cookie-authenticated requests must carry the
 * `X-WP-Nonce` (wp_rest) header; WordPress core verifies it before the permission callback.
 */
final class AuditController {

	public const REST_NAMESPACE = 'x7velo/v1';
	public const ROUTE          = '/audit';

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
	 * Hooks route registration.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(),
				),
			)
		);
	}

	/**
	 * Only store managers and administrators may read or run the audit.
	 *
	 * @return true|WP_Error
	 */
	public function permissions_check() {
		if ( \X7velo\Capabilities::current_user_can_manage() ) {
			return true;
		}
		return new WP_Error(
			'rest_forbidden',
			__( 'Sorry, you are not allowed to do that.', 'x7velo' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Returns the last audit.
	 *
	 * @param WP_REST_Request $request Request (unused; no parameters).
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- REST callback signature.
		$record = $this->service->last();
		if ( null === $record ) {
			return new WP_Error(
				'x7velo_no_audit',
				__( 'The audit has not run yet.', 'x7velo' ),
				array( 'status' => 404 )
			);
		}
		return rest_ensure_response( $this->service->present( $record ) );
	}

	/**
	 * Runs the audit and returns the new result.
	 *
	 * @param WP_REST_Request $request Request (unused; no parameters).
	 * @return WP_REST_Response|WP_Error 409 x7velo_audit_running when another run holds the lock.
	 */
	public function create_item( WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- REST callback signature.
		try {
			$record = $this->service->run();
		} catch ( AuditRunningException $e ) {
			return new WP_Error(
				'x7velo_audit_running',
				__( 'An audit is already running. Please wait a moment and try again.', 'x7velo' ),
				array( 'status' => 409 )
			);
		}
		return rest_ensure_response( $this->service->present( $record ) );
	}
}
