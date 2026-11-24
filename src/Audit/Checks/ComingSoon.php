<?php
/**
 * VIS-04 coming-soon.
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
 * WooCommerce "Coming soon" mode must be off. Critical: a fail caps the score at 20.
 */
final class ComingSoon implements Check, MessageRenderer {
	use BuildsResults;

	/**
	 * WooCommerce → Settings → Site visibility (tab ID `site-visibility`, verified on Woo 11.3.1).
	 */
	public const GUIDED_PATH = 'admin.php?page=wc-settings&tab=site-visibility';

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'VIS-04';
	}

	/**
	 * {@inheritDoc}
	 */
	public function category(): string {
		return Category::VISIBILITY;
	}

	/**
	 * {@inheritDoc}
	 */
	public function weight(): int {
		return 4;
	}

	/**
	 * {@inheritDoc}
	 */
	public function run(): CheckResult {
		// Missing option (WooCommerce < 9.1) = no coming-soon mode = pass.
		if ( 'yes' !== get_option( 'woocommerce_coming_soon', 'no' ) ) {
			return $this->make( Status::PASS, 'store_live', array(), Fixer::GUIDED, self::GUIDED_PATH );
		}
		return $this->make(
			Status::FAIL,
			'store_coming_soon',
			array( 'store_pages_only' => 'yes' === get_option( 'woocommerce_store_pages_only', 'no' ) ),
			Fixer::GUIDED,
			self::GUIDED_PATH,
			true
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $code    Code.
	 * @param array<string, mixed> $details Details.
	 */
	public function render_message( string $code, array $details ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Interface signature.
		if ( 'store_coming_soon' === $code ) {
			return __( 'Your store is in “Coming soon” mode, so shoppers, search engines and AI assistants see a placeholder instead of your products. You see the normal store only because you’re logged in. Set it to “Live” in WooCommerce → Settings → Site visibility.', 'x7velo' );
		}
		return __( 'Your store is live, so shoppers, search engines and AI assistants can see it.', 'x7velo' );
	}
}
