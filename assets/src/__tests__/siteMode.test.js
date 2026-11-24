/**
 * Site mode (WooCommerce not active, spec §15, AC 117/121/125–127).
 */
import apiFetch from '@wordpress/api-fetch';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { normalizeRecord } from '../api';
import App from '../components/App';
import { supportDetails } from '../components/Overview';
import { getBands } from '../constants';
import { getCheckCopy } from '../copy/checks';
import { getDialog } from '../copy/dialogs';
import {
	BOOT,
	rawRecord,
	rawSettings,
	rawSiteRecord,
	rawSiteSettings,
	SITE_BOOT,
} from './fixtures';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( '@wordpress/a11y', () => ( { speak: jest.fn() } ) );

const { speak } = jest.requireMock( '@wordpress/a11y' );

function mockApi( routes ) {
	apiFetch.mockImplementation( ( { path, method = 'GET', data } ) => {
		const key = `${ method } ${ path.split( '?' )[ 0 ] }`;
		let entry = routes[ key ];
		if ( typeof entry === 'function' ) {
			entry = entry( data );
		}
		if ( entry === undefined ) {
			return Promise.reject( {
				code: 'rest_no_route',
				data: { status: 404 },
			} );
		}
		if ( entry && entry.__reject ) {
			return Promise.reject( entry.__reject );
		}
		return Promise.resolve( entry );
	} );
}

const reject = ( code, status, extra = {} ) => ( {
	__reject: { code, message: code, data: { status, ...extra } },
} );

const STORE_GROUP = 'Store checks (12) — for WooCommerce stores';
const NA_LINE = 'Doesn’t apply: this check is for WooCommerce stores.';

beforeEach( () => {
	window.x7veloAdmin = SITE_BOOT;
	window.history.replaceState( {}, '', '/wp-admin/tools.php?page=x7velo' );
	apiFetch.mockReset();
	speak.mockReset();
} );

describe( 'Site mode overview', () => {
	it( 'AC 125: score card, basis lines, collapsed store group, two categories', async () => {
		mockApi( {
			'GET /x7velo/v1/audit': rawSiteRecord(),
			'GET /x7velo/v1/settings': rawSiteSettings(),
		} );
		const { container } = render( <App /> );
		expect(
			await screen.findByText(
				'Your AI readiness score is 87 out of 100: Easy for AI assistants to read.'
			)
		).toBeInTheDocument();
		expect(
			screen.getByText(
				'AI assistants can read your site well. Check again after big changes to your site or plugins.'
			)
		).toBeInTheDocument();
		expect(
			container.querySelector( '.x7velo-score__basis' ).textContent
		).toBe(
			'Based on 6 checks that apply to your site. 12 store checks don’t apply because WooCommerce isn’t active on this site.'
		);
		expect( screen.queryByText( /Based on \d+ of \d+ checks/ ) ).toBeNull();

		// Breakdown: only Visibility basics and Crawler access.
		const cats = screen.getByRole( 'region', { name: 'By category' } );
		const names = within( cats )
			.getAllByRole( 'link' )
			.map( ( a ) => a.textContent );
		expect( names ).toEqual( [ 'Visibility basics', 'Crawler access' ] );

		// All checks: store checks not inside categories; one collapsed group at the bottom.
		const groups = container.querySelectorAll( '.x7velo-checks__group' );
		const last = groups[ groups.length - 1 ];
		expect( last ).toHaveClass( 'x7velo-checks__store' );
		expect(
			container.querySelector(
				'#x7velo-cat-visibility #x7velo-check-VIS-04'
			)
		).toBeNull();
		const toggle = screen.getByRole( 'button', { name: STORE_GROUP } );
		expect( toggle ).toHaveAttribute( 'aria-expanded', 'false' );
		expect( screen.queryByText( NA_LINE ) ).toBeNull();
		const user = userEvent.setup();
		await user.click( toggle );
		expect( screen.getAllByText( NA_LINE ) ).toHaveLength( 12 );
		expect( within( last ).getAllByText( 'Doesn’t apply' ).length ).toBe(
			12
		);

		// Top fixes: only the real failure, with site-mode titles.
		const top = screen.getByRole( 'region', { name: /Top/ } );
		expect(
			within( top ).getByText( 'Site summary for AI' )
		).toBeInTheDocument();
	} );

	it( 'AC 126: never shows "Add products first" in site mode', async () => {
		mockApi( {
			// Even a (wrong) no_products state must not show it without WooCommerce.
			'GET /x7velo/v1/audit': rawSiteRecord(
				{},
				{ state: 'no_products', score: null, band: null }
			),
			'GET /x7velo/v1/settings': rawSiteSettings(),
		} );
		render( <App /> );
		await screen.findByRole( 'heading', { name: 'All checks' } );
		expect( screen.queryByText( 'Add products first' ) ).toBeNull();
	} );

	it( 'AC 126: still shows "Add products first" with WooCommerce and no products', async () => {
		window.x7veloAdmin = BOOT;
		mockApi( {
			'GET /x7velo/v1/audit': rawRecord(
				{
					'DATA-01': {
						status: 'not_applicable',
						code: 'no_products',
					},
				},
				{ state: 'no_products', score: null, band: null }
			),
			'GET /x7velo/v1/settings': rawSettings(),
		} );
		render( <App /> );
		expect(
			await screen.findByRole( 'heading', { name: 'Add products first' } )
		).toBeInTheDocument();
	} );

	it( 'AC 127: nothing suggests installing WooCommerce or another plugin', async () => {
		mockApi( {
			'GET /x7velo/v1/audit': rawSiteRecord(),
			'GET /x7velo/v1/settings': rawSiteSettings(),
		} );
		const user = userEvent.setup();
		const { container } = render( <App /> );
		await screen.findByText( /Your AI readiness score is 87/ );
		await user.click( screen.getByRole( 'button', { name: STORE_GROUP } ) );
		const overview = container.textContent;
		await user.click( screen.getByRole( 'tab', { name: 'Fixes' } ) );
		await screen.findByText( 'Site summary for AI (llms.txt)' );
		const text = overview + container.textContent;

		expect( text ).not.toMatch( /install/i );
		expect( text ).not.toMatch( /unlock|upgrade/i );
		const allowed = [
			STORE_GROUP,
			NA_LINE,
			'12 store checks don’t apply because WooCommerce isn’t active on this site.',
		];
		let rest = text;
		allowed.forEach( ( s ) => {
			rest = rest.split( s ).join( '' );
		} );
		expect( rest ).not.toMatch( /WooCommerce/ );
	} );

	it( 'uses site wording while running and in support details', async () => {
		mockApi( {
			'GET /x7velo/v1/audit': reject( 'x7velo_no_audit', 404 ),
			'POST /x7velo/v1/audit': () => new Promise( () => {} ),
			'GET /x7velo/v1/settings': rawSiteSettings(),
		} );
		render( <App /> );
		expect(
			await screen.findByText( 'Checking your site…' )
		).toBeInTheDocument();
		expect( speak ).toHaveBeenCalledWith( 'Checking your site…' );
		expect(
			screen.getByText(
				'This check runs on your own site. Nothing is sent anywhere, and nothing on your site is changed.'
			)
		).toBeInTheDocument();
		expect(
			supportDetails( normalizeRecord( rawSiteRecord() ), SITE_BOOT )
		).toContain( 'WooCommerce not active' );
	} );
} );

