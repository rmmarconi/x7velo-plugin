/**
 * The only module that talks to the REST API.
 *
 * Everything the UI reads goes through a normaliser here, so a field rename on the server
 * (spec §10 contract is being built in parallel) is a one-file fix. Components never see raw
 * REST payloads.
 */
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

import { NEUTRAL_SOURCES } from './constants';

const NS = '/x7velo/v1';

/**
 * Error with the REST code, HTTP status and per-field messages (400 rest_invalid_param).
 */
export class ApiError extends Error {
	constructor( { code, message, status, fieldErrors } ) {
		super( message || code || 'request_failed' );
		this.name = 'ApiError';
		this.code = code || 'request_failed';
		this.status = status || 0;
		this.fieldErrors = fieldErrors || {};
	}

	/** Cookie nonce expired: the user must reload. */
	get isSessionExpired() {
		return (
			this.code === 'rest_cookie_invalid_nonce' ||
			( this.status === 401 && this.code !== 'x7velo_no_audit' )
		);
	}
}

/**
 * @param {Error|Object} error Whatever apiFetch threw.
 * @return {ApiError} Normalised error.
 */
export function toApiError( error ) {
	if ( error instanceof ApiError ) {
		return error;
	}
	const data = error?.data || {};
	const fieldErrors = {};
	// WP core validation: data.params = { key: message }.
	if ( data.params && typeof data.params === 'object' ) {
		Object.entries( data.params ).forEach( ( [ key, msg ] ) => {
			fieldErrors[ key ] = typeof msg === 'string' ? msg : '';
		} );
	}
	// Custom validation may use data.fields or data.errors with the same shape.
	[ data.fields, data.errors ].forEach( ( extra ) => {
		if ( extra && typeof extra === 'object' && ! Array.isArray( extra ) ) {
			Object.entries( extra ).forEach( ( [ key, msg ] ) => {
				fieldErrors[ key ] = typeof msg === 'string' ? msg : '';
			} );
		}
	} );
	return new ApiError( {
		code: error?.code,
		message: typeof error?.message === 'string' ? error.message : '',
		status: data.status || 0,
		fieldErrors,
	} );
}

const str = ( v ) => ( typeof v === 'string' ? v : '' );
const intOrNull = ( v ) => {
	if ( v === null || v === undefined || v === '' ) {
		return null;
	}
	const n = Number( v );
	return Number.isFinite( n ) ? Math.trunc( n ) : null;
};

/**
 * @param {Object} raw Check as returned by GET/POST /audit.
 * @return {Object} UI check.
 */
export function normalizeCheck( raw ) {
	const details =
		raw.details &&
		typeof raw.details === 'object' &&
		! Array.isArray( raw.details )
			? raw.details
			: {};
	const source =
		str( details.source ) ||
		str( details.managed_by ) ||
		str( raw.managed_by );
	const sourceLabel =
		str( details.source_name ) ||
		str( details.managed_by_name ) ||
		str( raw.source_name );
	// The server sends examples in details.examples (AuditService::present()); a top-level
	// `examples` is still accepted. Contract: __tests__/contract/audit-response.json.
	let rawExamples = [];
	if ( Array.isArray( details.examples ) ) {
		rawExamples = details.examples;
	} else if ( Array.isArray( raw.examples ) ) {
		rawExamples = raw.examples;
	}
	const examples = rawExamples
		.filter(
			( e ) =>
				e && typeof e === 'object' && Number.isInteger( Number( e.id ) )
		)
		.map( ( e ) => ( {
			id: Number( e.id ),
			title: str( e.title ),
			editUrl: str( e.edit_url ),
		} ) );
	const status = str( raw.status );
	return {
		id: str( raw.id ),
		category: str( raw.category ),
		weight: Math.max( 0, Number( raw.weight ) || 0 ),
		status,
		code: str( raw.code ),
		// Rendered server-side in the viewer's locale. Text only, never HTML.
		message: str( raw.message ),
		details,
		fixer: str( raw.fixer ),
		guidedUrl: str( raw.guided_url ),
		critical: !! raw.critical && status === 'fail',
		examples,
		source: source || null,
		sourceLabel: sourceLabel || null,
		total: intOrNull( details.products ),
		missing: intOrNull( details.missing ),
	};
}

/**
 * Whether a passing check is "Managed by <another plugin>".
 *
 * @param {Object} check UI check.
 * @return {boolean} True when managed elsewhere.
 */
export function isManagedBy( check ) {
	if ( check.status !== 'pass' ) {
		return false;
	}
	if ( check.code === 'managed_by_unverified_plugin' ) {
		return true;
	}
	return (
		!! check.source &&
		! NEUTRAL_SOURCES.includes( String( check.source ).toLowerCase() )
	);
}

/**
 * Whether a passing check passes because an x7velo fixer is on.
 *
 * @param {Object} check UI check.
 * @return {boolean} True when fixed by x7velo.
 */
export function isFixedByX7velo( check ) {
	return (
		check.status === 'pass' &&
		String( check.source || '' ).toLowerCase() === 'x7velo'
	);
}

