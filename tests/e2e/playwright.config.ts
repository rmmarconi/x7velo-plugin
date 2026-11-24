/**
 * Playwright config for the x7velo e2e smoke (docs/qa/m1-test-plan.md §6, README.md here).
 *
 * One WordPress (the e2e wp-env), so specs run serially. The projects map to WordPress states
 * that tests/e2e/run.sh sets up with WP-CLI before running each one:
 *   plain  WooCommerce and Yoast inactive (site mode, AC 129)
 *   woo    WooCommerce active, tiny store seeded (E01/E03-style smoke + axe)
 *   yoast  WooCommerce + Yoast SEO free active (one coexistence case, R5)
 * `setup` logs in once per run and stores the admin session.
 */
import { defineConfig, devices } from '@playwright/test';

const baseURL = process.env.X7VELO_E2E_BASE_URL || 'http://localhost:8892';
const authFile = 'tests/e2e/.auth/admin.json';
// run.sh runs one project per WordPress state; keep each phase's report and results apart.
const phase = process.env.X7VELO_E2E_PHASE || 'all';

export default defineConfig( {
	testDir: './specs',
	outputDir: `./artifacts/${ phase }/results`,
	fullyParallel: false,
	workers: 1,
	retries: 0,
	forbidOnly: !! process.env.CI,
	timeout: 90_000,
	expect: { timeout: 30_000 },
	reporter: [
		[ 'list' ],
		[ 'html', { outputFolder: `artifacts/${ phase }/report`, open: 'never' } ],
		[ 'junit', { outputFile: `artifacts/${ phase }/junit.xml` } ],
	],
	use: {
		baseURL,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		...devices[ 'Desktop Chrome' ],
	},
	projects: [
		{ name: 'setup', testMatch: /setup\/.*\.setup\.ts/ },
		{
			name: 'plain',
			testMatch: /plain\/.*\.spec\.ts/,
			dependencies: [ 'setup' ],
			use: { storageState: authFile },
		},
		{
			name: 'woo',
			testMatch: /woo\/.*\.spec\.ts/,
			dependencies: [ 'setup' ],
			use: { storageState: authFile },
		},
		{
			name: 'yoast',
			testMatch: /yoast\/.*\.spec\.ts/,
			dependencies: [ 'setup' ],
			use: { storageState: authFile },
		},
	],
} );
