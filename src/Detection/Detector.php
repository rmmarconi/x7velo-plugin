<?php
/**
 * Detection facade over the SEO/schema plugin adapters (spec §7).
 *
 * @package X7velo
 */

namespace X7velo\Detection;

use X7velo\Detection\Adapters\Aioseo;
use X7velo\Detection\Adapters\BaseAdapter;
use X7velo\Detection\Adapters\RankMath;
use X7velo\Detection\Adapters\Seopress;
use X7velo\Detection\Adapters\WooCore;
use X7velo\Detection\Adapters\Yoast;
use X7velo\Detection\Adapters\YoastWooSeo;

defined( 'ABSPATH' ) || exit;

/**
 * Answers "who emits Product/Organization, who serves llms.txt, which sitemap, who changes
 * robots.txt" from adapter options only. Never reads hook state, never writes, no HTTP.
 * Create one per request/audit run (answers are not cached across option changes).
 */
final class Detector {

	/**
	 * Plugins that may replace Woo's Product schema but have no verified adapter (spec §7.2
	 * safety rule), basename => label.
	 */
	public const KNOWN_SCHEMA_PLUGINS = array(
		'wpseo-woocommerce/wpseo-woocommerce.php'         => 'Yoast WooCommerce SEO',
		'seo-by-rank-math-pro/rank-math-pro.php'          => 'Rank Math Pro',
		'all-in-one-seo-pack-pro/all_in_one_seo_pack.php' => 'All in One SEO Pro',
		'wp-seopress-pro/seopress-pro.php'                => 'SEOPress Pro',
		'wp-schema-pro/wp-schema-pro.php'                 => 'Schema Pro',
		'schema-and-structured-data-for-wp/structured-data-for-wp.php' => 'Schema & Structured Data for WP',
		'wpsso/wpsso.php'                                 => 'WPSSO',
	);

	/**
	 * Product feed plugins (CRAWL-04), basename => label. Basenames verified on wordpress.org
	 * installs 2026-11-22.
	 */
	public const KNOWN_FEED_PLUGINS = array(
		'google-listings-and-ads/google-listings-and-ads.php' => 'Google for WooCommerce',
		'facebook-for-woocommerce/facebook-for-woocommerce.php' => 'Facebook for WooCommerce',
		'webappick-product-feed-for-woocommerce/woo-feed.php' => 'CTX Feed',
		'woo-product-feed-pro/woocommerce-sea.php' => 'Product Feed PRO',
	);

	/**
	 * Multi-currency / multilingual plugins that can change prices, currency or text per visitor
	 * (feed Cache-Control turns private), label => signals (`const:NAME` or `class:Name`).
	 */
	public const CONTEXT_DEPENDENT_PLUGINS = array(
		'WPML'                     => array( 'const:ICL_SITEPRESS_VERSION' ),
		'WooCommerce Multilingual' => array( 'const:WCML_VERSION', 'class:woocommerce_wpml' ),
		'Polylang'                 => array( 'const:POLYLANG_VERSION' ),
		'WOOCS Currency Switcher'  => array( 'const:WOOCS_VERSION', 'class:WOOCS' ),
		'Aelia Currency Switcher'  => array( 'class:Aelia\WC\CurrencySwitcher\WC_Aelia_CurrencySwitcher', 'class:WC_Aelia_CurrencySwitcher' ),
		'CURCY Multi Currency'     => array( 'const:WOOMULTI_CURRENCY_F_VERSION', 'const:WOOMULTI_CURRENCY_VERSION', 'class:WOOMULTI_CURRENCY_F', 'class:WOOMULTI_CURRENCY' ),
	);

	/**
	 * Adapters in sitemap-priority order (spec §7.4), WooCore first.
	 *
	 * @var Adapter[]
	 */
	private array $adapters;

	/**
	 * Signal source.
	 *
	 * @var Environment
	 */
	private Environment $env;

	/**
	 * Memoised active adapters.
	 *
	 * @var Adapter[]|null
	 */
	private ?array $active = null;

