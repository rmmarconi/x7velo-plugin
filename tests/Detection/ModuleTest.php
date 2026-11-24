<?php
/**
 * Module wiring tests.
 *
 * @package X7velo
 */

namespace X7velo\Tests\Detection;

use WP_UnitTestCase;
use X7velo\Audit\Catalog\ProductScanner;
use X7velo\Audit\Registry;
use X7velo\Detection\Module;
use X7velo\Settings;

/**
 * Checks are registered in spec order and survive the registry's validation.
 */
final class ModuleTest extends WP_UnitTestCase {

	/**
	 * Setup.
	 */
	public function set_up(): void {
		parent::set_up();
		remove_all_filters( 'x7velo_audit_checks' );
		remove_all_filters( 'x7velo_fixer_status' );
		Module::register();
	}

	/**
	 * 18 checks; VIS-04 after VIS-03, then CRAWL-01..04, SCHEMA-01, then DATA-*.
	 */
	public function test_registers_checks_in_order(): void {
		$settings = new Settings();
		$ids      = array_column( ( new Registry( new ProductScanner(), $settings ) )->checks(), 'id' );
		$this->assertCount( 18, $ids );
		$this->assertSame(
			array( 'VIS-01', 'VIS-02', 'VIS-03', 'VIS-04', 'CRAWL-01', 'CRAWL-02', 'CRAWL-03', 'CRAWL-04', 'SCHEMA-01' ),
			array_slice( $ids, 0, 9 )
		);
	}

	/**
	 * Weights match spec §5 (total 100 with the built-ins).
	 */
	public function test_weights(): void {
		$settings = new Settings();
		$weights  = array_column( ( new Registry( new ProductScanner(), $settings ) )->checks(), 'weight', 'id' );
		$this->assertSame( 4, $weights['VIS-04'] );
		$this->assertSame( 10, $weights['CRAWL-01'] );
		$this->assertSame( 5, $weights['CRAWL-02'] );
		$this->assertSame( 5, $weights['CRAWL-03'] );
		$this->assertSame( 5, $weights['CRAWL-04'] );
		$this->assertSame( 10, $weights['SCHEMA-01'] );
		$this->assertSame( 100, array_sum( $weights ) );
	}

	/**
	 * Non-array filter input passes through.
	 */
	public function test_passthrough(): void {
		$this->assertSame( 'x', Module::add_checks( 'x' ) );
	}

	/**
	 * AC88: an audit run with the module makes no HTTP request.
	 */
	public function test_no_outbound_http(): void {
		$calls = 0;
		add_filter(
			'pre_http_request',
			static function () use ( &$calls ) {
				++$calls;
				return new \WP_Error( 'blocked', 'no http' );
			}
		);
		$settings = new Settings();
		foreach ( ( new Registry( new ProductScanner(), $settings ) )->checks() as $entry ) {
			if ( str_starts_with( $entry['id'], 'VIS' ) || str_starts_with( $entry['id'], 'CRAWL' ) || str_starts_with( $entry['id'], 'SCHEMA' ) ) {
				$entry['check']->run();
			}
		}
		$this->assertSame( 0, $calls );
	}
}
