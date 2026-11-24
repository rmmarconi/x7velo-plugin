/**
 * One coexistence case (R5, AC 51 single-config slice): WooCommerce + Yoast SEO free.
 * - A check credits Yoast ("Managed by Yoast SEO").
 * - With x7velo's JSON-LD fixer on (returns policy set), a product page still has exactly one
 *   Product node, and it carries x7velo's hasMerchantReturnPolicy (so our output went into
 *   WooCommerce's node instead of adding a second one), next to Yoast's own graph.
 * State from run.sh: WooCommerce + Yoast active, tiny store seeded, no stored audit.
 * The full 7-config matrix (C harness) is deferred (test plan §6, R5).
 */
import { test, expect } from '@playwright/test';
import { isType, jsonLdNodes, openAndWaitForScore, patchSettings } from '../../utils/admin';

const PAGE = '/wp-admin/admin.php?page=x7velo';

test.describe( 'WooCommerce + Yoast SEO free @smoke', () => {
	test( 'R5 a check shows Managed by Yoast SEO', async ( { page } ) => {
		await page.goto( '/wp-admin/plugins.php' );
		await expect( page.locator( 'tr[data-slug="wordpress-seo"]' ) ).toHaveClass( /\bactive\b/ );
		await openAndWaitForScore( page, PAGE );
		await expect( page.locator( '.x7velo-checks' ).getByText( /Managed by Yoast SEO/ ).first() ).toBeVisible();
	} );

	test( 'AC51 product page has exactly one Product node with the JSON-LD fixer on', async ( { page, request } ) => {
		await openAndWaitForScore( page, PAGE );
		const returnsPage = Number( process.env.X7VELO_E2E_RETURNS_PAGE || 0 );
		expect( returnsPage, 'run.sh exports the seeded returns page ID' ).toBeGreaterThan( 0 );

		await patchSettings( page, {
			returns_page_id: returnsPage,
			return_policy: 'finite',
			return_days: 30,
			fix_jsonld: true,
		} );

		try {
			const products = await ( await request.get( '/wp-json/wc/store/v1/products?per_page=1&type=simple' ) ).json();
			const html = await ( await request.get( products[ 0 ].permalink ) ).text();
			expect( html, 'Yoast is really outputting its graph' ).toContain( 'yoast-schema-graph' );

			const nodes = jsonLdNodes( html );
			const productNodes = nodes.filter( ( n ) => isType( n, 'Product' ) || isType( n, 'ProductGroup' ) );
			expect( productNodes ).toHaveLength( 1 );
			expect( JSON.stringify( productNodes[ 0 ] ) ).toContain( 'hasMerchantReturnPolicy' );
		} finally {
			await patchSettings( page, { fix_jsonld: false } );
		}
	} );
} );
