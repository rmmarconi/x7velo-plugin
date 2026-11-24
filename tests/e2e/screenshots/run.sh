#!/usr/bin/env bash
# Re-shoot the WordPress.org screenshots (plugin/.wordpress-org/screenshot-N[-pt_BR].png) on a
# throwaway "Northwind Ceramics" demo store. Test-only; see plugin/.wordpress-org/README.md ("Re-shooting").
#
# Usage (Linux host with Docker and Node; the dev VM works), from the repo root:
#   plugin/tests/e2e/screenshots/run.sh <x7velo.zip>        build env, shoot en + pt_BR, tear down
# Env: SHOTS_PORT (default 8930; the TLS proxy listens on SHOTS_PORT+1 on 127.0.0.1),
#      SHOTS_KEEP=1 (leave the site running), SHOTS_LOCALES (default "en pt_BR").
# Output: plugin/tests/e2e/screenshots/out/ (gitignored); copy reviewed images to .wordpress-org/.
# SC2016: PHP in single quotes is intentional.
# shellcheck disable=SC2016
set -euo pipefail

ZIP="$(realpath "${1:?usage: run.sh <x7velo.zip>}")"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$HERE/../../.." && pwd)"
HOST=northwind-ceramics.test
PORT="${SHOTS_PORT:-8930}"
TLS_PORT=$((PORT + 1))
WORK="${SHOTS_WORK:-$HOME/x7velo-shots-env}"
PW_IMAGE="${PW_IMAGE:-mcr.microsoft.com/playwright:v1.65.1-noble}"
OUT="$HERE/out"
PROXY=x7velo-shots-tls

wp_env() { (cd "$WORK" && npx --yes @wordpress/env@12.1.0 "$@"); }
wp() { wp_env run cli wp "$@" 2>/dev/null; }

teardown() {
	docker rm -f "$PROXY" >/dev/null 2>&1 || true
	wp_env destroy --force >/dev/null 2>&1 || true
	rm -rf "$WORK"
}
[ "${SHOTS_KEEP:-0}" = 1 ] || trap teardown EXIT

rm -rf "$WORK" && mkdir -p "$WORK/rel" "$WORK/tls" "$OUT"
(cd "$WORK/rel" && unzip -q "$ZIP")
cat > "$WORK/.wp-env.json" <<EOF
{
	"core": null, "phpVersion": "8.3", "port": $PORT, "testsEnvironment": false, "plugins": [],
	"mappings": {
		"wp-content/plugins/x7velo": "./rel/x7velo",
		"wp-content/plugins/woocommerce": "https://downloads.wordpress.org/plugin/woocommerce.latest-stable.zip",
		"wp-content/mu-plugins": "$HERE/mu-plugins",
		"wp-content/x7velo-demo": "$HERE"
	},
	"config": { "WP_DEBUG": false, "WP_ENVIRONMENT_TYPE": "production", "DISABLE_WP_CRON": true,
		"AUTOMATIC_UPDATER_DISABLED": true }
}
EOF
wp_env start 2>&1 | tail -n 2
# wp-env always appends the port to WP_HOME; the demo store lives on https://$HOST (TLS proxy).
wp config set WP_HOME "https://$HOST" >/dev/null
wp config set WP_SITEURL "https://$HOST" >/dev/null
wp rewrite structure '/%postname%/' --hard >/dev/null
wp plugin deactivate --all >/dev/null || true
wp plugin activate woocommerce x7velo >/dev/null
wp transient delete _wc_activation_redirect >/dev/null || true
wp option update timezone_string America/Los_Angeles >/dev/null
wp user update admin --display_name="Store Owner" --first_name=Store --last_name=Owner \
	--nickname="Store Owner" --user_email="owner@$HOST" --skip-email >/dev/null
wp eval-file wp-content/x7velo-demo/seed-demo-store.php
wp language core install pt_BR >/dev/null
wp language plugin install woocommerce pt_BR >/dev/null

openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj "/CN=$HOST" -addext "subjectAltName=DNS:$HOST" \
	-keyout "$WORK/tls/key.pem" -out "$WORK/tls/cert.pem" 2>/dev/null
cat > "$WORK/tls/nginx.conf" <<EOF
events {}
http { server {
	listen 127.0.0.1:$TLS_PORT ssl; server_name $HOST;
	ssl_certificate /tls/cert.pem; ssl_certificate_key /tls/key.pem;
	location / { proxy_pass http://127.0.0.1:$PORT; proxy_set_header Host $HOST;
		proxy_set_header X-Forwarded-Proto https; proxy_redirect off; }
} }
EOF
docker rm -f "$PROXY" >/dev/null 2>&1 || true
docker run -d --name "$PROXY" --network host -v "$WORK/tls:/tls:ro" \
	-v "$WORK/tls/nginx.conf:/etc/nginx/nginx.conf:ro" nginx:1.27-alpine >/dev/null

shoot() {
	docker run --rm --network host --ipc=host --user "$(id -u):$(id -g)" -e HOME=/tmp \
		-e SHOTS_PROXY="127.0.0.1:$TLS_PORT" -e SHOTS_RETURNS_TITLE="$returns_title" \
		-v "$PLUGIN_DIR":/work -w /work "$PW_IMAGE" \
		node tests/e2e/screenshots/shots.mjs /work/tests/e2e/screenshots/out "$@"
}

for locale in ${SHOTS_LOCALES:-en pt_BR}; do
	suffix=""; wp_locale=en_US
	[ "$locale" = en ] || { suffix="-$locale"; wp_locale="$locale"; }
	echo "== $locale"
	# English first: the pt_BR pass localizes the store (names, address, BRL) and doesn't undo it.
	[ "$locale" != pt_BR ] || wp eval-file wp-content/x7velo-demo/localize-pt_BR.php
	returns_title="$(wp post get "$(wp option get x7velo_demo_returns_page)" --field=post_title)"
	wp site switch-language "$wp_locale" >/dev/null 2>&1 || wp option update WPLANG "${wp_locale/en_US/}" >/dev/null
	wp user meta delete admin locale >/dev/null || true
	for o in x7velo_settings x7velo_last_audit; do wp option delete "$o" >/dev/null || true; done
	wp eval '@unlink( ABSPATH . "robots.txt" );' >/dev/null
	shoot "$suffix" main
	# A typical hand-made robots.txt that only lets the big search engines in.
	wp eval 'file_put_contents( ABSPATH . "robots.txt", "User-agent: Googlebot\nAllow: /\n\nUser-agent: Bingbot\nAllow: /\n\nUser-agent: *\nDisallow: /\n" );' >/dev/null
	shoot "$suffix" robots
	wp eval '@unlink( ABSPATH . "robots.txt" );' >/dev/null
done

# Lossless optimisation and metadata strip.
docker run --rm -v "$OUT":/o alpine:3.20 sh -c 'apk add -q optipng >/dev/null && optipng -quiet -o2 -strip all /o/screenshot-*.png && chown '"$(id -u):$(id -g)"' /o/screenshot-*.png'
ls -la "$OUT"
