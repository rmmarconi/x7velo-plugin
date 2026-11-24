<?php
/**
 * Effective robots.txt rules, computed in-process (spec §6.2).
 *
 * @package X7velo
 */

namespace X7velo\Detection;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the robots.txt text the site serves, without HTTP:
 *
 * 1. a physical `ABSPATH/robots.txt` wins (read locally, bounded);
 * 2. else WP core's default output run through `apply_filters( 'robots_txt' )` in-process, with
 *    x7velo's own callback excluded (it must return its input unchanged while
 *    computing_owner_rules() is true), then SEO-plugin lines that are only added on the front
 *    end are reconstructed from options (Rank Math editor replaces; Yoast block appends).
 *
 * That is the "owner rules". served() adds x7velo's robots block via the
 * `x7velo_robots_block` filter (the robots fixer hooks it), i.e. what crawlers get.
 */
final class RobotsRules {

	/**
	 * True while owner rules are being computed.
	 *
	 * @var bool
	 */
	private static bool $computing = false;

	/**
	 * Detector.
	 *
	 * @var Detector
	 */
	private Detector $detector;

	/**
	 * Signal source.
	 *
	 * @var Environment
	 */
	private Environment $env;

	/**
	 * Memoised owner rules.
	 *
	 * @var array{source: string, text: string, modifiers: string[]}|null
	 */
	private ?array $owner = null;

	/**
	 * Constructor.
	 *
	 * @param Detector $detector Detector (its environment is used for file reads).
	 */
	public function __construct( Detector $detector ) {
		$this->detector = $detector;
		$this->env      = $detector->environment();
	}

	/**
	 * Whether owner rules are being computed right now. x7velo's `robots_txt` callback must
	 * return its input unchanged while this is true.
	 */
	public static function computing_owner_rules(): bool {
		return self::$computing;
	}

	/**
	 * Absolute path of the physical robots.txt.
	 */
	public function physical_path(): string {
		return $this->env->root_path() . 'robots.txt';
	}

	/**
	 * Whether a physical robots.txt exists (the web server serves it; filters have no effect).
	 */
	public function has_physical_file(): bool {
		return $this->env->file_exists( $this->physical_path() );
	}

	/**
	 * Whether WordPress is installed in a subfolder of the domain (`home` has a path): the
	 * root robots.txt then belongs to another site.
	 */
	public static function is_subfolder_install(): bool {
		$path = (string) wp_parse_url( home_url(), PHP_URL_PATH );
		return '' !== trim( $path, '/' );
	}

	/**
	 * Owner rules: everything except x7velo.
	 *
	 * @return array{source: string, text: string, modifiers: string[]} `source` "file" or
	 *         "filter"; `modifiers`: IDs of adapters whose front-end lines were reconstructed.
	 */
	public function owner(): array {
		if ( null !== $this->owner ) {
			return $this->owner;
		}
		if ( $this->has_physical_file() ) {
			$this->owner = array(
				'source'    => 'file',
				'text'      => (string) $this->env->read_file( $this->physical_path() ),
				'modifiers' => array(),
			);
			return $this->owner;
		}

		// WP core do_robots() default (WP 5.3+: no "Disallow: /" when discouraged).
		$output  = "User-agent: *\n";
		$output .= 'Disallow: ' . wp_parse_url( admin_url(), PHP_URL_PATH ) . "\n";
		$output .= 'Allow: ' . wp_parse_url( admin_url( 'admin-ajax.php' ), PHP_URL_PATH ) . "\n";
		$public  = (bool) $this->env->option( 'blog_public', '1' );

		self::$computing = true;
		try {
			/** This filter is documented in wp-includes/functions.php */
			$output = (string) apply_filters( 'robots_txt', $output, $public ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.
		} finally {
			self::$computing = false;
		}

		$applied = array();
		foreach ( $this->detector->robots_modifiers() as $modifier ) {
			if ( 'replace' === $modifier['mode'] ) {
				$output    = $modifier['content'];
				$applied[] = $modifier['id'];
				continue;
			}
			if ( '' !== $modifier['marker'] && false !== strpos( $output, $modifier['marker'] ) ) {
				continue; // The plugin's own callback already ran (e.g. WP-CLI on Yoast).
			}
			if ( ! empty( $modifier['strip'] ) ) {
				$output = (string) preg_replace( $modifier['strip'], '', $output );
			}
			$output    = trim( $output . "\n" . $modifier['content'] . "\n" );
			$applied[] = $modifier['id'];
		}

		$this->owner = array(
			'source'    => 'filter',
			'text'      => $output,
			'modifiers' => $applied,
		);
		return $this->owner;
	}

	/**
	 * What crawlers get: owner rules plus x7velo's block (when the filter applies).
	 *
	 * @return array{source: string, text: string, modifiers: string[], x7velo_block: bool}
	 */
	public function served(): array {
		$owner = $this->owner();
		$block = '';
		if ( 'filter' === $owner['source'] ) {
			/**
			 * Filters x7velo's robots.txt block for the given owner rules ('' = none). The
			 * robots fixer hooks this so the audit sees exactly what the front end serves.
			 *
			 * @param string $block       Block to append.
			 * @param string $owner_rules Owner rules (everything except x7velo).
			 */
			$block = (string) apply_filters( 'x7velo_robots_block', '', $owner['text'] );
		}
		$served                 = $owner;
		$served['x7velo_block'] = '' !== trim( $block );
		if ( $served['x7velo_block'] ) {
			$served['text'] = rtrim( $owner['text'] ) . "\n\n" . $block;
		}
		return $served;
	}
}
