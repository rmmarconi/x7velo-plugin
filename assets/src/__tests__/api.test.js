import apiFetch from '@wordpress/api-fetch';

import {
	getAudit,
	getSettings,
	normalizeJsonldParts,
	normalizeRecord,
	normalizeSettings,
	patchSettings,
	runAudit,
	toApiError,
} from '../api';
import { rawRecord, rawSettings } from './fixtures';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

describe( 'normalizeRecord', () => {
	it( 'keeps v2 state and drops the score unless complete', () => {
		const r = normalizeRecord(
			rawRecord( {}, { state: 'no_products', score: null } )
		);
		expect( r.state ).toBe( 'no_products' );
		expect( r.score ).toBeNull();
	} );
	it( 'derives state for records without it', () => {
		const raw = rawRecord( {}, { complete: false, score: null } );
		delete raw.state;
		expect( normalizeRecord( raw ).state ).toBe( 'incomplete' );
	} );
	it( 'reads examples from details.examples (server shape) and keeps message as a plain string', () => {
		const r = normalizeRecord(
			rawRecord( {
				'DATA-01': {
					status: 'warn',
					message: '<img src=x onerror=alert(1)>',
					details: {
						products: 10,
						missing: 3,
						examples: [
							{
								id: 7,
								title: 'Mug',
								edit_url: 'post.php?post=7&action=edit',
							},
							{ id: 'x', title: 'Not an ID' },
							null,
						],
					},
				},
			} )
		);
		const d = r.checks.find( ( c ) => c.id === 'DATA-01' );
		expect( d.examples ).toEqual( [
			{ id: 7, title: 'Mug', editUrl: 'post.php?post=7&action=edit' },
		] );
		expect( d.message ).toBe( '<img src=x onerror=alert(1)>' );
		expect( d.missing ).toBe( 3 );
	} );
	it( 'still accepts a top-level examples list', () => {
		const r = normalizeRecord(
			rawRecord( {
				'DATA-02': {
					status: 'warn',
					details: { products: 4, missing: 1 },
					examples: [ { id: 9, title: 'Cap', edit_url: 'e' } ],
				},
			} )
		);
		expect( r.checks.find( ( c ) => c.id === 'DATA-02' ).examples ).toEqual(
			[ { id: 9, title: 'Cap', editUrl: 'e' } ]
		);
	} );
} );

describe( 'normalizeSettings', () => {
	it( 'accepts fixer_status keyed by setting name and treats unknown states as absent', () => {
		const s = normalizeSettings( {
			...rawSettings(),
			fixer_status: {
				fix_llms_txt: { state: 'paused', managed_by: 'yoast' },
				feed: { state: 'bogus' },
			},
		} );
		expect( s.fixerStatus.llms_txt ).toMatchObject( {
			state: 'paused',
			managedBy: 'yoast',
		} );
		expect( s.fixerStatus.feed ).toBeNull();
		expect( s.fixerStatus.jsonld ).toBeNull();
	} );
	it( 'keeps jsonld parts as booleans (shipping → delivery)', () => {
		const s = normalizeSettings(
			rawSettings(
				{},
				{
					jsonld: {
						state: 'on',
						mode: 'augment',
						parts: { returns: 'included', shipping: 'not_yet' },
					},
				}
			)
		);
		expect( s.fixerStatus.jsonld.parts ).toEqual( {
			returns: true,
			delivery: false,
		} );
		// Older server: no parts reported.
		expect(
			normalizeSettings( rawSettings() ).fixerStatus.jsonld.parts
		).toBeNull();
	} );
	it( 'drops hostile or unknown jsonld part values', () => {
		expect(
			normalizeJsonldParts( {
				returns: '<img src=x onerror=alert(1)>',
				shipping: 'included',
				extra: 'included',
				delivery: 'included',
			} )
		).toEqual( { delivery: true } );
		expect(
			normalizeJsonldParts( { returns: 'toString', shipping: true } )
		).toBeNull();
		expect(
			normalizeJsonldParts( JSON.parse( '{"__proto__":"included"}' ) )
		).toBeNull();
		expect( normalizeJsonldParts( [ 'included' ] ) ).toBeNull();
		expect( normalizeJsonldParts( 'included' ) ).toBeNull();
		expect( normalizeJsonldParts( null ) ).toBeNull();
	} );
	it( 'keeps robots lines as server strings; anything else is empty', () => {
		const s = normalizeSettings(
			rawSettings(
				{},
				{
					robots: {
						state: 'on',
						lines: {
							allow: 'User-agent: OAI-SearchBot\nAllow: /',
							training: '',
							sitemap: { evil: true },
						},
					},
				}
			)
		);
		expect( s.fixerStatus.robots.lines ).toEqual( {
			allow: 'User-agent: OAI-SearchBot\nAllow: /',
			training: '',
			sitemap: '',
		} );
	} );
	it( 'keeps 0 as a valid delivery value and null as unset', () => {
		const s = normalizeSettings(
			rawSettings( {
				delivery_days_min: 0,
				delivery_days_max: 2,
				return_days: 0,
			} )
		);
		expect( s.values.delivery_days_min ).toBe( 0 );
		expect( s.values.delivery_days_max ).toBe( 2 );
		expect( s.values.return_days ).toBeNull();
	} );
} );

describe( 'REST calls', () => {
	beforeEach( () => apiFetch.mockReset() );

	it( 'getAudit returns null on 404 x7velo_no_audit', async () => {
		apiFetch.mockRejectedValue( {
			code: 'x7velo_no_audit',
			message: 'No audit',
			data: { status: 404 },
		} );
		await expect( getAudit() ).resolves.toBeNull();
	} );

	it( 'runAudit surfaces 409 as ApiError with the code', async () => {
		apiFetch.mockRejectedValue( {
			code: 'x7velo_audit_running',
			message: 'Running',
			data: { status: 409 },
		} );
		await expect( runAudit() ).rejects.toMatchObject( {
			code: 'x7velo_audit_running',
			status: 409,
		} );
	} );

	it( 'getSettings returns null when the route does not exist yet', async () => {
		apiFetch.mockRejectedValue( {
			code: 'rest_no_route',
			data: { status: 404 },
		} );
		await expect( getSettings() ).resolves.toBeNull();
	} );

	it( 'patchSettings sends PATCH with known keys only', async () => {
		apiFetch.mockResolvedValue( rawSettings( { fix_feed: true } ) );
		const result = await patchSettings( {
			fix_feed: true,
			bogus: 1,
			delivery_days_min: null,
		} );
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/x7velo/v1/settings',
			method: 'PATCH',
			data: { fix_feed: true, delivery_days_min: null },
		} );
		expect( result.values.fix_feed ).toBe( true );
	} );
} );

describe( 'toApiError', () => {
	it( 'collects rest_invalid_param field errors and detects an expired nonce', () => {
		const e = toApiError( {
			code: 'rest_invalid_param',
			message: 'Invalid parameter(s): return_days',
			data: { status: 400, params: { return_days: 'Must be 1–365.' } },
		} );
		expect( e.fieldErrors ).toEqual( { return_days: 'Must be 1–365.' } );
		expect( e.isSessionExpired ).toBe( false );
		expect(
			toApiError( {
				code: 'rest_cookie_invalid_nonce',
				data: { status: 403 },
			} ).isSessionExpired
		).toBe( true );
	} );
} );
