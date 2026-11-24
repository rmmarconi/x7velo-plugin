<?php
/**
 * Audit orchestration.
 *
 * @package X7velo
 */

namespace X7velo\Audit;

use X7velo\Audit\Catalog\ProductScanner;
use X7velo\Audit\Checks\ProductDataCheck;
use X7velo\Plugin;
use X7velo\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the local audit, stores the result and prepares it for output.
 *
 * Stored record (schema v2): code + details per check, no translated text for checks that
 * implement MessageRenderer; `present()` renders messages in the viewer's locale.
 */
final class AuditService {

	/**
	 * Bump when the stored record shape changes incompatibly (see engine notes).
	 */
	public const SCHEMA_VERSION = 2;

	public const STATE_COMPLETE    = 'complete';
	public const STATE_INCOMPLETE  = 'incomplete';
	public const STATE_NO_PRODUCTS = 'no_products';

	/**
	 * Storage.
	 *
	 * @var AuditStore
	 */
	private AuditStore $store;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Plugin version recorded with each result.
	 *
	 * @var string
	 */
	private string $version;

	/**
	 * Constructor.
	 *
	 * @param AuditStore $store    Storage.
	 * @param Settings   $settings Settings.
	 * @param string     $version  Plugin version.
	 */
	public function __construct( AuditStore $store, Settings $settings, string $version ) {
		$this->store    = $store;
		$this->settings = $settings;
		$this->version  = $version;
	}

	/**
	 * Runs all checks, scores them, stores and returns the record.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws AuditRunningException When another audit is running for this site.
	 */
	public function run(): array {
		$lock = new RunLock();
		if ( ! $lock->acquire() ) {
			throw new AuditRunningException( 'An audit is already running.' );
		}
		try {
			return $this->run_unlocked();
		} finally {
			$lock->release();
		}
	}

	/**
	 * The audit itself (caller holds the lock).
	 *
	 * @return array<string, mixed>
	 */
	private function run_unlocked(): array {
		$woo      = Plugin::is_woocommerce_active();
		$scanner  = new ProductScanner();
		$registry = new Registry( $scanner, $this->settings );
		$entries  = $registry->checks();
		$results  = ( new Runner() )->run( $entries );

		$checks = array();
		$scored = array();
		foreach ( $results as $i => $entry ) {
			$result = $entry['result'];
			$row    = array(
				'id'       => $entry['id'],
				'category' => $entry['category'],
				'weight'   => $entry['weight'],
			) + $result->to_array();
			// Renderable checks store no text (rendered per viewer); others keep their run-time text.
			if ( $entries[ $i ]['check'] instanceof MessageRenderer || self::is_engine_code( $result->code() ) ) {
				unset( $row['message'] );
			}
			$checks[] = $row;
			$scored[] = array(
				'weight'   => $entry['weight'],
				'status'   => $result->status(),
				'critical' => $result->is_critical(),
			);
		}

		$score = ( new Scorer() )->score( $scored );
		if ( $scanner->aborted() || ! $score['complete'] ) {
			$state = self::STATE_INCOMPLETE;
		} elseif ( $woo && $scanner->completed() && 0 === $scanner->products() ) {
			// Never `no_products` without WooCommerce (spec §15.2).
			$state = self::STATE_NO_PRODUCTS;
		} else {
			$state = self::STATE_COMPLETE;
		}
		if ( self::STATE_COMPLETE !== $state ) {
			// A partial scan or an empty catalogue never produces a score.
			$score['score']  = null;
			$score['band']   = null;
			$score['capped'] = false;
		}
		$score['complete'] = self::STATE_INCOMPLETE !== $state;

		// `woocommerce_active` is additive (no schema bump): readers treat a missing key as true.
		$record = array(
			'schema_version'     => self::SCHEMA_VERSION,
			'generated_at'       => gmdate( 'c' ),
			'plugin_version'     => $this->version,
			'woocommerce_active' => $woo,
			'state'              => $state,
		) + $score + array( 'checks' => $checks );

		$this->store->save( $record );

		return $record;
	}

	/**
	 * Last stored record, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	public function last(): ?array {
		return $this->store->get();
	}

	/**
	 * Prepares a record for output (REST/CLI): messages rendered in the current user's locale
	 * (CLI: the CLI locale), absolute guided URLs, examples as {id, title, edit_url}, and
	 * object-shaped details.
	 *
	 * @param array<string, mixed> $record Audit record.
	 * @return array<string, mixed>
	 */
	public function present( array $record ): array {
		$user_id  = get_current_user_id();
		$switched = $user_id > 0 && switch_to_user_locale( $user_id );

		try {
			$renderers = array();
			foreach ( ( new Registry( new ProductScanner(), $this->settings ) )->checks() as $entry ) {
				if ( $entry['check'] instanceof MessageRenderer ) {
					$renderers[ $entry['id'] ] = $entry['check'];
				}
			}

			$points = self::points_available( $record );
			foreach ( (array) ( $record['checks'] ?? array() ) as $i => $check ) {
				$record['checks'][ $i ]['points_available'] = $points[ $i ] ?? null;

				$code    = (string) ( $check['code'] ?? '' );
				$details = is_array( $check['details'] ?? null ) ? $check['details'] : array();

				$record['checks'][ $i ]['message'] = self::render( $renderers[ $check['id'] ?? '' ] ?? null, $code, $details, (string) ( $check['message'] ?? '' ) );

				$path                                 = (string) ( $check['guided_path'] ?? '' );
				$record['checks'][ $i ]['guided_url'] = '' === $path ? '' : esc_url_raw( admin_url( $path ) );

				if ( isset( $details['examples'] ) ) {
					$details['examples'] = self::present_examples( (array) $details['examples'] );
				}
				// Encode empty details as a JSON object, not [], so consumers see one shape.
				$record['checks'][ $i ]['details'] = $details ? $details : new \stdClass();
			}
		} finally {
			if ( $switched ) {
				restore_previous_locale();
			}
		}

		return $record;
	}

