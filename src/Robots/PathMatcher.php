<?php
/**
 * Path pattern matching for robots.txt (RFC 9309 §2.2.2, §2.2.3).
 *
 * @package X7velo
 */

namespace X7velo\Robots;

defined( 'ABSPATH' ) || exit;

/**
 * Pure helpers: normalises paths/patterns and tests whether a pattern matches a path.
 *
 * Supported syntax: `*` matches any sequence of characters, a trailing `$` anchors the end.
 * Matching is case-sensitive (paths are), starts at the beginning of the path and compares
 * percent-encoded forms so `/caf%C3%A9` and `/café` are the same path.
 */
final class PathMatcher {

	/**
	 * Normalises a path or pattern for comparison.
	 *
	 * - percent-encodes bytes outside printable ASCII (and spaces);
	 * - upper-cases existing `%xx` escapes;
	 * - decodes escapes of unreserved characters (ALPHA / DIGIT / "-" / "." / "_" / "~");
	 * - collapses runs of `*` (same meaning, avoids pathological regexes).
	 *
	 * @param string $value Raw path or pattern.
	 */
	public static function normalize( string $value ): string {
		$out    = '';
		$length = strlen( $value );
		for ( $i = 0; $i < $length; $i++ ) {
			$char = $value[ $i ];
			if ( '%' === $char && $i + 2 < $length && ctype_xdigit( substr( $value, $i + 1, 2 ) ) ) {
				$hex     = strtoupper( substr( $value, $i + 1, 2 ) );
				$decoded = chr( (int) hexdec( $hex ) );
				$out    .= preg_match( '/^[A-Za-z0-9\-._~]$/', $decoded ) ? $decoded : '%' . $hex;
				$i      += 2;
				continue;
			}
			$ord = ord( $char );
			if ( $ord <= 0x20 || $ord >= 0x7F ) {
				$out .= '%' . strtoupper( str_pad( dechex( $ord ), 2, '0', STR_PAD_LEFT ) );
				continue;
			}
			$out .= $char;
		}
		return (string) preg_replace( '/\*{2,}/', '*', $out );
	}

	/**
	 * Normalises a rule pattern: like normalize(), and a pattern that starts with neither
	 * "/" nor "*" gets a leading "/" (what major crawlers do with `Disallow: private`).
	 *
	 * @param string $pattern Raw pattern from an Allow/Disallow line.
	 */
	public static function normalize_pattern( string $pattern ): string {
		$pattern = self::normalize( $pattern );
		if ( '' !== $pattern && '/' !== $pattern[0] && '*' !== $pattern[0] ) {
			$pattern = '/' . $pattern;
		}
		return $pattern;
	}

	/**
	 * Whether a normalised pattern matches a normalised path.
	 *
	 * @param string $pattern Normalised pattern (normalize_pattern()).
	 * @param string $path    Normalised path (normalize()), starting with "/".
	 */
	public static function matches( string $pattern, string $path ): bool {
		if ( '' === $pattern ) {
			return false;
		}
		$anchored = '$' === substr( $pattern, -1 );
		if ( $anchored ) {
			$pattern = substr( $pattern, 0, -1 );
		}
		// Fast path: plain prefix.
		if ( false === strpos( $pattern, '*' ) ) {
			return $anchored ? $pattern === $path : 0 === strncmp( $path, $pattern, strlen( $pattern ) );
		}
		$parts = array_map(
			static fn( string $part ): string => preg_quote( $part, '#' ),
			explode( '*', $pattern )
		);
		$regex = '#^' . implode( '.*', $parts ) . ( $anchored ? '$' : '' ) . '#s';
		return 1 === preg_match( $regex, $path );
	}
}
