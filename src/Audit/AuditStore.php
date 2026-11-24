<?php
/**
 * Persistence of the last audit result.
 *
 * @package X7velo
 */

namespace X7velo\Audit;

defined( 'ABSPATH' ) || exit;

/**
 * Stores the last audit in one non-autoloaded option.
 */
final class AuditStore {

	public const OPTION = 'x7velo_last_audit';

	/**
	 * Returns the last audit, or null if none (or stored in an older schema).
	 *
	 * @return array<string, mixed>|null
	 */
	public function get(): ?array {
		$value = get_option( self::OPTION, null );
		if ( ! is_array( $value ) || ( $value['schema_version'] ?? null ) !== AuditService::SCHEMA_VERSION ) {
			return null;
		}
		return $value;
	}

	/**
	 * Saves an audit record (autoload off: it is only needed on the x7velo screen).
	 *
	 * @param array<string, mixed> $record Audit record.
	 */
	public function save( array $record ): void {
		update_option( self::OPTION, $record, false );
	}

	/**
	 * Deletes a stored record from another schema version (upgrade step; it would be read as
	 * "no audit" anyway, this just frees the row).
	 */
	public function discard_incompatible(): void {
		$value = get_option( self::OPTION, null );
		if ( null !== $value && ( ! is_array( $value ) || ( $value['schema_version'] ?? null ) !== AuditService::SCHEMA_VERSION ) ) {
			$this->delete();
		}
	}

	/**
	 * Deletes the stored audit.
	 */
	public function delete(): void {
		delete_option( self::OPTION );
	}
}
