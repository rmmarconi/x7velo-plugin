import { changesFromDraft, validate } from '../components/SettingsTab';

const base = {
	returns_page_id: 0,
	own_brand_products: false,
	return_policy: '',
	return_days: '',
	delivery_days_min: '',
	delivery_days_max: '',
};

describe( 'Settings validation (design §3.5)', () => {
	it( 'accepts everything empty', () => {
		expect( validate( base ) ).toEqual( {} );
	} );
	it( 'requires 1–365 days for a finite return window', () => {
		expect(
			validate( { ...base, return_policy: 'finite' } )
		).toHaveProperty( 'return_days' );
		expect(
			validate( { ...base, return_policy: 'finite', return_days: '0' } )
		).toHaveProperty( 'return_days' );
		expect(
			validate( { ...base, return_policy: 'finite', return_days: '366' } )
		).toHaveProperty( 'return_days' );
		expect(
			validate( { ...base, return_policy: 'finite', return_days: '30' } )
		).toEqual( {} );
	} );
	it( 'needs both delivery numbers or neither; 0 is valid', () => {
		expect(
			validate( {
				...base,
				delivery_days_min: '0',
				delivery_days_max: '',
			} )
		).toEqual( {
			delivery_days_max: 'Fill in both numbers, or leave both empty.',
		} );
		expect(
			validate( {
				...base,
				delivery_days_min: '0',
				delivery_days_max: '0',
			} )
		).toEqual( {} );
	} );
	it( 'checks range and order', () => {
		expect(
			validate( {
				...base,
				delivery_days_min: '61',
				delivery_days_max: '62',
			} )
		).toEqual( {
			delivery_days_min: 'Enter a number from 0 to 60.',
			delivery_days_max: 'Enter a number from 0 to 60.',
		} );
		expect(
			validate( {
				...base,
				delivery_days_min: '5',
				delivery_days_max: '2',
			} )
		).toEqual( {
			delivery_days_max:
				'The first number can’t be bigger than the second.',
		} );
	} );
} );

describe( 'changesFromDraft', () => {
	it( 'sends null for empty numbers and drops days unless finite', () => {
		expect(
			changesFromDraft( {
				...base,
				return_policy: 'unlimited',
				return_days: '30',
				delivery_days_min: '0',
				delivery_days_max: '3',
			} )
		).toEqual( {
			returns_page_id: 0,
			own_brand_products: false,
			return_policy: 'unlimited',
			return_days: null,
			delivery_days_min: 0,
			delivery_days_max: 3,
		} );
	} );
} );
