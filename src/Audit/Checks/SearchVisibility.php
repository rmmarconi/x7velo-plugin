<?php
/**
 * VIS-01 search-visibility.
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

defined( 'ABSPATH' ) || exit;

/**
 * "Discourage search engines" must be off. Critical: a fail caps the score at 20.
 */
final class SearchVisibility implements Check, MessageRenderer {
	use BuildsResults;

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'VIS-01';
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
		return 10;
	}

	/**
	 * {@inheritDoc}
	 */
	public function run(): CheckResult {
		if ( '0' !== (string) get_option( 'blog_public', '1' ) ) {
			return $this->make( Status::PASS, 'search_engines_allowed', array(), Fixer::GUIDED, 'options-reading.php' );
		}
		return $this->make( Status::FAIL, 'search_engines_discouraged', array(), Fixer::GUIDED, 'options-reading.php', true );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $code    Code.
	 * @param array<string, mixed> $details Details.
	 */
	public function render_message( string $code, array $details ): string {
		if ( 'search_engines_discouraged' === $code ) {
			return __( 'Your site is telling every search engine and AI assistant to stay away. This is usually left on by mistake after building the site. Turn off “Discourage search engines from indexing this site” in Settings → Reading.', 'x7velo' );
		}
		return __( 'Search engines and AI assistants are allowed to read your site.', 'x7velo' );
	}
}
