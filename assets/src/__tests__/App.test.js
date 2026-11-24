/**
 * Integration tests for the dashboard with a mocked REST API.
 */
import apiFetch from '@wordpress/api-fetch';
import { act, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import App from '../components/App';
import { BOOT, rawRecord, rawSettings } from './fixtures';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( '@wordpress/a11y', () => ( { speak: jest.fn() } ) );

const { speak } = jest.requireMock( '@wordpress/a11y' );

/**
 * Mocks apiFetch from a route table.
 *
 * @param {Object} routes { 'GET /x7velo/v1/audit': value | reject(...) | fn(data) }.
 */
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

beforeEach( () => {
	window.x7veloAdmin = BOOT;
	window.history.replaceState( {}, '', '/wp-admin/admin.php?page=x7velo' );
	apiFetch.mockReset();
	speak.mockReset();
} );

describe( 'Overview', () => {
	it( 'runs the audit automatically on first visit and announces the score', async () => {
		const posted = jest.fn( () =>
			rawRecord(
				{ 'DATA-01': { status: 'fail' } },
				{ score: 92, band: 'high' }
			)
		);
		mockApi( {
			'GET /x7velo/v1/audit': reject( 'x7velo_no_audit', 404 ),
			'POST /x7velo/v1/audit': posted,
			'GET /x7velo/v1/settings': rawSettings(),
		} );
		render( <App /> );
		await waitFor( () =>
			expect( speak ).toHaveBeenCalledWith( 'Checking your store…' )
		);
		expect(
			await screen.findByText(
				'Your AI readiness score is 92 out of 100: Easy for AI assistants to read.'
			)
		).toBeInTheDocument();
		expect( posted ).toHaveBeenCalledTimes( 1 );
		expect( speak ).toHaveBeenCalledWith(
			'Audit finished. Your score is 92 out of 100: Easy for AI assistants to read. 1 thing to fix.'
		);
		// Top fixes shows the exact +N.
		const top = screen.getByRole( 'region', { name: 'Top fixes' } );
		expect(
			within( top ).getByText( 'Up to +8 points' )
		).toBeInTheDocument();
	} );

	it( 'renders server messages as text, never HTML', async () => {
		mockApi( {
			'GET /x7velo/v1/audit': rawRecord(
				{
					'DATA-01': {
						status: 'warn',
						message: '<b id="injected">bold</b>',
					},
				},
				{ score: 96 }
			),
			'GET /x7velo/v1/settings': rawSettings(),
		} );
		const { container } = render( <App /> );
		expect(
			( await screen.findAllByText( '<b id="injected">bold</b>' ) ).length
		).toBeGreaterThan( 0 );
		expect( container.querySelector( '#injected' ) ).toBeNull();
	} );

	it( 'shows "Add products first" instead of a score when there are no products', async () => {
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
		expect( screen.queryByText( /Your AI readiness score/ ) ).toBeNull();
		expect(
			screen.getByRole( 'link', { name: 'Add a product' } )
		).toHaveAttribute( 'href', BOOT.urls.newProduct );
	} );

	it( 'shows the incomplete state with support details and no score', async () => {
		const errors = {};
		[ 'DATA-01', 'DATA-02', 'DATA-03', 'DATA-04' ].forEach(
			( id ) =>
				( errors[ id ] = { status: 'error', code: 'check_error' } )
		);
		mockApi( {
			'GET /x7velo/v1/audit': rawRecord( errors, {
				state: 'incomplete',
				complete: false,
				score: null,
			} ),
			'GET /x7velo/v1/settings': rawSettings(),
		} );
		render( <App /> );
		expect(
			await screen.findByRole( 'heading', {
				name: 'We couldn’t finish checking your store',
			} )
		).toBeInTheDocument();
		expect(
			screen.getByText( /4 of 18 checks stopped with an error/ )
		).toBeInTheDocument();
		expect( screen.queryByRole( 'region', { name: /Top/ } ) ).toBeNull();
	} );

	it( 'waits for another running audit on 409 and shows its result', async () => {
		jest.useFakeTimers();
		let calls = 0;
		mockApi( {
			'GET /x7velo/v1/audit': () => {
				calls++;
				return calls === 1
					? rawRecord(
							{ 'DATA-01': { status: 'fail' } },
							{ score: 92 }
						)
					: rawRecord(
							{},
							{
								score: 100,
								generated_at: '2026-11-22T15:00:00+00:00',
							}
						);
			},
			'POST /x7velo/v1/audit': reject( 'x7velo_audit_running', 409 ),
			'GET /x7velo/v1/settings': rawSettings(),
		} );
		const user = userEvent.setup( {
			advanceTimers: jest.advanceTimersByTime,
		} );
		render( <App /> );
		await screen.findByText( /Your AI readiness score is 92/ );
		await user.click(
			screen.getByRole( 'button', { name: 'Run audit again' } )
		);
		expect(
			await screen.findByText( /An audit is already running/ )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Run audit again' } )
		).toHaveAttribute( 'aria-disabled', 'true' );
		await act( async () => {
			jest.advanceTimersByTime( 5000 );
		} );
		expect(
			await screen.findByText( /Your AI readiness score is 100/ )
		).toBeInTheDocument();
		expect(
			screen.queryByText( /An audit is already running/ )
		).toBeNull();
		jest.useRealTimers();
	} );

	it( 'shows the critical notice and a crawl-access Fix it dialog with Cancel focused', async () => {
		const patched = jest.fn( () =>
			rawSettings(
				{ fix_robots_search: true },
				{ robots: { state: 'on' } }
			)
		);
		mockApi( {
			'GET /x7velo/v1/audit': rawRecord(
				{
					'CRAWL-01': {
						status: 'fail',
						critical: true,
						code: 'all_blocked',
					},
				},
				{ score: 20, capped: true, band: 'low' }
			),
			'POST /x7velo/v1/audit': rawRecord( {}, { score: 100 } ),
			'GET /x7velo/v1/settings': rawSettings(),
			'PATCH /x7velo/v1/settings': patched,
		} );
		const user = userEvent.setup();
		render( <App /> );
		expect(
			await screen.findByText(
				'Critical: your crawler rules block all AI shopping assistants'
			)
		).toBeInTheDocument();
		// Wait for settings so Fix it is offered.
		const fix = await screen.findByRole( 'button', {
			name: 'Fix it: AI shopping assistants can visit',
		} );
		await user.click( fix );
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Let AI shopping assistants read your store?',
		} );
		expect(
			within( dialog ).getByRole( 'button', { name: 'Cancel' } )
		).toHaveFocus();
		await user.click(
			within( dialog ).getByRole( 'button', {
				name: 'Allow shopping assistants',
			} )
		);
		await waitFor( () =>
			expect( patched ).toHaveBeenCalledWith( {
				fix_robots_search: true,
			} )
		);
		await screen.findByText( /Your AI readiness score is 100/ );
		expect( speak ).toHaveBeenCalledWith(
			'Crawler rules for AI shopping assistants turned on. Score went from 20 to 100.'
		);
	} );

	it( 'Cancel in the dialog changes nothing', async () => {
		mockApi( {
			'GET /x7velo/v1/audit': rawRecord(
				{ 'CRAWL-04': { status: 'fail' } },
				{ score: 95 }
			),
			'GET /x7velo/v1/settings': rawSettings(),
			'PATCH /x7velo/v1/settings': () => {
				throw new Error( 'must not be called' );
			},
		} );
		const user = userEvent.setup();
		render( <App /> );
		await user.click(
			await screen.findByRole( 'button', {
				name: 'Fix it: Product list for shopping assistants',
			} )
		);
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Publish a product feed?',
		} );
		// Not a crawl-access change: the primary action is focused.
		expect(
			within( dialog ).getByRole( 'button', { name: 'Publish feed' } )
		).toHaveFocus();
		await user.click(
			within( dialog ).getByRole( 'button', { name: 'Cancel' } )
		);
		expect( screen.queryByRole( 'dialog' ) ).toBeNull();
		expect(
			apiFetch.mock.calls.some( ( [ o ] ) => o.method === 'PATCH' )
		).toBe( false );
	} );

	it( 'shows the session-expired notice on an invalid nonce', async () => {
		mockApi( {
			'GET /x7velo/v1/audit': reject( 'rest_cookie_invalid_nonce', 403 ),
			'GET /x7velo/v1/settings': rawSettings(),
		} );
		render( <App /> );
		expect(
			await screen.findByText(
				'Your login session expired. Reload the page and try again.'
			)
		).toBeInTheDocument();
	} );

	it( 'shows the request error notice when the site cannot be reached', async () => {
		mockApi( {
			'GET /x7velo/v1/audit': {
				__reject: { code: 'fetch_error', message: 'Failed' },
			},
			'GET /x7velo/v1/settings': rawSettings(),
		} );
		render( <App /> );
		expect(
			await screen.findByText(
				/The audit didn’t finish because the page lost contact/
			)
		).toBeInTheDocument();
	} );
} );