	/**
	 * Constructor.
	 *
	 * @param Environment|null $env      Signal source (tests pass a fake).
	 * @param Adapter[]|null   $adapters Adapters; null = defaults + `x7velo_detection_adapters` filter.
	 */
	public function __construct( ?Environment $env = null, ?array $adapters = null ) {
		$this->env      = $env ?? new Environment();
		$this->adapters = $adapters ?? self::default_adapters( $this->env );
	}

	/**
	 * Built-in adapters, filterable.
	 *
	 * @param Environment $env Signal source.
	 * @return Adapter[]
	 */
	public static function default_adapters( Environment $env ): array {
		$adapters = array(
			new WooCore( $env ),
			new Yoast( $env ),
			new YoastWooSeo( $env ),
			new RankMath( $env ),
			new Aioseo( $env ),
			new Seopress( $env ),
		);

		/**
		 * Filters the SEO/schema plugin detection adapters (spec §7.2). Order matters for the
		 * sitemap provider (first active adapter with a sitemap wins). Adapters must answer from
		 * options/constants only.
		 *
		 * @param Adapter[]   $adapters Adapters.
		 * @param Environment $env      Signal source.
		 */
		$filtered = apply_filters( 'x7velo_detection_adapters', $adapters, $env );
		if ( ! is_array( $filtered ) ) {
			return $adapters;
		}
		return array_values( array_filter( $filtered, static fn( $a ): bool => $a instanceof Adapter ) );
	}

	/**
	 * Signal source.
	 */
	public function environment(): Environment {
		return $this->env;
	}

	/**
	 * Active adapters (WooCore included when WooCommerce is loaded).
	 *
	 * @return Adapter[]
	 */
	public function active(): array {
		if ( null === $this->active ) {
			$this->active = array_values( array_filter( $this->adapters, static fn( Adapter $a ): bool => $a->is_active() ) );
		}
		return $this->active;
	}

	/**
	 * An active adapter by ID.
	 *
	 * @param string $id Adapter ID.
	 */
	public function get( string $id ): ?Adapter {
		foreach ( $this->active() as $adapter ) {
			if ( $adapter->id() === $id ) {
				return $adapter;
			}
		}
		return null;
	}

	/**
	 * Active non-Woo adapters that emit Product (by options).
	 *
	 * @return Adapter[]
	 */
	public function product_emitters(): array {
		return array_values(
			array_filter(
				$this->active(),
				static fn( Adapter $a ): bool => WooCore::ID !== $a->id() && $a->emits_product()
			)
		);
	}

	/**
	 * The adapter that removes Woo core's Product output, if any (by options).
	 */
	public function woo_product_removed_by(): ?Adapter {
		foreach ( $this->product_emitters() as $adapter ) {
			if ( $adapter->removes_woo_product() ) {
				return $adapter;
			}
		}
		return null;
	}

	/**
	 * Options-only Product source: the removing emitter, else another claiming emitter, else Woo.
	 *
	 * @return array{id: string, label: string}
	 */
	public function product_source(): array {
		$adapter = $this->woo_product_removed_by() ?? ( $this->product_emitters()[0] ?? null );
		return null === $adapter
			? array(
				'id'    => WooCore::ID,
				'label' => 'WooCommerce',
			)
			: array(
				'id'    => $adapter->id(),
				'label' => $adapter->label(),
			);
	}

	/**
	 * Active adapters that emit an Organization node.
	 *
	 * @return Adapter[]
	 */
	public function organization_emitters(): array {
		return array_values( array_filter( $this->active(), static fn( Adapter $a ): bool => $a->emits_organization() ) );
	}

	/**
	 * Active known schema plugins without a verified adapter (labels).
	 *
	 * @return string[]
	 */
	public function known_schema_plugins_active(): array {
		/**
		 * Filters the plugins that may replace Woo's Product schema without a verified adapter.
		 *
		 * @param array<string, string> $plugins Basename => label.
		 */
		return $this->active_from_list( (array) apply_filters( 'x7velo_known_schema_plugins', self::KNOWN_SCHEMA_PLUGINS ) );
	}

