#!/usr/bin/env bash
# x7velo performance gate (AC 90, test plan §4.1/§4.3): audit of a deterministic 2,000-product
# store in the shared-host profile must finish in < 10 s (median of 3 REST runs).
#
# Profile: PHP 8.1, memory_limit 128M (WP_MEMORY_LIMIT/WP_MAX_MEMORY_LIMIT), WP_DEBUG off, no
# persistent object cache, DISABLE_WP_CRON, WordPress + MySQL + CLI containers capped at 1 CPU
# (docker update, this env's containers only). Audit through REST POST /x7velo/v1/audit as the
# admin (cookie + nonce); one extra WP-CLI in-process run for retained memory.
#
# Usage: plugin/tests/perf/run.sh [count] [seed]      (defaults 2000 42)
# Env:   WP_ENV_PORT (default 8894; CI 8906), X7VELO_PERF_BUDGET_S (default 10),
#        X7VELO_PERF_KEEP=1 leave the env running, X7VELO_PERF_DESTROY=1 destroy it (CI),
#        X7VELO_PERF_LOG=<file> markdown log to append to (default docs/qa/perf-log.md when the
#        repo checkout has it; set to "" to skip).
# SC2016: PHP in single quotes is intentional. SC2317: finish() runs from the EXIT trap.
# shellcheck disable=SC2016,SC2317
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
REPO_DIR="$(cd "$PLUGIN_DIR/.." && pwd)"
ENV_DIR="$PLUGIN_DIR/tests/perf/env"
WP_ENV_VERSION="${WP_ENV_VERSION:-12.1.0}"
export WP_ENV_PORT="${WP_ENV_PORT:-8894}"
BASE="http://localhost:${WP_ENV_PORT}"
COUNT="${1:-2000}"
SEED="${2:-42}"
BUDGET="${X7VELO_PERF_BUDGET_S:-10}"
PERF_LOG="${X7VELO_PERF_LOG-$REPO_DIR/docs/qa/perf-log.md}"
WORK="$(mktemp -d)"

wp_env() { (cd "$ENV_DIR" && npx --yes "@wordpress/env@${WP_ENV_VERSION}" "$@"); }
wp() { wp_env run cli wp "$@" 2>/dev/null; }
log() { printf '\n== %s\n' "$*"; }

finish() {
	local code=$?
	rm -rf "$WORK"
	if [ "${X7VELO_PERF_DESTROY:-0}" = 1 ]; then
		wp_env destroy --force >/dev/null 2>&1 || true
	elif [ "${X7VELO_PERF_KEEP:-0}" != 1 ]; then
		wp_env stop >/dev/null 2>&1 || true
	fi
	exit "$code"
}
trap finish EXIT

log "start perf wp-env on :${WP_ENV_PORT}"
wp_env start 2>&1 | tail -n 2
wp_env reset development 2>&1 | tail -n 1
wp rewrite structure '/%postname%/' --hard >/dev/null
wp plugin activate woocommerce x7velo >/dev/null
wp option update woocommerce_coming_soon no >/dev/null
wp eval '@unlink( WP_CONTENT_DIR . "/x7velo-perf.log" ); @unlink( WP_CONTENT_DIR . "/x7velo-http-sentinel.log" );'
versions="PHP $(wp eval 'echo PHP_VERSION;') · WP $(wp core version) · WC $(wp plugin get woocommerce --field=version)"
echo "$versions"

log "generate ${COUNT} products (seed ${SEED})"
gen_start=$SECONDS
wp eval-file wp-content/plugins/x7velo/tests/perf/generate.php "$COUNT" "$SEED" | tail -n 1
gen_s=$((SECONDS - gen_start))
products="$(wp post list --post_type=product --post_status=publish --format=count)"
echo "published products: $products (generated in ${gen_s} s)"

log "shared-host profile: 1 CPU per container"
web="$(docker ps --filter "publish=${WP_ENV_PORT}" --format '{{.Names}}' | head -n 1)"
prefix="${web%-wordpress-1}"
for c in "$web" "${prefix}-mysql-1" "${prefix}-cli-1"; do
	docker update --cpus 1 "$c" >/dev/null && echo "capped $c"
