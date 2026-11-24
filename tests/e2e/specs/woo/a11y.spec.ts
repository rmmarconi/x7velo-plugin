/**
 * R7 basic: axe-core on the dashboard Overview and Fixes tabs (and the confirm dialog).
 * Gate: no serious or critical violations inside our root and our modal. Core admin chrome is
 * out of scope (test plan R7). Full per-state scans (A01) and keyboard walks (A02) are deferred.
 */
import { test, expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { ROOT, openAndWaitForScore, openFixesTab } from '../../utils/admin';

const TAGS = [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ];

async function seriousViolations( page: Page, include: string, name: string ) {
	const result = await new AxeBuilder( { page } ).include( include ).withTags( TAGS ).analyze();
	await test.info().attach( `axe-${ name }.json`, {
		body: JSON.stringify( result.violations, null, 2 ),
		contentType: 'application/json',
	} );
	return result.violations
		.filter( ( v ) => v.impact === 'serious' || v.impact === 'critical' )
		.map( ( v ) => `${ v.impact } ${ v.id }: ${ v.help } (${ v.nodes.map( ( n ) => n.target.join( ' ' ) ).join( ', ' ) })` );
}

test.describe( 'accessibility (axe) @smoke', () => {
	test( 'R7 Overview tab has no serious or critical violations', async ( { page } ) => {
		await openAndWaitForScore( page, '/wp-admin/admin.php?page=x7velo' );
		expect( await seriousViolations( page, ROOT, 'overview' ) ).toEqual( [] );
	} );

	test( 'R7 Fixes tab and its confirm dialog have no serious or critical violations', async ( { page } ) => {
		await openAndWaitForScore( page, '/wp-admin/admin.php?page=x7velo' );
		await openFixesTab( page );
		expect( await seriousViolations( page, ROOT, 'fixes' ) ).toEqual( [] );

		await page.getByRole( 'checkbox', { name: /Publish a store summary/ } ).click();
		await expect( page.getByRole( 'dialog' ) ).toBeVisible();
		expect( await seriousViolations( page, '.x7velo-modal', 'dialog' ) ).toEqual( [] );
		await page.getByRole( 'dialog' ).getByRole( 'button', { name: 'Cancel' } ).click();
		await expect( page.getByRole( 'dialog' ) ).toBeHidden();
	} );
} );
