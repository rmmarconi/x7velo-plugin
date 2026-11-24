<?php
/**
 * Contract test between the REST audit response and the admin UI (Jest) fixture.
 *
 * @package X7velo
 */

namespace X7velo\Tests\Rest;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use X7velo\Tests\Helpers\Products;

/**
 * The Jest suite reads `assets/src/__tests__/contract/audit-response.json` as the real server
 * shape (D-1: the client read `checks[].examples` while the server sends
 * `checks[].details.examples`). This test runs a real audit through the REST route and fails
 * when any path/type in that fixture no longer exists in the live response, so the two can't
 * drift silently.
 *
 * Regenerate the fixture after an intended contract change (inside wp-env tests-cli):
 *   X7VELO_WRITE_CONTRACT_FIXTURE=1 vendor/bin/phpunit --filter AuditContractTest
 */
final class AuditContractTest extends WP_UnitTestCase {

	/**
	 * Fixture path, relative to the plugin root.
	 */
	private const FIXTURE = 'assets/src/__tests__/contract/audit-response.json';

	/**
	 * Requires WooCommerce (store mode carries the product examples).
	 */
	public function set_up(): void {
		parent::set_up();
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is not loaded.' );
		}
		global $wp_rest_server;
		// Header-less server: PHPUnit output forbids headers.
		$wp_rest_server = new class() extends WP_REST_Server { // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test REST server.
			/**
			 * No-op.
			 *
			 * @param string $key   Header name.
			 * @param string $value Header value.
			 */
			public function send_header( $key, $value ) {}
		};
		do_action( 'rest_api_init', $wp_rest_server );
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
	 * Every path in the Jest fixture exists in a live POST /audit response with a matching type,
	 * and the image check carries `details.examples` with {id, title, edit_url}.
	 */
	public function test_fixture_matches_live_response(): void {
		$live = $this->live_response();

		$image = null;
		foreach ( $live->checks as $check ) {
			if ( 'DATA-02' === $check->id ) {
				$image = $check;
			}
		}
		$this->assertNotNull( $image );
		$this->assertFalse( property_exists( $image, 'examples' ), 'Examples live under details, not at the check level.' );
		$this->assertNotEmpty( $image->details->examples );
		$this->assertStringContainsString( 'action=edit', $image->details->examples[0]->edit_url );

		$path = dirname( __DIR__, 2 ) . '/' . self::FIXTURE;
		if ( getenv( 'X7VELO_WRITE_CONTRACT_FIXTURE' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture written on request only.
			file_put_contents( $path, wp_json_encode( $live, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );
		}

		$this->assertFileExists( $path, 'Generate the fixture: X7VELO_WRITE_CONTRACT_FIXTURE=1 vendor/bin/phpunit --filter AuditContractTest' );
		$fixture = json_decode( (string) file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture.
		$this->assertIsObject( $fixture );

		$want = self::shape( $fixture );
		$have = self::shape( $live );
		$this->assertArrayHasKey( 'checks[].details.examples[].edit_url', $want, 'The fixture must include product examples.' );
		foreach ( $want as $key => $types ) {
			$this->assertArrayHasKey( $key, $have, "Fixture path {$key} is missing from the live response. Update the client and regenerate the fixture." );
			$this->assertNotEmpty( array_intersect( $types, $have[ $key ] ), "Type of {$key} changed: fixture " . implode( '|', $types ) . ', live ' . implode( '|', $have[ $key ] ) . '.' );
		}
	}

	/**
	 * Runs the audit via REST as a shop manager on a small catalogue with missing images, and
	 * returns the JSON response with site-specific values replaced by stable ones.
	 *
	 * @return object Decoded JSON (objects stay objects).
	 */
	private function live_response(): object {
		Products::mix( 3, 2, array( 'image' => false ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );

		global $wp_rest_server;
		$response = $wp_rest_server->dispatch( new WP_REST_Request( 'POST', '/x7velo/v1/audit' ) );
		$this->assertSame( 200, $response->get_status() );

		$json = (string) wp_json_encode( $wp_rest_server->response_to_data( $response, false ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		// Stable values for the committed fixture: host, product IDs and titles, timestamp.
		$json = str_replace( untrailingslashit( home_url() ), 'https://example.com', $json );
		$ids  = array();
		$data = json_decode( $json );
		foreach ( $data->checks as $check ) {
			foreach ( (array) ( $check->details->examples ?? array() ) as $example ) {
				if ( ! isset( $ids[ $example->id ] ) ) {
					$ids[ $example->id ] = 101 + count( $ids );
				}
				$n                 = $ids[ $example->id ];
				$example->edit_url = (string) preg_replace( '/post=\d+/', 'post=' . $n, $example->edit_url );
				$example->id       = $n;
				$example->title    = 'Product ' . $n;
			}
		}
		$data->generated_at = '2026-11-23T12:00:00+00:00';
		return $data;
	}

	/**
	 * Flattens a decoded JSON value to path => list of types (lists merge their items under "[]").
	 *
	 * @param mixed                $value  Value.
	 * @param string               $prefix Path so far.
	 * @param array<string, array> $out    Accumulator.
	 * @return array<string, string[]>
	 */
	private static function shape( $value, string $prefix = '', array $out = array() ): array {
		if ( is_object( $value ) ) {
			$type = 'object';
			foreach ( get_object_vars( $value ) as $key => $child ) {
				$out = self::shape( $child, '' === $prefix ? (string) $key : $prefix . '.' . $key, $out );
			}
		} elseif ( is_array( $value ) ) {
			$type = 'array';
			foreach ( $value as $child ) {
				$out = self::shape( $child, $prefix . '[]', $out );
			}
		} elseif ( is_int( $value ) || is_float( $value ) ) {
			$type = 'number';
		} elseif ( null === $value ) {
			$type = 'null';
		} else {
			$type = gettype( $value );
		}
		if ( '' !== $prefix && ! in_array( $type, $out[ $prefix ] ?? array(), true ) ) {
			$out[ $prefix ][] = $type;
		}
		return $out;
	}
}
