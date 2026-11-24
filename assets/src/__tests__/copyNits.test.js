/**
 * M1 gate run 2 copy/UI nits: robots source badge, paused JSON-LD sentence, site-mode wording.
 */
import { render, screen } from '@testing-library/react';

import { normalizeRecord, normalizeSettings } from '../api';
import CopyBlock from '../components/CopyBlock';
import FixesTab from '../components/FixesTab';
import { statusPresentation } from '../components/StatusLabel';
import { getDialog } from '../copy/dialogs';
import {
	BOOT,
	rawRecord,
	rawSettings,
	rawSiteSettings,
	SITE_BOOT,
} from './fixtures';

jest.mock( '@wordpress/a11y', () => ( { speak: jest.fn() } ) );

function renderFixes( raw, boot ) {
	const s = normalizeSettings( raw );
	return render(
		<FixesTab
			settings={ { status: 'ready', ...s } }
			boot={ boot }
			onChange={ () => {} }
			cardNotices={ {} }
			goSettings={ () => {} }
			onRetry={ () => {} }
		/>
	);
}

describe( 'CRAWL-01 source badge', () => {
	it( 'WordPress-generated robots.txt ("filter") reads "Passed", not "Managed by filter"', () => {
		const r = normalizeRecord(
			rawRecord( {
				'CRAWL-01': { details: { source: 'filter' } },
			} )
		);
		const label = statusPresentation(
			r.checks.find( ( c ) => c.id === 'CRAWL-01' )
		).label;
		expect( label ).toBe( 'Passed' );
	} );
	it( 'a physical file still says who manages it', () => {
		const r = normalizeRecord(
			rawRecord( { 'CRAWL-01': { details: { source: 'file' } } } )
		);
		expect(
			statusPresentation( r.checks.find( ( c ) => c.id === 'CRAWL-01' ) )
				.label
		).toBe( 'Passed · Managed by a file on your server' );
	} );
} );

describe( 'JSON-LD paused', () => {
	it( 'names the plugin when known', () => {
		renderFixes(
			rawSettings(
				{ fix_jsonld: true },
				{ jsonld: { state: 'paused', managed_by: 'rank-math' } }
			),
			BOOT
		);
		expect(
			screen.getByText( /^Paused\. Rank Math adds product details now/ )
		).toBeInTheDocument();
	} );
	it( 'uses a full sentence when the plugin is unknown', () => {
		renderFixes(
			rawSettings(
				{ fix_jsonld: true },
				{ jsonld: { state: 'paused' } }
			),
			BOOT
		);
		expect(
			screen.getByText(
				'Paused. Another plugin adds product details now, so x7velo isn’t adding its own. If that plugin stops, x7velo’s product details come back on automatically.'
			)
		).toBeInTheDocument();
		expect(
			screen.queryByText( /Paused\. another plugin/ )
		).not.toBeInTheDocument();
	} );
} );

describe( 'site mode wording', () => {
	it( 'crawler card never mentions shopping or the store', () => {
		const { container } = renderFixes(
			rawSiteSettings( {
				fix_robots_search: true,
				fix_robots_block_training: true,
			} ),
			SITE_BOOT
		);
		const robots = container.querySelector(
			'[aria-labelledby="x7velo-fixer-robots"]'
		);
		expect( robots.textContent ).not.toMatch( /shop|store/i );
	} );
	it( 'training and Google-Extended dialogs say site/search', () => {
		const ctx = { values: {}, fixerStatus: {}, boot: SITE_BOOT };
		const training = getDialog( 'training', true, ctx ).body.join( ' ' );
		expect( training ).toContain( 'doesn’t block search assistants' );
		expect( training ).not.toMatch( /shop|store/i );
		const ge = getDialog( 'google_extended', true, ctx ).body.join( ' ' );
		expect( ge ).toContain( 'your site’s visibility' );
		expect( ge ).not.toMatch( /shop|store/i );
		// Store mode keeps its wording.
		expect(
			getDialog( 'training', true, { ...ctx, boot: BOOT } ).body.join(
				' '
			)
		).toContain( 'doesn’t block shopping assistants' );
	} );
} );

describe( 'CopyBlock', () => {
	it( 'renders all lines in one code block', () => {
		const { container } = render(
			<CopyBlock text={ 'User-agent: A\nAllow: /\nSitemap: /s.xml' } />
		);
		expect( container.querySelectorAll( 'pre' ) ).toHaveLength( 1 );
		expect( container.querySelectorAll( 'code' ) ).toHaveLength( 1 );
		expect( container.querySelector( 'code' ).textContent ).toBe(
			'User-agent: A\nAllow: /\nSitemap: /s.xml'
		);
	} );
} );
