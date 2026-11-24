<?php
/**
 * Multisite: activation per site, uninstall sweep across sites, network deactivation.
 *
 * Runs only on a multisite test install (`composer test:multisite`, WP_MULTISITE=1); the default
 * suite excludes `ms-required`. Covers the multisite halves of AC 91, AC 103 and AC 128.
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
 * The plugin on a multisite network.
 *
 * @group ms-required
 * @group multisite
 */
final class MultisiteTest extends WP_UnitTestCase {

	private const OUR_RULE = array( '^llms\.txt$' => 'index.php?x7velo_llms_txt=1' );

	/**
	 * Raw option value from a site's options table (bypasses every cache).
	 *
	 * @param int    $blog_id Blog ID.
	 * @param string $name    Option name.
	 * @return string|null
	 */
	private static function raw_option( int $blog_id, string $name ): ?string {
		global $wpdb;
		$table = $wpdb->get_blog_prefix( $blog_id ) . 'options';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $table, $name ) );
	}

	/**
	 * Every x7velo option and transient on a site.
	 *
	 * @param int $blog_id Blog ID.
	 * @return string[]
	 */
	private static function x7velo_options( int $blog_id ): array {
		global $wpdb;
		$table = $wpdb->get_blog_prefix( $blog_id ) . 'options';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_col(
			$wpdb->prepare(
				'SELECT option_name FROM %i WHERE option_name LIKE %s OR option_name LIKE %s',
				$table,
				$wpdb->esc_like( 'x7velo_' ) . '%',
				$wpdb->esc_like( '_transient' ) . '%x7velo%'
			)
		);
	}

	/**
	 * Puts x7velo data on one site, the way a real install would leave it.
	 *
	 * @param int  $blog_id  Blog ID.
	 * @param bool $our_rule Whether the stored rewrite rules contain our llms.txt rule.
	 */
	private static function populate( int $blog_id, bool $our_rule ): void {
		switch_to_blog( $blog_id );
		Plugin::activate();
		( new Settings() )->update(
			array(
				'fix_llms_txt' => true,
				'fix_feed'     => true,
			)
		);
		( new AuditService( new AuditStore(), new Settings(), X7VELO_VERSION ) )->run();
		update_option( 'x7velo_notice_state', array( 'llms_txt' => 'yoast' ), false );
		set_transient( 'x7velo_llms_txt', 'cached body', HOUR_IN_SECONDS );
		set_transient( 'x7velo_rewrite_sync', 'on', HOUR_IN_SECONDS );
		$rules = array( '^shop/?$' => 'index.php?post_type=product' );
		update_option( 'rewrite_rules', $our_rule ? $rules + self::OUR_RULE : $rules );
		restore_current_blog();
	}

	/**
	 * Uninstalls the plugin the way WordPress does.
	 */
	private static function uninstall(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'x7velo/x7velo.php' );
		}
		include dirname( __DIR__ ) . '/uninstall.php';
	}

	/**
	 * Activation on a network: the activation hook runs on the main site only; every other site
	 * gets its defaults and version on its first load (maybe_upgrade), and nothing is applied.
	 */
	public function test_activation_on_each_site(): void {
		$this->assertTrue( is_multisite() );
		$main = get_current_blog_id();
		$site = self::factory()->blog->create();

		delete_option( Plugin::VERSION_OPTION );
		delete_option( Settings::OPTION );
		Plugin::activate();
		$this->assertSame( X7VELO_VERSION, get_option( Plugin::VERSION_OPTION ) );
		$this->assertSame( Settings::defaults(), ( new Settings() )->all() );
		$this->assertNull( self::raw_option( $site, Settings::OPTION ), 'Activation on the main site writes nothing on other sites.' );

		switch_to_blog( $site );
		( new Plugin( X7VELO_PLUGIN_FILE, X7VELO_VERSION ) )->maybe_upgrade();
		$this->assertSame( X7VELO_VERSION, get_option( Plugin::VERSION_OPTION ) );
		$this->assertSame( Settings::defaults(), ( new Settings() )->all(), 'Defaults only: no fixer is on after activation.' );
		restore_current_blog();

		$this->assertSame( $main, get_current_blog_id() );
	}

	/**
	 * AC 91 / AC 128 (multisite): deleting the plugin removes every x7velo option and transient on
	 * every site, the notice dismissals, and only the stored rewrite rules that contain our rule.
	 */
	public function test_uninstall_sweeps_every_site(): void {
		$main  = get_current_blog_id();
		$ours  = self::factory()->blog->create();
		$other = self::factory()->blog->create();
		self::populate( $main, true );
		self::populate( $ours, true );
		self::populate( $other, false );
		$user = self::factory()->user->create();
		update_user_meta( $user, 'x7velo_dismissed_notices', array( 'activation-1' ) );

		foreach ( array( $main, $ours, $other ) as $blog_id ) {
			$this->assertNotEmpty( self::x7velo_options( $blog_id ), "site {$blog_id} populated" );
		}

		self::uninstall();

		foreach ( array( $main, $ours, $other ) as $blog_id ) {
			$this->assertSame( array(), self::x7velo_options( $blog_id ), "no x7velo option or transient left on site {$blog_id}" );
			switch_to_blog( $blog_id );
			$this->assertFalse( get_option( Settings::OPTION ), "option caches dropped on site {$blog_id}" );
			$this->assertFalse( get_transient( 'x7velo_llms_txt' ), "transient caches dropped on site {$blog_id}" );
			restore_current_blog();
		}
		$this->assertNull( self::raw_option( $main, 'rewrite_rules' ), 'Rules with our llms.txt rule are dropped (main site).' );
		$this->assertNull( self::raw_option( $ours, 'rewrite_rules' ), 'Rules with our llms.txt rule are dropped (sub-site).' );
		$this->assertNotNull( self::raw_option( $other, 'rewrite_rules' ), 'Rules without our rule are untouched.' );
		$this->assertSame( '', get_user_meta( $user, 'x7velo_dismissed_notices', true ) );
	}

	/**
	 * AC 103 / AC 91 (deactivation): network deactivation forgets our stored rule on every site
	 * (rules without it untouched) and keeps every setting.
	 */
	public function test_network_deactivation_forgets_rule_and_keeps_data(): void {
		$ours  = self::factory()->blog->create();
		$other = self::factory()->blog->create();
		self::populate( $ours, true );
		self::populate( $other, false );
		$before = self::raw_option( $ours, Settings::OPTION );

		Plugin::deactivate( true );

		$this->assertNull( self::raw_option( $ours, 'rewrite_rules' ), 'Our stored rule is forgotten on the other site.' );
		$this->assertNull( self::raw_option( $ours, '_transient_x7velo_rewrite_sync' ) );
		$this->assertNotNull( self::raw_option( $other, 'rewrite_rules' ), 'Rules without our rule are untouched.' );
		$this->assertSame( $before, self::raw_option( $ours, Settings::OPTION ), 'Deactivation keeps settings.' );
		$this->assertNotNull( self::raw_option( $ours, AuditStore::OPTION ), 'Deactivation keeps the last audit.' );
	}
}