describe( 'Site mode tabs and fixes (AC 117)', () => {
	it( 'has no Settings tab, and ?tab=settings opens Overview', async () => {
		window.history.replaceState(
			{},
			'',
			'/wp-admin/tools.php?page=x7velo&tab=settings'
		);
		mockApi( {
			'GET /x7velo/v1/audit': rawSiteRecord(),
			'GET /x7velo/v1/settings': rawSiteSettings(),
		} );
		render( <App /> );
		const overview = await screen.findByRole( 'tab', {
			name: 'Overview',
		} );
		expect( overview ).toHaveAttribute( 'aria-selected', 'true' );
		expect( screen.getByRole( 'tab', { name: 'Fixes' } ) ).toBeVisible();
		expect( screen.queryByRole( 'tab', { name: 'Settings' } ) ).toBeNull();
	} );

	it( 'shows only the llms.txt and crawler cards with site wording', async () => {
		mockApi( {
			'GET /x7velo/v1/audit': rawSiteRecord(),
			'GET /x7velo/v1/settings': rawSiteSettings(),
		} );
		const user = userEvent.setup();
		const { container } = render( <App /> );
		await user.click( await screen.findByRole( 'tab', { name: 'Fixes' } ) );
		await screen.findByText( 'Site summary for AI (llms.txt)' );
		const cards = [
			...container.querySelectorAll( '.x7velo-fixes .x7velo-fixer h2' ),
		].map( ( el ) => el.id );
		expect( cards ).toEqual( [
			'x7velo-fixer-llms',
			'x7velo-fixer-robots',
		] );
		expect(
			screen.getByRole( 'checkbox', {
				name: /Publish a site summary at example\.com\/llms\.txt/,
			} )
		).toBeEnabled();
		expect(
			screen.getByRole( 'checkbox', {
				name: 'Let AI search assistants in',
			} )
		).toBeEnabled();
		expect( screen.queryByText( /product feed/i ) ).toBeNull();
		expect( screen.queryByText( /product details/i ) ).toBeNull();
	} );

	it( 'confirm dialog for llms.txt uses site wording', async () => {
		mockApi( {
			'GET /x7velo/v1/audit': rawSiteRecord(),
			'GET /x7velo/v1/settings': rawSiteSettings(),
		} );
		const user = userEvent.setup();
		render( <App /> );
		await user.click( await screen.findByRole( 'tab', { name: 'Fixes' } ) );
		await user.click(
			await screen.findByRole( 'checkbox', {
				name: /Publish a site summary/,
			} )
		);
		expect(
			await screen.findByRole( 'dialog', {
				name: 'Publish a site summary?',
			} )
		).toBeInTheDocument();
		expect(
			screen.getByText( /lists your main pages and recent posts/ )
		).toBeInTheDocument();
	} );
} );

