<?php
/**
 * VIS-04 tests.
 *
 * @package X7velo
 */

namespace X7velo\Tests\Audit\Checks;

use WP_UnitTestCase;
use X7velo\Audit\Checks\ComingSoon;
use X7velo\Audit\Fixer;
use X7velo\Audit\Status;

/**
 * Coming-soon mode (AC7, AC8).
 */
final class ComingSoonCheckTest extends WP_UnitTestCase {

	/**
	 * AC8: missing or "no" passes.
	 */
	public function test_coming_soon_missing_or_no_passes(): void {
		delete_option( 'woocommerce_coming_soon' );
		$this->assertSame( Status::PASS, ( new ComingSoon() )->run()->status() );
		update_option( 'woocommerce_coming_soon', 'no' );
		$this->assertSame( Status::PASS, ( new ComingSoon() )->run()->status() );
	}

	/**
	 * AC7: whole site and store-pages-only both fail as critical, Guided to Site visibility.
	 */
	public function test_coming_soon_fails_critical(): void {
		update_option( 'woocommerce_coming_soon', 'yes' );
		foreach ( array( 'no', 'yes' ) as $store_only ) {
			update_option( 'woocommerce_store_pages_only', $store_only );
			$result = ( new ComingSoon() )->run();
			$this->assertSame( Status::FAIL, $result->status() );
			$this->assertTrue( $result->is_critical() );
			$this->assertSame( 'store_coming_soon', $result->code() );
			$this->assertSame( 'yes' === $store_only, $result->details()['store_pages_only'] );
			$this->assertSame( Fixer::GUIDED, $result->fixer() );
			$this->assertSame( 'admin.php?page=wc-settings&tab=site-visibility', $result->guided_path() );
			$this->assertSame( $result->message(), ( new ComingSoon() )->render_message( $result->code(), $result->details() ) );
		}
	}
}
