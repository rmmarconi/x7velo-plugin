/**
 * Helpers for the AI Readiness admin page. Setup goes through REST (wp.apiFetch on our own page,
 * so the real cookie nonce and capability checks apply); only the behaviour under test goes
 * through the UI.
 */
import { expect, type Page } from '@playwright/test';

export const ROOT = '#x7velo-admin-root';

/** Opens AI Readiness and waits for a finished audit (score card visible). */
export async function openAndWaitForScore( page: Page, path: string ) {
	await page.goto( path );
	await expect( page.locator( '.x7velo-app' ) ).toBeVisible();
	await expect( page.locator( '.x7velo-score__value' ) ).toHaveText( /^\d{1,3}$/, {
		timeout: 60_000,
	} );
}

/** Applicable check rows (the collapsed "Store checks" group in site mode is excluded). */
export function checkRows( page: Page ) {
	return page.locator(
		'.x7velo-checks__group:not(.x7velo-checks__store) .x7velo-check'
	);
}

/** Calls the plugin's REST API from the admin page with the page's own nonce. */
export async function apiFetch< T = unknown >(
	page: Page,
	options: { path: string; method?: string; data?: unknown }
): Promise< T > {
	return page.evaluate( ( o ) => {
		const wp = ( window as unknown as { wp: { apiFetch: ( x: unknown ) => Promise< unknown > } } ).wp;
		return wp.apiFetch( o );
	}, options ) as Promise< T >;
}

export async function patchSettings( page: Page, data: Record< string, unknown > ) {
	return apiFetch( page, { path: '/x7velo/v1/settings', method: 'PATCH', data } );
}

/** Opens the Fixes tab. */
export async function openFixesTab( page: Page ) {
	await page.getByRole( 'tab', { name: 'Fixes' } ).click();
	await expect( page.locator( '.x7velo-fixes' ) ).toBeVisible();
}

/**
 * Flips a Fixes-tab toggle through its confirm dialog: click → dialog → primary button →
 * dialog closes → the toggle shows the new state (only after PATCH /settings succeeded).
 */
export async function toggleFix( page: Page, label: RegExp, on: boolean ) {
	const toggle = page.getByRole( 'checkbox', { name: label } );
	await expect( toggle ).toBeChecked( { checked: ! on } );
	await toggle.click();
	const dialog = page.getByRole( 'dialog' );
	await expect( dialog ).toBeVisible();
	// The click alone never changes the stored value: still in the old state behind the dialog
	// (the modal marks the rest of the page aria-hidden, hence includeHidden).
	await expect(
		page.getByRole( 'checkbox', { name: label, includeHidden: true } )
	).toBeChecked( { checked: ! on } );
	await dialog.locator( '.x7velo-modal__actions .components-button.is-primary' ).click();
	await expect( dialog ).toBeHidden();
	await expect( toggle ).toBeChecked( { checked: on } );
}

/** Every JSON-LD node on an HTML page, @graph flattened. */
export function jsonLdNodes( html: string ): Record< string, unknown >[] {
	const nodes: Record< string, unknown >[] = [];
	const re = /<script[^>]*type=["']application\/ld\+json["'][^>]*>([\s\S]*?)<\/script>/gi;
	const walk = ( v: unknown ) => {
		if ( Array.isArray( v ) ) {
			v.forEach( walk );
		} else if ( v && typeof v === 'object' ) {
			const o = v as Record< string, unknown >;
			if ( '@type' in o ) {
				nodes.push( o );
			}
			if ( Array.isArray( o[ '@graph' ] ) ) {
				walk( o[ '@graph' ] );
			}
		}
	};
	for ( const m of html.matchAll( re ) ) {
		walk( JSON.parse( m[ 1 ] ) );
	}
	return nodes;
}

export function isType( node: Record< string, unknown >, type: string ) {
	const t = node[ '@type' ];
	return t === type || ( Array.isArray( t ) && t.includes( type ) );
}