done

log "REST login"
jar="$WORK/cookies"
curl -fsS -o /dev/null -c "$jar" -b 'wordpress_test_cookie=WP%20Cookie%20check' \
	--data-urlencode log=admin --data-urlencode pwd=password --data-urlencode wp-submit='Log In' \
	--data-urlencode testcookie=1 "$BASE/wp-login.php"
nonce="$(curl -fsS -b "$jar" "$BASE/wp-admin/admin-ajax.php?action=rest-nonce")"
if [ -z "$nonce" ] || [ "$nonce" = 0 ]; then echo "could not get a REST nonce" >&2; exit 1; fi

rest_audit() {
	curl -fsS -o "$WORK/audit.json" -w '%{time_total}' -X POST -b "$jar" -H "X-WP-Nonce: $nonce" \
		"$BASE/wp-json/x7velo/v1/audit"
}

log "audit via REST: 1 cold + 3 timed"
cold="$(rest_audit)"
echo "cold: ${cold} s"
times=()
for i in 1 2 3; do
	t="$(rest_audit)"
	times+=("$t")
	echo "run $i: ${t} s"
done
state="$(jq -r '.state' "$WORK/audit.json")"
score="$(jq -r '.score' "$WORK/audit.json")"
median="$(printf '%s\n' "${times[@]}" | sort -n | sed -n 2p)"
probe="$(wp eval 'echo file_get_contents( WP_CONTENT_DIR . "/x7velo-perf.log" );')"
echo "server probe (wall / peak per request):"; printf '%s\n' "$probe" | sed 's/^/  /'
peak="$(printf '%s\n' "$probe" | jq -s 'map(.peak_mb) | max')"
limit="$(printf '%s\n' "$probe" | jq -r -s '.[0].memory_limit')"

log "audit via WP-CLI (in-process, retained memory)"
cli_json="$(wp eval-file wp-content/plugins/x7velo/tests/perf/time-audit.php)"
echo "$cli_json"
cli_wall="$(jq -r .wall_s <<<"$cli_json")"
retained="$(jq -r .retained_mb <<<"$cli_json")"

log "sentinel (AC 88)"
sentinel="$(wp eval '$f = WP_CONTENT_DIR . "/x7velo-http-sentinel.log"; echo is_readable( $f ) ? file_get_contents( $f ) : "";')"
failed=0
if [ -n "$sentinel" ]; then echo "FAIL: outbound HTTP from x7velo:"; echo "$sentinel"; failed=1; else echo "PASS: none"; fi

verdict=PASS
awk -v m="$median" -v b="$BUDGET" 'BEGIN { exit !(m < b) }' || { verdict=FAIL; failed=1; }
[ "$state" = complete ] || { verdict=FAIL; failed=1; echo "audit state is $state, expected complete"; }

commit="$(git -C "$REPO_DIR" rev-parse --short HEAD 2>/dev/null || echo "${GITHUB_SHA:0:7}")"
row="| $(date -u +%Y-%m-%d) | \`${commit}\` | ${versions} | ${products} | ${median} s (runs ${times[*]}; cold ${cold}) | ${peak} MB / ${limit} | ${cli_wall} s / ${retained} MB | ${state}, score ${score} | ${gen_s} s | **${verdict}** (< ${BUDGET} s) |"
log "result"
echo "$row"
if [ -n "$PERF_LOG" ] && [ -f "$PERF_LOG" ]; then
	echo "$row" >> "$PERF_LOG" && echo "appended to $PERF_LOG"
fi
if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then
	{
		echo "### x7velo perf (AC 90)"
		echo "| Date | Commit | Versions | Products | REST audit median | Peak / limit | WP-CLI wall / retained | Record | Generate | Verdict |"
		echo "|---|---|---|---|---|---|---|---|---|---|"
		echo "$row"
	} >> "$GITHUB_STEP_SUMMARY"
fi
exit "$failed"
