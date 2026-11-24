<?php
/**
 * REST endpoint for plugin settings.
 *
 * @package X7velo
 */

namespace X7velo\Rest;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use X7velo\FixerStatus;
use X7velo\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * GET   /x7velo/v1/settings → full sanitised settings + computed `fixer_status`.
 * PATCH /x7velo/v1/settings → top-level merge (POST/PUT accepted). Unknown key or invalid value
 *                             → 400, nothing saved. Turning on an area whose state is `managed`
 *                             or `cannot_run` → 409 x7velo_fixer_unavailable. Turning off is
 *                             always allowed. Saving never runs the audit.
 *
 * Requires Capabilities::manage() (manage_woocommerce, or manage_options without WooCommerce); cookie auth needs the wp_rest nonce (checked by core).
 * Deliberately not exposed through register_setting()/wp/v2/settings (needs manage_options).
 */
final class SettingsController {

	public const ROUTE = '/settings';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
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
		$args = array();
		foreach ( Settings::schema() as $key => $schema ) {
			$args[ $key ] = $schema + array(
				'required'          => false,
				'validate_callback' => 'rest_validate_request_arg',
			);
		}

		register_rest_route(
			AuditController::REST_NAMESPACE,
			self::ROUTE,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => $args,
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Same permission as the audit routes.
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
	 * Returns settings + fixer status.
	 *
	 * @param WP_REST_Request $request Request (unused).
	 */
	public function get_item( WP_REST_Request $request ): WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- REST callback signature.
		return $this->response( $this->settings->all() );
	}

	/**
	 * Applies a partial update.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( WP_REST_Request $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = $request->get_body_params();
		}
		$allowed = array_keys( Settings::defaults() );
		$unknown = array_diff( array_keys( $body ), $allowed );
		if ( $unknown ) {
			return new WP_Error(
				'rest_invalid_param',
				sprintf(
					/* translators: %s: comma-separated parameter names. */
					_n( 'Unknown parameter: %s', 'Unknown parameters: %s', count( $unknown ), 'x7velo' ),
					implode( ', ', array_map( 'sanitize_key', $unknown ) )
				),
				array( 'status' => 400 )
			);
		}

		$patch = array();
		foreach ( $allowed as $key ) {
			if ( array_key_exists( $key, $body ) ) {
				$patch[ $key ] = $request->get_param( $key ); // Validated/sanitised against the schema by core.
			}
		}

		$current = $this->settings->all();
		$merged  = Settings::validate( array_merge( $current, $patch ) );
		if ( is_wp_error( $merged ) ) {
			return $merged;
		}

		// Store-only fixers can't be turned on without WooCommerce (spec §15.3); off is allowed.
		$areas = FixerStatus::areas();
		foreach ( FixerStatus::STORE_AREAS as $area ) {
			if ( isset( $areas[ $area ] ) ) {
				continue;
			}
			foreach ( FixerStatus::AREA_KEYS[ $area ] as $key ) {
				if ( ! empty( $patch[ $key ] ) ) {
					return new WP_Error(
						'x7velo_fixer_unavailable',
						__( 'This fix can’t be turned on right now. Check the Fixes tab for why.', 'x7velo' ),
						array(
							'status' => 409,
							'area'   => $area,
							'state'  => 'cannot_run',
							'reason' => FixerStatus::REASON_WOOCOMMERCE_INACTIVE,
						)
					);
				}
			}
		}

		// Turning any of an area's options on is refused while it is managed elsewhere or cannot run.
		$status = FixerStatus::compute( $current );
		foreach ( array_keys( $areas ) as $area ) {
			$turning_on = false;
			foreach ( FixerStatus::AREA_KEYS[ $area ] as $key ) {
				$turning_on = $turning_on || ( empty( $current[ $key ] ) && ! empty( $merged[ $key ] ) );
			}
			if ( $turning_on && in_array( $status[ $area ]['state'], array( 'managed', 'cannot_run' ), true ) ) {
				return new WP_Error(
					'x7velo_fixer_unavailable',
					__( 'This fix can’t be turned on right now. Check the Fixes tab for why.', 'x7velo' ),
					array(
						'status' => 409,
						'area'   => $area,
						'state'  => $status[ $area ]['state'],
					)
				);
			}
		}

		$this->settings->update( $merged );
		return $this->response( $this->settings->all() );
	}

	/**
	 * Item schema (for OPTIONS / discovery).
	 *
	 * @return array<string, mixed>
	 */
	public function get_public_item_schema(): array {
		return array(
			'$schema'              => 'http://json-schema.org/draft-04/schema#',
			'title'                => 'x7velo-settings',
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => Settings::schema() + array(
				'fixer_status' => array(
					'type'     => 'object',
					'readonly' => true,
				),
			),
		);
	}

	/**
	 * Settings + computed fixer status.
	 *
	 * @param array<string, mixed> $settings Sanitised settings.
	 */
	private function response( array $settings ): WP_REST_Response {
		return rest_ensure_response( $settings + array( 'fixer_status' => FixerStatus::compute( $settings ) ) );
	}
}
