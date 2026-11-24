<?php
/**
 * Settings, activation and upgrade tests.
 *
 * @package X7velo
 */

namespace X7velo\Tests;

use WP_UnitTestCase;
use X7velo\Data\AiCrawlers;
use X7velo\Plugin;
use X7velo\Settings;

/**
 * Settings sanitisation and install/upgrade routine.
 */
final class SettingsTest extends WP_UnitTestCase {

	/**
	 * Defaults have every DBA-agreed key and everything is off.
	 */
	public function test_defaults(): void {
		$this->assertSame(
			array(
				'returns_page_id'           => 0,
				'own_brand_products'        => false,
				'fix_jsonld'                => false,
				'fix_llms_txt'              => false,
				'fix_feed'                  => false,
				'fix_robots_search'         => false,
				'fix_robots_block_training' => false,
				'block_google_extended'     => false,
				'block_experimental'        => array(),
				'return_policy'             => '',
				'return_days'               => null,
				'delivery_days_min'         => null,
				'delivery_days_max'         => null,
			),
			Settings::defaults()
		);
	}

	/**
	 * Unknown keys dropped; types coerced; block_experimental filtered against the data file.
	 */
	public function test_sanitize(): void {
		$clean = Settings::sanitize(
			array(
				'returns_page_id'    => '12abc',
				'fix_feed'           => 'true',
				'fix_jsonld'         => '0',
				'evil'               => 'x',
				'block_experimental' => array( 'expbot', 'GoodBot', 'Nope', 'EXPBOT', 42, 'bytespider' ),
			),
			__DIR__ . '/fixtures/ai-crawlers-test.json'
		);

		$this->assertArrayNotHasKey( 'evil', $clean );
		$this->assertSame( 12, $clean['returns_page_id'] );
		$this->assertTrue( $clean['fix_feed'] );
		$this->assertFalse( $clean['fix_jsonld'] );
		$this->assertSame( array( 'ExpBot', 'Bytespider' ), $clean['block_experimental'] );
	}

	/**
	 * The bundled data file marks Bytespider as experimental.
	 */
	public function test_bundled_crawler_file(): void {
		$this->assertContains( 'Bytespider', AiCrawlers::experimental_tokens() );
		$this->assertSame( array( 'Bytespider' ), Settings::sanitize( array( 'block_experimental' => array( 'bytespider', 'GPTBot' ) ) )['block_experimental'] );
	}

	/**
	 * Update merges shallowly and replaces lists whole.
	 */
	public function test_update_merges(): void {
		$settings = new Settings();
		$settings->update( array( 'fix_feed' => true ) );
		$settings->update( array( 'own_brand_products' => true ) );
		$this->assertTrue( $settings->get( 'fix_feed' ) );
		$this->assertTrue( $settings->get( 'own_brand_products' ) );
		$this->assertNull( $settings->get( 'unknown' ) );
	}

	/**
	 * Activation writes defaults (autoloaded) and the version, and never overwrites owner settings.
	 *
	 * @group multisite
	 */
	public function test_activation_and_upgrade(): void {
		delete_option( Settings::OPTION );
		delete_option( Plugin::VERSION_OPTION );

		Plugin::activate();
		$this->assertSame( Settings::defaults(), get_option( Settings::OPTION ) );
		$this->assertSame( X7VELO_VERSION, get_option( Plugin::VERSION_OPTION ) );

		global $wpdb;
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Settings::OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertContains( $autoload, array( 'yes', 'on' ) );

		( new Settings() )->update( array( 'fix_feed' => true ) );
		update_option( Plugin::VERSION_OPTION, '0.0.1' );
		( new Plugin( X7VELO_PLUGIN_FILE, '9.9.9' ) )->maybe_upgrade();

		$this->assertSame( '9.9.9', get_option( Plugin::VERSION_OPTION ) );
		$this->assertTrue( ( new Settings() )->get( 'fix_feed' ), 'Upgrade must keep owner settings.' );
	}

	/**
	 * Strict validation on the write path.
	 */
	public function test_validate(): void {
		$base  = Settings::defaults();
		$file  = __DIR__ . '/fixtures/ai-crawlers-test.json';
		$draft = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
			)
		);
		$page  = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);

		$this->assertWPError( Settings::validate( array( 'returns_page_id' => $draft ) + $base, $file ) );
		$this->assertWPError( Settings::validate( array( 'return_policy' => 'finite' ) + $base, $file ) );
		$this->assertWPError( Settings::validate( array( 'return_days' => 0 ) + $base, $file ) );
		$this->assertWPError( Settings::validate( array( 'delivery_days_max' => 61 ) + $base, $file ) );
		$this->assertIsArray(
			Settings::validate(
				array(
					'delivery_days_min' => 0,
					'delivery_days_max' => 0,
				) + $base,
				$file
			),
			'0 = same day is valid.'
		);
		$this->assertIsArray( Settings::validate( array( 'delivery_days_min' => 3 ) + $base, $file ), 'Only one bound set is allowed.' );
		$this->assertWPError(
			Settings::validate(
				array(
					'delivery_days_min' => 5,
					'delivery_days_max' => 2,
				) + $base,
				$file
			)
		);
		$this->assertWPError( Settings::validate( array( 'block_experimental' => array( 'GoodBot' ) ) + $base, $file ) );

		$ok = Settings::validate(
			array(
				'returns_page_id'    => $page,
				'return_policy'      => 'finite',
				'return_days'        => 30,
				'delivery_days_min'  => 2,
				'delivery_days_max'  => 5,
				'block_experimental' => array( 'expbot' ),
			) + $base,
			$file
		);
		$this->assertIsArray( $ok );
		$this->assertSame( array( 'ExpBot' ), $ok['block_experimental'] );
		$this->assertSame( 30, $ok['return_days'] );
	}

	/**
	 * Read-path sanitisation clamps out-of-range stored values.
	 */
	public function test_sanitize_clamps(): void {
		$clean = Settings::sanitize(
			array(
				'return_policy'     => 'forever',
				'return_days'       => 9999,
				'delivery_days_max' => 999,
				'delivery_days_min' => 'x',
			)
		);
		$this->assertSame( '', $clean['return_policy'] );
		$this->assertSame( 365, $clean['return_days'] );
		$this->assertSame( 60, $clean['delivery_days_max'] );
		$this->assertNull( $clean['delivery_days_min'] );
		$this->assertSame( 0, Settings::sanitize( array( 'delivery_days_min' => 0 ) )['delivery_days_min'] );
	}
}
