/**
 * JSON-LD parts resolution: server parts win per part; settings only fill gaps.
 */
import { jsonldParts } from '../components/FixesTab';

const READY = {
	returns_page_id: 5,
	return_policy: 'finite',
	return_days: 30,
	delivery_days_min: 2,
	delivery_days_max: 5,
};
const EMPTY = {
	returns_page_id: 0,
	return_policy: '',
	return_days: null,
	delivery_days_min: null,
	delivery_days_max: null,
};

describe( 'jsonldParts', () => {
	it( 'uses server parts over settings', () => {
		const p = jsonldParts(
			{ state: 'on', parts: { returns: false, delivery: false } },
			READY
		);
		expect( p.returns ).toBe( false );
		expect( p.delivery ).toBe( false );
		expect( p.fromServer ).toEqual( { returns: true, delivery: true } );
		expect(
			jsonldParts(
				{ state: 'on', parts: { returns: true, delivery: true } },
				EMPTY
			)
		).toMatchObject( { returns: true, delivery: true } );
	} );
	it( 'falls back to settings when parts are absent or partial', () => {
		expect(
			jsonldParts( { state: 'on', parts: null }, READY )
		).toMatchObject( {
			returns: true,
			delivery: true,
			fromServer: { returns: false, delivery: false },
		} );
		expect( jsonldParts( null, EMPTY ) ).toMatchObject( {
			returns: false,
			delivery: false,
		} );
		const partial = jsonldParts(
			{ state: 'on', parts: { returns: false } },
			READY
		);
		expect( partial.returns ).toBe( false );
		expect( partial.delivery ).toBe( true );
		expect( partial.fromServer ).toEqual( {
			returns: true,
			delivery: false,
		} );
	} );
} );
