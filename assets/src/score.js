/**
 * Pure scoring and ordering logic (no React).
 *
 * computeScore() mirrors X7velo\Audit\Scorer exactly (spec §8): pass 1, warn 0.5, fail 0;
 * not_applicable and error excluded; a critical fail caps the score at 20; more than 3 errors →
 * no score. "+N points" = score if the check passed − current score, with the same formula, so
 * caps and N/A exclusions are honoured (design §3.2).
 */
import {
	AUTO_FIXERS,
	MAX_ERRORS,
	ROW_ORDER,
	SCORE_CAP,
	specIndex,
} from './constants';
import { isFixedByX7velo, isManagedBy } from './api';

/**
 * @param {Array<{weight: number, status: string, critical?: boolean}>} checks Checks.
 * @return {number|null} Score, or null when it can't be computed.
 */
export function computeScore( checks ) {
	let earnedHalves = 0; // Σ weight × credit × 2, kept integer to avoid float drift.
	let total = 0;
	let errors = 0;
	let cap = false;
	checks.forEach( ( c ) => {
		if ( c.status === 'error' ) {
			errors++;
			return;
		}
		if ( ! [ 'pass', 'warn', 'fail' ].includes( c.status ) ) {
			return;
		}
		const weight = Math.max( 0, Number( c.weight ) || 0 );
		total += weight;
		if ( c.status === 'pass' ) {
			earnedHalves += 2 * weight;
		} else if ( c.status === 'warn' ) {
			earnedHalves += weight;
		} else if ( c.critical ) {
			cap = true;
		}
	} );
	if ( errors > MAX_ERRORS || total === 0 ) {
		return null;
	}
	// round( 100 × earned / total ); halves → divide by 2 × total. Positive, so Math.round = PHP round.
	let score = Math.round( ( 100 * earnedHalves ) / ( 2 * total ) );
	if ( cap && score > SCORE_CAP ) {
		score = SCORE_CAP;
	}
	return score;
}

/**
 * Points gained if the given checks all passed.
 *
 * @param {Array}    checks All checks.
 * @param {string[]} ids    Check IDs to flip to pass.
 * @return {number} Points (0 when no score can be computed).
 */
export function pointsIfPassed( checks, ids ) {
	const current = computeScore( checks );
	const next = computeScore(
		checks.map( ( c ) =>
			ids.includes( c.id ) ? { ...c, status: 'pass', critical: false } : c
		)
	);
	if ( current === null || next === null ) {
		return 0;
	}
	return Math.max( 0, next - current );
}

/** Critical root causes, in the order notices and top fixes show them. */
export const CRITICAL_ORDER = [ 'VIS-01', 'VIS-04', 'CRAWL-01' ];

/**
 * Whether CRAWL-01's failure is just VIS-01 ("discourage search engines") seen through robots.
 *
 * @param {Object} check CRAWL-01.
 * @return {boolean} True for the merged root cause.
 */
export function isSearchVisibilityBlock( check ) {
	return (
		check?.id === 'CRAWL-01' &&
		check.code === 'blocked_by_search_visibility'
	);
}

/**
 * Decides the primary (and optional secondary) action for a fail/warn check.
 *
 * @param {Object} check       UI check.
 * @param {Object} fixerStatus Normalised fixer_status (may hold nulls) or null.
 * @return {{primary: string, secondary: (string|null), fixer: (string|null)}} kinds:
 *   'fix' | 'where' | 'how' | 'settings'.
 */
export function actionsFor( check, fixerStatus ) {
	if ( check.id === 'STORE-02' ) {
		return { primary: 'settings', secondary: null, fixer: null };
	}
	const fixer = AUTO_FIXERS[ check.fixer ] || null;
	const fs = fixer && fixerStatus ? fixerStatus[ fixer ] : null;
	const canFix =
		!! fs &&
		fs.state === 'off' &&
		[ 'fail', 'warn' ].includes( check.status ) &&
		! isSearchVisibilityBlock( check );
	if ( canFix ) {
		// CRAWL-03 with an SEO plugin that has its own llms.txt: Guided first, our Fix it second.
		if ( check.id === 'CRAWL-03' && check.guidedUrl ) {
			return { primary: 'where', secondary: 'fix', fixer };
		}
		return { primary: 'fix', secondary: null, fixer };
	}
	if ( check.guidedUrl ) {
		return { primary: 'where', secondary: null, fixer: null };
	}
	return { primary: 'how', secondary: null, fixer: null };
}

const ACTION_RANK = { fix: 0, where: 1, settings: 1, how: 2 };

/**
 * Top fixes (design §3.2): critical first (spec order), then most points, then quick wins, then
 * spec order. Only fail/warn; CRAWL-01 blocked by search visibility merges into VIS-01.
 *
 * @param {Array}  checks      All checks.
 * @param {Object} fixerStatus Fixer status map or null.
 * @param {number} limit       Max items.
 * @return {Array<{check: Object, points: number, actions: Object}>} Items.
 */
