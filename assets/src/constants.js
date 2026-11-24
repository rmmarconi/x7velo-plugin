/**
 * Fixed values and copy keyed by ID: band labels, categories, statuses, spec order.
 *
 * Band labels are Option A (approved by Rafael, 2026-11-22), keyed by the band ID the API returns
 * (`low` | `medium` | `high`) so copy changes never need logic changes (spec AC 87).
 */
import { __ } from '@wordpress/i18n';

export const SCORE_CAP = 20;
export const MAX_ERRORS = 3;

/** Code of checks that don't apply because WooCommerce isn't active (spec §15.2). */
export const WOO_INACTIVE = 'woocommerce_inactive';

/**
 * Whether a check is a store check that doesn't apply without WooCommerce.
 *
 * @param {Object} check UI check.
 * @return {boolean} True for `woocommerce_inactive`.
 */
export function isWooInactive( check ) {
	return check?.status === 'not_applicable' && check?.code === WOO_INACTIVE;
}

/**
 * @param {boolean} siteMode Site mode (no WooCommerce, spec §15.6): same labels, `_site` texts.
 * @return {Object<string, {label: string, text: string}>} Band copy by band ID.
 */
export function getBands( siteMode = false ) {
	if ( siteMode ) {
		return {
			low: {
				label: __( 'Hard for AI assistants to read', 'x7velo' ),
				text: __(
					'AI assistants can’t read much of your site yet. Start with the fixes below.',
					'x7velo'
				),
			},
			medium: {
				label: __( 'Partly readable', 'x7velo' ),
				text: __(
					'AI assistants can read part of your site. A few fixes will make your pages easier to find and quote.',
					'x7velo'
				),
			},
			high: {
				label: __( 'Easy for AI assistants to read', 'x7velo' ),
				text: __(
					'AI assistants can read your site well. Check again after big changes to your site or plugins.',
					'x7velo'
				),
			},
		};
	}
	return {
		low: {
			label: __( 'Hard for AI assistants to read', 'x7velo' ),
			text: __(
				'AI assistants can’t read much of your store yet. Start with the fixes below.',
				'x7velo'
			),
		},
		medium: {
			label: __( 'Partly readable', 'x7velo' ),
			text: __(
				'AI assistants can read part of your store. A few fixes will make your products easier to find and quote.',
				'x7velo'
			),
		},
		high: {
			label: __( 'Easy for AI assistants to read', 'x7velo' ),
			text: __(
				'AI assistants can read your store well. Keep product details complete as you add products.',
				'x7velo'
			),
		},
	};
}

/**
 * Band ID for a score (mirrors X7velo\Audit\Scorer::band()).
 *
 * @param {number} score Score 0–100.
 * @return {string} Band ID.
 */
export function bandFor( score ) {
	if ( score >= 80 ) {
		return 'high';
	}
	if ( score >= 50 ) {
		return 'medium';
	}
	return 'low';
}

/** Category display order (spec §5 A–E). */
export const CATEGORY_ORDER = [
	'visibility',
	'crawl',
	'schema',
	'data',
	'store',
];

/**
 * @return {Object<string, string>} Category labels.
 */
export function getCategoryLabels() {
	return {
		visibility: __( 'Visibility basics', 'x7velo' ),
		crawl: __( 'Crawler access', 'x7velo' ),
		schema: __( 'Product information', 'x7velo' ),
		data: __( 'Product data quality', 'x7velo' ),
		store: __( 'Store trust', 'x7velo' ),
	};
}

/** Check IDs in spec order (tie-break for top fixes, critical ordering). */
export const SPEC_ORDER = [
	'VIS-01',
	'VIS-02',
	'VIS-03',
	'VIS-04',
	'CRAWL-01',
	'CRAWL-02',
	'CRAWL-03',
	'CRAWL-04',
	'SCHEMA-01',
	'DATA-01',
	'DATA-02',
	'DATA-03',
	'DATA-04',
	'DATA-05',
	'STORE-01',
	'STORE-02',
	'STORE-03',
	'STORE-04',
];

/**
 * Position of a check in spec order; unknown (third-party) checks go last, by ID.
 *
 * @param {string} id Check ID.
 * @return {number} Index.
 */
export function specIndex( id ) {
	const i = SPEC_ORDER.indexOf( id );
	return i === -1 ? SPEC_ORDER.length : i;
}

/** Audit check `fixer` values that x7velo can switch on, mapped to fixer keys. */
export const AUTO_FIXERS = {
	'json-ld': 'jsonld',
	'llms-txt': 'llms_txt',
	feed: 'feed',
	robots: 'robots',
};

/** Fixer key → settings key that turns it on. */
export const FIXER_SETTING = {
	jsonld: 'fix_jsonld',
	llms_txt: 'fix_llms_txt',
	feed: 'fix_feed',
	robots: 'fix_robots_search',
};

/**
 * Display names for `details.source` / `managed_by` slugs the engine may return.
 *
 * @param {string|null} slug Source slug.
 * @return {string} Display name.
 */
export function sourceName( slug ) {
	if ( ! slug ) {
		return '';
	}
	const names = {
		woocommerce: 'WooCommerce',
		woocore: 'WooCommerce',
		core: 'WordPress',
		wordpress: 'WordPress',
		wp: 'WordPress',
		yoast: 'Yoast SEO',
		'yoast-woo': 'Yoast WooCommerce SEO',
		yoast_woo: 'Yoast WooCommerce SEO',
		yoastwoo: 'Yoast WooCommerce SEO',
		rankmath: 'Rank Math',
		'rank-math': 'Rank Math',
		rank_math: 'Rank Math',
		aioseo: 'All in One SEO',
		seopress: 'SEOPress',
		x7velo: 'x7velo',
		'google-listings-and-ads': 'Google for WooCommerce',
		gla: 'Google for WooCommerce',
		file: __( 'a file on your server', 'x7velo' ),
		physical: __( 'a file on your server', 'x7velo' ),
		physical_file: __( 'a file on your server', 'x7velo' ),
	};
	const key = String( slug ).toLowerCase();
	return names[ key ] ?? String( slug );
}

/**
 * Sources that are the default path, not "managed by another plugin". `filter` is the robots.txt
 * WordPress generates (CRAWL-01/02 `details.source`): plugins may add lines to it, but nothing
 * takes it over and x7velo's crawler rules still apply, so the check just reads "Passed".
 */
export const NEUTRAL_SOURCES = [
	'woocommerce',
	'woocore',
	'core',
	'wordpress',
	'wp',
	'filter',
	'x7velo',
];

/** Within-category order of row kinds (AC 81). */
export const ROW_ORDER = [
	'fail',
	'warn',
	'pass',
	'managed',
	'not_applicable',
	'error',
];
