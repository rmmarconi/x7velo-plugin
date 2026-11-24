<?php
/**
 * VIS-01..03 and STORE-04 tests.
 *
 * @package X7velo
 */

namespace X7velo\Tests\Audit\Checks;

use WP_UnitTestCase;
use X7velo\Audit\Checks\GuestCheckout;
use X7velo\Audit\Checks\Https;
use X7velo\Audit\Checks\Permalinks;
use X7velo\Audit\Checks\SearchVisibility;
use X7velo\Audit\Fixer;
use X7velo\Audit\Status;

/**
 * Option-based checks.
 */
final class VisibilityChecksTest extends WP_UnitTestCase {

	/**
	 * VIS-01 pass and critical fail.
	 */
	public function test_search_visibility(): void {
		update_option( 'blog_public', '1' );
		$this->assertSame( Status::PASS, ( new SearchVisibility() )->run()->status() );

		update_option( 'blog_public', '0' );
		$result = ( new SearchVisibility() )->run();
		$this->assertSame( Status::FAIL, $result->status() );
		$this->assertTrue( $result->is_critical() );
		$this->assertSame( Fixer::GUIDED, $result->fixer() );
		$this->assertSame( 'options-reading.php', $result->guided_path() );
	}

	/**
	 * VIS-02 needs both URLs on https.
	 */
	public function test_https(): void {
		// wp-env defines WP_HOME/WP_SITEURL, which override the options via option_* filters.
		$home    = 'https://shop.example';
		$siteurl = 'https://shop.example';
		add_filter( 'option_home', static fn() => $home, 99 );
		add_filter(
			'option_siteurl',
			static function () use ( &$siteurl ) {
				return $siteurl;
			},
			99
		);
		$this->assertSame( Status::PASS, ( new Https() )->run()->status() );

		$siteurl = 'http://shop.example';
		$result  = ( new Https() )->run();
		$this->assertSame( Status::FAIL, $result->status() );
		$this->assertFalse( $result->details()['siteurl_https'] );
		$this->assertSame( Fixer::MANUAL, $result->fixer() );
	}

	/**
	 * VIS-03 fails on plain permalinks.
	 */
	public function test_permalinks(): void {
		update_option( 'permalink_structure', '/%postname%/' );
		$this->assertSame( Status::PASS, ( new Permalinks() )->run()->status() );

		update_option( 'permalink_structure', '' );
		$this->assertSame( Status::FAIL, ( new Permalinks() )->run()->status() );
	}

	/**
	 * STORE-04 warns (never fails) when guest checkout is off.
	 */
	public function test_guest_checkout(): void {
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );
		$this->assertSame( Status::PASS, ( new GuestCheckout() )->run()->status() );

		update_option( 'woocommerce_enable_guest_checkout', 'no' );
		$this->assertSame( Status::WARN, ( new GuestCheckout() )->run()->status() );
	}
}
