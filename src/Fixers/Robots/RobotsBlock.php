<?php
/**
 * Pure builder for x7velo's robots.txt block (spec §6.3).
 *
 * @package X7velo
 */

namespace X7velo\Fixers\Robots;

use X7velo\Robots\CrawlerAccess;
use X7velo\Robots\PathMatcher;
use X7velo\Robots\RobotsTxt;

defined( 'ABSPATH' ) || exit;

/**
 * Computes the block x7velo appends to the owner's robots rules. Pure: no WordPress calls, no
 * I/O. Every input (owner rules, settings, crawler entries, site facts) is passed in, so the
 * golden files (QA plan §3) run as fast unit tests and the front end, the audit and the
 * copy-paste blocks all use the same algorithm.
 */
final class RobotsBlock {

	public const BEGIN = '# x7velo: begin';
	public const END   = '# x7velo: end';

	/**
	 * Builds the block.
	 *
	 * @param string                           $owner_rules Owner rules (everything except x7velo).
	 * @param array<string, mixed>             $settings    Keys used: fix_robots_search, fix_robots_block_training,
	 *                                                      block_google_extended, block_experimental.
	 * @param array<int, array<string, mixed>> $agents Entries of data/ai-crawlers.json.
	 * @param array<string, mixed>             $site        Site facts: admin_path, ajax_path, private_paths (string[]),
	 *                                                      sitemap_url ('' = none), home_host, sample_paths (string[]),
	 *                                                      fallbacks (token => token).
	 * @return array{block: string, allow: string, training: string, sitemap: string}
	 *         `block`: exact text to append ('' = nothing; ends with "\n"). `allow`, `training`,
	 *         `sitemap`: the parts (no trailing newline), '' when not emitted.
	 */
	public static function build( string $owner_rules, array $settings, array $agents, array $site ): array {
		$owner = RobotsTxt::parse( $owner_rules );

		$allow   = ! empty( $settings['fix_robots_search'] ) ? self::allow_group( $owner, $owner_rules, $agents, $site ) : '';
		$sitemap = ! empty( $settings['fix_robots_search'] ) ? self::sitemap_line( $owner, $site ) : '';

		$training = self::training_group( $owner, self::training_tokens( $settings, $agents ) );

		$parts = array_values( array_filter( array( $allow, $training, $sitemap ), static fn( string $p ): bool => '' !== $p ) );
		$block = array() === $parts ? '' : self::BEGIN . "\n" . implode( "\n\n", $parts ) . "\n" . self::END . "\n";

		return array(
			'block'    => $block,
			'allow'    => $allow,
			'training' => $training,
			'sitemap'  => $sitemap,
		);
	}

	/**
	 * Scored tokens blocked today from any sample path that the owner rules don't name (and don't
	 * reach through a documented fallback such as Applebot → Googlebot), in data-file order.
	 *
	 * @param RobotsTxt                        $owner  Parsed owner rules.
	 * @param array<int, array<string, mixed>> $agents Crawler entries.
	 * @param array<string, mixed>             $site   Site facts (sample_paths, fallbacks).
	 * @return string[]
	 */
	public static function candidates( RobotsTxt $owner, array $agents, array $site ): array {
		$scored = array();
		foreach ( $agents as $agent ) {
			if ( true === ( $agent['affects_score'] ?? false ) && isset( $agent['token'] ) && is_string( $agent['token'] ) ) {
				$scored[] = $agent['token'];
			}
		}
		$paths = array_values( array_filter( (array) ( $site['sample_paths'] ?? array( '/' ) ), 'is_string' ) );
		if ( ! in_array( '/', $paths, true ) ) {
			array_unshift( $paths, '/' );
		}
		$fallbacks = is_array( $site['fallbacks'] ?? null ) ? $site['fallbacks'] : CrawlerAccess::DEFAULT_FALLBACKS;
		$access    = new CrawlerAccess( $owner, $fallbacks );

		$out = array();
		foreach ( $access->evaluate_all( $scored, $paths ) as $result ) {
			if ( $result['blocked'] && ! $result['named'] && '' === $result['via_fallback'] ) {
				$out[] = $result['token'];
			}
		}
		return $out;
	}

