/**
 * Contract with the real REST response (D-1).
 *
 * `contract/audit-response.json` is written by the PHPUnit test
 * tests/Rest/AuditContractTest.php from a live POST /x7velo/v1/audit, and that test fails when
 * the server stops sending any path this fixture has. Don't edit the fixture by hand.
 */
import { render, screen, within } from '@testing-library/react';

import { normalizeRecord } from '../api';
import { CheckDetail } from '../components/CheckList';
import { getCheckCopy } from '../copy/checks';
import { BOOT } from './fixtures';
import response from './contract/audit-response.json';

const handlers = {
	onRun: jest.fn(),
	onFix: jest.fn(),
	onHow: jest.fn(),
	onTurnOff: jest.fn(),
	onSettings: jest.fn(),
};

describe( 'REST contract: GET/POST /audit', () => {
	it( 'the fixture carries examples under details, as the server sends them', () => {
		const raw = response.checks.find( ( c ) => c.id === 'DATA-02' );
		expect( raw.examples ).toBeUndefined();
		expect( raw.details.examples.length ).toBeGreaterThan( 0 );
	} );

	it( 'normalises product examples with edit links from the real server shape', () => {
		const record = normalizeRecord( response );
		const image = record.checks.find( ( c ) => c.id === 'DATA-02' );
		expect( image.examples.length ).toBeGreaterThan( 0 );
		image.examples.forEach( ( ex ) => {
			expect( Number.isInteger( ex.id ) ).toBe( true );
			expect( ex.title ).not.toBe( '' );
			expect( ex.editUrl ).toMatch( /post\.php\?post=\d+&action=edit/ );
		} );
	} );

	it( 'renders the examples with Edit links in the expanded check', () => {
		const record = normalizeRecord( response );
		const image = record.checks.find( ( c ) => c.id === 'DATA-02' );
		const copy = getCheckCopy( BOOT.coreLabels )[ 'DATA-02' ];
		render(
			<CheckDetail
				check={ image }
				copy={ copy }
				fixerStatus={ {} }
				boot={ BOOT }
				handlers={ handlers }
				settingsValues={ {} }
				scored
			/>
		);
		expect(
			screen.getByText(
				`Examples (${ image.examples.length } of ${ image.missing })`
			)
		).toBeInTheDocument();
		const list = document.querySelector( '.x7velo-examples' );
		const items = within( list ).getAllByRole( 'listitem' );
		expect( items ).toHaveLength( image.examples.length );
		const link = within( items[ 0 ] ).getByRole( 'link', {
			name: `Edit ${ image.examples[ 0 ].title }`,
		} );
		expect( link ).toHaveAttribute( 'href', image.examples[ 0 ].editUrl );
	} );
} );