describe( 'Fixes tab', () => {
	const goFixes = async ( user ) => {
		await user.click( await screen.findByRole( 'tab', { name: 'Fixes' } ) );
	};

	it( 'renders managed, paused and missing fixers without fake switches', async () => {
		mockApi( {
			'GET /x7velo/v1/audit': rawRecord(),
			'GET /x7velo/v1/settings': {
				...rawSettings( { fix_jsonld: true } ),
				fixer_status: {
					jsonld: { state: 'paused', managed_by: 'rankmath' },
					llms_txt: { state: 'managed', managed_by: 'yoast' },
					robots: { state: 'off' },
				},
			},
		} );
		const user = userEvent.setup();
		render( <App /> );
		await goFixes( user );
		expect(
			await screen.findByText(
				/Paused\. Rank Math adds product details now/
			)
		).toBeInTheDocument();
		const llms = screen.getByRole( 'checkbox', {
			name: /Publish a store summary/,
		} );
		expect( llms ).toBeDisabled();
		expect(
			screen.getByText( /Managed by Yoast SEO\./ )
		).toBeInTheDocument();
		// Feed is absent from fixer_status → unavailable, disabled.
		expect(
			screen.getByRole( 'checkbox', { name: 'Publish a product feed' } )
		).toBeDisabled();
		// Paused stays on and can be turned off.
		expect(
			screen.getByRole( 'checkbox', {
				name: /Add returns policy and delivery times/,
			} )
		).toBeEnabled();
	} );

	it( 'renders one EXPERIMENTAL checkbox per experimental crawler, off by default', async () => {
		const patched = jest.fn( () =>
			rawSettings( { block_experimental: [ 'Bytespider' ] } )
		);
		mockApi( {
			'GET /x7velo/v1/audit': rawRecord(),
			'GET /x7velo/v1/settings': rawSettings(),
			'PATCH /x7velo/v1/settings': patched,
		} );
		const user = userEvent.setup();
		render( <App /> );
		await goFixes( user );
		const box = await screen.findByRole( 'checkbox', {
			name: /Also block Bytespider \(ByteDance\) EXPERIMENTAL/,
		} );
		expect( box ).not.toBeChecked();
		await user.click( box );
		const dialog = await screen.findByRole( 'dialog' );
		expect(
			within( dialog ).getByRole( 'button', { name: 'Cancel' } )
		).toHaveFocus();
		await user.click(
			within( dialog ).getByRole( 'button', { name: 'Block Bytespider' } )
		);
		await waitFor( () =>
			expect( patched ).toHaveBeenCalledWith( {
				block_experimental: [ 'Bytespider' ],
			} )
		);
	} );

	it( 'reverts and explains on 409 x7velo_fixer_unavailable', async () => {
		let settingsCalls = 0;
		mockApi( {
			'GET /x7velo/v1/audit': rawRecord(),
			'GET /x7velo/v1/settings': () => {
				settingsCalls++;
				return settingsCalls === 1
					? rawSettings()
					: rawSettings(
							{},
							{
								llms_txt: {
									state: 'managed',
									managed_by: 'yoast',
								},
							}
						);
			},
			'PATCH /x7velo/v1/settings': reject(
				'x7velo_fixer_unavailable',
				409
			),
		} );
		const user = userEvent.setup();
		render( <App /> );
		await goFixes( user );
		await user.click(
			await screen.findByRole( 'checkbox', {
				name: /Publish a store summary/,
			} )
		);
		await user.click(
			within( await screen.findByRole( 'dialog' ) ).getByRole( 'button', {
				name: 'Publish summary',
			} )
		);
		expect(
			await screen.findByText(
				'x7velo can’t turn this on right now: Managed by Yoast SEO.'
			)
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'checkbox', { name: /Publish a store summary/ } )
		).not.toBeChecked();
	} );

	const READY = {
		fix_jsonld: true,
		returns_page_id: 5,
		return_policy: 'finite',
		return_days: 30,
		delivery_days_min: 2,
		delivery_days_max: 5,
	};

	it( 'renders JSON-LD parts from server parts, not from settings', async () => {
		mockApi( {
			'GET /x7velo/v1/audit': rawRecord(),
			'GET /x7velo/v1/settings': rawSettings( READY, {
				jsonld: {
					state: 'on',
					mode: 'augment',
					parts: { returns: 'included', shipping: 'not_yet' },
				},
			} ),
		} );
		const user = userEvent.setup();
		render( <App /> );
		await goFixes( user );
		expect(
			await screen.findByText( 'Returns policy: included (30 days).' )
		).toBeInTheDocument();
		// Days are set, but the server says shipping isn't included (e.g. rate not a plain number).
		expect(
			screen.getByText(
				/Delivery times: not included yet\. They’re added only for products with free or flat-rate shipping in Brazil\./
			)
		).toBeInTheDocument();
		expect(
			screen.queryByText( /Delivery times: included/ )
		).not.toBeInTheDocument();
	} );

	it( 'shows a part as not yet when the server says so even if settings look complete', async () => {
		mockApi( {
			'GET /x7velo/v1/audit': rawRecord(),
			'GET /x7velo/v1/settings': rawSettings( READY, {
				jsonld: {
					state: 'on',
					mode: 'augment',
					parts: { returns: 'not_yet', shipping: 'included' },
				},
			} ),
		} );
		const user = userEvent.setup();
		render( <App /> );
		await goFixes( user );
		expect(
			await screen.findByText(
				/Returns policy: not included yet\. Check that your returns page is published/
			)
		).toBeInTheDocument();
		expect(
			screen.getByText(
				'Delivery times: included for products with free or flat-rate shipping in Brazil.'
			)
		).toBeInTheDocument();
	} );

	it( 'falls back to settings when the server does not report parts', async () => {
		mockApi( {
			'GET /x7velo/v1/audit': rawRecord(),
			'GET /x7velo/v1/settings': rawSettings(
				{
					...READY,
					delivery_days_min: null,
					delivery_days_max: null,
				},
				{ jsonld: { state: 'on', mode: 'augment' } }
			),
		} );
		const user = userEvent.setup();
		render( <App /> );
		await goFixes( user );
		expect(
			await screen.findByText( 'Returns policy: included (30 days).' )
		).toBeInTheDocument();
		expect(
			screen.getByText(
				/Delivery times: not included yet\. Add your delivery days in Settings/
			)
		).toBeInTheDocument();
	} );

	it( 'refetches settings after saving delivery days so parts update', async () => {
		const notYet = { returns: 'not_yet', shipping: 'not_yet' };
		let gets = 0;
		const saved = {
			...READY,
			returns_page_id: 0,
			return_policy: '',
			return_days: null,
		};
		mockApi( {
			'GET /x7velo/v1/audit': rawRecord(),
			'GET /x7velo/v1/settings': () => {
				gets++;
				return gets === 1
					? rawSettings(
							{ fix_jsonld: true },
							{
								jsonld: {
									state: 'on',
									mode: 'augment',
									parts: notYet,
								},
							}
						)
					: rawSettings( saved, {
							jsonld: {
								state: 'on',
								mode: 'augment',
								parts: {
									returns: 'not_yet',
									shipping: 'included',
								},
							},
						} );
			},
			// PATCH answers with the parts it had before the change was stored.
			'PATCH /x7velo/v1/settings': () =>
				rawSettings( saved, {
					jsonld: { state: 'on', mode: 'augment', parts: notYet },
				} ),
			'GET /wp/v2/pages': [],
		} );
		const user = userEvent.setup();
		render( <App /> );
		await user.click(
			await screen.findByRole( 'tab', { name: 'Settings' } )
		);
		await user.type(
			await screen.findByLabelText( 'Fastest delivery, in days' ),
			'2'
		);
		await user.type(
			screen.getByLabelText( 'Slowest delivery, in days' ),
			'5'
		);
		await user.click(
			screen.getByRole( 'button', { name: 'Save settings' } )
		);
		await waitFor( () => expect( gets ).toBe( 2 ) );
		await goFixes( user );
		expect(
			await screen.findByText(
				'Delivery times: included for products with free or flat-rate shipping in Brazil.'
			)
		).toBeInTheDocument();
	} );

	it( 'says fixes are unavailable when the settings endpoint does not exist', async () => {
		mockApi( { 'GET /x7velo/v1/audit': rawRecord() } );
		const user = userEvent.setup();
		render( <App /> );
		await goFixes( user );
		expect(
			await screen.findByText(
				'Fixes aren’t available in this version of x7velo yet.'
			)
		).toBeInTheDocument();
	} );
} );

