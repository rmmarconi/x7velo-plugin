<?php
/**
 * CRAWL-01 ai-search-crawlers.
 *
 * @package X7velo
 */

namespace X7velo\Audit\Checks;

use RuntimeException;
use X7velo\Audit\Category;
use X7velo\Audit\Check;
use X7velo\Audit\CheckResult;
use X7velo\Audit\Fixer;
use X7velo\Audit\MessageRenderer;
use X7velo\Audit\Status;
use X7velo\Data\AiCrawlers;
use X7velo\Detection\RobotsRules;
use X7velo\Plugin;
use X7velo\Robots\CrawlerAccess;
use X7velo\Robots\RobotsTxt;

defined( 'ABSPATH' ) || exit;

/**
 * Every scored AI search/user crawler (`affects_score: true` in data/ai-crawlers.json) must be
 * allowed to fetch "/", the shop page and one product page under the served robots rules
 * (spec §5 CRAWL-01, §6.2). Training/experimental tokens never affect the status.
 */
final class AiSearchCrawlers implements Check, MessageRenderer {
	use BuildsResults;

	/**
	 * Served robots rules.
	 *
	 * @var RobotsRules
	 */
	private RobotsRules $rules;

	/**
	 * Crawler data file (tests); null = bundled file.
	 *
	 * @var string|null
	 */
	private ?string $crawlers_file;

