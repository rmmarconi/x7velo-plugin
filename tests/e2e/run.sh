#!/usr/bin/env bash
# x7velo e2e smoke driver (docs/qa/m1-test-plan.md §6, tests/e2e/README.md).
#
# Starts the e2e wp-env (tests/e2e/env), puts WordPress in each state with WP-CLI, runs the
# matching Playwright project in the official Playwright container, then applies two guards to
# everything that ran:
#   - R12 / AC 88 sentinel: wp-content/x7velo-http-sentinel.log must be empty;
#   - G9 / R11: wp-content/debug.log must have no notice/warning/deprecation from x7velo.
#
# Usage (from anywhere; Linux host with Docker and Node >= 22.13):
#   plugin/tests/e2e/run.sh                 all phases: plain woo yoast
#   plugin/tests/e2e/run.sh plain woo       some phases
# Env:
#   WP_ENV_PORT          web port (default 8892; CI uses 8904)
#   WP_ENV_PHP_VERSION   PHP of the env (default from env/.wp-env.json, 8.1; G9 runs 8.4)
#   X7VELO_E2E_KEEP=1    leave the env running afterwards (default: stop it)
#   X7VELO_E2E_DESTROY=1 destroy the env afterwards (CI)
# Needs plugin/node_modules (npm ci) and plugin/build (npm run build).
# SC2016: PHP in single quotes is intentional. SC2317: finish() runs from the EXIT trap.
# shellcheck disable=SC2016,SC2317
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
ENV_DIR="$PLUGIN_DIR/tests/e2e/env"
WP_ENV_VERSION="${WP_ENV_VERSION:-12.1.0}"
PW_IMAGE="${PW_IMAGE:-mcr.microsoft.com/playwright:v1.65.1-noble}"
export WP_ENV_PORT="${WP_ENV_PORT:-8892}"
BASE_URL="http://localhost:${WP_ENV_PORT}"
ARTIFACTS="$PLUGIN_DIR/tests/e2e/artifacts"
PHASES=("$@")
[ "${#PHASES[@]}" -gt 0 ] || PHASES=(plain woo yoast)

wp_env() { (cd "$ENV_DIR" && npx --yes "@wordpress/env@${WP_ENV_VERSION}" "$@"); }
# WP-CLI in the env's cli container; status lines go to stderr, the command's stdout stays clean.
wp() { wp_env run cli wp "$@" 2>/dev/null; }
log() { printf '\n== %s\n' "$*"; }

[ -f "$PLUGIN_DIR/build/index.js" ] || { echo "run.sh: plugin/build missing, run 'npm run build' first" >&2; exit 2; }
[ -d "$PLUGIN_DIR/node_modules/@playwright/test" ] || { echo "run.sh: run 'npm ci' in plugin/ first" >&2; exit 2; }

finish() {
	local code=$?
	if [ "${X7VELO_E2E_DESTROY:-0}" = 1 ]; then
		wp_env destroy --force >/dev/null 2>&1 || true
	elif [ "${X7VELO_E2E_KEEP:-0}" != 1 ]; then
		wp_env stop >/dev/null 2>&1 || true
	fi
	exit "$code"
}
trap finish EXIT

log "start wp-env on :${WP_ENV_PORT} (PHP ${WP_ENV_PHP_VERSION:-8.1 default})"
start=$SECONDS
wp_env start 2>&1 | tail -n 3
wp_env reset development 2>&1 | tail -n 1

log "base state"
wp rewrite structure '/%postname%/' --hard >/dev/null
wp plugin deactivate --all >/dev/null || true
wp plugin activate x7velo
echo "PHP $(wp eval 'echo PHP_VERSION;') · WP $(wp core version) · WooCommerce $(wp plugin get woocommerce --field=version) · Yoast $(wp plugin get wordpress-seo --field=version)"
# Fresh logs: everything after this line is attributable to this run.
wp eval 'foreach ( array( "debug.log", "x7velo-http-sentinel.log" ) as $f ) { @unlink( WP_CONTENT_DIR . "/$f" ); } echo "logs cleared\n";'
rm -rf "$ARTIFACTS" && mkdir -p "$ARTIFACTS" "$PLUGIN_DIR/tests/e2e/.auth"

returns_page=0
ensure_store() {
	wp plugin activate woocommerce >/dev/null
	wp option update woocommerce_coming_soon no >/dev/null
	wp transient delete _wc_activation_redirect >/dev/null || true
	wp eval-file wp-content/plugins/x7velo/tests/e2e/fixtures/seed-tiny.php
	returns_page="$(wp option get e2e_x7velo_returns_page)"
}

playwright() {
	local project=$1
	docker run --rm --network host --ipc=host \
		--user "$(id -u):$(id -g)" -e HOME=/tmp -e CI="${CI:-}" \
		-e X7VELO_E2E_BASE_URL="$BASE_URL" -e X7VELO_E2E_PHASE="$project" \
		-e X7VELO_E2E_RETURNS_PAGE="$returns_page" \
		-v "$PLUGIN_DIR":/work -w /work "$PW_IMAGE" \
		npx playwright test -c tests/e2e/playwright.config.ts --project="$project"
}

failed=0
for phase in "${PHASES[@]}"; do
	log "phase: $phase"
	case "$phase" in
		plain)
			wp plugin deactivate woocommerce wordpress-seo >/dev/null 2>&1 || true
			;;
		woo)
			wp plugin deactivate wordpress-seo >/dev/null 2>&1 || true
			ensure_store
			;;
		yoast)
			ensure_store
			wp plugin activate wordpress-seo >/dev/null
			# Skip Yoast's first-run redirect so the admin pages under test load directly.
			wp eval '$o = get_option( "wpseo" ); if ( is_array( $o ) ) { $o["should_redirect_after_install_free"] = false; update_option( "wpseo", $o ); }' >/dev/null || true
			;;
		*) echo "unknown phase: $phase" >&2; exit 2 ;;
	esac
	# No stored audit: the first visit runs it (AC 80) in the mode of this phase.
	wp option delete x7velo_last_audit >/dev/null 2>&1 || true
	wp plugin list --fields=name,status --status=active --format=csv | tail -n +2 | tr '\n' ' '; echo
	playwright "$phase" || failed=1
done

log "guard R12/AC 88: outbound-HTTP sentinel"
sentinel="$(wp eval '$f = WP_CONTENT_DIR . "/x7velo-http-sentinel.log"; echo is_readable( $f ) ? file_get_contents( $f ) : "";')"
if [ -n "$sentinel" ]; then
	echo "FAIL: x7velo attempted outbound HTTP:"; printf '%s\n' "$sentinel" | sed 's/^/  /'
	failed=1
else
	echo "PASS: no outbound HTTP from x7velo"
fi
printf '%s' "$sentinel" > "$ARTIFACTS/http-sentinel.log"

log "guard G9/R11: debug.log"
wp eval '$f = WP_CONTENT_DIR . "/debug.log"; echo is_readable( $f ) ? file_get_contents( $f ) : "";' > "$ARTIFACTS/debug.log"
"$PLUGIN_DIR/tests/g9/scan-debug-log.sh" "$ARTIFACTS/debug.log" || failed=1

log "done in $((SECONDS - start)) s; artifacts in tests/e2e/artifacts/ (report: <phase>/report)"
[ "$failed" = 0 ] && echo "RESULT: PASS" || echo "RESULT: FAIL"
exit "$failed"