describe( 'Settings tab', () => {
	it( 'validates client-side and shows server 400 field errors', async () => {
		const patched = jest.fn( () =>
			reject( 'rest_invalid_param', 400, {
				params: { delivery_days_max: 'Server says no.' },
			} )
		);
		mockApi( {
			'GET /x7velo/v1/audit': rawRecord(),
			'GET /x7velo/v1/settings': rawSettings(),
			'PATCH /x7velo/v1/settings': patched,
			'GET /wp/v2/pages': [],
		} );
		const user = userEvent.setup();
		render( <App /> );
		await user.click(
			await screen.findByRole( 'tab', { name: 'Settings' } )
		);
		const min = await screen.findByLabelText( 'Fastest delivery, in days' );
		await user.type( min, '3' );
		await user.click(
			screen.getByRole( 'button', { name: 'Save settings' } )
		);
		const max = screen.getByLabelText( 'Slowest delivery, in days' );
		expect(
			await screen.findByText(
				'Fill in both numbers, or leave both empty.'
			)
		).toBeInTheDocument();
		expect( max ).toHaveAttribute( 'aria-invalid', 'true' );
		await waitFor( () => expect( max ).toHaveFocus() );
		expect( patched ).not.toHaveBeenCalled();

		await user.type( max, '5' );
		await user.click(
			screen.getByRole( 'button', { name: 'Save settings' } )
		);
		await waitFor( () =>
			expect( patched ).toHaveBeenCalledWith(
				expect.objectContaining( {
					delivery_days_min: 3,
					delivery_days_max: 5,
					return_days: null,
				} )
			)
		);
		expect(
			await screen.findByText( 'Server says no.' )
		).toBeInTheDocument();
		expect(
			screen.getByText(
				'Settings weren’t saved. Check the highlighted fields and try again.'
			)
		).toBeInTheDocument();
	} );
} );
