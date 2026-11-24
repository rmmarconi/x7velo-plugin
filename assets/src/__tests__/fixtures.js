/**
 * Test fixtures: spec v2 checks (18, weights total 100) in REST shape.
 */
export const WEIGHTS = {
	'VIS-01': [ 'visibility', 10, 'guided' ],
	'VIS-02': [ 'visibility', 5, 'manual' ],
	'VIS-03': [ 'visibility', 4, 'guided' ],
	'VIS-04': [ 'visibility', 4, 'guided' ],
	'CRAWL-01': [ 'crawl', 10, 'robots' ],
	'CRAWL-02': [ 'crawl', 5, 'robots' ],
	'CRAWL-03': [ 'crawl', 5, 'llms-txt' ],
	'CRAWL-04': [ 'crawl', 5, 'feed' ],
	'SCHEMA-01': [ 'schema', 10, 'json-ld' ],
	'DATA-01': [ 'data', 8, 'manual' ],
	'DATA-02': [ 'data', 6, 'manual' ],
	'DATA-03': [ 'data', 6, 'manual' ],
	'DATA-04': [ 'data', 4, 'manual' ],
	'DATA-05': [ 'data', 2, 'manual' ],
	'STORE-01': [ 'store', 5, 'guided' ],
	'STORE-02': [ 'store', 5, 'guided' ],
	'STORE-03': [ 'store', 3, 'guided' ],
	'STORE-04': [ 'store', 3, 'guided' ],
};

/**
 * @param {Object} overrides id → partial raw check.
 * @return {Array} Raw checks.
 */
export function rawChecks( overrides = {} ) {
	return Object.entries( WEIGHTS ).map(
		( [ id, [ category, weight, fixer ] ] ) => ( {
			id,
			category,
			weight,
			status: 'pass',
			code: 'ok',
			message: `${ id } message`,
			details: {},
			fixer,
			guided_path: '',
			guided_url: '',
			critical: false,
			...( overrides[ id ] || {} ),
		} )
	);
}

export function rawRecord( overrides = {}, recordOverrides = {} ) {
	return {
		schema_version: 2,
		generated_at: '2026-11-22T14:05:00+00:00',
		plugin_version: '0.1.0',
		score: 100,
		band: 'high',
		capped: false,
		complete: true,
		state: 'complete',
		counts: {},
		checks: rawChecks( overrides ),
		...recordOverrides,
	};
}

export function rawSettings( overrides = {}, status = {} ) {
	return {
		returns_page_id: 0,
		own_brand_products: false,
		fix_jsonld: false,
		fix_llms_txt: false,
		fix_feed: false,
		fix_robots_search: false,
		fix_robots_block_training: false,
		block_google_extended: false,
		block_experimental: [],
		return_policy: '',
		return_days: null,
		delivery_days_min: null,
		delivery_days_max: null,
		...overrides,
		fixer_status: {
			jsonld: { state: 'off', mode: 'augment' },
			llms_txt: { state: 'off', url: 'https://example.com/llms.txt' },
			feed: { state: 'off', url: 'https://example.com/?x7velo_feed=1' },
			robots: { state: 'off' },
			...status,
		},
	};
}

export const BOOT = {
	homeUrl: 'https://example.com/',
	siteHost: 'example.com',
	homePath: '/',
	locale: 'en-US',
	baseCountry: 'Brazil',
	urls: {
		page: 'admin.php?page=x7velo',
		reading: 'options-reading.php',
		permalinks: 'options-permalink.php',
		newProduct: 'post-new.php?post_type=product',
		newPage: 'post-new.php?post_type=page',
		products: 'edit.php?post_type=product',
		llmsTxt: 'https://example.com/llms.txt',
		feed: 'https://example.com/?x7velo_feed=1',
		supportForum: 'https://wordpress.org/support/plugin/x7velo/',
	},
	versions: {
		plugin: '0.1.0',
		wp: '6.9',
		wc: '11.3.1',
		php: '8.1.0',
		memoryLimit: '256M',
	},
	crawlers: {
		training: [
			{ token: 'GPTBot', operator: 'OpenAI' },
			{ token: 'CCBot', operator: 'Common Crawl' },
		],
		experimental: [ { token: 'Bytespider', operator: 'ByteDance' } ],
		googleExtended: true,
	},
	glaActive: false,
	coreLabels: {},
	notices: [],
};

/** Store checks that report `woocommerce_inactive` without WooCommerce (spec §15.2). */
export const STORE_ONLY = [
	'VIS-04',
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
 * Site-mode record (no WooCommerce): 6 real checks, 12 N/A `woocommerce_inactive`.
 * Default: CRAWL-03 fails → 34 / 39 → 87.
 *
 * @param {Object} overrides       id → partial raw check.
 * @param {Object} recordOverrides Record fields.
 * @return {Object} Raw record.
 */
export function rawSiteRecord( overrides = {}, recordOverrides = {} ) {
	const base = {};
	STORE_ONLY.forEach( ( id ) => {
		base[ id ] = {
			status: 'not_applicable',
			code: 'woocommerce_inactive',
			message: 'Doesn’t apply: this check is for WooCommerce stores.',
		};
	} );
	[ 'VIS-02', 'VIS-03', 'CRAWL-01', 'CRAWL-02' ].forEach( ( id ) => {
		base[ id ] = { details: { site_kind: 'site' } };
	} );
	base[ 'CRAWL-03' ] = {
		status: 'fail',
		code: 'no_llms_txt',
		message:
			'You don’t have an llms.txt file, a short summary of your site that AI assistants can read.',
		details: { site_kind: 'site' },
	};
	const merged = { ...base };
	Object.entries( overrides ).forEach( ( [ id, o ] ) => {
		merged[ id ] = { ...( base[ id ] || {} ), ...o };
	} );
	return rawRecord( merged, {
		score: 87,
		band: 'high',
		woocommerce_active: false,
		...recordOverrides,
	} );
}

/**
 * Site-mode settings: fixer_status without jsonld/feed (spec §15.3).
 *
 * @param {Object} overrides Settings.
 * @param {Object} status    Fixer status rows.
 * @return {Object} Raw settings.
 */
export function rawSiteSettings( overrides = {}, status = {} ) {
	const settings = rawSettings( overrides, status );
	delete settings.fixer_status.jsonld;
	delete settings.fixer_status.feed;
	return settings;
}

export const SITE_BOOT = {
	...BOOT,
	woocommerceActive: false,
	urls: { ...BOOT.urls, page: 'tools.php?page=x7velo' },
	versions: { ...BOOT.versions, wc: '' },
};
