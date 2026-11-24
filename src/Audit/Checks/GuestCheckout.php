<?php
/**
 * STORE-04 guest-checkout.
 *
 * @package X7velo
 */

namespace X7velo\Audit\Checks;

use X7velo\Audit\Category;
use X7velo\Audit\Check;
use X7velo\Audit\CheckResult;
use X7velo\Audit\Fixer;
use X7velo\Audit\MessageRenderer;
use X7velo\Audit\Status;

defined( 'ABSPATH' ) || exit;

/**
 * Guest checkout on = pass; off = warn (a business choice, never fail).
 */
final class GuestCheckout implements Check, MessageRenderer {
	use BuildsResults;

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'STORE-04';
	}

	/**
	 * {@inheritDoc}
	 */
	public function category(): string {
		return Category::STORE;
	}

	/**
	 * {@inheritDoc}
	 */
	public function weight(): int {
		return 3;
	}

	/**
	 * {@inheritDoc}
	 */
	public function run(): CheckResult {
		$on = 'yes' === get_option( 'woocommerce_enable_guest_checkout', 'yes' );
		return $this->make( $on ? Status::PASS : Status::WARN, $on ? 'guest_checkout_on' : 'guest_checkout_off', array(), Fixer::GUIDED, 'admin.php?page=wc-settings&tab=account' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $code    Code.
	 * @param array<string, mixed> $details Details.
	 */
	public function render_message( string $code, array $details ): string {
		if ( 'guest_checkout_off' === $code ) {
			return __( 'Shoppers (and assistants acting for them) must create an account to buy. Allowing guest checkout removes a step. You can change this in WooCommerce → Settings → Accounts & Privacy.', 'x7velo' );
		}
		return __( 'Shoppers can buy without creating an account.', 'x7velo' );
	}
}
