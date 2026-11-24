<?php
/**
 * Non-blocking MySQL named lock (GET_LOCK with timeout 0).
 *
 * @package X7velo
 */

namespace X7velo;

defined( 'ABSPATH' ) || exit;

/**
 * Shared by the audit run lock, the feed single-flight and the rewrite sync.
 *
 * The lock belongs to the request's DB connection, so a crashed or aborted request releases it
 * when its connection closes: no TTL, no stale state, nothing stored. Names are made per site with
 * site_name() (database name + table prefix, which includes the blog ID on multisite), so two
 * installs or two sites sharing one MySQL server never collide. add_option() is never used as a
 * lock: it is not atomic.
 */
final class NamedLock {

	public const ACQUIRED    = 'acquired';
	public const BUSY        = 'busy';
	public const UNSUPPORTED = 'unsupported';

	/**
	 * Lock name (≤ 64 chars, MySQL's limit).
	 *
	 * @var string
	 */
	private string $name;

	/**
	 * Whether this instance holds the lock.
	 *
	 * @var bool
	 */
	private bool $held = false;

	/**
	 * Constructor.
	 *
	 * @param string $name Lock name (use site_name()).
	 */
	public function __construct( string $name ) {
		$this->name = $name;
	}

	/**
	 * Per-site lock name: `<prefix>` + md5( database name | table prefix ).
	 *
	 * @param string $prefix Short prefix, e.g. `x7velo_feed_` (≤ 32 chars).
	 */
	public static function site_name( string $prefix ): string {
		global $wpdb;
		return substr( $prefix, 0, 32 ) . md5( (string) $wpdb->dbname . '|' . $wpdb->prefix );
	}

	/**
	 * Lock name.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Tries to take the lock without waiting.
	 *
	 * @return string ACQUIRED, BUSY (another connection holds it) or UNSUPPORTED (GET_LOCK
	 *                returned NULL, e.g. SQLite; the caller decides whether to run unlocked).
	 */
	public function acquire(): string {
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Named lock; must hit the server, never a cache.
		$result = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 0 )', $this->name ) );
		$wpdb->suppress_errors( $suppress );

		if ( null === $result ) {
			return self::UNSUPPORTED;
		}
		$this->held = '1' === (string) $result;
		if ( $this->held ) {
			// Persistent DB connections (`p:` hosts) outlive the request, and so would the lock;
			// release on every exit path too (v2.3 N4). release() is idempotent.
			register_shutdown_function( array( $this, 'release' ) );
		}
		return $this->held ? self::ACQUIRED : self::BUSY;
	}

	/**
	 * Whether this instance holds the lock.
	 */
	public function held(): bool {
		return $this->held;
	}

	/**
	 * Releases the lock if held. Idempotent: safe to call again (e.g. from the shutdown hook).
	 */
	public function release(): void {
		if ( ! $this->held ) {
			return;
		}
		global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
			return;
		}
		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Named lock release.
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $this->name ) );
		$wpdb->suppress_errors( $suppress );
		$this->held = false;
	}
}
