# x7velo

Agent-readiness kit for WooCommerce stores: x7velo audits a store and fixes what stops AI
assistants and buyer agents from understanding its products (structured data, `llms.txt`,
product feeds, crawler access). Everything runs inside WordPress; the free plugin works without
an account and makes no external requests.

- Install: [wordpress.org/plugins/x7velo](https://wordpress.org/plugins/x7velo/) (listing pending review)
- Website: [x7velo.com](https://x7velo.com)
- This release: `v1.0.0`. Requires WordPress 6.6+, PHP 8.1+, WooCommerce.

This repository is a read-only mirror of the plugin, updated once per release. Each release is a
single commit, tagged `vX.Y.Z`.

## Build the admin UI (JS)

Node 24:

```sh
npm ci
npm run build        # -> build/
npm run lint:js
npm run test:unit
```

## PHP lint and tests

```sh
composer install
composer lint        # PHPCS: WordPress Coding Standards + PHPCompatibilityWP
```

PHPUnit runs against the WordPress test suite inside [wp-env](https://www.npmjs.com/package/@wordpress/env)
(needs Docker), with WooCommerce loaded and without it:

```sh
npx @wordpress/env@12.1.0 start
npx @wordpress/env@12.1.0 run tests-cli --env-cwd=wp-content/plugins/x7velo composer install
npx @wordpress/env@12.1.0 run tests-cli --env-cwd=wp-content/plugins/x7velo composer test
npx @wordpress/env@12.1.0 run tests-cli --env-cwd=wp-content/plugins/x7velo composer test:no-wc
```

## Contributing

Issues are welcome. Pull requests are reviewed; accepted changes are applied upstream and show up
here with the next release, so your PR will be closed rather than merged (you keep the credit in the
changelog).

## License

GPLv2 or later. See [LICENSE](LICENSE).
