<?php
/**
 * REST route tests.
 *
 * @package X7velo
 */

namespace X7velo\Tests\Rest;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use X7velo\Audit\AuditStore;
use X7velo\Audit\RunLock;

/**
 * Permission and behaviour tests for x7velo/v1/audit.
 */
final class AuditControllerTest extends WP_UnitTestCase {

	/**
	 * REST server.
	 *
	 * @var WP_REST_Server
	 */
	private WP_REST_Server $server;

	/**
	 * Boots a REST server with our routes.
	 */
	public function set_up(): void {
		parent::set_up();
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is not loaded.' );
		}
		global $wp_rest_server;
		// Header-less server: cookie auth sends an X-WP-Nonce header, which PHPUnit output forbids.
		$wp_rest_server = new class() extends WP_REST_Server { // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test REST server.
			/**
			 * No-op.
			 *
			 * @param string $key   Header name.
			 * @param string $value Header value.
			 */
			public function send_header( $key, $value ) {}
		};
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init', $this->server );
	}

	/**
	 * Resets the REST server.
	 */
	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test REST server.
		parent::tear_down();
	}

	/**
	 * Dispatches a request as a user with a role.
	 *
	 * @param string      $method HTTP method.
	 * @param string|null $role   Role, or null for logged out.
	 */
	private function dispatch( string $method, ?string $role ): \WP_REST_Response {
		wp_set_current_user( $role ? self::factory()->user->create( array( 'role' => $role ) ) : 0 );
		return $this->server->dispatch( new WP_REST_Request( $method, '/x7velo/v1/audit' ) );
	}

	/**
	 * Route is registered for GET and POST.
	 */
	public function test_route_registered(): void {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/x7velo/v1/audit', $routes );
	}

	/**
	 * Logged-out users get 401, subscribers and customers 403, for both methods.
	 */
	public function test_forbidden_without_capability(): void {
		foreach ( array( 'GET', 'POST' ) as $method ) {
			$this->assertSame( 401, $this->dispatch( $method, null )->get_status(), $method );
			$this->assertSame( 403, $this->dispatch( $method, 'subscriber' )->get_status(), $method );
			$this->assertSame( 403, $this->dispatch( $method, 'customer' )->get_status(), $method );
		}
		$this->assertFalse( get_option( AuditStore::OPTION ), 'Forbidden POST must not run the audit.' );
	}

	/**
	 * Shop managers: GET before any run → 404; POST runs (200); GET then returns it (200).
	 */
	public function test_shop_manager_can_run_and_read(): void {
		$this->assertSame( 404, $this->dispatch( 'GET', 'shop_manager' )->get_status() );

		$post = $this->dispatch( 'POST', 'shop_manager' );
		$this->assertSame( 200, $post->get_status() );
		$data = $post->get_data();
		$this->assertCount( 18, $data['checks'] );
		$this->assertArrayHasKey( 'guided_url', $data['checks'][0] );

		$get = $this->dispatch( 'GET', 'shop_manager' );
		$this->assertSame( 200, $get->get_status() );
		$this->assertSame( $data['generated_at'], $get->get_data()['generated_at'] );
	}

	/**
	 * Administrators are allowed.
	 */
	public function test_administrator_allowed(): void {
		$this->assertSame( 200, $this->dispatch( 'POST', 'administrator' )->get_status() );
	}

	/**
	 * A second run while another connection holds the lock gets 409 and stores nothing.
	 */
	public function test_concurrent_run_gets_409(): void {
		$other = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$this->assertSame( '1', (string) $other->get_var( $other->prepare( 'SELECT GET_LOCK( %s, 0 )', RunLock::name() ) ) );

		try {
			$response = $this->dispatch( 'POST', 'shop_manager' );
			$this->assertSame( 409, $response->get_status() );
			$this->assertSame( 'x7velo_audit_running', $response->get_data()['code'] );
			$this->assertFalse( get_option( AuditStore::OPTION ) );
		} finally {
			$other->get_var( $other->prepare( 'SELECT RELEASE_LOCK( %s )', RunLock::name() ) );
			$other->close();
		}

		$this->assertSame( 200, $this->dispatch( 'POST', 'shop_manager' )->get_status(), 'Lock is free again.' );
	}

	/**
	 * Cookie-authenticated POST without the wp_rest nonce is treated as logged out (401).
	 */
	public function test_cookie_auth_without_nonce_is_401(): void {
		global $wp_rest_auth_cookie;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
		$wp_rest_auth_cookie = true;
		unset( $_SERVER['HTTP_X_WP_NONCE'], $_REQUEST['_wpnonce'] );

		try {
			$auth     = $this->server->check_authentication();
			$response = $this->server->dispatch( new WP_REST_Request( 'POST', '/x7velo/v1/audit' ) );
		} finally {
			$wp_rest_auth_cookie = false;
		}

		$this->assertTrue( true === $auth || null === $auth );
		$this->assertSame( 0, get_current_user_id() );
		$this->assertSame( 401, $response->get_status() );
		$this->assertFalse( get_option( AuditStore::OPTION ) );
	}

	/**
	 * Cookie-authenticated POST with a valid nonce works.
	 */
	public function test_cookie_auth_with_nonce_is_200(): void {
		global $wp_rest_auth_cookie;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
		$wp_rest_auth_cookie        = true;
		$_SERVER['HTTP_X_WP_NONCE'] = wp_create_nonce( 'wp_rest' );

		try {
			$this->server->check_authentication();
			$response = $this->server->dispatch( new WP_REST_Request( 'POST', '/x7velo/v1/audit' ) );
		} finally {
			$wp_rest_auth_cookie = false;
			unset( $_SERVER['HTTP_X_WP_NONCE'] );
		}

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * A stored v1 record reads as "no audit" (404).
	 */
	public function test_v1_record_is_404(): void {
		update_option(
			AuditStore::OPTION,
			array(
				'schema_version' => 1,
				'score'          => 50,
			),
			false
		);
		$response = $this->dispatch( 'GET', 'shop_manager' );
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'x7velo_no_audit', $response->get_data()['code'] );
	}
}
