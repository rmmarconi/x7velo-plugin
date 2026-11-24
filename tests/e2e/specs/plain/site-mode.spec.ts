/**
 * AC 129 (W26): Playwright smoke on a plain WordPress site (WooCommerce not active).
 * activate → Tools › AI Readiness → score with 6 checks → turn llms.txt on → /llms.txt 200.
 * State from run.sh: WooCommerce and Yoast inactive, x7velo active, no stored audit.
 */
import { test, expect } from '@playwright/test';
import { checkRows, openAndWaitForScore, openFixesTab, toggleFix } from '../../utils/admin';

test.describe( 'plain WordPress (site mode) @smoke', () => {
	test( 'AC129 AC122 AI Readiness is under Tools and the audit scores 6 checks', async ( { page } ) => {
		await page.goto( '/wp-admin/plugins.php' );
		await expect( page.locator( 'tr[data-slug="x7velo"]' ) ).toHaveClass( /\bactive\b/ );
		await expect( page.locator( 'tr[data-slug="woocommerce"]' ) ).toHaveClass( /\binactive\b/ );

		// Menu location: Tools › AI Readiness, not WooCommerce.
		const item = page.locator( '#menu-tools a[href$="tools.php?page=x7velo"]' );
		await expect( item ).toHaveText( 'AI Readiness' );
		await expect( page.locator( '#toplevel_page_woocommerce' ) ).toHaveCount( 0 );

		// First visit runs the audit by itself (AC 80) and shows a score.
		await openAndWaitForScore( page, '/wp-admin/tools.php?page=x7velo' );
		await expect( page.locator( '.x7velo-score__basis' ) ).toContainText(
			'Based on 6 checks that apply to your site.'
		);
		await expect( checkRows( page ) ).toHaveCount( 6 );
		await expect( page.locator( '.x7velo-checks__store' ) ).toContainText( 'Store checks (12)' );
		// AC 126: no "Add products first" without WooCommerce.
		await expect( page.getByText( 'Add products first' ) ).toHaveCount( 0 );
		// AC 117: no Settings tab in site mode.
		await expect( page.getByRole( 'tab' ) ).toHaveText( [ 'Overview', 'Fixes' ] );
	} );

	test( 'AC129 AC113 turn llms.txt on, /llms.txt is served, turn it off again', async ( { page, request } ) => {
		await openAndWaitForScore( page, '/wp-admin/tools.php?page=x7velo' );
		await openFixesTab( page );
		// AC 117: only the llms.txt and crawler cards.
		await expect( page.locator( '.x7velo-fixer h2' ) ).toHaveText( [
			'Site summary for AI (llms.txt)',
			'Crawler rules (robots.txt)',
		] );

		await toggleFix( page, /Publish a site summary/, true );
		const on = await request.get( '/llms.txt' );
		expect( on.status() ).toBe( 200 );
		expect( on.headers()[ 'content-type' ] ).toBe( 'text/plain; charset=utf-8' );
		const body = await on.text();
		expect( body ).toMatch( /^# / );
		expect( body ).not.toContain( '## Products' );

		await toggleFix( page, /Publish a site summary/, false );
		expect( ( await request.get( '/llms.txt' ) ).status() ).toBe( 404 );
	} );
} );
