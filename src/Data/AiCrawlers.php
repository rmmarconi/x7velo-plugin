<?php
/**
 * Reader for the bundled AI crawler list.
 *
 * @package X7velo
 */

namespace X7velo\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Reads plugin/data/ai-crawlers.json (shipped with the plugin, never fetched remotely).
 */
final class AiCrawlers {

	/**
	 * Memoised agents per file path.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private static array $cache = array();

	/**
	 * Path of the bundled data file.
	 */
	public static function default_path(): string {
		return dirname( __DIR__, 2 ) . '/data/ai-crawlers.json';
	}

	/**
	 * Agent entries (empty if the file is missing or invalid).
	 *
	 * @param string|null $path File path (tests); null = bundled file.
	 * @return array<int, array<string, mixed>>
	 */
	public static function agents( ?string $path = null ): array {
		$path = $path ?? self::default_path();
		if ( ! isset( self::$cache[ $path ] ) ) {
			$data                 = is_readable( $path ) ? wp_json_file_decode( $path, array( 'associative' => true ) ) : null;
			$agents               = is_array( $data ) && isset( $data['agents'] ) && is_array( $data['agents'] ) ? $data['agents'] : array();
			self::$cache[ $path ] = array_values( array_filter( $agents, static fn( $a ) => is_array( $a ) && isset( $a['token'] ) && is_string( $a['token'] ) ) );
		}
		return self::$cache[ $path ];
	}

	/**
	 * Tokens of entries marked `"experimental": true`, as written in the file.
	 *
	 * @param string|null $path File path (tests); null = bundled file.
	 * @return string[]
	 */
	public static function experimental_tokens( ?string $path = null ): array {
		$tokens = array();
		foreach ( self::agents( $path ) as $agent ) {
			if ( true === ( $agent['experimental'] ?? false ) ) {
				$tokens[] = $agent['token'];
			}
		}
		return $tokens;
	}
}
