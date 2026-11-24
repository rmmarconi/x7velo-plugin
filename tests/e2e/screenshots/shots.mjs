/**
 * WordPress.org screenshots (plugin/readme.txt "== Screenshots ==", .wordpress-org/README.md).
 * Test-only; driven by tests/e2e/screenshots/run.sh against the Northwind Ceramics demo site.
 *
 *   node shots.mjs <outdir> <suffix> main     shots 1-6
 *   node shots.mjs <outdir> <suffix> robots   shot 7 (needs a physical robots.txt in place)
 *
 * <suffix> is "" for English or "-pt_BR". Selectors avoid UI text so the same script runs in
 * every locale. Viewport 1280x900 for every shot.
 */
/* eslint-disable no-console */
import { chromium } from '@playwright/test';

const [ out, suffix = '', phase = 'main' ] = process.argv.slice( 2 );
const host = process.env.SHOTS_HOST || 'northwind-ceramics.test';
const proxy = process.env.SHOTS_PROXY || '127.0.0.1:8931';
const base = `https://${ host }`;
const app = `${ base }/wp-admin/admin.php?page=x7velo`;

const browser = await chromium.launch( {
	args: [ `--host-resolver-rules=MAP ${ host }:443 ${ proxy }` ],
} );
const ctx = await browser.newContext( {
	viewport: { width: 1280, height: 900 },
	ignoreHTTPSErrors: true,
	locale: suffix === '-pt_BR' ? 'pt-BR' : 'en-US',
	timezoneId: 'America/Los_Angeles',
} );
const page = await ctx.newPage();
const errors = [];
page.on( 'pageerror', ( e ) => errors.push( e.message ) );

const shot = async ( n, { blur = false } = {} ) => {
	await page.mouse.move( 0, 899 );
	if ( blur ) {
		// eslint-disable-next-line @wordpress/no-global-active-element
		await page.evaluate( () => document.activeElement?.blur() );
	}
	await page.waitForTimeout( 400 );
	const path = `${ out }/screenshot-${ n }${ suffix }.png`;
	await page.screenshot( { path } );
	console.log( 'saved', path );
};

async function open( query = '' ) {
	await page.goto( app + query );
	await page.locator( '.x7velo-app' ).waitFor();
	await page.waitForFunction(
		() =>
			/^\d+$/.test(
				document.querySelector( '.x7velo-score__value' )?.textContent ||
					''
			) ||
			document.querySelector(
				'.x7velo-fixes, .x7velo-settings, form'
			) !== null,
		null,
		{ timeout: 60_000 }
	);
	await page.waitForLoadState( 'networkidle' );
}

async function rerunAudit() {
	await open();
	await page.locator( '.x7velo-score .components-button' ).first().click();
	await page.waitForTimeout( 1000 );
	await page.waitForFunction(
		() =>
			/^\d+$/.test(
				document.querySelector( '.x7velo-score__value' )?.textContent ||
					''
			),
		null,
		{ timeout: 60_000 }
	);
	await page.waitForLoadState( 'networkidle' );
}

const scrollTo = ( selector, offset = 0 ) =>
	page.evaluate(
		( [ s, o ] ) => {
			const el = document.querySelector( s );
			window.scrollTo(
				0,
				el.getBoundingClientRect().top + window.scrollY - 32 - o
			);
		},
		[ selector, offset ]
	);

const dialogPrimary = () =>
	page.locator(
		'[role="dialog"] .x7velo-modal__actions .components-button.is-primary'
	);

await page.goto( `${ base }/wp-login.php` );
await page.fill( '#user_login', process.env.SHOTS_USER || 'admin' );
await page.fill( '#user_pass', process.env.SHOTS_PASS || 'password' );
await page.click( '#wp-submit' );
await page.locator( '#wpadminbar' ).waitFor();

