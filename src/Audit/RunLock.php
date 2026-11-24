<?php
/**
 * Single-flight lock for audit runs.
 *
 * @package X7velo
 */

namespace X7velo\Audit;

use X7velo\NamedLock;

defined( 'ABSPATH' ) || exit;

/**
 * MySQL named lock (GET_LOCK, non-blocking, see NamedLock) so two audits never run at once on
 * one site.
 *
 * The name is derived from the database name and table prefix, so it is per site (the prefix
 * includes the blog ID on multisite) and never collides with another install on the same
 * MySQL server. The lock is tied to the DB connection, so a crashed request releases it.
 * If the database does not support named locks (GET_LOCK returns NULL, e.g. SQLite), the audit
 * runs unlocked rather than not at all and `x7velo_audit_lock_unavailable` fires. There is no TTL:
 * the lock lives exactly as long as the holding request's DB connection (spec v2, QA plan). add_option() is not used as a lock: it is not atomic.
 */
final class RunLock {

	/**
	 * Underlying named lock.
	 *
	 * @var NamedLock
	 */
	private NamedLock $lock;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->lock = new NamedLock( self::name() );
	}

	/**
	 * Lock name for the current site (≤ 64 chars, MySQL's limit).
	 */
	public static function name(): string {
		return NamedLock::site_name( 'x7velo_audit_' );
	}

	/**
	 * Tries to take the lock without waiting.
	 *
	 * @return bool False only when another connection holds it.
	 */
	public function acquire(): bool {
		$result = $this->lock->acquire();
		if ( NamedLock::UNSUPPORTED === $result ) {
			/**
			 * Fires when the database can't provide a named lock (GET_LOCK returned NULL, e.g.
			 * SQLite). The audit then runs unlocked.
			 */
			do_action( 'x7velo_audit_lock_unavailable' );
			return true;
		}
		return NamedLock::ACQUIRED === $result;
	}

	/**
	 * Releases the lock if held.
	 */
	public function release(): void {
		$this->lock->release();
	}
}
