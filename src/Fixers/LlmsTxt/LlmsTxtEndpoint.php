<?php
/**
 * The llms.txt fixer: dynamic /llms.txt (spec §8.2).
 *
 * @package X7velo
 */

namespace X7velo\Fixers\LlmsTxt;

use X7velo\Detection\Detector;
use X7velo\Detection\FixerStates;
use X7velo\Fixers\PublicOutput;
use X7velo\NamedLock;
use X7velo\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Serves /llms.txt from a rewrite rule (`^llms\.txt$` → query var) on `template_redirect`.
 * Never writes a file.
 *
 * - The rule is wanted while the toggle is on, no other llms.txt source exists (Yoast/AIOSEO/
 *   Rank Math registered/a physical file → `managed`/`paused`) and permalinks are readable. So
 *   x7velo never competes with another source's rule. Coming-soon mode keeps the rule (serve()
 *   answers 404) so launching the store needs no flush.
 * - Front-end requests never run full detection (no file checks): `init` registers the rule from
 *   the toggle and the stored rewrite rules only. Full detection runs in wp-admin (`admin_init`
 *   → sync_rules()), on a toggle change, and when /llms.txt itself is requested.
 * - Rewrite rules are flushed only when the stored rules disagree with what is wanted, under a
 *   non-blocking named lock (concurrent requests skip) and at most once per SYNC_GUARD_TTL per
 *   transition, never on every request.
 * - If the query var arrives while we're not serving (stale rule, coming soon,
 *   `x7velo_public_output_allowed` false), the request is a 404.
 * - The body is generated per request (34–60 ms for 2,000 products on the dev VM, under the
 *   spec's 100 ms threshold), as a logged-out visitor, so nothing is cached server-side. The
 *   response may be cached by browsers and proxies for an hour (headers()); it never depends on
 *   who asks. No ETag: the body also depends on options, terms and SEO-plugin settings that no
 *   cheap validator covers.
 */
final class LlmsTxtEndpoint {

	public const QUERY_VAR      = 'x7velo_llms_txt';
	public const REGEX          = '^llms\.txt$';
	public const TARGET         = 'index.php?x7velo_llms_txt=1';
	public const SYNC_GUARD     = 'x7velo_rewrite_sync';
	public const SYNC_GUARD_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Network deactivation clears our rule on at most this many sites (bounded request time);
	 * sites beyond it lose the rule on their next flush. Their stale rule only maps /llms.txt to
	 * an unknown query var once the plugin is inactive, which WordPress answers like any page.
	 */
	public const NETWORK_SITES_MAX = 500;

	/**
	 * Cache-Control of a served body (the same for every visitor).
	 */
	public const CACHE_CONTROL = 'public, max-age=3600';

	/**
	 * Whether this request registered the rule.
	 *
	 * @var bool
	 */
	private static bool $registered = false;

	/**
	 * Hooks the endpoint.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'add_rule' ) );
		add_filter( 'query_vars', array( self::class, 'query_vars' ) );
		add_action( 'admin_init', array( self::class, 'sync_rules' ) );
		add_action( 'template_redirect', array( self::class, 'serve' ), 0 );
		add_action( 'update_option_' . Settings::OPTION, array( self::class, 'settings_changed' ), 10, 2 );
		add_action( 'add_option_' . Settings::OPTION, array( self::class, 'settings_added' ), 10, 2 );
	}

	/**
	 * Fixer state for llms.txt: on | off | managed | paused | cannot_run (derived, never stored).
	 *
	 * @param array<string, mixed>|null $settings Settings (null = stored).
	 */
	public static function state( ?array $settings = null ): string {
		return (string) ( self::state_row( $settings )['state'] ?? 'off' );
	}

	/**
	 * Full fixer status row for llms.txt (state, reason, …), with full detection.
	 *
	 * @param array<string, mixed>|null $settings Settings (null = stored).
	 * @return array<string, mixed>
	 */
	public static function state_row( ?array $settings = null ): array {
		$settings = $settings ?? ( new Settings() )->all();
		$on       = ! empty( $settings['fix_llms_txt'] );
		return FixerStates::llms( new Detector(), array( 'state' => $on ? 'on' : 'off' ), $on );
	}

	/**
	 * Whether our rewrite rule should exist (full detection): serving, or only held back because
	 * the store isn't public yet.
	 *
	 * @param array<string, mixed>|null $settings Settings (null = stored).
	 */
	public static function wants_rule( ?array $settings = null ): bool {
		$settings = $settings ?? ( new Settings() )->all();
		if ( empty( $settings['fix_llms_txt'] ) ) {
			return false;
		}
		$row = self::state_row( $settings );
		if ( 'on' === ( $row['state'] ?? '' ) ) {
			return true;
		}
		return 'cannot_run' === ( $row['state'] ?? '' )
			&& in_array( $row['reason'] ?? '', array( PublicOutput::REASON_COMING_SOON, PublicOutput::REASON_FILTERED ), true );
	}

	/**
	 * Cheap toggle read (raw autoloaded option, no sanitising, no detection).
	 */
	private static function toggle_on(): bool {
		$raw = get_option( Settings::OPTION, array() );
		return is_array( $raw ) && true === rest_sanitize_boolean( $raw['fix_llms_txt'] ?? false );
	}

	/**
	 * Whether x7velo serves /llms.txt right now.
	 *
	 * @param array<string, mixed>|null $settings Settings (null = stored).
	 */
	public static function is_serving( ?array $settings = null ): bool {
		$settings = $settings ?? ( new Settings() )->all();
		// Cheap path: no detection when the toggle is off.
		return ! empty( $settings['fix_llms_txt'] ) && 'on' === self::state( $settings );
	}

	/**
	 * `init` (every request): registers the rewrite rule without full detection. Toggle on,
	 * readable permalinks and our rule already in the stored rules → keep it registered, so any
	 * flush by someone else keeps it. Only when the stored rules are empty (core is about to
	 * regenerate them) is full detection used. Conflicts appearing or going away are handled by
	 * sync_rules() (wp-admin, toggle changes, /llms.txt hits).
	 */
	public static function add_rule(): void {
		self::$registered = false;
		if ( ! self::toggle_on() || '' === trim( (string) get_option( 'permalink_structure', '' ) ) ) {
			return;
		}
		$rules            = get_option( 'rewrite_rules' );
		self::$registered = is_array( $rules ) && array() !== $rules ? self::rule_stored( $rules ) : self::wants_rule();
		if ( self::$registered ) {
			add_rewrite_rule( self::REGEX, self::TARGET, 'top' );
		}
	}

	/**
	 * Adds the query var (always, so a stale rule can be answered with a 404).
	 *
	 * @param mixed $vars Query vars.
	 * @return mixed
	 */
	public static function query_vars( $vars ) {
		if ( is_array( $vars ) ) {
			$vars[] = self::QUERY_VAR;
		}
		return $vars;
	}

	/**
	 * `admin_init`, and from serve(): full detection; flushes once when the stored rules disagree
	 * with what is wanted.
	 */
	public static function sync_rules(): void {
		if ( '' === trim( (string) get_option( 'permalink_structure', '' ) ) ) {
			return;
		}
		$rules = get_option( 'rewrite_rules' );
		if ( ! is_array( $rules ) || array() === $rules ) {
			return; // Core regenerates empty rules itself, with ours if registered.
		}
		$wanted = self::wants_rule();
		if ( self::rule_stored( $rules ) === $wanted ) {
			return;
		}
		// The guard remembers the state we last flushed towards: a flush that didn't stick (another
		// plugin filtering rules) isn't retried for SYNC_GUARD_TTL, but a new transition (conflict
		// gone again) flushes immediately.
		$want = $wanted ? 'on' : 'off';
		if ( get_transient( self::SYNC_GUARD ) === $want ) {
			return;
		}
		// Single-flight: while another request is flushing, skip (it converges to the same rules).
		$lock = new NamedLock( self::sync_lock_name() );
		if ( NamedLock::BUSY === $lock->acquire() ) {
			return;
		}
		set_transient( self::SYNC_GUARD, $want, self::SYNC_GUARD_TTL );
		self::remove_rule();
		if ( $wanted ) {
			add_rewrite_rule( self::REGEX, self::TARGET, 'top' );
			self::$registered = true;
		}
		flush_rewrite_rules( false );
		$lock->release();
	}

	/**
	 * Named-lock name for the rewrite sync on this site.
	 */
	public static function sync_lock_name(): string {
		return NamedLock::site_name( 'x7velo_rewrite_sync_' );
	}

	/**
	 * Whether the stored rules contain our rule.
	 *
	 * @param array<string, string> $rules Stored rewrite rules.
	 */
	public static function rule_stored( array $rules ): bool {
		return isset( $rules[ self::REGEX ] ) && self::TARGET === $rules[ self::REGEX ];
	}

	/**
	 * `template_redirect` (priority 0, before canonical redirects): serves the body and exits.
	 */
	public static function serve(): void {
		if ( '' === (string) get_query_var( self::QUERY_VAR ) ) {
			return;
		}
		if ( ! self::is_serving() ) {
			// A conflict may have appeared since the rule was stored: hand /llms.txt back.
			self::sync_rules();
			global $wp_query;
			if ( $wp_query instanceof \WP_Query ) {
				$wp_query->set_404();
			}
			status_header( 404 );
			nocache_headers();
			return;
		}
		$body = self::body();
		// Public, cacheable response: never carry a cookie set earlier in the request (as the feed).
		if ( function_exists( 'header_remove' ) ) {
			header_remove( 'Set-Cookie' );
		}
		status_header( 200 );
		foreach ( self::headers() as $name => $value ) {
			header( $name . ': ' . $value );
		}
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- text/plain; every value is reduced to one escaped line by LlmsTxtBuilder.
		exit;
	}

	/**
	 * Response headers of a served body.
	 *
	 * @return array<string, string>
	 */
	public static function headers(): array {
		return array(
			'Content-Type'           => 'text/plain; charset=utf-8',
			'X-Content-Type-Options' => 'nosniff',
			'X-Robots-Tag'           => 'noindex',
			'Cache-Control'          => self::CACHE_CONTROL,
		);
	}

	/**
	 * The body, generated per request.
	 */
	public static function body(): string {
		return LlmsTxtBuilder::build( ( new Settings() )->all() );
	}

	/**
	 * Settings saved: on a toggle change, re-register and flush now (this request already ran
	 * `init` with the old value).
	 *
	 * @param mixed $old_value Old settings.
	 * @param mixed $value     New settings.
	 */
	public static function settings_changed( $old_value, $value ): void {
		$was = is_array( $old_value ) && ! empty( $old_value['fix_llms_txt'] );
		$now = is_array( $value ) && ! empty( $value['fix_llms_txt'] );
		if ( $was !== $now ) {
			self::refresh_rule( Settings::sanitize( is_array( $value ) ? $value : array() ) );
		}
	}

	/**
	 * Settings created (first save).
	 *
	 * @param mixed $option Option name.
	 * @param mixed $value  Settings.
	 */
	public static function settings_added( $option, $value ): void {
		self::settings_changed( array(), $value );
	}

	/**
	 * Registers or removes our rule for the given settings and flushes.
	 *
	 * @param array<string, mixed> $settings Settings.
	 */
	public static function refresh_rule( array $settings ): void {
		self::remove_rule();
		self::$registered = self::wants_rule( $settings );
		if ( self::$registered ) {
			add_rewrite_rule( self::REGEX, self::TARGET, 'top' );
		}
		flush_rewrite_rules( false );
	}

	/**
	 * Removes our rule from the in-memory rewrite object (not someone else's identical regex).
	 */
	public static function remove_rule(): void {
		global $wp_rewrite;
		if ( $wp_rewrite instanceof \WP_Rewrite && isset( $wp_rewrite->extra_rules_top[ self::REGEX ] ) && self::TARGET === $wp_rewrite->extra_rules_top[ self::REGEX ] ) {
			unset( $wp_rewrite->extra_rules_top[ self::REGEX ] );
		}
		self::$registered = false;
	}

	/**
	 * Deactivation: remove the rule and flush (spec §10; settings are kept). Network-wide: every
	 * other site (up to NETWORK_SITES_MAX) drops its stored rules if they contain ours, so core
	 * regenerates them without it on that site's next request (flushing from a switched blog would
	 * build rules with the wrong site's permalink settings).
	 *
	 * @param bool $network_wide Network deactivation.
	 */
	public static function deactivate( bool $network_wide = false ): void {
		self::remove_rule();
		flush_rewrite_rules( false );
		delete_transient( self::SYNC_GUARD );

		if ( ! $network_wide || ! is_multisite() ) {
			return;
		}
		$current = get_current_blog_id();
		$sites   = get_sites(
			array(
				'fields' => 'ids',
				'number' => self::NETWORK_SITES_MAX,
			)
		);
		foreach ( $sites as $site_id ) {
			if ( (int) $site_id === $current ) {
				continue;
			}
			self::forget_stored_rule_for_blog( (int) $site_id );
		}
	}

	/**
	 * Same as forget_stored_rule() for another blog, without switch_to_blog() (which would load that
	 * blog's autoloaded options): one prepared read and at most two deletes on its options table,
	 * then its options caches are dropped (v2.3 N3).
	 *
	 * @param int $blog_id Blog ID.
	 */
	public static function forget_stored_rule_for_blog( int $blog_id ): void {
		global $wpdb;
		$table = $wpdb->get_blog_prefix( $blog_id ) . 'options';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Another blog's options table; caches are invalidated below.
		$raw     = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $table, 'rewrite_rules' ) );
		$rules   = is_string( $raw ) ? maybe_unserialize( $raw ) : null;
		$changed = 0;
		if ( is_array( $rules ) && self::rule_stored( $rules ) ) {
			$changed += (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s', $table, 'rewrite_rules' ) );
		}
		$changed += (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name IN ( %s, %s )', $table, '_transient_' . self::SYNC_GUARD, '_transient_timeout_' . self::SYNC_GUARD ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $changed > 0 ) {
			self::flush_blog_option_cache( $blog_id );
		}
	}

	/**
	 * Drops another blog's option caches (alloptions, notoptions, rewrite_rules, sync guard).
	 *
	 * @param int $blog_id Blog ID.
	 */
	private static function flush_blog_option_cache( int $blog_id ): void {
		$current = get_current_blog_id();
		$switch  = $blog_id !== $current && function_exists( 'wp_cache_switch_to_blog' );
		if ( $switch ) {
			wp_cache_switch_to_blog( $blog_id );
		}
		foreach ( array( 'alloptions', 'notoptions', 'rewrite_rules', '_transient_' . self::SYNC_GUARD, '_transient_timeout_' . self::SYNC_GUARD ) as $key ) {
			wp_cache_delete( $key, 'options' );
		}
		wp_cache_delete( self::SYNC_GUARD, 'transient' );
		if ( $switch ) {
			wp_cache_switch_to_blog( $current );
		}
	}

	/**
	 * Current site: deletes the stored rewrite rules if they contain ours (core regenerates them
	 * on the next request) and the sync guard.
	 */
	public static function forget_stored_rule(): void {
		$rules = get_option( 'rewrite_rules' );
		if ( is_array( $rules ) && self::rule_stored( $rules ) ) {
			delete_option( 'rewrite_rules' );
		}
		delete_transient( self::SYNC_GUARD );
	}

	/**
	 * `x7velo_notice_sources` callback: llms.txt conflict source for the notice state machine.
	 * Omitted when the fixer can't run for a non-conflict reason (plain permalinks), so that is
	 * never announced as "active again".
	 *
	 * @param mixed $sources  Area => {enabled, source}.
	 * @param mixed $settings Settings.
	 * @return mixed
	 */
	public static function notice_source( $sources, $settings = array() ) {
		if ( ! is_array( $sources ) ) {
			return $sources;
		}
		$settings = is_array( $settings ) ? $settings : array();
		$on       = ! empty( $settings['fix_llms_txt'] );
		if ( ! $on ) {
			$sources['llms_txt'] = array(
				'enabled' => false,
				'source'  => '',
			);
			return $sources;
		}
		$others = ( new Detector() )->llms_sources( false );
		if ( array() !== $others ) {
			$sources['llms_txt'] = array(
				'enabled' => true,
				'source'  => (string) $others[0]['id'],
			);
			return $sources;
		}
		if ( 'on' === self::state( $settings ) ) {
			$sources['llms_txt'] = array(
				'enabled' => true,
				'source'  => '',
			);
		}
		return $sources;
	}
}