describe( 'Auto re-run when WooCommerce changed (AC 121)', () => {
	it( 're-runs when the stored record is from a store and WooCommerce is now off', async () => {
		const posted = jest.fn( () => rawSiteRecord() );
		mockApi( {
			'GET /x7velo/v1/audit': rawRecord(
				{},
				{ woocommerce_active: true }
			),
			'POST /x7velo/v1/audit': posted,
			'GET /x7velo/v1/settings': rawSiteSettings(),
		} );
		render( <App /> );
		await waitFor( () => expect( posted ).toHaveBeenCalledTimes( 1 ) );
		expect(
			await screen.findByText( /Your AI readiness score is 87/ )
		).toBeInTheDocument();
		expect( speak ).toHaveBeenCalledWith( 'Checking your site…' );
	} );

	it( 're-runs when the stored record is from a site and WooCommerce is now on', async () => {
		window.x7veloAdmin = BOOT;
		const posted = jest.fn( () =>
			rawRecord( {}, { woocommerce_active: true } )
		);
		mockApi( {
			'GET /x7velo/v1/audit': rawSiteRecord(),
			'POST /x7velo/v1/audit': posted,
			'GET /x7velo/v1/settings': rawSettings(),
		} );
		render( <App /> );
		await waitFor( () => expect( posted ).toHaveBeenCalledTimes( 1 ) );
		expect(
			await screen.findByText( /Your AI readiness score is 100/ )
		).toBeInTheDocument();
	} );

	it( 'does not re-run when the mode matches (records without the key count as stores)', async () => {
		const posted = jest.fn( () => rawSiteRecord() );
		mockApi( {
			'GET /x7velo/v1/audit': rawSiteRecord(),
			'POST /x7velo/v1/audit': posted,
			'GET /x7velo/v1/settings': rawSiteSettings(),
		} );
		const { unmount } = render( <App /> );
		await screen.findByText( /Your AI readiness score is 87/ );
		unmount();

		window.x7veloAdmin = BOOT;
		const legacy = rawRecord();
		delete legacy.woocommerce_active;
		mockApi( {
			'GET /x7velo/v1/audit': legacy,
			'POST /x7velo/v1/audit': posted,
			'GET /x7velo/v1/settings': rawSettings(),
		} );
		render( <App /> );
		await screen.findByText( /Your AI readiness score is 100/ );
		expect( posted ).not.toHaveBeenCalled();
	} );
} );

describe( 'Site-mode copy (AC 111 / §15.6)', () => {
	const STORE_WORDS =
		/\b(store|stores|shop|shops|shopping|product|products)\b/i;

	it( 'has no store wording for the six site checks, bands or crawl/llms dialogs', () => {
		const copy = getCheckCopy( {}, true );
		[
			'VIS-01',
			'VIS-02',
			'VIS-03',
			'CRAWL-01',
			'CRAWL-02',
			'CRAWL-03',
		].forEach( ( id ) =>
			expect( JSON.stringify( copy[ id ] ) ).not.toMatch( STORE_WORDS )
		);
		expect( JSON.stringify( getBands( true ) ) ).not.toMatch( STORE_WORDS );
		const ctx = { values: {}, fixerStatus: {}, boot: SITE_BOOT };
		[ 'robots', 'llms_txt' ].forEach( ( action ) => {
			[ true, false ].forEach( ( on ) =>
				expect(
					JSON.stringify( getDialog( action, on, ctx ) )
				).not.toMatch( STORE_WORDS )
			);
		} );
	} );

	it( 'keeps store copy unchanged in store mode', () => {
		expect( getCheckCopy( {} )[ 'CRAWL-03' ].title ).toBe(
			'Store summary for AI'
		);
		expect( getBands().high.text ).toBe(
			'AI assistants can read your store well. Keep product details complete as you add products.'
		);
	} );
} );
