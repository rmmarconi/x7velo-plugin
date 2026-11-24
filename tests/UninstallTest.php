<?php
/**
 * Uninstall tests.
 *
 * @package X7velo
 */

namespace X7velo\Tests;

use WP_UnitTestCase;
use X7velo\Audit\AuditService;
use X7velo\Audit\AuditStore;
use X7velo\Plugin;
use X7velo\Settings;

/**
 * The uninstall script removes everything x7velo stored. Also runs on multisite
 * (`composer test:multisite`); MultisiteTest covers the other sites.
 *
 * @group multisite
 */
final class UninstallTest extends WP_UnitTestCase {

	/**
	 * After uninstall no x7velo option or transient remains.
	 */
	public function test_uninstall_removes_all_options(): void {
		Plugin::activate();
		( new Settings() )->update( array( 'fix_feed' => true ) );
		update_option( 'x7velo_notice_state', array( 'llms_txt' => 'yoast' ), false );
		set_transient( 'x7velo_llms_txt', 'x', HOUR_IN_SECONDS );
		set_transient( 'x7velo_rewrite_sync', 'on', HOUR_IN_SECONDS );
		$users = array( self::factory()->user->create(), self::factory()->user->create() );
		foreach ( $users as $user ) {
			update_user_meta( $user, 'x7velo_dismissed_notices', array( 'activation-1' ) );
		}
		// AC 128 (W25): a real audit in both modes (site mode without WooCommerce).
		$record = ( new AuditService( new AuditStore(), new Settings(), X7VELO_VERSION ) )->run();
		$this->assertSame( class_exists( 'WooCommerce' ), $record['woocommerce_active'] );
		( new Settings() )->update( array( 'fix_llms_txt' => true ) );

		update_option(
			'rewrite_rules',
			array(
				'^shop/?$'    => 'index.php?post_type=product',
				'^llms\.txt$' => 'index.php?x7velo_llms_txt=1',
			)
		);

		global $wpdb;
		$sql = "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'x7velo\\_%' OR option_name LIKE '\\_transient%x7velo%'";
		$this->assertNotEmpty( $wpdb->get_col( $sql ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'x7velo/x7velo.php' );
		}
		include dirname( __DIR__ ) . '/uninstall.php';

		$this->assertSame( array(), $wpdb->get_col( $sql ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$this->assertFalse( get_option( Settings::OPTION ) );
		$this->assertFalse( get_option( AuditStore::OPTION ) );
		$this->assertFalse( get_option( 'rewrite_rules' ), 'Stored rules containing our llms.txt rule are dropped (core regenerates them).' );
		foreach ( $users as $user ) {
			$this->assertSame( '', get_user_meta( $user, 'x7velo_dismissed_notices', true ), 'Notice dismissals removed for every user.' );
		}
	}
}
