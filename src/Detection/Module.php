<?php
/**
 * Detection module: registers VIS-04, CRAWL-01..04, SCHEMA-01, detection-aware fixer states
 * and the runtime schema sample.
 *
 * @package X7velo
 */

namespace X7velo\Detection;

use Throwable;
use X7velo\Audit\Category;
use X7velo\Audit\Check;
use X7velo\Audit\Checks\AiSearchCrawlers;
use X7velo\Audit\Checks\ComingSoon;
use X7velo\Audit\Checks\LlmsTxt;
use X7velo\Audit\Checks\ProductFeed;
use X7velo\Audit\Checks\ProductSchemaSource;
use X7velo\Audit\Checks\Sitemap;
use X7velo\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Wiring only. Call Module::register() once from Plugin::boot() (WooCommerce active).
 */
final class Module {

	/**
	 * Hooks the checks into the audit and the front-end schema sampler.
	 */
	public static function register(): void {
		add_filter( 'x7velo_audit_checks', array( self::class, 'add_checks' ), 10, 3 );
		add_filter( 'x7velo_fixer_status', array( FixerStates::class, 'filter' ), 10, 2 );
		SchemaSample::register();
	}

	/**
	 * `x7velo_audit_checks` callback: inserts VIS-04 after the last visibility check, then the
	 * crawl and schema checks (spec §5 display order). Fresh Detector per audit run.
	 *
	 * @param mixed $checks   Checks.
	 * @param mixed $scanner  Product scanner (unused: these checks are option-based).
	 * @param mixed $settings Settings.
	 * @return mixed
	 */
	public static function add_checks( $checks, $scanner = null, $settings = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- Filter signature.
		if ( ! is_array( $checks ) ) {
			return $checks;
		}
		$settings = $settings instanceof Settings ? $settings : new Settings();
		$detector = new Detector();
		$rules    = new RobotsRules( $detector );

		$ours = array(
			new ComingSoon(),
			new AiSearchCrawlers( $rules ),
			new Sitemap( $detector, $rules ),
			new LlmsTxt( $detector, $settings ),
			new ProductFeed( $detector, $settings ),
			new ProductSchemaSource( $detector, $settings ),
		);

		$at = 0;
		foreach ( array_values( $checks ) as $i => $check ) {
			try {
				if ( $check instanceof Check && Category::VISIBILITY === $check->category() ) {
					$at = $i + 1;
				}
			} catch ( Throwable $e ) {
				continue;
			}
		}
		$checks = array_values( $checks );
		array_splice( $checks, $at, 0, $ours );
		return $checks;
	}
}