export function topFixes( checks, fixerStatus, limit = 3 ) {
	const vis01Failing = checks.some(
		( c ) => c.id === 'VIS-01' && c.status === 'fail'
	);
	const crawlMerged = checks.find(
		( c ) => isSearchVisibilityBlock( c ) && c.status === 'fail'
	);
	const items = checks
		.filter( ( c ) => [ 'fail', 'warn' ].includes( c.status ) )
		.filter(
			( c ) => ! ( vis01Failing && crawlMerged && c.id === 'CRAWL-01' )
		)
		.map( ( c ) => {
			const ids =
				c.id === 'VIS-01' && crawlMerged
					? [ 'VIS-01', 'CRAWL-01' ]
					: [ c.id ];
			const actions = actionsFor( c, fixerStatus );
			return { check: c, points: pointsIfPassed( checks, ids ), actions };
		} );
	items.sort( ( a, b ) => {
		const criticalRank = ( c ) => {
			if ( ! c.critical ) {
				return Infinity;
			}
			const i = CRITICAL_ORDER.indexOf( c.id );
			return i === -1 ? CRITICAL_ORDER.length : i;
		};
		const ra = criticalRank( a.check );
		const rb = criticalRank( b.check );
		if ( ra !== rb ) {
			return ra - rb;
		}
		if ( a.points !== b.points ) {
			return b.points - a.points;
		}
		const aa = ACTION_RANK[ a.actions.primary ] ?? 3;
		const ab = ACTION_RANK[ b.actions.primary ] ?? 3;
		if ( aa !== ab ) {
			return aa - ab;
		}
		return compareSpec( a.check, b.check );
	} );
	return items.slice( 0, limit );
}

function compareSpec( a, b ) {
	const d = specIndex( a.id ) - specIndex( b.id );
	return d !== 0 ? d : a.id.localeCompare( b.id );
}

/**
 * Row kind used for ordering inside a category.
 *
 * @param {Object} check UI check.
 * @return {string} One of ROW_ORDER.
 */
export function rowKind( check ) {
	if ( check.status === 'pass' && isManagedBy( check ) ) {
		return 'managed';
	}
	return ROW_ORDER.includes( check.status ) ? check.status : 'error';
}

/**
 * Checks grouped by category: categories with failures first, then warnings, then the rest,
 * each in spec order; inside: fail → warn → pass → managed-by → not_applicable → error.
 *
 * @param {Array}    checks        All checks.
 * @param {string[]} categoryOrder Category IDs in display order.
 * @return {Array<{category: string, checks: Array}>} Groups.
 */
export function groupChecks( checks, categoryOrder ) {
	const byCat = {};
	checks.forEach( ( c ) => {
		( byCat[ c.category ] = byCat[ c.category ] || [] ).push( c );
	} );
	const cats = Object.keys( byCat ).sort( ( a, b ) => {
		const rank = ( cat ) => {
			if ( byCat[ cat ].some( ( c ) => c.status === 'fail' ) ) {
				return 0;
			}
			if ( byCat[ cat ].some( ( c ) => c.status === 'warn' ) ) {
				return 1;
			}
			return 2;
		};
		const d = rank( a ) - rank( b );
		if ( d !== 0 ) {
			return d;
		}
		const ia = categoryOrder.indexOf( a );
		const ib = categoryOrder.indexOf( b );
		return ( ia === -1 ? 99 : ia ) - ( ib === -1 ? 99 : ib );
	} );
	return cats.map( ( category ) => ( {
		category,
		checks: [ ...byCat[ category ] ].sort( ( a, b ) => {
			const d =
				ROW_ORDER.indexOf( rowKind( a ) ) -
				ROW_ORDER.indexOf( rowKind( b ) );
			return d !== 0 ? d : compareSpec( a, b );
		} ),
	} ) );
}

/**
 * Per-category summary for the breakdown card.
 *
 * @param {Array} checks Checks in one category.
 * @return {{toFix: number, toImprove: number, na: number, errors: number, share: number}} Summary;
 *   share = earned / available points (0–1), decorative only.
 */
export function categorySummary( checks ) {
	let earned = 0;
	let total = 0;
	const s = { toFix: 0, toImprove: 0, na: 0, errors: 0 };
	checks.forEach( ( c ) => {
		if ( c.status === 'fail' ) {
			s.toFix++;
		} else if ( c.status === 'warn' ) {
			s.toImprove++;
		} else if ( c.status === 'not_applicable' ) {
			s.na++;
		} else if ( c.status === 'error' ) {
			s.errors++;
		}
		if ( [ 'pass', 'warn', 'fail' ].includes( c.status ) ) {
			total += c.weight;
			earned += c.status === 'pass' ? c.weight : 0;
			earned += c.status === 'warn' ? c.weight / 2 : 0;
		}
	} );
	return { ...s, share: total > 0 ? earned / total : 0 };
}

/**
 * Whether a check is the one the "Fixed by x7velo · Turn off" control applies to.
 *
 * @param {Object} check       UI check.
 * @param {Object} fixerStatus Fixer status map or null.
 * @return {string|null} Fixer key to turn off, or null.
 */
export function turnOffFixerFor( check, fixerStatus ) {
	const fixer = AUTO_FIXERS[ check.fixer ] || null;
	if ( ! fixer || ! fixerStatus || ! fixerStatus[ fixer ] ) {
		return null;
	}
	const state = fixerStatus[ fixer ].state;
	if (
		isFixedByX7velo( check ) &&
		( state === 'on' || state === 'paused' )
	) {
		return fixer;
	}
	return null;
}
