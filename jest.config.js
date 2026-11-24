/**
 * Jest (legacy path of @wordpress/scripts 37, which defaults to Vitest; see
 * node_modules/@wordpress/scripts/docs/vitest-migration.md "Keep an existing Jest suite").
 * Uses the project's @wordpress/babel-preset-default for transforms.
 */
module.exports = {
	preset: '@wordpress/jest-preset-default',
	transform: {
		'\\.[mc]?[jt]sx?$': [ 'babel-jest', { presets: [ '@wordpress/babel-preset-default' ] } ],
	},
	// @wordpress/theme and uuid (pulled in by components 45) ship ESM only; let babel-jest compile them.
	transformIgnorePatterns: [ '/node_modules/(?!(@wordpress/theme|uuid)/)' ],
	setupFilesAfterEnv: [ '<rootDir>/assets/src/__tests__/setup.js' ],
	testMatch: [ '<rootDir>/assets/src/**/__tests__/**/*.test.js' ],
	testPathIgnorePatterns: [ '/node_modules/', '/build/', '/vendor/' ],
};