if ( phase === 'main' ) {
	// 1. Score, label and the top 3 fixes (first screenful).
	await rerunAudit();
	await shot( 1, { blur: true } );

	// 2. A check opened from a deep link: what we found, why, how to fix, product links.
	await open( '&check=DATA-04' );
	await page.locator( '#x7velo-check-DATA-04 .x7velo-detail' ).waitFor();
	await scrollTo( '#x7velo-check-DATA-04', 0 );
	await shot( 2, { blur: true } );

	// 3. Fixes tab, everything off.
	await open( '&tab=fixes' );
	await page.locator( '.x7velo-fixes' ).waitFor();
	await shot( 3 );

	// 4. Crawler access always asks first: the "Let AI shopping assistants in" dialog.
	await scrollTo( 'section[aria-labelledby="x7velo-fixer-robots"]', 140 );
	await page
		.locator(
			'section[aria-labelledby="x7velo-fixer-robots"] input[type="checkbox"]'
		)
		.first()
		.click();
	await page.locator( '[role="dialog"]' ).waitFor();
	await shot( 4 );
	await page.keyboard.press( 'Escape' );
	await page.locator( '[role="dialog"]' ).waitFor( { state: 'hidden' } );

	// 5. Settings filled in and saved: returns page, 30-day window, 2-5 days delivery.
	await open( '&tab=settings' );
	const returnsTitle = process.env.SHOTS_RETURNS_TITLE || 'Returns & Refunds';
	await page.locator( '#x7velo-set-returns' ).waitFor();
	await page.waitForLoadState( 'networkidle' );
	const form = page.locator( '#wpbody-content' );
	const suggested = form.locator( '.x7velo-suggested .components-button' );
	if ( await suggested.count() ) {
		await suggested.first().click(); // "Use this page" on the detected returns page.
	} else {
		const combo = form
			.locator( '.components-combobox-control input' )
			.first();
		await combo.fill( returnsTitle.slice( 0, 7 ) );
		await page.getByRole( 'option', { name: returnsTitle } ).click();
	}
	await form.locator( 'input[type="radio"][value="finite"]' ).check();
	await form.locator( '.x7velo-number input' ).first().fill( '30' );
	const delivery = form.locator( '.x7velo-delivery__row input' );
	await delivery.nth( 0 ).fill( '2' );
	await delivery.nth( 1 ).fill( '5' );
	await form.locator( '.components-button.is-primary' ).last().click();
	await page.waitForLoadState( 'networkidle' );
	await page.waitForTimeout( 1500 );
	await scrollTo( 'section[aria-labelledby="x7velo-set-returns"]', 8 );
	await shot( 5 );

	// 6. Turn on the llms.txt fix through its dialog, rerun, open the check: "Fixed by x7velo".
	await open( '&tab=fixes' );
	await page
		.locator(
			'section[aria-labelledby="x7velo-fixer-llms"] input[type="checkbox"]'
		)
		.first()
		.click();
	await dialogPrimary().click();
	await page.locator( '[role="dialog"]' ).waitFor( { state: 'hidden' } );
	await page.waitForLoadState( 'networkidle' );
	await rerunAudit();
	await open( '&check=CRAWL-03' );
	await page.locator( '#x7velo-check-CRAWL-03 .x7velo-detail' ).waitFor();
	// Show the whole "Crawler access" group so the fixed check is seen in context.
	await page.evaluate( () => {
		const g = document
			.querySelector( '#x7velo-check-CRAWL-03' )
			.closest( '.x7velo-checks__group' );
		window.scrollTo(
			0,
			g.getBoundingClientRect().top + window.scrollY - 32 - 16
		);
	} );
	await shot( 6, { blur: true } );
} else {
	// 7. Own robots.txt: x7velo shows the lines to copy instead (Crawler rules card).
	await rerunAudit();
	await open( '&tab=fixes' );
	await page
		.locator( 'section[aria-labelledby="x7velo-fixer-robots"]' )
		.waitFor();
	await scrollTo( 'section[aria-labelledby="x7velo-fixer-robots"]', 16 );
	await shot( 7 );
}

if ( errors.length ) {
	console.error( 'page errors:', errors );
	process.exitCode = 1;
}
await browser.close();
