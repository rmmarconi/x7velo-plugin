# x7velo end-to-end smoke, sentinel and log guards

Status: **M1 smoke built** (2026-11-23). The full plan (coexistence matrix over 7 SEO configs, the
E01–E20 / A01–A02 / V01 scenarios) is in `docs/qa/m1-test-plan.md` §6; what is automated today
and what is deferred is in §5.1 and the traceability table there.

Everything under `plugin/tests/` is excluded from the release zip by `plugin/.distignore`.

## What runs

`tests/e2e/run.sh` starts a dedicated wp-env (`env/.wp-env.json`: WordPress latest, WooCommerce
and Yoast SEO free mapped in but **inactive**, `WP_DEBUG` + `WP_DEBUG_LOG` on, display off), then
for each phase sets the state with WP-CLI and runs one Playwright project:

| Phase / project | WordPress state | Specs | Proves |
|---|---|---|---|
| `plain` | x7velo only (WooCommerce, Yoast inactive) | `specs/plain/site-mode.spec.ts` | AC 129: Tools › AI Readiness, first visit runs the audit, score + "Based on 6 checks", 6 rows + "Store checks (12)", no Settings tab, llms.txt on via the dialog → `/llms.txt` 200 `text/plain`, off → 404 |
| `woo` | + WooCommerce, `tiny` store (`fixtures/seed-tiny.php`) | `specs/woo/store-smoke.spec.ts`, `specs/woo/a11y.spec.ts` | WooCommerce › AI Readiness, row action, 18 checks with status text; Fixes tab: Cancel changes nothing, llms.txt on/off through the confirm dialog; axe (wcag2a/aa, 21a/aa, 22aa) on Overview, Fixes and the dialog: 0 serious/critical |
| `yoast` | + Yoast SEO free | `specs/yoast/coexistence.spec.ts` | A check shows "Managed by Yoast SEO"; with the JSON-LD fixer on (returns policy set) a product page has exactly **one** Product node, carrying x7velo's `hasMerchantReturnPolicy`, next to Yoast's graph (AC 51, one config) |

Before each phase the stored audit is deleted, so the first visit runs it (AC 80) in that mode.

After the phases, two guards cover everything that ran (browser, REST, WP-CLI, front end):

- **Outbound-HTTP sentinel (AC 88, R12).** `env/mu-plugins/x7velo-e2e-sentinel.php` loads
  `tests/support/http-sentinel.php`, which hooks `pre_http_request` first and blocks + logs any
  request whose backtrace passes through `plugins/x7velo/` (not `tests/`). Other plugins' and core's
  requests pass through. Non-empty `wp-content/x7velo-http-sentinel.log` = run fails. The same
  sentinel is loaded by `tests/bootstrap.php` for every PHPUnit run, where a hit turns the exit code
  into 1 after the summary.
- **G9 log hygiene (R11).** `wp-content/debug.log` is copied to `artifacts/debug.log` and checked by
  `tests/g9/scan-debug-log.sh`: any PHP notice/warning/deprecation/error naming a file under
  `plugins/x7velo/` (or naming x7velo in the message, e.g. "translation loading for the x7velo
  domain was triggered too early") fails the run. Notices from core/WooCommerce/Yoast are counted
  and ignored. CI runs the PR smoke on PHP 8.4 for this reason.

`run.sh` exits non-zero if any Playwright test, the sentinel or the G9 scan fails, and prints
`RESULT: PASS|FAIL`.

## Running locally

Needs a Linux host with Docker and Node >= 22.13 (the project's dev VM works; macOS with Docker
should too, untested). Playwright runs in the official container
`mcr.microsoft.com/playwright:v1.65.1-noble` (same version as `@playwright/test` in
`package.json`), so no browsers are installed on the host.

```sh
cd plugin
npm ci && npm run build                     # dependencies and the admin bundle
tests/e2e/run.sh                            # all phases: plain woo yoast
tests/e2e/run.sh woo                        # one phase
WP_ENV_PORT=8920 X7VELO_E2E_KEEP=1 tests/e2e/run.sh   # other port, leave the env running
WP_ENV_PHP_VERSION=8.4 tests/e2e/run.sh     # G9 run on PHP 8.4 (what PRs do)
```

| Variable | Default | Meaning |
|---|---|---|
| `WP_ENV_PORT` | 8892 | Web port of the e2e env (CI: 8904). Pick a free one if other envs run on the host |
| `WP_ENV_PHP_VERSION` | 8.1 | PHP of the env |
| `X7VELO_E2E_KEEP` | 0 | 1 = don't stop the env at the end (faster reruns) |
| `X7VELO_E2E_DESTROY` | 0 | 1 = destroy the env at the end (CI) |
| `PW_IMAGE` | `mcr.microsoft.com/playwright:v1.65.1-noble` | Playwright container; must match `@playwright/test` |

A full run takes about 5 minutes on the dev VM from a stopped env (≈ 3.5 min when it is already
running). Results land in `tests/e2e/artifacts/<phase>/` (HTML report, JUnit XML, traces and
screenshots of failures) plus `artifacts/debug.log` and `artifacts/http-sentinel.log`. View a
report with `npx playwright show-report tests/e2e/artifacts/woo/report --host 127.0.0.1` and, on a
remote host, an SSH tunnel to port 9323.

Stop or remove the env yourself with `cd tests/e2e/env && npx @wordpress/env@12.1.0 stop|destroy`
(set the same `WP_ENV_PORT` you started it with).

## Conventions

- One WordPress, specs run serially (`workers: 1`). Never parallelise against the same env.
- Setup state through WP-CLI (run.sh) and REST (`utils/admin.ts › patchSettings`, which uses the
  page's own nonce). Only the behaviour under test goes through the UI.
- A spec that turns a fixer on turns it off again (in `finally` when it changed it through REST).
- Name tests after the acceptance criterion they prove, e.g. `AC129 …`. Tag the smoke set `@smoke`.
- Test-only PHP lives under `plugin/tests/` (mu-plugins, seeders, sentinel), never in `src/`.

## Layout

```
plugin/tests/e2e/
├── README.md                this file
├── run.sh                   driver: env, phases, Playwright container, sentinel + G9 guards
├── playwright.config.ts     projects setup / plain / woo / yoast; workers 1; per-phase artifacts
├── env/
│   ├── .wp-env.json         e2e instance: port 8892, no tests env, Woo + Yoast mapped (inactive)
│   └── mu-plugins/x7velo-e2e-sentinel.php
├── fixtures/seed-tiny.php   tiny store (3 simple + 1 variable with 3 variations, returns page)
├── utils/admin.ts           open page + wait for score, REST helper, toggle-through-dialog, JSON-LD parser
├── specs/{setup,plain,woo,yoast}/
├── .auth/                   stored admin session (gitignored)
└── artifacts/               reports, traces, logs (gitignored)
```

Related: `tests/support/http-sentinel.php` (shared sentinel), `tests/g9/` (G9 PHPUnit config and
log scanner), `tests/perf/` (AC 90 performance harness, own README section in the test plan §4).

## Not here (deferred, see the test plan)

- Coexistence harness over the 7 SEO configs and variants, option-fixture drift, robots HTTP goldens.
- RFC 9309 robots oracle (protego).
- Full E01–E20, A01–A02 per-state axe + keyboard walks, V01 responsive.