	/**
	 * Constructor.
	 *
	 * @param RobotsRules $rules         Robots rules.
	 * @param string|null $crawlers_file Crawler data file (tests); null = bundled file.
	 */
	public function __construct( RobotsRules $rules, ?string $crawlers_file = null ) {
		$this->rules         = $rules;
		$this->crawlers_file = $crawlers_file;
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'CRAWL-01';
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
		return 10;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws RuntimeException When the crawler data file has no scored entries.
	 */
	public function run(): CheckResult {
		if ( RobotsRules::is_subfolder_install() ) {
			return $this->make( Status::NOT_APPLICABLE, 'subfolder_install', array( 'site_kind' => Plugin::site_kind() ) );
		}

		if ( '0' === (string) get_option( 'blog_public', '1' ) ) {
			return $this->make(
				Status::FAIL,
				'blocked_by_search_visibility',
				array(
					'check'     => 'VIS-01',
					'site_kind' => Plugin::site_kind(),
				),
				Fixer::GUIDED,
				'options-reading.php',
				true
			);
		}

		$scored   = array();
		$training = array();
		foreach ( AiCrawlers::agents( $this->crawlers_file ) as $agent ) {
			if ( true === ( $agent['affects_score'] ?? false ) ) {
				$scored[ $agent['token'] ] = $agent;
			} elseif ( 'training' === ( $agent['purpose'] ?? '' ) ) {
				$training[] = $agent['token'];
			}
		}
		if ( array() === $scored ) {
			throw new RuntimeException( 'No scored crawler entries in the data file.' );
		}

		$served = $this->rules->served();
		$access = new CrawlerAccess( RobotsTxt::parse( $served['text'] ), self::fallbacks() );
		$paths  = self::sample_paths();

		$blocked       = array();
		$owner_named   = array();
		$may_visit     = array();
		$fixable       = 0;
		$blocked_roots = 0;
		foreach ( $access->evaluate_all( array_keys( $scored ), $paths ) as $result ) {
			if ( ! $result['blocked'] ) {
				continue;
			}
			$token     = $result['token'];
			$blocked[] = $token;
			if ( $result['blocked_root'] ) {
				++$blocked_roots;
			}
			if ( $result['named'] || '' !== $result['via_fallback'] ) {
				$owner_named[] = $token;
			} else {
				++$fixable;
			}
			if ( false === ( $scored[ $token ]['respects_robots'] ?? null ) ) {
				$may_visit[] = $token;
			}
		}

		$training_blocked = 0;
		foreach ( $access->evaluate_all( $training, array( '/' ) ) as $result ) {
			$training_blocked += $result['blocked'] ? 1 : 0;
		}

		$physical = 'file' === $served['source'];
		$details  = array(
			'source'                 => $served['source'],
			'physical_file'          => $physical,
			'scored_count'           => count( $scored ),
			'blocked_count'          => count( $blocked ),
			// Lists are capped here (not only by CheckResult) so the run-time message and the one
			// rendered later from the stored record are identical.
			'blocked'                => array_slice( $blocked, 0, CheckResult::MAX_LIST_ITEMS ),
			'owner_named'            => array_slice( $owner_named, 0, CheckResult::MAX_LIST_ITEMS ),
			'may_still_visit'        => array_slice( $may_visit, 0, CheckResult::MAX_LIST_ITEMS ),
			'fixable_count'          => $fixable,
			'training_blocked_count' => $training_blocked,
			'x7velo_block'           => $served['x7velo_block'],
			'site_kind'              => Plugin::site_kind(),
		);
		// Robots fixer only when it can act: filter output (no physical file) and something
		// it may change (blocked tokens the owner didn't name). Otherwise copy-paste / Guided.
		$fixer = ( ! $physical && $fixable > 0 ) ? Fixer::ROBOTS : Fixer::GUIDED;

		if ( array() === $blocked ) {
			return $this->make(
				Status::PASS,
				'search_crawlers_allowed',
				$details,
				$physical ? Fixer::GUIDED : Fixer::ROBOTS
			);
		}

		if ( count( $scored ) === $blocked_roots ) {
			return $this->make(
				Status::FAIL,
				'all_search_crawlers_blocked',
				$details,
				$fixer,
				'',
				true
			);
		}

		return $this->make( Status::WARN, 'some_search_crawlers_blocked', $details, $fixer );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $code    Code.
	 * @param array<string, mixed> $details Details.
	 */
	public function render_message( string $code, array $details ): string {
		if ( 'site' === ( $details['site_kind'] ?? '' ) ) {
			return self::render_site_message( $code, $details );
		}
		switch ( $code ) {
			case 'subfolder_install':
				return __( 'Your store is in a subfolder, so its robots.txt is managed by the main site. We can’t check it from here yet.', 'x7velo' );
			case 'blocked_by_search_visibility':
				return __( 'Your site asks every search engine and AI assistant to stay away (Settings → Reading). Fix that first; x7velo never uses robots.txt to undo it.', 'x7velo' );
			case 'all_search_crawlers_blocked':
				return __( 'Your robots.txt rules block every AI shopping and search assistant from your store. You can still block AI training bots — the fix only lets shopping and search assistants in.', 'x7velo' );
			case 'some_search_crawlers_blocked':
				$names = array_filter( (array) ( $details['blocked'] ?? array() ), 'is_string' );
				$more  = max( 0, (int) ( $details['blocked_count'] ?? 0 ) - count( $names ) );
				$list  = implode( ', ', $names );
				if ( $more > 0 ) {
					/* translators: 1: comma-separated crawler names, 2: number of further crawlers. */
					$list = sprintf( _n( '%1$s and %2$d more', '%1$s and %2$d more', $more, 'x7velo' ), $list, $more );
				}
				$text = sprintf(
					/* translators: %s: crawler names, e.g. "OAI-SearchBot, PerplexityBot". */
					__( 'Some AI assistants that recommend products are blocked from reading your store: %s. You can still block AI training bots — this only lets shopping and search assistants in.', 'x7velo' ),
					$list
				);
				$may_visit = array_filter( (array) ( $details['may_still_visit'] ?? array() ), 'is_string' );
				if ( array() !== $may_visit ) {
					/* translators: %s: crawler names. */
					$text .= ' ' . sprintf( __( 'Your robots.txt asks %s not to visit; they may still visit when a user asks.', 'x7velo' ), implode( ', ', $may_visit ) );
				}
				return $text;
		}
		if ( ! empty( $details['training_blocked_count'] ) ) {
			return __( 'AI shopping assistants can read your store. You block AI training bots. That’s your choice and doesn’t affect your score.', 'x7velo' );
		}
		return __( 'AI shopping assistants can read your store.', 'x7velo' );
	}

	/**
	 * Site-mode messages (spec §15.6): no "store", "shop" or "product".
	 *
	 * @param string               $code    Code.
	 * @param array<string, mixed> $details Details.
	 */
	private static function render_site_message( string $code, array $details ): string {
		switch ( $code ) {
			case 'subfolder_install':
				return __( 'Your site is in a subfolder, so its robots.txt is managed by the main site. We can’t check it from here yet.', 'x7velo' );
			case 'blocked_by_search_visibility':
				return __( 'Your site asks every search engine and AI assistant to stay away (Settings → Reading). Fix that first; x7velo never uses robots.txt to undo it.', 'x7velo' );
			case 'all_search_crawlers_blocked':
				return __( 'Your robots.txt rules block every AI search assistant from your site. You can still block AI training bots — the fix only lets search assistants in.', 'x7velo' );
			case 'some_search_crawlers_blocked':
				$names = array_filter( (array) ( $details['blocked'] ?? array() ), 'is_string' );
				$more  = max( 0, (int) ( $details['blocked_count'] ?? 0 ) - count( $names ) );
				$list  = implode( ', ', $names );
				if ( $more > 0 ) {
					/* translators: 1: comma-separated crawler names, 2: number of further crawlers. */
					$list = sprintf( _n( '%1$s and %2$d more', '%1$s and %2$d more', $more, 'x7velo' ), $list, $more );
				}
				/* translators: %s: crawler names, e.g. "OAI-SearchBot, PerplexityBot". */
				$text      = sprintf( __( 'Some AI search assistants are blocked: %s.', 'x7velo' ), $list );
				$text     .= ' ' . __( 'You can still block AI training bots — this only lets search assistants in.', 'x7velo' );
				$may_visit = array_filter( (array) ( $details['may_still_visit'] ?? array() ), 'is_string' );
				if ( array() !== $may_visit ) {
					/* translators: %s: crawler names. */
					$text .= ' ' . sprintf( __( 'Your robots.txt asks %s not to visit; they may still visit when a user asks.', 'x7velo' ), implode( ', ', $may_visit ) );
				}
				return $text;
		}
		if ( ! empty( $details['training_blocked_count'] ) ) {
			return __( 'AI search assistants can read your site. You block AI training bots. That’s your choice and doesn’t affect your score.', 'x7velo' );
		}
		return __( 'AI search assistants can read your site.', 'x7velo' );
	}

	/**
	 * Documented crawler fallbacks (spec §6.2.4), filterable.
	 *
	 * @return array<string, string>
	 */
	public static function fallbacks(): array {
		/**
		 * Filters crawler fallbacks: token => token whose group it follows when no group names it.
		 *
		 * @param array<string, string> $fallbacks Fallbacks.
		 */
		$fallbacks = apply_filters( 'x7velo_robots_agent_fallbacks', CrawlerAccess::DEFAULT_FALLBACKS );
		return is_array( $fallbacks ) ? $fallbacks : CrawlerAccess::DEFAULT_FALLBACKS;
	}

	/**
	 * Sample paths: "/", the shop page and the newest published product (spec §5 CRAWL-01).
	 *
	 * @return string[]
	 */
	public static function sample_paths(): array {
		if ( ! Plugin::is_woocommerce_active() ) {
			return self::site_sample_paths();
		}
		$paths = array( '/' );
		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$paths[] = self::path_of( (string) wc_get_page_permalink( 'shop' ) );
		}
		if ( function_exists( 'wc_get_products' ) ) {
			$ids = wc_get_products(
				array(
					'status'  => 'publish',
					'limit'   => 1,
					'orderby' => 'ID',
					'order'   => 'DESC',
					'return'  => 'ids',
				)
			);
			if ( is_array( $ids ) && isset( $ids[0] ) ) {
				$link = get_permalink( (int) $ids[0] );
				if ( is_string( $link ) && '' !== $link ) {
					$paths[] = self::path_of( $link );
				}
			}
		}
		return array_values( array_unique( $paths ) );
	}

	/**
	 * Site-mode sample paths (spec §15.2): "/", the posts page when the front page is static, and
	 * the newest published non-password post (else the newest such page other than the front
	 * page). One `fields => ids`, `posts_per_page => 1` query per step.
	 *
	 * @return string[]
	 */
	public static function site_sample_paths(): array {
		$paths = array( '/' );
		$front = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front', 0 ) : 0;
		$posts = (int) get_option( 'page_for_posts', 0 );
		if ( $front > 0 && $posts > 0 && 'publish' === get_post_status( $posts ) ) {
			$link = get_permalink( $posts );
			if ( is_string( $link ) && '' !== $link ) {
				$paths[] = self::path_of( $link );
			}
		}

		$newest = self::newest_id( 'post', array() );
		if ( 0 === $newest ) {
			$newest = self::newest_id( 'page', array_filter( array( $front ) ) );
		}
		if ( $newest > 0 ) {
			$link = get_permalink( $newest );
			if ( is_string( $link ) && '' !== $link ) {
				$paths[] = self::path_of( $link );
			}
		}
		return array_values( array_unique( $paths ) );
	}

	/**
	 * Newest published, non-password post of a type (0 if none).
	 *
	 * @param string $type    Post type.
	 * @param int[]  $exclude IDs to skip.
	 */
	private static function newest_id( string $type, array $exclude ): int {
		$args = array(
			'post_type'      => $type,
			'post_status'    => 'publish',
			'has_password'   => false,
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'orderby'        => array(
				'date' => 'DESC',
				'ID'   => 'DESC',
			),
			'no_found_rows'  => true,
		);
		if ( array() !== $exclude ) {
			$args['post__not_in'] = array_map( 'intval', $exclude ); // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- At most one ID (the front page).
		}
		$ids = get_posts( $args );
		return isset( $ids[0] ) ? (int) $ids[0] : 0;
	}

	/**
	 * Path + query of a URL ("/" when empty).
	 *
	 * @param string $url URL.
	 */
	public static function path_of( string $url ): string {
		$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		$path  = '' === $path ? '/' : $path;
		return '' === $query ? $path : $path . '?' . $query;
	}
}
