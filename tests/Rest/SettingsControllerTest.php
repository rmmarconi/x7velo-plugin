<?php
/**
 * Settings REST route tests.
 *
 * @package X7velo
 */

namespace X7velo\Tests\Rest;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use X7velo\Settings;

/**
 * Tests for x7velo/v1/settings.
 */
final class SettingsControllerTest extends WP_UnitTestCase {

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
		$wp_rest_server = new WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test REST server.
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
	 * Dispatches a request.
	 *
	 * @param string                    $method HTTP method.
	 * @param string|null               $role   Role, or null for logged out.
	 * @param array<string, mixed>|null $body   JSON body.
	 */
	private function call( string $method, ?string $role, ?array $body = null ): \WP_REST_Response {
		wp_set_current_user( $role ? self::factory()->user->create( array( 'role' => $role ) ) : 0 );
		$request = new WP_REST_Request( $method, '/x7velo/v1/settings' );
		if ( null !== $body ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		return $this->server->dispatch( $request );
	}

	/**
	 * Permissions: 401 logged out, 403 subscriber, for GET and PATCH.
	 */
	public function test_permissions(): void {
		foreach ( array( 'GET', 'PATCH' ) as $method ) {
			$this->assertSame( 401, $this->call( $method, null, array( 'fix_feed' => true ) )->get_status() );
			$this->assertSame( 403, $this->call( $method, 'subscriber', array( 'fix_feed' => true ) )->get_status() );
		}
		$this->assertFalse( ( new Settings() )->get( 'fix_feed' ) );
	}

	/**
	 * GET returns every setting plus computed fixer_status.
	 */
	public function test_get(): void {
		$response = $this->call( 'GET', 'shop_manager' );
		$data     = $response->get_data();
		$this->assertSame( 200, $response->get_status() );
		foreach ( array_keys( Settings::defaults() ) as $key ) {
			$this->assertArrayHasKey( $key, $data );
		}
		$this->assertSame(
			array(
				'state' => 'off',
				'mode'  => 'augment',
				'parts' => array(
					'returns'  => 'not_yet',
					'shipping' => 'not_yet',
				),
			),
			$data['fixer_status']['jsonld']
		);
	}

	/**
	 * PATCH merges only the given keys.
	 */
	public function test_patch_partial_merge(): void {
		( new Settings() )->update( array( 'own_brand_products' => true ) );
		$page = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);

		$response = $this->call(
			'PATCH',
			'shop_manager',
			array(
				'fix_feed'        => true,
				'returns_page_id' => $page,
				'return_policy'   => 'finite',
				'return_days'     => 30,
			)
		);
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['fix_feed'] );
		$this->assertTrue( $data['own_brand_products'], 'Untouched keys keep their value.' );
		$this->assertSame( $page, $data['returns_page_id'] );
		$this->assertSame( array( 'state' => 'on' ), $data['fixer_status']['feed'] );
		$this->assertTrue( ( new Settings() )->get( 'fix_feed' ) );
	}

	/**
	 * Delivery days: 0 is a valid same-day value, null clears, min > max is a 400 with a field error.
	 */
	public function test_patch_nullable_days(): void {
		$set = $this->call(
			'PATCH',
			'shop_manager',
			array(
				'delivery_days_min' => 0,
				'delivery_days_max' => 2,
			)
		);
		$this->assertSame( 200, $set->get_status() );
		$this->assertSame( 0, $set->get_data()['delivery_days_min'] );

		$bad = $this->call( 'PATCH', 'shop_manager', array( 'delivery_days_min' => 5 ) );
		$this->assertSame( 400, $bad->get_status() );
		$this->assertArrayHasKey( 'delivery_days_min', $bad->get_data()['data']['params'] );

		$cleared = $this->call(
			'PATCH',
			'shop_manager',
			array(
				'delivery_days_min' => null,
				'delivery_days_max' => null,
			)
		);
		$this->assertSame( 200, $cleared->get_status() );
		$this->assertNull( $cleared->get_data()['delivery_days_min'] );
		$this->assertNull( ( new Settings() )->get( 'delivery_days_max' ) );
	}

	/**
	 * Invalid input → 400, nothing saved.
	 *
	 * @dataProvider invalid_bodies
	 *
	 * @param array<string, mixed> $body Body.
	 */
	public function test_patch_invalid_is_400( array $body ): void {
		if ( isset( $body['returns_page_id'] ) && 'draft' === $body['returns_page_id'] ) {
			$body['returns_page_id'] = self::factory()->post->create(
				array(
					'post_type'   => 'page',
					'post_status' => 'draft',
				)
			);
		}
		$before   = get_option( Settings::OPTION );
		$response = $this->call( 'PATCH', 'shop_manager', $body + array( 'fix_llms_txt' => true ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( $before, get_option( Settings::OPTION ) );
	}

	/**
	 * Invalid bodies.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public function invalid_bodies(): array {
		return array(
			'unknown key'         => array( array( 'evil' => 1 ) ),
			'finite without days' => array( array( 'return_policy' => 'finite' ) ),
			'return days zero'    => array( array( 'return_days' => 0 ) ),
			'draft returns page'  => array( array( 'returns_page_id' => 'draft' ) ),
			'bad enum'            => array( array( 'return_policy' => 'forever' ) ),
			'days out of range'   => array( array( 'delivery_days_max' => 61 ) ),
			'min above max'       => array(
				array(
					'delivery_days_min' => 5,
					'delivery_days_max' => 2,
				),
			),
			'non-experimental'    => array( array( 'block_experimental' => array( 'GPTBot' ) ) ),
			'wrong type'          => array( array( 'fix_feed' => 'maybe' ) ),
		);
	}

	/**
	 * Turning on an area reported as managed/cannot_run → 409; turning off always works.
	 */
	public function test_patch_unavailable_fixer_is_409(): void {
		add_filter(
			'x7velo_fixer_status',
			static function ( $status ) {
				$status['robots'] = array(
					'state'  => 'cannot_run',
					'reason' => 'blocked_by_search_visibility',
				);
				return $status;
			}
		);

		$response = $this->call( 'PATCH', 'shop_manager', array( 'fix_robots_search' => true ) );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'x7velo_fixer_unavailable', $response->get_data()['code'] );
		$this->assertFalse( ( new Settings() )->get( 'fix_robots_search' ) );

		( new Settings() )->update( array( 'fix_robots_block_training' => true ) );
		$this->assertSame( 200, $this->call( 'PATCH', 'shop_manager', array( 'fix_robots_block_training' => false ) )->get_status() );
		$this->assertSame( 'cannot_run', $this->call( 'GET', 'shop_manager' )->get_data()['fixer_status']['robots']['state'] );
	}
}