	/**
	 * Active product feed plugins (labels).
	 *
	 * @return string[]
	 */
	public function feed_plugins_active(): array {
		/**
		 * Filters the product feed plugins that make CRAWL-04 pass.
		 *
		 * @param array<string, string> $plugins Basename => label.
		 */
		return $this->active_from_list( (array) apply_filters( 'x7velo_known_feed_plugins', self::KNOWN_FEED_PLUGINS ) );
	}

	/**
	 * Active multi-currency / multilingual plugins (labels), detected by constant or class.
	 *
	 * @return string[]
	 */
	public function context_dependent_plugins(): array {
		/**
		 * Filters the plugins whose presence makes the product feed per-visitor (private cache).
		 *
		 * @param array<string, string[]> $plugins Label => signals (`const:NAME` / `class:Name`).
		 */
		$plugins = (array) apply_filters( 'x7velo_context_dependent_plugins', self::CONTEXT_DEPENDENT_PLUGINS );
		$labels  = array();
		foreach ( $plugins as $label => $signals ) {
			foreach ( (array) $signals as $signal ) {
				$signal = (string) $signal;
				$name   = substr( $signal, strpos( $signal, ':' ) + 1 );
				$hit    = str_starts_with( $signal, 'const:' ) ? $this->env->defined( $name ) : ( str_starts_with( $signal, 'class:' ) && $this->env->class_exists( $name ) );
				if ( $hit ) {
					$labels[] = (string) $label;
					break;
				}
			}
		}
		return $labels;
	}

	/**
	 * Fingerprint of everything that decides Product emission (active plugins + emitter state).
	 * Used to discard a stale front-end runtime sample.
	 */
	public function schema_fingerprint(): string {
		$claims = array_map( static fn( Adapter $a ): string => $a->id() . ( $a->removes_woo_product() ? ':r' : '' ), $this->product_emitters() );
		return md5( implode( ',', $this->env->active_plugins() ) . '|' . implode( ',', $claims ) );
	}

	/**
	 * Resolved sitemap provider (spec §7.4): first active adapter with a sitemap, else WP core
	 * when `blog_public = 1`, else null.
	 *
	 * `includes_products` answers for `$content_type`: products in store mode; `post` or `page` in
	 * site mode (spec §15.2, per-provider signals in the adapters' sitemap_includes()).
	 *
	 * @param string $content_type `product` (default), `post` or `page`.
	 * @return array{provider: string, label: string, path: string, includes_products: bool, settings_path: string}|null
	 */
	public function sitemap( string $content_type = 'product' ): ?array {
		foreach ( $this->active() as $adapter ) {
			$sitemap = $adapter->sitemap();
			if ( null !== $sitemap ) {
				if ( 'product' === $content_type ) {
					$includes = (bool) $sitemap['includes_products'];
				} else {
					$includes = $adapter instanceof BaseAdapter ? $adapter->sitemap_includes( $content_type ) : true;
				}
				return array(
					'provider'          => $adapter->id(),
					'label'             => $adapter->label(),
					'path'              => $sitemap['path'],
					'includes_products' => $includes,
					'settings_path'     => $adapter->settings_path( $includes || 'product' !== $content_type ? 'sitemap' : 'sitemap_products' ),
				);
			}
		}
		if ( '0' === (string) $this->env->option( 'blog_public', '1' ) ) {
			return null;
		}
		return array(
			'provider'          => 'core',
			'label'             => 'WordPress',
			'path'              => '/wp-sitemap.xml',
			'includes_products' => ! post_type_exists( $content_type ) || is_post_type_viewable( $content_type ),
			'settings_path'     => 'options-reading.php',
		);
	}

	/**
	 * Loads what the active SEO adapters need to answer is_post_noindex() for these posts (one
	 * query per source, none when no SEO plugin is active).
	 *
	 * @param int[] $ids Post IDs.
	 */
	public function prime_noindex( array $ids ): void {
		if ( array() === $ids ) {
			return;
		}
		foreach ( $this->active() as $adapter ) {
			if ( $adapter instanceof BaseAdapter ) {
				$adapter->prime_noindex( $ids );
			}
		}
	}

