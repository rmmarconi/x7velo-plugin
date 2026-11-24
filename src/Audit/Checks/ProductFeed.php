<?php
/**
 * CRAWL-04 product-feed.
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
use X7velo\Detection\Detector;
use X7velo\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Pass when x7velo's feed is on or a known feed plugin is active (no Warn state; spec §5).
 */
final class ProductFeed implements Check, MessageRenderer {
	use BuildsResults;

	/**
	 * Detector.
	 *
	 * @var Detector
	 */
	private Detector $detector;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Detector $detector Detector.
	 * @param Settings $settings Settings.
	 */
	public function __construct( Detector $detector, Settings $settings ) {
		$this->detector = $detector;
		$this->settings = $settings;
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'CRAWL-04';
	}

	/**
	 * {@inheritDoc}
	 */
	public function category(): string {
		return Category::CRAWL;
	}

	/**
	 * {@inheritDoc}
	 */
	public function weight(): int {
		return 5;
	}

	/**
	 * {@inheritDoc}
	 */
	public function run(): CheckResult {
		if ( true === $this->settings->get( 'fix_feed' ) ) {
			return $this->make( Status::PASS, 'feed_x7velo', array( 'source' => 'x7velo' ), Fixer::FEED );
		}
		$plugins = $this->detector->feed_plugins_active();
		if ( array() !== $plugins ) {
			return $this->make(
				Status::PASS,
				'feed_managed',
				array(
					'source'  => $plugins[0],
					'plugins' => $plugins,
				),
				Fixer::FEED
			);
		}
		return $this->make( Status::FAIL, 'no_feed', array(), Fixer::FEED );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $code    Code.
	 * @param array<string, mixed> $details Details.
	 */
	public function render_message( string $code, array $details ): string {
		switch ( $code ) {
			case 'feed_x7velo':
				return __( 'Your store has a product feed (from x7velo).', 'x7velo' );
			case 'feed_managed':
				/* translators: %s: plugin name, e.g. "Google for WooCommerce". */
				return sprintf( __( 'Your store has a product feed (from %s).', 'x7velo' ), (string) ( $details['source'] ?? '' ) );
		}
		return __( 'You don’t have a product feed. A feed gives shopping assistants your full product list with prices and stock in one file.', 'x7velo' );
	}
}
