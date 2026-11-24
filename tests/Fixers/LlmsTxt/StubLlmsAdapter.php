<?php
/**
 * Test adapter that serves a dynamic llms.txt (stands in for Rank Math's module / Yoast's toggle).
 *
 * @package X7velo
 */

namespace X7velo\Tests\Fixers\LlmsTxt;

use X7velo\Detection\Adapters\BaseAdapter;

/**
 * Active only while $active is true; registered through `x7velo_detection_adapters`.
 */
final class StubLlmsAdapter extends BaseAdapter {

	/**
	 * Whether the stub plugin is active with its llms.txt on.
	 *
	 * @var bool
	 */
	public static bool $active = false;

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'yoast';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'Yoast SEO';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_active(): bool {
		return self::$active;
	}

	/**
	 * {@inheritDoc}
	 */
	public function llms_source(): ?array {
		return array( 'kind' => 'dynamic' );
	}

	/**
	 * Registers the stub for every Detector.
	 */
	public static function hook(): void {
		add_filter(
			'x7velo_detection_adapters',
			static function ( $adapters, $env ) {
				$adapters[] = new self( $env );
				return $adapters;
			},
			10,
			2
		);
	}
}
