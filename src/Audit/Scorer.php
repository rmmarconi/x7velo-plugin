<?php
/**
 * Score calculation (spec §8).
 *
 * @package X7velo
 */

namespace X7velo\Audit;

defined( 'ABSPATH' ) || exit;

/**
 * Computes the 0–100 readiness score.
 *
 * Formula: round( 100 × Σ(weight × credit) / Σ(weight of pass/warn/fail) ),
 * credit pass 1, warn 0.5, fail 0; not_applicable and error excluded from both sums.
 * Any critical failure caps the score at CAP. More than MAX_ERRORS errors → no score.
 */
final class Scorer {

	public const CAP        = 20;
	public const MAX_ERRORS = 3;

	public const BAND_LOW    = 'low';
	public const BAND_MEDIUM = 'medium';
	public const BAND_HIGH   = 'high';

	/**
	 * Scores a list of check outcomes.
	 *
	 * @param array<int, array{weight: int, status: string, critical?: bool}> $entries Outcomes.
	 * @return array{score: int|null, band: string|null, capped: bool, complete: bool, counts: array<string, int>}
	 */
	public function score( array $entries ): array {
		$counts = array_fill_keys( Status::all(), 0 );
		$earned = 0.0;
		$total  = 0;
		$cap    = false;

		foreach ( $entries as $entry ) {
			$status = (string) $entry['status'];
			$weight = max( 0, (int) $entry['weight'] );
			if ( isset( $counts[ $status ] ) ) {
				++$counts[ $status ];
			}
			if ( ! Status::is_scored( $status ) ) {
				continue;
			}
			$total += $weight;
			if ( Status::PASS === $status ) {
				$earned += $weight;
			} elseif ( Status::WARN === $status ) {
				$earned += $weight * 0.5;
			} elseif ( ! empty( $entry['critical'] ) ) {
				$cap = true;
			}
		}

		$complete = $counts[ Status::ERROR ] <= self::MAX_ERRORS;
		$score    = null;
		$capped   = false;

		if ( $complete && $total > 0 ) {
			$score = (int) round( 100 * $earned / $total );
			if ( $cap && $score > self::CAP ) {
				$score  = self::CAP;
				$capped = true;
			}
		}

		return array(
			'score'    => $score,
			'band'     => null === $score ? null : self::band( $score ),
			'capped'   => $capped,
			'complete' => $complete,
			'counts'   => $counts,
		);
	}

	/**
	 * Band ID for a score: 0–49 low, 50–79 medium, 80–100 high.
	 *
	 * @param int $score Score.
	 */
	public static function band( int $score ): string {
		if ( $score >= 80 ) {
			return self::BAND_HIGH;
		}
		if ( $score >= 50 ) {
			return self::BAND_MEDIUM;
		}
		return self::BAND_LOW;
	}

	/**
	 * Translated label of a band ID (same labels as the dashboard; unknown IDs are returned as is).
	 *
	 * @param string $band Band ID.
	 */
	public static function band_label( string $band ): string {
		switch ( $band ) {
			case self::BAND_HIGH:
				return __( 'Easy for AI assistants to read', 'x7velo' );
			case self::BAND_MEDIUM:
				return __( 'Partly readable', 'x7velo' );
			case self::BAND_LOW:
				return __( 'Hard for AI assistants to read', 'x7velo' );
		}
		return $band;
	}
}
