<?php
/**
 * Security re-review lows (engine notes v2.3) that don't need WooCommerce.
 *
 * @package X7velo
 */

namespace X7velo\Tests;

use WP_UnitTestCase;
use X7velo\Fixers\LlmsTxt\LlmsTxtEndpoint;
use X7velo\NamedLock;

/**
 * N3 (per-blog rule cleanup without switch_to_blog) and N4 (lock release is idempotent).
 */
final class SecurityLowsTest extends WP_UnitTestCase {

	/**
	 * N3: our stored rule and the sync guard are removed from a blog's options table through
	 * prepared queries, and that blog's option caches are dropped (no stale alloptions).
	 */
	public function test_n3_forget_rule_for_blog_without_switch(): void {
		update_option(
			'rewrite_rules',
			array(
				'^shop/?$'             => 'index.php?post_type=product',
				LlmsTxtEndpoint::REGEX => LlmsTxtEndpoint::TARGET,
			)
		);
		set_transient( LlmsTxtEndpoint::SYNC_GUARD, 'on', HOUR_IN_SECONDS );
		$this->assertIsArray( get_option( 'rewrite_rules' ) );

		$switched = 0;
		$count    = static function () use ( &$switched ): void {
			++$switched;
		};
		add_action( 'switch_blog', $count );
		LlmsTxtEndpoint::forget_stored_rule_for_blog( get_current_blog_id() );
		remove_action( 'switch_blog', $count );

		$this->assertSame( 0, $switched, 'No switch_to_blog().' );
		$this->assertFalse( get_option( 'rewrite_rules' ) );
		$this->assertFalse( get_transient( LlmsTxtEndpoint::SYNC_GUARD ) );

		// Rules without ours are left alone.
		update_option( 'rewrite_rules', array( '^shop/?$' => 'index.php?post_type=product' ) );
		LlmsTxtEndpoint::forget_stored_rule_for_blog( get_current_blog_id() );
		$this->assertSame( array( '^shop/?$' => 'index.php?post_type=product' ), get_option( 'rewrite_rules' ) );
	}

	/**
	 * N4: release() is idempotent (it is also registered as a shutdown function on acquire).
	 */
	public function test_n4_release_idempotent(): void {
		$lock = new NamedLock( NamedLock::site_name( 'x7velo_test_' ) );
		$this->assertSame( NamedLock::ACQUIRED, $lock->acquire() );
		$this->assertTrue( $lock->held() );
		$lock->release();
		$lock->release();
		$this->assertFalse( $lock->held() );
		$this->assertSame( NamedLock::ACQUIRED, $lock->acquire(), 'Free again after release.' );
		$lock->release();
	}
}
