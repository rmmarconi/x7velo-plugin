<?php
/**
 * Optional contract: render a check's message from its stored code + details.
 *
 * @package X7velo
 */

namespace X7velo\Audit;

defined( 'ABSPATH' ) || exit;

/**
 * Checks implementing this get their message rendered at presentation time in the viewer's
 * locale (record schema v2 stores only code + details). Checks that don't implement it have the
 * message they returned at run time stored as a fallback (in the locale of whoever ran it).
 *
 * Rendering must be pure: only $code and $details, never option values (store name, email,
 * address) or personal data.
 */
interface MessageRenderer {

	/**
	 * Plain-language message in the current locale.
	 *
	 * @param string               $code    Result code.
	 * @param array<string, mixed> $details Stored details.
	 */
	public function render_message( string $code, array $details ): string;
}
