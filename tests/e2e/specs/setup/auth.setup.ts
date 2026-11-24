/**
 * Logs in as the wp-env admin (admin / password) and stores the session for the other projects.
 */
import { test as setup, expect } from '@playwright/test';

setup( 'log in as admin', async ( { page } ) => {
	await page.goto( '/wp-login.php' );
	await page.locator( '#user_login' ).fill( 'admin' );
	await page.locator( '#user_pass' ).fill( 'password' );
	await page.locator( '#wp-submit' ).click();
	await expect( page.locator( '#wpadminbar' ) ).toBeVisible();
	await page.context().storageState( { path: 'tests/e2e/.auth/admin.json' } );
} );