	/**
	 * The "allow" group (§6.3 steps 1–4), '' when no candidate remains.
	 *
	 * @param RobotsTxt                        $owner       Parsed owner rules.
	 * @param string                           $owner_rules Raw owner rules.
	 * @param array<int, array<string, mixed>> $agents      Crawler entries.
	 * @param array<string, mixed>             $site        Site facts.
	 */
	private static function allow_group( RobotsTxt $owner, string $owner_rules, array $agents, array $site ): string {
		$tokens = self::candidates( $owner, $agents, $site );
		if ( array() === $tokens ) {
			return '';
		}
		$lines = array();
		foreach ( $tokens as $token ) {
			$lines[] = 'User-agent: ' . $token;
		}
		$lines[] = 'Allow: /';

		$rules = array();
		foreach ( self::star_disallows( $owner_rules ) as $value ) {
			$rules[] = 'Disallow: ' . $value;
		}
		foreach ( (array) ( $site['private_paths'] ?? array() ) as $path ) {
			if ( is_string( $path ) && self::is_safe_path( $path ) && '/' !== $path ) {
				$rules[] = 'Disallow: ' . $path;
			}
		}
		$admin = self::is_safe_path( (string) ( $site['admin_path'] ?? '' ) ) ? (string) $site['admin_path'] : '/wp-admin/';
		$ajax  = self::is_safe_path( (string) ( $site['ajax_path'] ?? '' ) ) ? (string) $site['ajax_path'] : '/wp-admin/admin-ajax.php';
		if ( '/' !== $admin ) {
			$rules[] = 'Disallow: ' . $admin;
		}
		$rules[] = 'Allow: ' . $ajax;

		return implode( "\n", array_merge( $lines, array_values( array_unique( $rules ) ) ) );
	}

	/**
	 * Raw values of every non-empty `Disallow` line in the owner's `User-agent: *` group(s), in
	 * file order, except a blanket `Disallow: /` (or `/*`). Same group semantics as RobotsTxt.
	 *
	 * @param string $owner_rules Raw owner rules.
	 * @return string[]
	 */
	public static function star_disallows( string $owner_rules ): array {
		$text = substr( $owner_rules, 0, RobotsTxt::MAX_BYTES );
		if ( 0 === strncmp( $text, "\xEF\xBB\xBF", 3 ) ) {
			$text = substr( $text, 3 );
		}
		$values     = array();
		$is_star    = false;
		$collecting = false;
		$in_group   = false;
		foreach ( (array) preg_split( '/\r\n|\r|\n/', $text ) as $line ) {
			$line = (string) $line;
			$hash = strpos( $line, '#' );
			if ( false !== $hash ) {
				$line = substr( $line, 0, $hash );
			}
			$colon = strpos( $line, ':' );
			if ( false === $colon ) {
				continue;
			}
			$key   = strtolower( trim( substr( $line, 0, $colon ) ) );
			$value = trim( substr( $line, $colon + 1 ) );
			if ( 'user-agent' === $key ) {
				if ( ! $collecting ) {
					$is_star    = false;
					$collecting = true;
					$in_group   = true;
				}
				if ( '*' === RobotsTxt::agent_token( $value ) ) {
					$is_star = true;
				}
				continue;
			}
			if ( 'allow' === $key || 'disallow' === $key ) {
				$collecting = false;
				if ( ! $in_group || ! $is_star || 'disallow' !== $key || '' === $value ) {
					continue;
				}
				$pattern = PathMatcher::normalize_pattern( $value );
				if ( '/' === $pattern || '/*' === $pattern ) {
					continue;
				}
				// Verbatim when it is a plain path; otherwise the form crawlers read it as
				// ("private" → "/private", spaces percent-encoded).
				$value = self::is_safe_path( $value ) ? $value : $pattern;
				if ( self::is_safe_path( $value ) ) {
					$values[] = $value;
				}
			}
		}
		return array_values( array_unique( $values ) );
	}

