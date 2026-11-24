/**
 * "Last checked" uses the WordPress date settings (wp-date), not the browser locale.
 */
import { getSettings, setSettings } from '@wordpress/date';

import { formatDate } from '../components/Overview';

const original = getSettings();

afterEach( () => setSettings( original ) );

describe( 'formatDate', () => {
	it( 'uses the site date/time formats and timezone', () => {
		setSettings( {
			...original,
			formats: { ...original.formats, date: 'F j, Y', time: 'g:i a' },
			timezone: { offset: 0, string: 'UTC', abbr: 'UTC' },
		} );
		expect( formatDate( '2026-11-23T14:05:00+00:00' ) ).toBe(
			'November 23, 2026 2:05 pm'
		);
	} );

	it( 'follows a different site format and offset', () => {
		setSettings( {
			...original,
			formats: { ...original.formats, date: 'd/m/Y', time: 'H:i' },
			timezone: { offset: -3, string: '', abbr: '' },
		} );
		expect( formatDate( '2026-11-23T14:05:00+00:00' ) ).toBe(
			'23/11/2026 11:05'
		);
	} );

	it( 'handles missing and unparsable values', () => {
		expect( formatDate( '' ) ).toBe( '' );
		expect( formatDate( 'not a date' ) ).toBe( 'not a date' );
	} );
} );
