<?php
/**
 * CRAWL-02 sitemap.
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
use X7velo\Detection\RobotsRules;
use X7velo\Plugin;
use X7velo\Robots\RobotsTxt;

defined( 'ABSPATH' ) || exit;

/**
 * An XML sitemap must be active, include products and be referenced by a `Sitemap:` line in
 * the served robots rules (spec §5 CRAWL-02, §7.4). Provider resolved from options only.
 */
final class Sitemap implements Check, MessageRenderer {
	use BuildsResults;

	/**
	 * Detector.
	 *
	 * @var Detector
	 */
	private Detector $detector;

	/**
	 * Robots rules.
	 *
	 * @var RobotsRules
	 */
	private RobotsRules $rules;

	/**
	 * Site kind override (`store` | `site`); null = current mode.
	 *
	 * @var string|null
	 */
	private ?string $site_kind;

	/**
	 * Constructor.
	 *
	 * @param Detector    $detector  Detector.
	 * @param RobotsRules $rules     Robots rules.
	 * @param string|null $site_kind `store` (products) or `site` (posts/pages); null = current
	 *                               mode (Plugin::site_kind()). Options-only either way.
	 */
	public function __construct( Detector $detector, RobotsRules $rules, ?string $site_kind = null ) {
		$this->detector  = $detector;
		$this->rules     = $rules;
		$this->site_kind = in_array( $site_kind, array( 'store', 'site' ), true ) ? $site_kind : null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'CRAWL-02';
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
		$kind    = $this->site_kind ?? Plugin::site_kind();
		$type    = 'site' === $kind ? self::content_type() : 'product';
		$sitemap = $this->detector->sitemap( $type );
		$base    = array( 'site_kind' => $kind );
		if ( 'site' === $kind ) {
			$base['content_type'] = $type;
		}

		if ( null === $sitemap ) {
			if ( '0' === (string) get_option( 'blog_public', '1' ) ) {
				return $this->make( Status::FAIL, 'no_sitemap_search_discouraged', array( 'check' => 'VIS-01' ) + $base, Fixer::GUIDED, 'options-reading.php' );
			}
			return $this->make( Status::FAIL, 'no_sitemap', $base, Fixer::GUIDED );
		}

		$details = array(
			'provider'     => $sitemap['provider'],
			'source'       => $sitemap['label'],
			'sitemap_path' => $sitemap['path'],
		) + $base;

		if ( ! $sitemap['includes_products'] ) {
			return $this->make( Status::FAIL, 'sitemap_excludes_products', $details, Fixer::GUIDED, $sitemap['settings_path'] );
		}

		if ( RobotsRules::is_subfolder_install() ) {
			// The root robots.txt belongs to another site (M2 checks it over HTTP).
			$details['robots_checked'] = false;
			return $this->make( Status::PASS, 'sitemap_ok', $details, Fixer::GUIDED, $sitemap['settings_path'] );
		}

		$served                    = $this->rules->served();
		$details['robots_checked'] = true;
		$details['physical_file']  = 'file' === $served['source'];

		if ( ! self::referenced( RobotsTxt::parse( $served['text'] )->sitemaps(), $sitemap['path'] ) ) {
			return $this->make( Status::WARN, 'sitemap_not_referenced', $details, $details['physical_file'] ? Fixer::GUIDED : Fixer::ROBOTS );
		}

		return $this->make( Status::PASS, 'sitemap_ok', $details, Fixer::GUIDED, $sitemap['settings_path'] );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $code    Code.
	 * @param array<string, mixed> $details Details.
	 */
	public function render_message( string $code, array $details ): string {
		$source = (string) ( $details['source'] ?? '' );
		if ( 'site' === ( $details['site_kind'] ?? '' ) ) {
			return self::render_site_message( $code, $details, $source );
		}
		switch ( $code ) {
			case 'no_sitemap_search_discouraged':
				return __( 'You don’t have a sitemap: WordPress turns it off while your site asks search engines to stay away. Fix that first in Settings → Reading.', 'x7velo' );
			case 'no_sitemap':
				return __( 'You don’t have a sitemap, so assistants have no list of your product pages.', 'x7velo' );
			case 'sitemap_excludes_products':
				/* translators: %s: plugin name, e.g. "Yoast SEO". */
				return sprintf( __( 'Your sitemap (from %s) leaves out your products, or your products are set to “noindex”. Include products in its settings.', 'x7velo' ), $source );
			case 'sitemap_not_referenced':
				return __( 'Your sitemap works, but your robots.txt doesn’t point to it, so assistants may not find it.', 'x7velo' );
		}
		if ( false === ( $details['robots_checked'] ?? true ) ) {
			/* translators: %s: plugin name, e.g. "Yoast SEO". */
			return sprintf( __( 'Your sitemap (from %s) lists your products.', 'x7velo' ), $source );
		}
		/* translators: %s: plugin name, e.g. "Yoast SEO". */
		return sprintf( __( 'Your sitemap (from %s) lists your products and assistants can find it.', 'x7velo' ), $source );
	}

	/**
	 * Site-mode messages (spec §15.6): "content included" instead of "products included".
	 *
	 * @param string               $code    Code.
	 * @param array<string, mixed> $details Details.
	 * @param string               $source  Sitemap provider label.
	 */
	private static function render_site_message( string $code, array $details, string $source ): string {
		// Whole sentences per content type: "pages"/"posts" change gender and articles in translation.
		$pages = 'page' === ( $details['content_type'] ?? '' );
		switch ( $code ) {
			case 'no_sitemap_search_discouraged':
				return __( 'You don’t have a sitemap: WordPress turns it off while your site asks search engines to stay away. Fix that first in Settings → Reading.', 'x7velo' );
			case 'no_sitemap':
				return __( 'You don’t have a sitemap, so assistants have no list of your pages.', 'x7velo' );
			case 'sitemap_excludes_products':
				return $pages
					/* translators: %s: plugin name, e.g. "Yoast SEO". */
					? sprintf( __( 'Your sitemap leaves out your pages, or they are set to “noindex”. Include them in the sitemap settings of %s.', 'x7velo' ), $source )
					/* translators: %s: plugin name, e.g. "Yoast SEO". */
					: sprintf( __( 'Your sitemap leaves out your posts, or they are set to “noindex”. Include them in the sitemap settings of %s.', 'x7velo' ), $source );
			case 'sitemap_not_referenced':
				return __( 'Your sitemap works, but your robots.txt doesn’t point to it, so assistants may not find it.', 'x7velo' );
		}
		if ( false === ( $details['robots_checked'] ?? true ) ) {
			return $pages
				? __( 'Your sitemap lists your pages.', 'x7velo' )
				: __( 'Your sitemap lists your posts.', 'x7velo' );
		}
		return $pages
			? __( 'Your sitemap lists your pages and assistants can find it.', 'x7velo' )
			: __( 'Your sitemap lists your posts and assistants can find it.', 'x7velo' );
	}

	/**
	 * Site-mode content type (spec §15.2): `post` when the site has a published post, else `page`.
	 */
	public static function content_type(): string {
		$counts = wp_count_posts( 'post' );
		return isset( $counts->publish ) && (int) $counts->publish > 0 ? 'post' : 'page';
	}

	/**
	 * Whether a `Sitemap:` URL points at this site's sitemap path (host case-insensitive,
	 * scheme ignored, so an http/https mismatch still counts).
	 *
	 * @param string[] $urls Sitemap URLs from robots.txt.
	 * @param string   $path Provider path relative to home, e.g. "/sitemap_index.xml".
	 */
	public static function referenced( array $urls, string $path ): bool {
		$home_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$expected  = untrailingslashit( (string) wp_parse_url( home_url( $path ), PHP_URL_PATH ) );
		foreach ( $urls as $url ) {
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			$got  = untrailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) );
			if ( $host === $home_host && $got === $expected ) {
				return true;
			}
		}
		return false;
	}
}
