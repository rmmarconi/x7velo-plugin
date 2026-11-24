<?php
/**
 * Scorer tests (spec v2 §11 acceptance criteria 1–7, 9, 10, 13, 14; 18 checks, v2 weights).
 *
 * @package X7velo
 */

namespace X7velo\Tests\Audit;

use WP_UnitTestCase;
use X7velo\Audit\Scorer;
use X7velo\Audit\Status;

/**
 * Scorer unit tests with the spec's worked numbers.
 */
final class ScorerTest extends WP_UnitTestCase {

	/**
	 * The 18 spec v2 checks and weights (total 100).
	 *
	 * @var array<string, int>
	 */
	private const WEIGHTS = array(
		'VIS-01'    => 10,
		'VIS-02'    => 5,
		'VIS-03'    => 4,
		'VIS-04'    => 4,
		'CRAWL-01'  => 10,
		'CRAWL-02'  => 5,
		'CRAWL-03'  => 5,
		'CRAWL-04'  => 5,
		'SCHEMA-01' => 10,
		'DATA-01'   => 8,
		'DATA-02'   => 6,
		'DATA-03'   => 6,
		'DATA-04'   => 4,
		'DATA-05'   => 2,
		'STORE-01'  => 5,
		'STORE-02'  => 5,
		'STORE-03'  => 3,
		'STORE-04'  => 3,
	);

	/**
	 * Builds entries with every check passing except the overrides.
	 *
	 * @param array<string, string|array{0: string, 1: bool}> $overrides ID => status or [status, critical].
	 * @return array<int, array{weight: int, status: string, critical: bool}>
	 */
	private static function entries( array $overrides = array() ): array {
		$entries = array();
		foreach ( self::WEIGHTS as $id => $weight ) {
			$override  = $overrides[ $id ] ?? Status::PASS;
			$entries[] = array(
				'weight'   => $weight,
				'status'   => is_array( $override ) ? $override[0] : $override,
				'critical' => is_array( $override ) ? $override[1] : false,
			);
		}
		return $entries;
	}

	/**
	 * Weights in the spec total 100.
	 */
	public function test_spec_weights_total_100(): void {
		$this->assertSame( 100, array_sum( self::WEIGHTS ) );
	}

	/**
	 * AC1: all pass → 100.
	 */
	public function test_all_pass_scores_100(): void {
		$result = ( new Scorer() )->score( self::entries() );
		$this->assertSame( 100, $result['score'] );
		$this->assertSame( Scorer::BAND_HIGH, $result['band'] );
		$this->assertTrue( $result['complete'] );
		$this->assertFalse( $result['capped'] );
		$this->assertSame( 18, $result['counts'][ Status::PASS ] );
	}

	/**
	 * AC2: DATA-01 fails → 92.
	 */
	public function test_data01_fail_scores_92(): void {
		$this->assertSame( 92, ( new Scorer() )->score( self::entries( array( 'DATA-01' => Status::FAIL ) ) )['score'] );
	}

	/**
	 * AC3: DATA-01 warns → 96.
	 */
	public function test_data01_warn_scores_96(): void {
		$this->assertSame( 96, ( new Scorer() )->score( self::entries( array( 'DATA-01' => Status::WARN ) ) )['score'] );
	}

	/**
	 * AC4: STORE-03 not applicable, rest passing → 100.
	 */
	public function test_not_applicable_is_excluded(): void {
		$this->assertSame( 100, ( new Scorer() )->score( self::entries( array( 'STORE-03' => Status::NOT_APPLICABLE ) ) )['score'] );
	}

	/**
	 * AC5: STORE-03 n/a and DATA-01 failing → round(100 × 89/97) = 92.
	 */
	public function test_not_applicable_and_fail(): void {
		$result = ( new Scorer() )->score(
			self::entries(
				array(
					'STORE-03' => Status::NOT_APPLICABLE,
					'DATA-01'  => Status::FAIL,
				)
			)
		);
		$this->assertSame( 92, $result['score'] );
		$this->assertSame( (int) round( 100 * 89 / 97 ), $result['score'] );
	}

	/**
	 * AC6: VIS-01 critical fail, rest passing → 20.
	 */
	public function test_critical_fail_caps_at_20(): void {
		$result = ( new Scorer() )->score( self::entries( array( 'VIS-01' => array( Status::FAIL, true ) ) ) );
		$this->assertSame( 20, $result['score'] );
		$this->assertTrue( $result['capped'] );
		$this->assertSame( Scorer::BAND_LOW, $result['band'] );
	}

