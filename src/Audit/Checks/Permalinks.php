<?php
/**
 * VIS-03 permalinks.
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
use X7velo\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Permalinks must not be "Plain".
 */
final class Permalinks implements Check, MessageRenderer {
	use BuildsResults;

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'VIS-03';
	}

	/**
	 * {@inheritDoc}
	 */
	public function category(): string {
		return Category::VISIBILITY;
	}

	/**
	 * {@inheritDoc}
	 */
	public function weight(): int {
		return 4;
	}

	/**
	 * {@inheritDoc}
	 */
	public function run(): CheckResult {
		$readable = '' !== trim( (string) get_option( 'permalink_structure', '' ) );
		return $this->make( $readable ? Status::PASS : Status::FAIL, $readable ? 'readable_links' : 'plain_links', array( 'site_kind' => Plugin::site_kind() ), Fixer::GUIDED, 'options-permalink.php' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $code    Code.
	 * @param array<string, mixed> $details Details.
	 */
	public function render_message( string $code, array $details ): string {
		if ( 'site' === ( $details['site_kind'] ?? '' ) ) {
			if ( 'plain_links' === $code ) {
				return __( 'Your page links look like ?p=123. Readable links help assistants and are needed for llms.txt. Change to “Post name” in Settings → Permalinks.', 'x7velo' );
			}
			return __( 'Your page links are readable.', 'x7velo' );
		}
		if ( 'plain_links' === $code ) {
			return __( 'Your product links look like ?p=123. Readable links help assistants and are needed for llms.txt. Change to “Post name” in Settings → Permalinks.', 'x7velo' );
		}
		return __( 'Your product links are readable.', 'x7velo' );
	}
}
