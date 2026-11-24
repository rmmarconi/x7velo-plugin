<?php
/**
 * Read-only access to the signals adapters use (constants, classes, options, local files, and
 * the per-post robots rows SEO plugins keep in their own tables).
 *
 * @package X7velo
 */

namespace X7velo\Detection;

defined( 'ABSPATH' ) || exit;

/**
 * Thin seam over PHP/WordPress state so adapters stay unit-testable: tests subclass it to
 * simulate SEO plugins' constants, classes and files (the real plugins can't be loaded in the
 * unit test environment). Options are read for real.
 *
 * Never makes HTTP requests and never writes anything.
 */
class Environment {

	/**
	 * Local files are read up to this many bytes.
	 */
	public const MAX_READ_BYTES = 512000;

	/**
	 * Whether a constant is defined.
	 *
	 * @param string $name Constant name.
	 */
	public function defined( string $name ): bool {
		return defined( $name );
	}

	/**
	 * Value of a constant, or null when undefined.
	 *
	 * @param string $name Constant name.
	 * @return mixed
	 */
	public function constant( string $name ) {
		return defined( $name ) ? constant( $name ) : null;
	}

	/**
	 * Whether a class exists (without autoloading other plugins' code paths needlessly).
	 *
	 * @param string $name Class name.
	 */
	public function class_exists( string $name ): bool {
		return class_exists( $name, false );
	}

	/**
	 * Option value.
	 *
	 * @param string $name    Option name.
	 * @param mixed  $fallback Default.
	 * @return mixed
	 */
	public function option( string $name, $fallback = false ) {
		return get_option( $name, $fallback );
	}

	/**
	 * Whether a plugin (basename, e.g. "wp-seopress/seopress.php") is active on this site or network.
	 *
	 * Reads the options directly so it works in any context without loading wp-admin includes.
	 *
	 * @param string $basename Plugin basename.
	 */
	public function plugin_active( string $basename ): bool {
		$active = (array) get_option( 'active_plugins', array() );
		if ( in_array( $basename, $active, true ) ) {
			return true;
		}
		if ( is_multisite() ) {
			$network = (array) get_site_option( 'active_sitewide_plugins', array() );
			return isset( $network[ $basename ] );
		}
		return false;
	}

	/**
	 * Active plugin basenames (site + network), sorted.
	 *
	 * @return string[]
	 */
	public function active_plugins(): array {
		$active = array_filter( (array) get_option( 'active_plugins', array() ), 'is_string' );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		$active = array_values( array_unique( $active ) );
		sort( $active );
		return $active;
	}

	/**
	 * Whether a local file exists.
	 *
	 * @param string $path Absolute path.
	 */
	public function file_exists( string $path ): bool {
		return is_file( $path );
	}

	/**
	 * Reads a local file (bounded). Null when missing or unreadable.
	 *
	 * @param string $path      Absolute path.
	 * @param int    $max_bytes Maximum bytes to read.
	 */
	public function read_file( string $path, int $max_bytes = self::MAX_READ_BYTES ): ?string {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return null;
		}
		// Local file read (never a URL): robots.txt / llms.txt in the site root.
		$contents = file_get_contents( $path, false, null, 0, max( 0, $max_bytes ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return false === $contents ? null : $contents;
	}

	/**
	 * MD5 of a local file, or null.
	 *
	 * @param string $path Absolute path.
	 */
	public function file_md5( string $path ): ?string {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return null;
		}
		$hash = md5_file( $path );
		return false === $hash ? null : $hash;
	}

	/**
	 * Absolute path of the site root (where a physical robots.txt / llms.txt is served from).
	 */
	public function root_path(): string {
		return ABSPATH;
	}

	/**
	 * Directory above wp-content (AIOSEO writes llms.txt there).
	 */
	public function content_parent_path(): string {
		return trailingslashit( dirname( WP_CONTENT_DIR ) );
	}

	/**
	 * AIOSEO's per-post robots settings for these posts, from its `aioseo_posts` table (one
	 * prepared query). Posts without a row are absent. Empty when the table doesn't exist.
	 *
	 * @param int[] $ids Post IDs (at most a few hundred per call).
	 * @return array<int, array{default: bool, noindex: bool}> Post ID => `robots_default`, `robots_noindex`.
	 */
	public function aioseo_post_robots( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => $id > 0 ) ) );
		if ( array() === $ids ) {
			return array();
		}
		$table = $wpdb->prefix . 'aioseo_posts';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Another plugin's table; read once per llms.txt request for a bounded ID set.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( $exists !== $table ) {
			return array();
		}
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$rows         = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, robots_default, robots_noindex FROM %i WHERE post_id IN ( $placeholders )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Only %d placeholders, one per ID.
				array_merge( array( $table ), $ids )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['post_id'] ] = array(
				'default' => (bool) (int) $row['robots_default'],
				'noindex' => (bool) (int) $row['robots_noindex'],
			);
		}
		return $out;
	}

	/**
	 * Whether Rank Math considers its registration invalid (front end inert).
	 *
	 * Uses Rank Math's own helper when loaded; otherwise mirrors its logic from options:
	 * valid when `RANK_MATH_REGISTRATION_SKIP` is true, `rank_math_registration_skip` is set,
	 * or the site is connected (`rank_math_connect_data` holds an access token).
	 */
	public function rank_math_registration_invalid(): bool {
		if ( class_exists( '\RankMath\Helper', false ) && method_exists( '\RankMath\Helper', 'is_invalid_registration' ) ) {
			return (bool) \RankMath\Helper::is_invalid_registration();
		}
		if ( $this->defined( 'RANK_MATH_REGISTRATION_SKIP' ) && $this->constant( 'RANK_MATH_REGISTRATION_SKIP' ) ) {
			return false;
		}
		if ( $this->option( 'rank_math_registration_skip' ) ) {
			return false;
		}
		$connect = $this->option( 'rank_math_connect_data', array() );
		return ! ( is_array( $connect ) && ! empty( $connect['access_token'] ) );
	}
}
