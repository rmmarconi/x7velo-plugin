import { normalizeRecord } from '../api';
import { CATEGORY_ORDER } from '../constants';
import {
	actionsFor,
	computeScore,
	groupChecks,
	pointsIfPassed,
	topFixes,
} from '../score';
import { rawRecord } from './fixtures';

const checks = ( overrides ) =>
	normalizeRecord( rawRecord( overrides ) ).checks;
const OFF = {
	jsonld: { state: 'off' },
	llms_txt: { state: 'off' },
	feed: { state: 'off' },
	robots: { state: 'off' },
};

describe( 'computeScore mirrors Scorer.php (spec AC 1–9)', () => {
	it( 'all pass → 100', () => {
		expect( computeScore( checks() ) ).toBe( 100 );
	} );
	it( 'DATA-01 fail → 92', () => {
		expect(
			computeScore( checks( { 'DATA-01': { status: 'fail' } } ) )
		).toBe( 92 );
	} );
	it( 'DATA-01 warn → 96', () => {
		expect(
			computeScore( checks( { 'DATA-01': { status: 'warn' } } ) )
		).toBe( 96 );
	} );
	it( 'STORE-03 n/a → 100; plus DATA-01 fail → round(100×89/97) = 92', () => {
		expect(
			computeScore(
				checks( { 'STORE-03': { status: 'not_applicable' } } )
			)
		).toBe( 100 );
		expect(
			computeScore(
				checks( {
					'STORE-03': { status: 'not_applicable' },
					'DATA-01': { status: 'fail' },
				} )
			)
		).toBe( 92 );
	} );
	it( 'critical fail caps at 20, caps do not stack', () => {
		expect(
			computeScore(
				checks( { 'VIS-01': { status: 'fail', critical: true } } )
			)
		).toBe( 20 );
		expect(
			computeScore(
				checks( {
					'VIS-01': { status: 'fail', critical: true },
					'VIS-04': { status: 'fail', critical: true },
				} )
			)
		).toBe( 20 );
	} );
	it( 'more than 3 errors → null', () => {
		const four = {};
		[ 'DATA-01', 'DATA-02', 'DATA-03', 'DATA-04' ].forEach(
			( id ) => ( four[ id ] = { status: 'error' } )
		);
		expect( computeScore( checks( four ) ) ).toBeNull();
	} );
} );

describe( 'pointsIfPassed', () => {
	it( 'is the exact score difference', () => {
		const c = checks( { 'DATA-01': { status: 'fail' } } );
		expect( pointsIfPassed( c, [ 'DATA-01' ] ) ).toBe( 8 );
	} );
	it( 'is 0 for a non-critical fix while another cap stays in force', () => {
		const c = checks( {
			'VIS-01': { status: 'fail', critical: true },
			'DATA-01': { status: 'fail' },
		} );
		expect( pointsIfPassed( c, [ 'DATA-01' ] ) ).toBe( 0 );
		expect( pointsIfPassed( c, [ 'VIS-01' ] ) ).toBe( 92 - 20 );
	} );
} );

describe( 'topFixes ordering (design §3.2)', () => {
	const issues = {
		'DATA-02': { status: 'fail' }, // 6 points, manual.
		'CRAWL-03': { status: 'fail' }, // 5 points, Fix it.
		'CRAWL-04': { status: 'fail' }, // 5 points, Fix it, later in spec order.
		'STORE-04': { status: 'warn' }, // 2 points (1.5 → rounding), guided.
	};

	it( 'orders by points, then fix type, then spec order', () => {
		const items = topFixes( checks( issues ), OFF );
		expect( items.map( ( i ) => i.check.id ) ).toEqual( [
			'DATA-02',
			'CRAWL-03',
			'CRAWL-04',
		] );
		expect( items.map( ( i ) => i.points ) ).toEqual( [ 6, 5, 5 ] );
	} );

	it( 'puts critical first; under a cap other fixes are worth 0 and quick wins lead', () => {
		const items = topFixes(
			checks( {
				...issues,
				'VIS-04': { status: 'fail', critical: true },
			} ),
			OFF
		);
		expect( items.map( ( i ) => i.check.id ) ).toEqual( [
			'VIS-04',
			'CRAWL-03',
			'CRAWL-04',
		] );
		expect( items[ 1 ].points ).toBe( 0 );
	} );

	it( 'merges CRAWL-01 blocked_by_search_visibility into VIS-01 with combined points', () => {
		const c = checks( {
			'VIS-01': { status: 'fail', critical: true },
			'CRAWL-01': {
				status: 'fail',
				critical: true,
				code: 'blocked_by_search_visibility',
			},
		} );
		const items = topFixes( c, OFF );
		expect( items.map( ( i ) => i.check.id ) ).toEqual( [ 'VIS-01' ] );
		expect( items[ 0 ].points ).toBe( 80 );
	} );

	it( 'returns nothing when everything passes', () => {
		expect( topFixes( checks(), OFF ) ).toEqual( [] );
	} );
} );

describe( 'actionsFor', () => {
	it( 'offers Fix it only when the fixer is off', () => {
		const [ crawl04 ] = checks( { 'CRAWL-04': { status: 'fail' } } ).filter(
			( x ) => x.id === 'CRAWL-04'
		);
		expect( actionsFor( crawl04, OFF ).primary ).toBe( 'fix' );
		expect(
			actionsFor( crawl04, { ...OFF, feed: { state: 'managed' } } )
				.primary
		).toBe( 'how' );
		expect( actionsFor( crawl04, null ).primary ).toBe( 'how' );
	} );
	it( 'CRAWL-03 with a guided link: Show me where first, Fix it second', () => {
		const [ crawl03 ] = checks( {
			'CRAWL-03': {
				status: 'fail',
				guided_url: 'https://example.com/wp-admin/x',
			},
		} ).filter( ( x ) => x.id === 'CRAWL-03' );
		expect( actionsFor( crawl03, OFF ) ).toMatchObject( {
			primary: 'where',
			secondary: 'fix',
		} );
	} );
	it( 'STORE-02 goes to Settings', () => {
		const [ s ] = checks( { 'STORE-02': { status: 'warn' } } ).filter(
			( x ) => x.id === 'STORE-02'
		);
		expect( actionsFor( s, OFF ).primary ).toBe( 'settings' );
	} );
} );

describe( 'groupChecks (AC 81)', () => {
	it( 'puts failing categories first and orders rows fail → warn → pass → managed → n/a → error', () => {
		const c = checks( {
			'STORE-01': { status: 'pass', details: { source: 'yoast' } },
			'STORE-02': { status: 'error' },
			'STORE-03': { status: 'not_applicable' },
			'STORE-04': { status: 'fail' },
			'DATA-01': { status: 'warn' },
		} );
		const groups = groupChecks( c, CATEGORY_ORDER );
		expect( groups.map( ( g ) => g.category ) ).toEqual( [
			'store',
			'data',
			'visibility',
			'crawl',
			'schema',
		] );
		expect( groups[ 0 ].checks.map( ( x ) => x.id ) ).toEqual( [
			'STORE-04',
			'STORE-01',
			'STORE-03',
			'STORE-02',
		] );
	} );
} );