	/**
	 * Training tokens selected by the settings, in data-file order, de-duplicated: the default
	 * training set (purpose training, not experimental, not Google-Extended, never a scored
	 * token), Google-Extended, and the selected experimental tokens (re-filtered on the data).
	 *
	 * @param array<string, mixed>             $settings Settings.
	 * @param array<int, array<string, mixed>> $agents   Crawler entries.
	 * @return string[]
	 */
	public static function training_tokens( array $settings, array $agents ): array {
		$experimental = array_map(
			static fn( $t ): string => strtolower( trim( (string) $t ) ),
			array_filter( (array) ( $settings['block_experimental'] ?? array() ), 'is_string' )
		);
		$tokens       = array();
		foreach ( $agents as $agent ) {
			$token = isset( $agent['token'] ) && is_string( $agent['token'] ) ? $agent['token'] : '';
			if ( '' === $token || true === ( $agent['affects_score'] ?? false ) ) {
				continue;
			}
			$is_experimental = true === ( $agent['experimental'] ?? false );
			if ( $is_experimental ) {
				$on = in_array( strtolower( $token ), $experimental, true );
			} elseif ( 0 === strcasecmp( $token, 'Google-Extended' ) ) {
				$on = ! empty( $settings['block_google_extended'] );
			} else {
				$on = 'training' === ( $agent['purpose'] ?? '' ) && ! empty( $settings['fix_robots_block_training'] );
			}
			if ( $on && ! isset( $tokens[ strtolower( $token ) ] ) ) {
				$tokens[ strtolower( $token ) ] = $token;
			}
		}
		return array_values( $tokens );
	}

	/**
	 * The training group (`Disallow: /`), skipping tokens the owner already names. '' if empty.
	 *
	 * @param RobotsTxt $owner  Parsed owner rules.
	 * @param string[]  $tokens Selected tokens.
	 */
	private static function training_group( RobotsTxt $owner, array $tokens ): string {
		$lines = array();
		foreach ( $tokens as $token ) {
			if ( ! $owner->names_agent( $token ) ) {
				$lines[] = 'User-agent: ' . $token;
			}
		}
		if ( array() === $lines ) {
			return '';
		}
		$lines[] = 'Disallow: /';
		return implode( "\n", $lines );
	}

	/**
	 * `Sitemap:` line for the provider URL, '' when there is no provider or the owner rules
	 * already reference it (host case-insensitive, scheme and trailing slash ignored).
	 *
	 * @param RobotsTxt            $owner Parsed owner rules.
	 * @param array<string, mixed> $site  Site facts (sitemap_url).
	 */
	private static function sitemap_line( RobotsTxt $owner, array $site ): string {
		$url = (string) ( $site['sitemap_url'] ?? '' );
		if ( '' === $url || ! preg_match( '#^https?://[^\s]+\z#i', $url ) ) {
			return '';
		}
		$want = self::url_key( $url );
		foreach ( $owner->sitemaps() as $existing ) {
			if ( self::url_key( $existing ) === $want ) {
				return '';
			}
		}
		return 'Sitemap: ' . $url;
	}

	/**
	 * Comparison key of a URL: lower-case host + path without trailing slash.
	 *
	 * @param string $url URL.
	 */
	private static function url_key( string $url ): string {
		$parts = wp_parse_url( trim( $url ) );
		$host  = strtolower( (string) ( $parts['host'] ?? '' ) );
		$path  = rtrim( (string) ( $parts['path'] ?? '' ), '/' );
		return $host . $path;
	}

	/**
	 * Whether a path/pattern can be written on a robots.txt line safely (starts with "/" or "*",
	 * no whitespace or control characters, no "#").
	 *
	 * @param string $path Path or pattern.
	 */
	private static function is_safe_path( string $path ): bool {
		return 1 === preg_match( '#^[/*][^\s\x00-\x1F\x7F\#]*\z#', $path );
	}
}