/**
 * @param {Object} raw Audit record (presented).
 * @return {Object} UI record.
 */
export function normalizeRecord( raw ) {
	const checks = Array.isArray( raw?.checks )
		? raw.checks.map( normalizeCheck )
		: [];
	const score = Number.isInteger( raw?.score ) ? raw.score : null;
	let state = str( raw?.state );
	if ( ! [ 'complete', 'incomplete', 'no_products' ].includes( state ) ) {
		// Pre-v2 records have no `state`: derive it.
		if ( raw?.complete === false ) {
			state = 'incomplete';
		} else if (
			score === null &&
			checks.some( ( c ) => c.code === 'no_products' )
		) {
			state = 'no_products';
		} else {
			state = 'complete';
		}
	}
	return {
		generatedAt: str( raw?.generated_at ),
		pluginVersion: str( raw?.plugin_version ),
		score: state === 'complete' ? score : null,
		band: str( raw?.band ) || null,
		capped: !! raw?.capped,
		state,
		stale: !! raw?.stale,
		// Missing = true: every record written before spec v3 came from a store.
		woocommerceActive: raw?.woocommerce_active !== false,
		checks,
	};
}

/** Settings keys the UI reads and writes (spec §10). */
export const SETTINGS_KEYS = [
	'returns_page_id',
	'own_brand_products',
	'fix_jsonld',
	'fix_llms_txt',
	'fix_feed',
	'fix_robots_search',
	'fix_robots_block_training',
	'block_google_extended',
	'block_experimental',
	'return_policy',
	'return_days',
	'delivery_days_min',
	'delivery_days_max',
];

/** Accepted spellings of each fixer_status key, first match wins. */
const FIXER_STATUS_ALIASES = {
	jsonld: [ 'jsonld', 'fix_jsonld', 'json-ld', 'json_ld' ],
	llms_txt: [ 'llms_txt', 'fix_llms_txt', 'llms-txt', 'llms' ],
	feed: [ 'feed', 'fix_feed' ],
	robots: [ 'robots', 'fix_robots_search', 'robots_txt' ],
};

const FIXER_STATES = [ 'off', 'on', 'managed', 'paused', 'cannot_run' ];

/**
 * JSON-LD per-part states from the server (`included` | `not_yet`) → booleans. Server `shipping`
 * is the UI's `delivery`. Unknown keys and values are dropped; a part the server didn't report
 * stays absent so the caller can fall back to the client-side derivation.
 */
const PART_STATES = { included: true, not_yet: false };
const PART_KEYS = [
	[ 'returns', 'returns' ],
	[ 'shipping', 'delivery' ],
];

/**
 * @param {Object|undefined} raw fixer_status.jsonld.parts (untrusted).
 * @return {{returns?: boolean, delivery?: boolean}|null} Parts, null when none is usable.
 */
export function normalizeJsonldParts( raw ) {
	if ( ! raw || typeof raw !== 'object' || Array.isArray( raw ) ) {
		return null;
	}
	const parts = {};
	PART_KEYS.forEach( ( [ from, to ] ) => {
		const v = raw[ from ];
		if (
			typeof v === 'string' &&
			Object.prototype.hasOwnProperty.call( PART_STATES, v )
		) {
			parts[ to ] = PART_STATES[ v ];
		}
	} );
	return Object.keys( parts ).length ? parts : null;
}

const lines = ( v ) => {
	if ( Array.isArray( v ) ) {
		return v.filter( ( l ) => typeof l === 'string' ).join( '\n' );
	}
	return str( v );
};

/**
 * @param {Object|undefined} raw One fixer_status entry.
 * @return {Object|null} UI fixer status, null when the API doesn't report this fixer.
 */
export function normalizeFixerStatus( raw ) {
	if ( ! raw || typeof raw !== 'object' ) {
		return null;
	}
	const state = FIXER_STATES.includes( raw.state ) ? raw.state : null;
	if ( ! state ) {
		return null;
	}
	const copy = raw.lines || raw.copy_paste || {};
	return {
		state,
		managedBy: str( raw.managed_by ) || null,
		reason: str( raw.reason ) || null,
		url: str( raw.url ) || null,
		mode: str( raw.mode ) || null,
		// JSON-LD: which parts the markup actually carries (server truth); null on older servers.
		parts: normalizeJsonldParts( raw.parts ),
		// Robots: exact lines as strings (preview when filtered; copy-paste when a physical file
		// exists). '' means nothing to show.
		lines: {
			allow: lines( copy.allow ),
			training: lines( copy.training ),
			sitemap: lines( copy.sitemap ),
		},
	};
}

/**
 * @param {Object} raw GET/PATCH /settings response.
 * @return {{values: Object, fixerStatus: Object}} UI settings.
 */