	/**
	 * Points each check would add if it passed: score with that check passing − current score,
	 * using the real formula (caps, exclusions). 0 for checks that aren't fail/warn; null for all
	 * when there is no score. VIS-01 also counts CRAWL-01 as passing when CRAWL-01 only fails
	 * because of search visibility (same root cause, design §3.2).
	 *
	 * @param array<string, mixed> $record Audit record.
	 * @return array<int, int|null> Index => points.
	 */
	public static function points_available( array $record ): array {
		$checks  = array_values( (array) ( $record['checks'] ?? array() ) );
		$current = $record['score'] ?? null;
		$entries = array();
		foreach ( $checks as $check ) {
			$entries[] = array(
				'weight'   => (int) ( $check['weight'] ?? 0 ),
				'status'   => (string) ( $check['status'] ?? '' ),
				'critical' => ! empty( $check['critical'] ),
			);
		}

		$scorer = new Scorer();
		$points = array();
		foreach ( $checks as $i => $check ) {
			if ( null === $current ) {
				$points[ $i ] = null;
				continue;
			}
			if ( ! in_array( $entries[ $i ]['status'], array( Status::FAIL, Status::WARN ), true ) ) {
				$points[ $i ] = 0;
				continue;
			}
			$what_if                   = $entries;
			$what_if[ $i ]             = array( 'status' => Status::PASS ) + $what_if[ $i ];
			$what_if[ $i ]['critical'] = false;
			if ( 'VIS-01' === ( $check['id'] ?? '' ) ) {
				foreach ( $checks as $j => $other ) {
					if ( 'CRAWL-01' === ( $other['id'] ?? '' ) && 'blocked_by_search_visibility' === ( $other['code'] ?? '' ) ) {
						$what_if[ $j ]['status']   = Status::PASS;
						$what_if[ $j ]['critical'] = false;
					}
				}
			}
			$new          = $scorer->score( $what_if )['score'];
			$points[ $i ] = null === $new ? 0 : max( 0, $new - (int) $current );
		}
		return $points;
	}

	/**
	 * Renders one message.
	 *
	 * @param MessageRenderer|null $renderer Check renderer, if available.
	 * @param string               $code     Code.
	 * @param array<string, mixed> $details  Details.
	 * @param string               $fallback Stored run-time message (non-renderer checks).
	 */
	private static function render( ?MessageRenderer $renderer, string $code, array $details, string $fallback ): string {
		if ( 'check_error' === $code ) {
			return CheckResult::error()->message();
		}
		if ( 'scan_memory_limit' === $code ) {
			return ProductDataCheck::scan_aborted()->message();
		}
		if ( StoreOnlyCheck::CODE === $code ) {
			return StoreOnlyCheck::message();
		}
		if ( $renderer ) {
			$message = $renderer->render_message( $code, $details );
			if ( '' !== $message ) {
				return mb_substr( wp_strip_all_tags( $message ), 0, CheckResult::MAX_MESSAGE_CHARS );
			}
		}
		return $fallback;
	}

	/**
	 * Whether a code is produced by the engine itself (rendered here, not by the check).
	 *
	 * @param string $code Code.
	 */
	private static function is_engine_code( string $code ): bool {
		return in_array( $code, array( 'check_error', 'scan_memory_limit', StoreOnlyCheck::CODE ), true );
	}

	/**
	 * Example product IDs → {id, title, edit_url}. Variations link to their parent's edit
	 * screen; deleted IDs are omitted. edit_url is '' when the viewer can't edit (e.g. CLI).
	 *
	 * @param array<int, mixed> $ids Stored example IDs.
	 * @return array<int, array{id: int, title: string, edit_url: string}>
	 */
	private static function present_examples( array $ids ): array {
		$examples = array();
		foreach ( array_slice( $ids, 0, CheckResult::MAX_EXAMPLES ) as $id ) {
			$post = get_post( absint( $id ) );
			if ( ! $post || ! in_array( $post->post_type, array( 'product', 'product_variation' ), true ) ) {
				continue;
			}
			$target     = 'product_variation' === $post->post_type && $post->post_parent ? (int) $post->post_parent : (int) $post->ID;
			$examples[] = array(
				'id'       => (int) $post->ID,
				'title'    => html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' ),
				'edit_url' => (string) get_edit_post_link( $target, 'raw' ),
			);
		}
		return $examples;
	}
}
