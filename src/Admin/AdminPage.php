<?php
/**
 * AI Readiness admin page (WooCommerce › AI Readiness, or Tools › AI Readiness without WooCommerce).
 *
 * @package X7velo
 */

namespace X7velo\Admin;

use X7velo\Audit\AuditRunningException;
use X7velo\Audit\AuditService;
use X7velo\Capabilities;
use X7velo\Data\AiCrawlers;
use X7velo\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the "AI Readiness" submenu under WooCommerce, enqueues the React dashboard only on
 * that screen, prints the page data it needs, and renders a no-JS fallback (design §1.1, §3.1).
 *
 * The React app talks to the REST API (x7velo/v1/audit, x7velo/v1/settings) with the cookie
 * nonce that core attaches to wp-api-fetch; nothing here writes options.
 */
final class AdminPage {

	public const SLUG    = Capabilities::SLUG;
	public const HANDLE  = 'x7velo-admin';
	public const ROOT_ID = 'x7velo-admin-root';

	/**
	 * Action name for the no-JS "Run audit" form (admin-post.php).
	 */
	public const NOJS_ACTION = 'x7velo_run_audit';

	/**
	 * Screen hook suffix returned by add_submenu_page().
	 *
	 * @var string|null
	 */
	private static ?string $hook_suffix = null;

	/**
	 * Audit service, used only by the no-JS fallback.
	 *
	 * @var AuditService|null
	 */
	private static ?AuditService $audit = null;

	/**
	 * Hooks the page into WordPress. Call once from Plugin::boot().
	 *
	 * @param AuditService|null $audit Audit service for the no-JS fallback (optional).
	 */
	public static function register( ?AuditService $audit = null ): void {
		self::$audit = $audit;
		// Priority after WooCommerce registers its own submenus (it uses 9–60 on admin_menu).
		add_action( 'admin_menu', array( self::class, 'add_menu' ), 70 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'admin_post_' . self::NOJS_ACTION, array( self::class, 'handle_nojs_run' ) );
		if ( defined( 'X7VELO_PLUGIN_FILE' ) ) {
			add_filter(
				'plugin_action_links_' . plugin_basename( X7VELO_PLUGIN_FILE ),
				array( self::class, 'action_links' )
			);
		}
	}

	/**
	 * Adds WooCommerce › AI Readiness, or Tools › AI Readiness without WooCommerce (spec §15.5).
	 */
	public static function add_menu(): void {
		$hook = add_submenu_page(
			Capabilities::parent(),
			__( 'AI Readiness', 'x7velo' ),
			__( 'AI Readiness', 'x7velo' ),
			Capabilities::manage(),
			self::SLUG,
			array( self::class, 'render' )
		);

		self::$hook_suffix = $hook ? $hook : null;
	}

	/**
	 * URL of the page, optionally on a tab (menu_page_url(); see Capabilities::page_url()).
	 *
	 * @param string $tab Tab name (overview|fixes|settings) or ''.
	 */
	public static function url( string $tab = '' ): string {
		return Capabilities::page_url( $tab );
	}

