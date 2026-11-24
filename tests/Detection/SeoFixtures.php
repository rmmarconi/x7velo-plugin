<?php
/**
 * Loads SEO-plugin option fixtures captured from real installs.
 *
 * @package X7velo
 */

namespace X7velo\Tests\Detection;

/**
 * Fixtures live in tests/fixtures/seo/<plugin>-<version>.json (captured by the track E
 * integration run on WP 7.1.5 / WC 11.3.1 with the real free plugins at default settings;
 * Rank Math with registration skipped).
 */
final class SeoFixtures {

	public const YOAST     = 'yoast-28.9';
	public const RANK_MATH = 'rank-math-1.0.282';
	public const AIOSEO    = 'aioseo-5.0.5.1';
	public const SEOPRESS  = 'seopress-10.4';

	/**
	 * Writes the fixture's options with update_option().
	 *
	 * @param string $name Fixture name (class constant).
	 * @return array<string, mixed> The options written.
	 */
	public static function load( string $name ): array {
		$data = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/seo/' . $name . '.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture.
		foreach ( $data['options'] as $option => $value ) {
			update_option( $option, $value );
		}
		return $data['options'];
	}

	/**
	 * Merges keys into an array option.
	 *
	 * @param string               $option Option.
	 * @param array<string, mixed> $values Values.
	 */
	public static function merge( string $option, array $values ): void {
		$current = get_option( $option, array() );
		update_option( $option, array_merge( is_array( $current ) ? $current : array(), $values ) );
	}
}
