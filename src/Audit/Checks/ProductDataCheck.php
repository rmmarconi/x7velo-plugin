<?php
/**
 * Base class for product data quality checks (DATA-*).
 *
 * @package X7velo
 */

namespace X7velo\Audit\Checks;

use X7velo\Audit\Catalog\ProductConsumer;
use X7velo\Audit\Catalog\ProductScanner;
use X7velo\Audit\Catalog\Tally;
use X7velo\Audit\Category;
use X7velo\Audit\Check;
use X7velo\Audit\CheckResult;
use X7velo\Audit\Fixer;
use X7velo\Audit\MessageRenderer;
use X7velo\Audit\Status;

defined( 'ABSPATH' ) || exit;

/**
 * A DATA check subscribes to the shared ProductScanner on construction, reduces each streamed
 * product into a Tally (is_ok() + optional count()), and evaluates the totals in evaluate().
 * Instances are single-use: one per audit run, like the scanner.
 */
abstract class ProductDataCheck implements Check, ProductConsumer, MessageRenderer {

	/**
	 * Product scanner (shared per audit run).
	 *
	 * @var ProductScanner
	 */
	protected ProductScanner $scanner;

	/**
	 * Running totals.
	 *
	 * @var Tally
	 */
	protected Tally $tally;

	/**
	 * Constructor: subscribes to the scanner.
	 *
	 * @param ProductScanner $scanner Product scanner.
	 */
	public function __construct( ProductScanner $scanner ) {
		$this->scanner = $scanner;
		$this->tally   = new Tally();
		$scanner->subscribe( $this );
	}

	/**
	 * {@inheritDoc}
	 */
	public function category(): string {
		return Category::DATA;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int                  $product_id Product ID.
	 * @param array<string, mixed> $facts      Facts.
	 */
	public function consume( int $product_id, array $facts ): void {
		$this->tally->record( $product_id, $this->is_ok( $facts ) );
		$this->count( $facts );
	}

	/**
	 * Whether one product satisfies this check.
	 *
	 * @param array<string, mixed> $facts Facts.
	 */
	abstract protected function is_ok( array $facts ): bool;

	/**
	 * Adds extra counters to the tally (optional).
	 *
	 * @param array<string, mixed> $facts Facts.
	 */
	protected function count( array $facts ): void {
		unset( $facts );
	}

	/**
	 * Builds the result from the totals (only called with at least one product).
	 */
	abstract protected function evaluate(): CheckResult;

	/**
	 * {@inheritDoc}
	 */
	public function run(): CheckResult {
		$this->scanner->scan();
		if ( $this->scanner->aborted() ) {
			return self::scan_aborted();
		}
		if ( 0 === $this->tally->total() ) {
			return new CheckResult( Status::NOT_APPLICABLE, 'no_products', $this->render_message( 'no_products', array() ), array( 'products' => 0 ) );
		}
		return $this->evaluate();
	}

	/**
	 * Uniform thresholds (spec §5 D): pass ≥ 95 %, warn 75–94 %, fail < 75 %.
	 */
	protected function uniform_status(): string {
		if ( $this->tally->at_least( 95 ) ) {
			return Status::PASS;
		}
		if ( $this->tally->at_least( 75 ) ) {
			return Status::WARN;
		}
		return Status::FAIL;
	}

	/**
	 * Result when the scan stopped at the memory ceiling.
	 */
	public static function scan_aborted(): CheckResult {
		return new CheckResult(
			Status::ERROR,
			'scan_memory_limit',
			__( 'We stopped checking your products because your site was running low on memory. Ask your host to raise the PHP memory limit, then run the audit again.', 'x7velo' )
		);
	}

	/**
	 * Builds a result with the tally's details; the message is rendered from code + details.
	 *
	 * @param string               $status Status.
	 * @param string               $code   Code.
	 * @param array<string, mixed> $extra  Extra details.
	 */
	protected function result( string $status, string $code, array $extra = array() ): CheckResult {
		$details = $this->tally->to_details() + $extra;
		return new CheckResult( $status, $code, $this->render_message( $code, $details ), $details, Fixer::MANUAL );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $code    Code.
	 * @param array<string, mixed> $details Details.
	 */
	public function render_message( string $code, array $details ): string {
		if ( 'no_products' === $code ) {
			return __( 'You have no published products yet, so there is nothing to check.', 'x7velo' );
		}
		return $this->render( $code, $details, (int) ( $details['missing'] ?? 0 ) );
	}

	/**
	 * Check-specific message.
	 *
	 * @param string               $code    Code.
	 * @param array<string, mixed> $details Details.
	 * @param int                  $missing Products failing the check.
	 */
	abstract protected function render( string $code, array $details, int $missing ): string;
}