	/**
	 * Plugins screen row action "Open AI Readiness" (replaces a generic "Settings" link).
	 *
	 * @param array<string|int, string> $links Existing links.
	 * @return array<string|int, string>
	 */
	public static function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		if ( ! Capabilities::current_user_can_manage() ) {
			return $links;
		}
		$open = sprintf(
			'<a href="%s">%s</a>',
			esc_url( self::url() ),
			esc_html__( 'Open AI Readiness', 'x7velo' )
		);
		return array_merge( array( 'x7velo-open' => $open ), $links );
	}

	/**
	 * Enqueues the dashboard bundle on our screen only.
	 *
	 * @param string $hook_suffix Current admin screen hook suffix.
	 */
	public static function enqueue( $hook_suffix ): void {
		if ( null === self::$hook_suffix || self::$hook_suffix !== $hook_suffix ) {
			return;
		}
		$dir   = self::plugin_dir();
		$asset = $dir . 'build/index.asset.php';
		if ( ! is_readable( $asset ) ) {
			// Build missing (e.g. a git checkout without `npm run build`): the no-JS fallback stays.
			return;
		}
		$meta = require $asset;
		$deps = isset( $meta['dependencies'] ) && is_array( $meta['dependencies'] ) ? $meta['dependencies'] : array();
		$ver  = isset( $meta['version'] ) ? (string) $meta['version'] : self::plugin_version();
		$url  = plugins_url( 'build/', $dir . 'x7velo.php' );

		wp_enqueue_script( self::HANDLE, $url . 'index.js', $deps, $ver, array( 'in_footer' => true ) );
		wp_add_inline_script(
			self::HANDLE,
			'window.x7veloAdmin = ' . wp_json_encode( self::page_data(), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP ) . ';',
			'before'
		);
		wp_set_script_translations( self::HANDLE, 'x7velo', $dir . 'languages' );

		$css = is_rtl() ? 'style-index-rtl.css' : 'style-index.css';
		if ( is_readable( $dir . 'build/' . $css ) ) {
			wp_enqueue_style( self::HANDLE, $url . $css, array( 'wp-components' ), $ver );
		}
	}

	/**
	 * Data the dashboard fills in at render time (never stored in the audit record).
	 *
	 * @return array<string, mixed>
	 */
	public static function page_data(): array {
		$home      = home_url( '/' );
		$host      = (string) wp_parse_url( $home, PHP_URL_HOST );
		$path      = (string) wp_parse_url( $home, PHP_URL_PATH );
		$scheme    = (string) wp_parse_url( $home, PHP_URL_SCHEME );
		$woo       = Plugin::is_woocommerce_active();
		$countries = $woo && function_exists( 'WC' ) && WC()->countries ? WC()->countries : null;
		$country   = '';
		if ( $countries ) {
			$code    = $countries->get_base_country();
			$all     = $countries->get_countries();
			$country = isset( $all[ $code ] ) ? html_entity_decode( (string) $all[ $code ], ENT_QUOTES, 'UTF-8' ) : '';
		}

		/**
		 * Conflict notices shown on the AI Readiness page ("taken over" / "active again").
		 *
		 * Each entry: { id: string, type: 'taken_over'|'resumed', area: 'llms_txt'|'jsonld',
		 * source: plugin slug }. Fired once per transition by the code that tracks
		 * `x7velo_notice_state` (spec §8); the page only displays them.
		 *
		 * @param array<int, array<string, string>> $notices Notices.
		 */
		$notices = apply_filters( 'x7velo_admin_page_notices', array() );

		return array(
			// Current mode (spec §15): false = site mode (no store checks/fixers, Tools menu).
			'woocommerceActive' => $woo,
			'homeUrl'           => esc_url_raw( $home ),
			'siteHost'          => $host,
			'homePath'          => '' === $path ? '/' : $path,
			'mainSiteUrl'       => esc_url_raw( $scheme . '://' . $host . '/' ),
			'locale'            => str_replace( '_', '-', determine_locale() ),
			'baseCountry'       => $country,
			'urls'              => array(
				'page'           => self::url(),
				'reading'        => admin_url( 'options-reading.php' ),
				'permalinks'     => admin_url( 'options-permalink.php' ),
				'siteVisibility' => admin_url( 'admin.php?page=wc-settings&tab=site-visibility' ),
				'newProduct'     => admin_url( 'post-new.php?post_type=product' ),
				'newPage'        => admin_url( 'post-new.php?post_type=page' ),
				'products'       => admin_url( 'edit.php?post_type=product' ),
				'llmsTxt'        => home_url( '/llms.txt' ),
				'feed'           => add_query_arg( 'x7velo_feed', '1', $home ),
				'supportForum'   => 'https://wordpress.org/support/plugin/x7velo/',
			),
			'versions'          => array(
				'plugin'      => self::plugin_version(),
				'wp'          => get_bloginfo( 'version' ),
				'wc'          => defined( 'WC_VERSION' ) ? WC_VERSION : '',
				'php'         => PHP_VERSION,
				'memoryLimit' => (string) ini_get( 'memory_limit' ),
			),
			'crawlers'          => self::crawler_data(),
			'glaActive'         => self::is_plugin_active( 'google-listings-and-ads/google-listings-and-ads.php' ),
			'coreLabels'        => self::core_labels(),
			'notices'           => is_array( $notices ) ? array_values( $notices ) : array(),
		);
	}

	/**
	 * Crawler groups from data/ai-crawlers.json (never hard-coded; spec §6.1).
	 *
	 * @return array{training: array<int, array{token: string, operator: string}>, experimental: array<int, array{token: string, operator: string}>, googleExtended: bool}
	 */
	public static function crawler_data(): array {
		$training        = array();
		$experimental    = array();
		$google_extended = false;
		foreach ( AiCrawlers::agents() as $agent ) {
			$token    = (string) $agent['token'];
			$operator = isset( $agent['operator'] ) ? (string) $agent['operator'] : '';
			$entry    = array(
				'token'    => $token,
				'operator' => $operator,
			);
			if ( 0 === strcasecmp( $token, 'Google-Extended' ) ) {
				$google_extended = true;
				continue;
			}
			if ( true === ( $agent['experimental'] ?? false ) ) {
				$experimental[] = $entry;
				continue;
			}
			if ( 'training' === ( $agent['purpose'] ?? '' ) ) {
				$training[] = $entry;
			}
		}
		return array(
			'training'       => $training,
			'experimental'   => $experimental,
			'googleExtended' => $google_extended,
		);
	}

	/**
	 * WordPress core labels, translated by core, so steps match what the owner sees on that
	 * screen (design §5.6).
	 *
	 * @return array<string, string>
	 */
	private static function core_labels(): array {
		// phpcs:disable WordPress.WP.I18n.TextDomainMismatch -- Intentionally core's own strings (design §5.6).
		$labels = array(
			'discourage'  => __( 'Discourage search engines from indexing this site', 'default' ),
			'saveChanges' => __( 'Save Changes', 'default' ),
			'postName'    => __( 'Post name', 'default' ),
		);
		// phpcs:enable WordPress.WP.I18n.TextDomainMismatch
		return $labels;
	}

	/**
	 * Renders the page shell. React mounts into the root div and replaces the fallback inside it.
	 */
	public static function render(): void {
		if ( ! Capabilities::current_user_can_manage() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'x7velo' ), 403 );
		}
		?>
		<div class="wrap x7velo-admin">
			<h1><?php esc_html_e( 'AI Readiness', 'x7velo' ); ?></h1>
			<p class="x7velo-byline"><?php esc_html_e( 'by x7velo', 'x7velo' ); ?></p>
			<div id="<?php echo esc_attr( self::ROOT_ID ); ?>">
				<?php self::render_fallback(); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * No-JS / JS-failed fallback (design §3.1 notes): the running text, the last score if any,
	 * and a plain form that runs the audit through admin-post.php.
	 */
	private static function render_fallback(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only flag for a notice after our own redirect.
		$ran    = isset( $_GET['x7velo_ran'] ) ? sanitize_key( wp_unslash( $_GET['x7velo_ran'] ) ) : '';
		$record = null !== self::$audit ? self::$audit->last() : null;
		?>
		<div class="x7velo-nojs">
			<?php if ( 'running' === $ran ) : ?>
				<div class="notice notice-info inline"><p><?php esc_html_e( 'An audit is already running, maybe from another tab or another admin. Reload this page in a moment to see the results.', 'x7velo' ); ?></p></div>
			<?php endif; ?>
			<?php if ( is_array( $record ) && isset( $record['score'] ) && is_int( $record['score'] ) ) : ?>
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: score from 0 to 100. */
							__( 'Your AI readiness score is %d out of 100.', 'x7velo' ),
							$record['score']
						)
					);
					?>
				</p>
				<p><?php esc_html_e( 'To see the details and fixes, use a browser with JavaScript turned on.', 'x7velo' ); ?></p>
			<?php elseif ( ! Plugin::is_woocommerce_active() ) : ?>
				<p><strong><?php esc_html_e( 'Checking your site…', 'x7velo' ); ?></strong></p>
				<p><?php esc_html_e( 'We’re looking at your settings and pages to see how easily AI assistants like ChatGPT and Perplexity can read your site.', 'x7velo' ); ?></p>
				<p><?php esc_html_e( 'This check runs on your own site. Nothing is sent anywhere, and nothing on your site is changed.', 'x7velo' ); ?></p>
			<?php else : ?>
				<p><strong><?php esc_html_e( 'Checking your store…', 'x7velo' ); ?></strong></p>
				<p><?php esc_html_e( 'We’re looking at your settings and product details to see how easily AI assistants like ChatGPT and Perplexity can read your store.', 'x7velo' ); ?></p>
				<p><?php esc_html_e( 'This check runs on your own site. Nothing is sent anywhere, and nothing on your store is changed.', 'x7velo' ); ?></p>
			<?php endif; ?>
			<?php if ( null !== self::$audit ) : ?>
				<?php // Not inside <noscript>: it must also work when JavaScript is on but the bundle fails to load. ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::NOJS_ACTION ); ?>" />
					<?php wp_nonce_field( self::NOJS_ACTION ); ?>
					<?php submit_button( __( 'Run audit', 'x7velo' ), 'primary', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Handles the no-JS "Run audit" form. Read-only audit; nothing on the store changes.
	 */
	public static function handle_nojs_run(): void {
		if ( ! Capabilities::current_user_can_manage() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'x7velo' ), 403 );
		}
		check_admin_referer( self::NOJS_ACTION );

		$flag = 'done';
		if ( null !== self::$audit ) {
			try {
				self::$audit->run();
			} catch ( AuditRunningException $e ) {
				$flag = 'running';
			}
		}
		wp_safe_redirect( add_query_arg( 'x7velo_ran', $flag, self::url() ) );
		exit;
	}

	/**
	 * Whether a plugin is active (works before wp-admin/includes/plugin.php is loaded).
	 *
	 * @param string $basename Plugin basename.
	 */
	private static function is_plugin_active( string $basename ): bool {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return is_plugin_active( $basename ) || ( is_multisite() && is_plugin_active_for_network( $basename ) );
	}

	/**
	 * Plugin root directory with trailing slash.
	 */
	private static function plugin_dir(): string {
		return defined( 'X7VELO_PLUGIN_FILE' ) ? plugin_dir_path( X7VELO_PLUGIN_FILE ) : dirname( __DIR__, 2 ) . '/';
	}

	/**
	 * Plugin version.
	 */
	private static function plugin_version(): string {
		return defined( 'X7VELO_VERSION' ) ? (string) X7VELO_VERSION : '0';
	}
}
