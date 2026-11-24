/**
 * WooCommerce store smoke (E01/E02/E03/E09-style, test plan §6.2).
 * State from run.sh: WooCommerce active, Yoast inactive, tiny store seeded, no stored audit.
 */
import { test, expect } from '@playwright/test';
import { checkRows, openAndWaitForScore, openFixesTab, toggleFix } from '../../utils/admin';

const PAGE = '/wp-admin/admin.php?page=x7velo';

test.describe( 'WooCommerce store @smoke', () => {
	test( 'AC79 AC80 AC81 WooCommerce › AI Readiness runs the audit and lists every check', async ( { page } ) => {
		await page.goto( '/wp-admin/plugins.php' );
		await expect( page.locator( 'tr[data-slug="woocommerce"]' ) ).toHaveClass( /\bactive\b/ );
		// AC 79 / 123: the row action resolves to the registered page.
		await expect(
			page.locator( 'tr[data-slug="x7velo"] .x7velo-open a, tr[data-slug="x7velo"] a', {
				hasText: 'Open AI Readiness',
			} ).first()
		).toHaveAttribute( 'href', /admin\.php\?page=x7velo$/ );

		const item = page.locator( '#toplevel_page_woocommerce a[href$="admin.php?page=x7velo"]' );
		await expect( item ).toHaveText( 'AI Readiness' );
		await expect( page.locator( '#menu-tools a[href$="tools.php?page=x7velo"]' ) ).toHaveCount( 0 );

		await openAndWaitForScore( page, PAGE );
		await expect( page.getByText( 'Add products first' ) ).toHaveCount( 0 );
		// 18 built-in checks, all applicable with WooCommerce on; no "Store checks" group.
		await expect( checkRows( page ) ).toHaveCount( 18 );
		await expect( page.locator( '.x7velo-checks__store' ) ).toHaveCount( 0 );
		// AC 81: every row shows a status text, never colour alone.
		const statuses = page.locator( '.x7velo-checks .x7velo-check .x7velo-status' );
		await expect( statuses.first() ).toBeVisible();
		await expect( page.getByRole( 'tab' ) ).toHaveText( [ 'Overview', 'Fixes', 'Settings' ] );
	} );

	test( 'AC82 AC83 Fixes tab: turn llms.txt on through the dialog, then off again', async ( { page, request } ) => {
		await openAndWaitForScore( page, PAGE );
		await openFixesTab( page );
		await expect( page.locator( '.x7velo-fixer h2' ) ).toHaveText( [
			'Product details for AI assistants',
			'Store summary for AI (llms.txt)',
			'Product list for shopping assistants (product feed)',
			'Crawler rules (robots.txt)',
		] );

		// Cancel leaves everything as it was.
		const toggle = page.getByRole( 'checkbox', { name: /Publish a store summary/ } );
		await toggle.click();
		await page.getByRole( 'dialog' ).getByRole( 'button', { name: 'Cancel' } ).click();
		await expect( page.getByRole( 'dialog' ) ).toBeHidden();
		await expect( toggle ).not.toBeChecked();
		expect( ( await request.get( '/llms.txt' ) ).status() ).toBe( 404 );

		await toggleFix( page, /Publish a store summary/, true );
		const on = await request.get( '/llms.txt' );
		expect( on.status() ).toBe( 200 );
		expect( on.headers()[ 'content-type' ] ).toBe( 'text/plain; charset=utf-8' );
		expect( await on.text() ).toContain( 'E2E simple product' );

		await toggleFix( page, /Publish a store summary/, false );
		expect( ( await request.get( '/llms.txt' ) ).status() ).toBe( 404 );
	} );
} );