	/**
	 * Whether any active SEO adapter marks the post `noindex` (per post or post-type default).
	 *
	 * @param int $id Post ID.
	 */
	public function is_post_noindex( int $id ): bool {
		foreach ( $this->active() as $adapter ) {
			if ( $adapter instanceof BaseAdapter && $adapter->is_post_noindex( $id ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Distinct llms.txt sources (spec §7.5).
	 *
	 * @param bool $x7velo_enabled Whether x7velo's own llms.txt toggle is on (counted only when
	 *                             nothing else serves one and permalinks are readable).
	 * @return array<int, array{id: string, label: string, kind: string, served: bool}>
	 *         `served`: this is what /llms.txt returns (a physical file always wins).
	 */
	public function llms_sources( bool $x7velo_enabled = false ): array {
		$root      = $this->env->root_path() . 'llms.txt';
		$root_file = $this->env->file_exists( $root );
		$claimed   = false;
		$sources   = array();

		foreach ( $this->active() as $adapter ) {
			$source = $adapter->llms_source();
			if ( null === $source ) {
				continue;
			}
			$owns = false;
			if ( 'physical' === $source['kind'] ) {
				$path = $source['path'] ?? $root;
				$owns = $this->env->file_exists( $path ) && $adapter->owns_llms_file( $path );
				if ( $owns && $path === $root ) {
					$claimed = true;
				}
			}
			$sources[] = array(
				'id'     => $adapter->id(),
				'label'  => $adapter->label(),
				'kind'   => $source['kind'],
				'served' => $owns && ( $source['path'] ?? $root ) === $root,
			);
		}

		if ( $root_file && ! $claimed ) {
			$sources[] = array(
				'id'     => 'file',
				'label'  => 'file',
				'kind'   => 'physical',
				'served' => true,
			);
		}

		$readable_links = '' !== trim( (string) $this->env->option( 'permalink_structure', '' ) );
		if ( array() === $sources && $x7velo_enabled && $readable_links ) {
			$sources[] = array(
				'id'     => 'x7velo',
				'label'  => 'x7velo',
				'kind'   => 'dynamic',
				'served' => true,
			);
		}

		// No physical file: the (single) dynamic source is what's served.
		if ( ! $root_file ) {
			foreach ( $sources as $i => $source ) {
				if ( 'dynamic' === $source['kind'] ) {
					$sources[ $i ]['served'] = true;
					break;
				}
			}
		}

		return $sources;
	}

	/**
	 * Adapters that can serve llms.txt if turned on (for the Guided "turn on <plugin>'s llms.txt").
	 *
	 * @return Adapter[]
	 */
	public function llms_capable(): array {
		return array_values(
			array_filter(
				$this->active(),
				static fn( Adapter $a ): bool => in_array( $a->id(), array( Yoast::ID, Aioseo::ID, RankMath::ID ), true )
			)
		);
	}

	/**
	 * Front-end-only robots.txt changes to reconstruct, in application order (replace first).
	 *
	 * @return array<int, array{id: string, label: string, mode: string, content: string, marker: string, strip?: string, settings_path: string}>
	 */
	public function robots_modifiers(): array {
		$replace = array();
		$append  = array();
		foreach ( $this->active() as $adapter ) {
			$robots = $adapter->robots();
			if ( null === $robots ) {
				continue;
			}
			$entry = array_merge(
				$robots,
				array(
					'id'            => $adapter->id(),
					'label'         => $adapter->label(),
					'settings_path' => $adapter->settings_path( 'robots' ),
				)
			);
			if ( 'replace' === $robots['mode'] ) {
				$replace[] = $entry;
			} else {
				$append[] = $entry;
			}
		}
		return array_merge( $replace, $append );
	}

	/**
	 * Labels of the active plugins from a basename => label list.
	 *
	 * @param array<mixed, mixed> $plugins Basename => label.
	 * @return string[]
	 */
	private function active_from_list( array $plugins ): array {
		$labels = array();
		foreach ( $plugins as $basename => $label ) {
			if ( is_string( $basename ) && is_string( $label ) && $this->env->plugin_active( $basename ) ) {
				$labels[] = $label;
			}
		}
		return $labels;
	}
}
