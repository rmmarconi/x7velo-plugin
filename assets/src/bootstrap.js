/**
 * Page data printed by X7velo\Admin\AdminPage (window.x7veloAdmin), with safe defaults.
 *
 * Values filled at render time (site address, base country, core labels) come from here, never
 * from the audit record (spec §10: no interpolated option values in stored results).
 */
const DEFAULTS = {
	homeUrl: '/',
	siteHost: '',
	homePath: '/',
	mainSiteUrl: '',
	locale: 'en-US',
	baseCountry: '',
	urls: {
		page: 'admin.php?page=x7velo',
		reading: 'options-reading.php',
		permalinks: 'options-permalink.php',
		siteVisibility: 'admin.php?page=wc-settings&tab=site-visibility',
		newProduct: 'post-new.php?post_type=product',
		newPage: 'post-new.php?post_type=page',
		products: 'edit.php?post_type=product',
		llmsTxt: '/llms.txt',
		feed: '/?x7velo_feed=1',
		supportForum: 'https://wordpress.org/support/plugin/x7velo/',
	},
	versions: { plugin: '', wp: '', wc: '', php: '', memoryLimit: '' },
	crawlers: { training: [], experimental: [], googleExtended: true },
	glaActive: false,
	coreLabels: {},
	notices: [],
	woocommerceActive: true,
};

/**
 * Whether the page is in site mode (WooCommerce not active right now, spec §15).
 *
 * @param {Object} boot Bootstrap data.
 * @return {boolean} True without WooCommerce.
 */
export function isSiteMode( boot ) {
	return boot?.woocommerceActive === false;
}

/**
 * @return {Object} Bootstrap data merged over defaults.
 */
export function getBootstrap() {
	const raw = ( typeof window !== 'undefined' && window.x7veloAdmin ) || {};
	return {
		...DEFAULTS,
		...raw,
		urls: { ...DEFAULTS.urls, ...( raw.urls || {} ) },
		versions: { ...DEFAULTS.versions, ...( raw.versions || {} ) },
		crawlers: { ...DEFAULTS.crawlers, ...( raw.crawlers || {} ) },
		coreLabels: { ...( raw.coreLabels || {} ) },
		notices: Array.isArray( raw.notices ) ? raw.notices : [],
		// Missing (older PHP) = store mode.
		woocommerceActive: raw.woocommerceActive !== false,
	};
}
