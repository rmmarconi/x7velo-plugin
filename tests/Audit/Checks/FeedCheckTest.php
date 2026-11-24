<?php
/**
 * CRAWL-04 tests.
 *
 * @package X7velo
 */

namespace X7velo\Tests\Audit\Checks;

use WP_UnitTestCase;
use X7velo\Audit\Checks\ProductFeed;
use X7velo\Audit\Status;
use X7velo\Detection\Detector;
use X7velo\Detection\FixerStates;
use X7velo\Settings;
use X7velo\Tests\Detection\FakeEnvironment;

/**
 * AC65, AC66.
 */
final class FeedCheckTest extends WP_UnitTestCase {

	/**
	 * AC66: no feed plugin and fix_feed off → fail.
	 */
	public function test_fails_without_feed(): void {
		$result = ( new ProductFeed( new Detector( new FakeEnvironment() ), new Settings() ) )->run();
		$this->assertSame( Status::FAIL, $result->status() );
		$this->assertSame( 'no_feed', $result->code() );
	}

	/**
	 * AC66: fix_feed on → pass.
	 */
	public function test_passes_with_own_feed(): void {
		( new Settings() )->update( array( 'fix_feed' => true ) );
		$result = ( new ProductFeed( new Detector( new FakeEnvironment() ), new Settings() ) )->run();
		$this->assertSame( 'feed_x7velo', $result->code() );
	}

	/**
	 * AC65: Google for WooCommerce → pass, and the feed fixer stays available (not managed).
	 */
	public function test_google_for_woocommerce_passes_fixer_available(): void {
		$env          = new FakeEnvironment();
		$env->plugins = array( 'google-listings-and-ads/google-listings-and-ads.php' );
		$result       = ( new ProductFeed( new Detector( $env ), new Settings() ) )->run();
		$this->assertSame( Status::PASS, $result->status() );
		$this->assertSame( 'Google for WooCommerce', $result->details()['source'] );
		$this->assertStringContainsString( 'Google for WooCommerce', $result->message() );

		$status = FixerStates::filter( array( 'feed' => array( 'state' => 'off' ) ), array() );
		$this->assertSame( 'off', $status['feed']['state'] );
	}
}
