<?php
/**
 * Per-crawler access evaluation over a set of sample paths (spec §6.2).
 *
 * @package X7velo
 */

namespace X7velo\Robots;

defined( 'ABSPATH' ) || exit;

/**
 * Pure: evaluates which crawler tokens may fetch which paths, with documented fallbacks.
 *
 * A fallback ("applebot" => "googlebot") applies only when no group names the token itself
 * and some group names the fallback token (Apple: Applebot follows Googlebot rules when there
 * are no Applebot rules). Otherwise normal RFC 9309 selection (`*`) applies.
 */
final class CrawlerAccess {

	/**
	 * Default documented fallbacks, lower-case token => lower-case fallback token (spec §6.2.4).
	 */
	public const DEFAULT_FALLBACKS = array( 'applebot' => 'googlebot' );

	/**
	 * Parsed rules.
	 *
	 * @var RobotsTxt
	 */
	private RobotsTxt $robots;

	/**
	 * Fallbacks, lower-case token => lower-case token.
	 *
	 * @var array<string, string>
	 */
	private array $fallbacks;

	/**
	 * Constructor.
	 *
	 * @param RobotsTxt             $robots    Parsed robots.txt.
	 * @param array<string, string> $fallbacks Token => fallback token (any case).
	 */
	public function __construct( RobotsTxt $robots, array $fallbacks = self::DEFAULT_FALLBACKS ) {
		$this->robots    = $robots;
		$this->fallbacks = array();
		foreach ( $fallbacks as $token => $fallback ) {
			if ( is_string( $token ) && is_string( $fallback ) && '' !== $token && '' !== $fallback ) {
				$this->fallbacks[ strtolower( $token ) ] = strtolower( $fallback );
			}
		}
	}

	/**
	 * Evaluates one token over the sample paths.
	 *
	 * @param string   $token Product token as written in the data file.
	 * @param string[] $paths Sample paths; the first one is treated as the root ("/").
	 * @return array{token: string, named: bool, via_fallback: string, blocked: bool, blocked_root: bool, blocked_paths: string[]}
	 *         `named`: a group names the token itself. `via_fallback`: the fallback token whose
	 *         group was used ('' if none). `blocked`: at least one path disallowed.
	 *         `blocked_root`: "/" disallowed.
	 */
	public function evaluate( string $token, array $paths ): array {
		$lower     = strtolower( $token );
		$named     = $this->robots->names_agent( $lower );
		$effective = $lower;
		$via       = '';
		if ( ! $named && isset( $this->fallbacks[ $lower ] ) && $this->robots->names_agent( $this->fallbacks[ $lower ] ) ) {
			$effective = $this->fallbacks[ $lower ];
			$via       = $effective;
		}

		$blocked_paths = array();
		$blocked_root  = false;
		foreach ( array_values( array_unique( $paths ) ) as $path ) {
			if ( ! $this->robots->is_allowed( $effective, $path ) ) {
				$blocked_paths[] = $path;
				if ( '/' === $path ) {
					$blocked_root = true;
				}
			}
		}

		return array(
			'token'         => $token,
			'named'         => $named,
			'via_fallback'  => $via,
			'blocked'       => array() !== $blocked_paths,
			'blocked_root'  => $blocked_root,
			'blocked_paths' => $blocked_paths,
		);
	}

	/**
	 * Evaluates many tokens.
	 *
	 * @param string[] $tokens Tokens.
	 * @param string[] $paths  Sample paths (include "/").
	 * @return array<int, array{token: string, named: bool, via_fallback: string, blocked: bool, blocked_root: bool, blocked_paths: string[]}>
	 */
	public function evaluate_all( array $tokens, array $paths ): array {
		$out = array();
		foreach ( $tokens as $token ) {
			$out[] = $this->evaluate( $token, $paths );
		}
		return $out;
	}
}