export function normalizeSettings( raw ) {
	const values = {
		returns_page_id: intOrNull( raw?.returns_page_id ) || 0,
		own_brand_products: !! raw?.own_brand_products,
		fix_jsonld: !! raw?.fix_jsonld,
		fix_llms_txt: !! raw?.fix_llms_txt,
		fix_feed: !! raw?.fix_feed,
		fix_robots_search: !! raw?.fix_robots_search,
		fix_robots_block_training: !! raw?.fix_robots_block_training,
		block_google_extended: !! raw?.block_google_extended,
		block_experimental: Array.isArray( raw?.block_experimental )
			? raw.block_experimental.filter( ( t ) => typeof t === 'string' )
			: [],
		return_policy: [ 'finite', 'unlimited', 'none' ].includes(
			raw?.return_policy
		)
			? raw.return_policy
			: '',
		return_days: intOrNull( raw?.return_days ),
		delivery_days_min: intOrNull( raw?.delivery_days_min ),
		delivery_days_max: intOrNull( raw?.delivery_days_max ),
	};
	// Not set: v2 stores 0 as default for return_days; it is never valid (1–365) so treat as unset.
	if ( values.return_days === 0 ) {
		values.return_days = null;
	}
	const rawStatus = raw?.fixer_status || {};
	const fixerStatus = {};
	Object.entries( FIXER_STATUS_ALIASES ).forEach( ( [ key, aliases ] ) => {
		const found = aliases.find( ( a ) => rawStatus[ a ] );
		fixerStatus[ key ] = found
			? normalizeFixerStatus( rawStatus[ found ] )
			: null;
	} );
	return { values, fixerStatus };
}

async function call( options ) {
	try {
		return await apiFetch( options );
	} catch ( error ) {
		throw toApiError( error );
	}
}

/**
 * Last stored audit, or null if none (404 x7velo_no_audit, incl. a discarded v1 record).
 *
 * @return {Promise<Object|null>} UI record.
 */
export async function getAudit() {
	try {
		return normalizeRecord( await call( { path: `${ NS }/audit` } ) );
	} catch ( error ) {
		if ( error.code === 'x7velo_no_audit' ) {
			return null;
		}
		throw error;
	}
}

/**
 * Runs the audit. Throws ApiError code x7velo_audit_running (409) if one is already running.
 *
 * @return {Promise<Object>} UI record.
 */
export async function runAudit() {
	return normalizeRecord(
		await call( { path: `${ NS }/audit`, method: 'POST' } )
	);
}

/**
 * Settings, or null when the endpoint doesn't exist on this install yet.
 *
 * @return {Promise<{values: Object, fixerStatus: Object}|null>} UI settings.
 */
export async function getSettings() {
	try {
		return normalizeSettings( await call( { path: `${ NS }/settings` } ) );
	} catch ( error ) {
		if ( error.code === 'rest_no_route' ) {
			return null;
		}
		throw error;
	}
}

/**
 * Partial update (top-level merge). Unknown keys are dropped client-side.
 *
 * @param {Object} changes Keys to change; null clears a numeric field.
 * @return {Promise<{values: Object, fixerStatus: Object}>} Full settings after the change.
 */
export async function patchSettings( changes ) {
	const data = {};
	Object.keys( changes ).forEach( ( key ) => {
		if ( SETTINGS_KEYS.includes( key ) ) {
			data[ key ] = changes[ key ];
		}
	} );
	return normalizeSettings(
		await call( { path: `${ NS }/settings`, method: 'PATCH', data } )
	);
}

/**
 * Published pages for the returns page picker (core REST).
 *
 * @param {string} search Search term.
 * @return {Promise<Array<{id: number, title: string, link: string}>>} Pages.
 */
export async function searchPages( search = '' ) {
	const pages = await call( {
		path: addQueryArgs( '/wp/v2/pages', {
			status: 'publish',
			per_page: 20,
			search: search || undefined,
			orderby: search ? 'relevance' : 'title',
			order: search ? undefined : 'asc',
			_fields: 'id,title,link',
		} ),
	} );
	return ( Array.isArray( pages ) ? pages : [] ).map( pageFromRest );
}

/**
 * One page by ID, or null if missing / not published.
 *
 * @param {number} id Page ID.
 * @return {Promise<{id: number, title: string, link: string}|null>} Page.
 */
export async function getPage( id ) {
	if ( ! id ) {
		return null;
	}
	try {
		const page = await call( {
			path: addQueryArgs( `/wp/v2/pages/${ Number( id ) }`, {
				_fields: 'id,title,link,status',
			} ),
		} );
		return page && ( ! page.status || page.status === 'publish' )
			? pageFromRest( page )
			: null;
	} catch {
		return null;
	}
}

function pageFromRest( page ) {
	const title = page?.title?.rendered ?? page?.title ?? '';
	return {
		id: Number( page.id ),
		title: decodeEntities( str( title ) ),
		link: str( page.link ),
	};
}

/**
 * Decodes HTML entities in REST `title.rendered` without parsing markup into the DOM.
 *
 * @param {string} text Text with entities.
 * @return {string} Plain text.
 */
export function decodeEntities( text ) {
	if ( ! text || ! text.includes( '&' ) ) {
		return text;
	}
	const textarea = document.createElement( 'textarea' );
	textarea.innerHTML = text;
	return textarea.value;
}
