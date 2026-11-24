/**
 * ESLint flat config: the @wordpress/scripts defaults, with Jest rules (not Vitest) for tests.
 * See node_modules/@wordpress/scripts/docs/vitest-migration.md, "Keep Jest lint rules".
 */
const wpPlugin = require( '@wordpress/eslint-plugin' );
const jestPlugin = require( 'eslint-plugin-jest' );

module.exports = [
	{ ignores: [ '**/build/**', '**/node_modules/**', '**/vendor/**' ] },
	...wpPlugin.configs.recommended,
	{
		languageOptions: {
			parserOptions: {
				requireConfigFile: false,
				babelOptions: {
					presets: [ require.resolve( '@wordpress/babel-preset-default' ) ],
				},
			},
		},
	},
	{
		...jestPlugin.configs[ 'flat/recommended' ],
		files: [ '**/__tests__/**/*.js', '**/*.test.js' ],
	},
];
