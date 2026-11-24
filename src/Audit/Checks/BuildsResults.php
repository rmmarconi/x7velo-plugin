<?php
/**
 * Helper for checks that render their own messages.
 *
 * @package X7velo
 */

namespace X7velo\Audit\Checks;

use X7velo\Audit\CheckResult;
use X7velo\Audit\Fixer;

defined( 'ABSPATH' ) || exit;

/**
 * Builds a CheckResult whose message comes from render_message( $code, $details ), so the run-time
 * text and the text rendered later from the stored record are always the same.
 */
trait BuildsResults {

	/**
	 * Builds a result.
	 *
	 * @param string               $status      Status.
	 * @param string               $code        Code.
	 * @param array<string, mixed> $details     Details.
	 * @param string               $fixer       Fixer type.
	 * @param string               $guided_path Guided admin path.
	 * @param bool                 $critical    Whether a fail caps the score.
	 */
	protected function make( string $status, string $code, array $details = array(), string $fixer = Fixer::MANUAL, string $guided_path = '', bool $critical = false ): CheckResult {
		// Build first so the message is rendered from the sanitised, capped details — the same
		// details later read back from the stored record — keeping both texts identical.
		$draft = new CheckResult( $status, $code, '', $details, $fixer, $guided_path, $critical );
		return new CheckResult( $status, $code, $this->render_message( $code, $draft->details() ), $draft->details(), $fixer, $guided_path, $critical );
	}
}