	/**
	 * AC7: VIS-04 coming soon (critical) → 20.
	 */
	public function test_coming_soon_caps_at_20(): void {
		$result = ( new Scorer() )->score( self::entries( array( 'VIS-04' => array( Status::FAIL, true ) ) ) );
		$this->assertSame( 20, $result['score'] );
		$this->assertTrue( $result['capped'] );
	}

	/**
	 * AC9: caps don't stack — two critical fails still give min(score, 20), not lower.
	 */
	public function test_caps_do_not_stack(): void {
		$result = ( new Scorer() )->score(
			self::entries(
				array(
					'VIS-01' => array( Status::FAIL, true ),
					'VIS-04' => array( Status::FAIL, true ),
				)
			)
		);
		$this->assertSame( 20, $result['score'] );
	}

	/**
	 * AC10: CRAWL-01 full block (critical) → ≤ 20.
	 */
	public function test_crawl_full_block_caps(): void {
		$result = ( new Scorer() )->score( self::entries( array( 'CRAWL-01' => array( Status::FAIL, true ) ) ) );
		$this->assertLessThanOrEqual( 20, $result['score'] );
	}

	/**
	 * A non-critical fail does not cap.
	 */
	public function test_non_critical_fail_does_not_cap(): void {
		$result = ( new Scorer() )->score( self::entries( array( 'CRAWL-01' => Status::FAIL ) ) );
		$this->assertSame( 90, $result['score'] );
		$this->assertFalse( $result['capped'] );
	}

	/**
	 * Cap never raises a lower score.
	 */
	public function test_cap_keeps_lower_score(): void {
		$all_fail           = array_fill_keys( array_keys( self::WEIGHTS ), Status::FAIL );
		$all_fail['VIS-01'] = array( Status::FAIL, true );
		$result             = ( new Scorer() )->score( self::entries( $all_fail ) );
		$this->assertSame( 0, $result['score'] );
		$this->assertFalse( $result['capped'] );
	}

	/**
	 * AC13: errors are excluded like not_applicable (3 errors still scored).
	 */
	public function test_three_errors_still_scored(): void {
		$result = ( new Scorer() )->score(
			self::entries(
				array(
					'DATA-01' => Status::ERROR,
					'DATA-02' => Status::ERROR,
					'DATA-03' => Status::ERROR,
				)
			)
		);
		$this->assertSame( 100, $result['score'] );
		$this->assertTrue( $result['complete'] );
		$this->assertSame( 3, $result['counts'][ Status::ERROR ] );
	}

	/**
	 * AC14: 4 errors → no score.
	 */
	public function test_four_errors_no_score(): void {
		$result = ( new Scorer() )->score(
			self::entries(
				array(
					'DATA-01' => Status::ERROR,
					'DATA-02' => Status::ERROR,
					'DATA-03' => Status::ERROR,
					'DATA-04' => Status::ERROR,
				)
			)
		);
		$this->assertNull( $result['score'] );
		$this->assertNull( $result['band'] );
		$this->assertFalse( $result['complete'] );
	}

	/**
	 * Nothing scoreable → no score, but complete.
	 */
	public function test_nothing_scoreable(): void {
		$result = ( new Scorer() )->score(
			array(
				array(
					'weight' => 5,
					'status' => Status::NOT_APPLICABLE,
				),
			)
		);
		$this->assertNull( $result['score'] );
		$this->assertTrue( $result['complete'] );
	}

	/**
	 * Band boundaries 0–49 / 50–79 / 80–100.
	 */
	public function test_bands(): void {
		$this->assertSame( Scorer::BAND_LOW, Scorer::band( 0 ) );
		$this->assertSame( Scorer::BAND_LOW, Scorer::band( 49 ) );
		$this->assertSame( Scorer::BAND_MEDIUM, Scorer::band( 50 ) );
		$this->assertSame( Scorer::BAND_MEDIUM, Scorer::band( 79 ) );
		$this->assertSame( Scorer::BAND_HIGH, Scorer::band( 80 ) );
		$this->assertSame( Scorer::BAND_HIGH, Scorer::band( 100 ) );
	}
}
