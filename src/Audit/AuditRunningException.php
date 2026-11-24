<?php
/**
 * Thrown when another audit run holds the lock.
 *
 * @package X7velo
 */

namespace X7velo\Audit;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Another audit is already running for this site.
 */
final class AuditRunningException extends RuntimeException {
}
