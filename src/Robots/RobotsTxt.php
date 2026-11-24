<?php
/**
 * Parser and evaluator for robots.txt (RFC 9309).
 *
 * @package X7velo
 */

namespace X7velo\Robots;

defined( 'ABSPATH' ) || exit;

/**
 * Parsed robots.txt. Pure: no WordPress state, no I/O.
 *
 * Semantics (RFC 9309):
 * - A group starts with one or more consecutive `User-agent` lines followed by rules. A
 *   `User-agent` line after a rule starts a new group. Empty lines, comments and unknown
 *   records (Crawl-delay, Host, …) neither end nor start a group.
 * - Rules before the first `User-agent` line belong to no group and are ignored.
 * - A crawler obeys every group that names its product token (case-insensitive), merged; if
 *   none names it, every `*` group, merged; if there is no `*` group either, everything is
 *   allowed.
 * - Among the matching rules the longest pattern wins; on a tie `Allow` wins. An empty
 *   `Disallow:` (or `Allow:`) is not a rule.
 * - `Sitemap` records are global, wherever they appear.
 */
final class RobotsTxt {

	/**
	 * Parsing stops after this many bytes (RFC 9309 §2.5: at least 500 KiB must be parsed).
	 */
	public const MAX_BYTES = 512000;

	/**
	 * Groups: lower-case product tokens ("*" for the wildcard) and rules.
	 *
	 * @var array<int, array{agents: string[], rules: array<int, array{allow: bool, pattern: string}>}>
	 */
	private array $groups = array();

	/**
	 * Sitemap URLs in file order (raw values, trimmed).
	 *
	 * @var string[]
	 */
	private array $sitemaps = array();

	/**
	 * Use parse().
	 */
	private function __construct() {
	}

	/**
	 * Parses robots.txt content.
	 *
	 * @param string $text robots.txt body.
	 */
	public static function parse( string $text ): self {
		$robots = new self();
		$text   = substr( $text, 0, self::MAX_BYTES );
		// Strip a UTF-8 BOM.
		if ( 0 === strncmp( $text, "\xEF\xBB\xBF", 3 ) ) {
			$text = substr( $text, 3 );
		}

		$current    = null; // Index of the group being built.
		$collecting = false; // True while reading the User-agent lines of a group.

		foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $line ) {
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

			switch ( $key ) {
				case 'user-agent':
					if ( ! $collecting ) {
						$robots->groups[] = array(
							'agents' => array(),
							'rules'  => array(),
						);
						$current          = count( $robots->groups ) - 1;
						$collecting       = true;
					}
					$agent = self::agent_token( $value );
					if ( '' !== $agent && ! in_array( $agent, $robots->groups[ $current ]['agents'], true ) ) {
						$robots->groups[ $current ]['agents'][] = $agent;
					}
					break;

				case 'allow':
				case 'disallow':
					if ( null === $current ) {
						break;
					}
					$collecting = false;
					$pattern    = PathMatcher::normalize_pattern( $value );
					if ( '' !== $pattern ) {
						$robots->groups[ $current ]['rules'][] = array(
							'allow'   => 'allow' === $key,
							'pattern' => $pattern,
						);
					}
					break;

				case 'sitemap':
					if ( '' !== $value ) {
						$robots->sitemaps[] = $value;
					}
					break;
			}
		}

		// A group whose User-agent values were all unusable can never match.
		$robots->groups = array_values( array_filter( $robots->groups, static fn( array $g ): bool => array() !== $g['agents'] ) );

		return $robots;
	}

	/**
	 * Product token of a User-agent value, lower-cased: "*" for the wildcard, else the leading
	 * run of token characters ("Googlebot/2.1" → "googlebot"). "" when unusable.
	 *
	 * @param string $value Raw User-agent value.
	 */
	public static function agent_token( string $value ): string {
		$value = trim( $value );
		if ( '' !== $value && '*' === $value[0] ) {
			return '*';
		}
		return preg_match( '/^[A-Za-z0-9_-]+/', $value, $m ) ? strtolower( $m[0] ) : '';
	}

	/**
	 * Groups as parsed.
	 *
	 * @return array<int, array{agents: string[], rules: array<int, array{allow: bool, pattern: string}>}>
	 */
	public function groups(): array {
		return $this->groups;
	}

	/**
	 * Sitemap URLs.
	 *
	 * @return string[]
	 */
	public function sitemaps(): array {
		return $this->sitemaps;
	}

	/**
	 * Whether some group names this token explicitly (not via `*`).
	 *
	 * @param string $token Product token (any case).
	 */
	public function names_agent( string $token ): bool {
		$token = strtolower( $token );
		foreach ( $this->groups as $group ) {
			if ( '*' !== $token && in_array( $token, $group['agents'], true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Merged rules that apply to a token, or null when no group applies (everything allowed).
	 *
	 * @param string $token Product token (any case).
	 * @return array<int, array{allow: bool, pattern: string}>|null
	 */
	public function rules_for( string $token ): ?array {
		$token = strtolower( $token );
		$rules = null;
		if ( '*' !== $token ) {
			$rules = $this->merged_rules( $token );
		}
		return $rules ?? $this->merged_rules( '*' );
	}

	/**
	 * Whether the token may fetch the path.
	 *
	 * @param string $token Product token (any case).
	 * @param string $path  URL path (with query, if any), starting with "/".
	 */
	public function is_allowed( string $token, string $path ): bool {
		$rules = $this->rules_for( $token );
		return null === $rules ? true : self::evaluate( $rules, $path );
	}

	/**
	 * Applies RFC 9309 precedence to a rule list.
	 *
	 * @param array<int, array{allow: bool, pattern: string}> $rules Rules.
	 * @param string                                          $path  URL path.
	 */
	public static function evaluate( array $rules, string $path ): bool {
		$path = PathMatcher::normalize( '' === $path ? '/' : $path );
		if ( '/robots.txt' === $path ) {
			return true; // RFC 9309 §2.2.2: always allowed.
		}
		$best_length = -1;
		$allowed     = true;
		foreach ( $rules as $rule ) {
			if ( ! PathMatcher::matches( $rule['pattern'], $path ) ) {
				continue;
			}
			$length = strlen( $rule['pattern'] );
			if ( $length > $best_length || ( $length === $best_length && $rule['allow'] ) ) {
				$best_length = $length;
				$allowed     = $rule['allow'];
			}
		}
		return $allowed;
	}

	/**
	 * Rules of every group naming the token, merged; null if none names it.
	 *
	 * @param string $token Lower-case token or "*".
	 * @return array<int, array{allow: bool, pattern: string}>|null
	 */
	private function merged_rules( string $token ): ?array {
		$found = false;
		$rules = array();
		foreach ( $this->groups as $group ) {
			if ( in_array( $token, $group['agents'], true ) ) {
				$found = true;
				$rules = array_merge( $rules, $group['rules'] );
			}
		}
		return $found ? $rules : null;
	}
}
