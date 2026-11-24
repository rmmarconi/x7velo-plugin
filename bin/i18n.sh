#!/bin/sh
# Translation build for the x7velo plugin. Needs WP-CLI (`wp i18n`); run it from plugin/ or let
# `make i18n` / `make i18n-check` run it inside the wp-env tests-cli container.
#
#   bin/i18n.sh          Regenerate languages/x7velo.pot, merge it into every languages/*.po,
#                        then build .mo and the JS translation JSON (x7velo-<locale>-<md5>.json).
#   bin/i18n.sh check    Fail if languages/x7velo.pot is stale (strings, contexts, plurals or
#                        translator comments differ from the code). Source references (#:) and
#                        the creation date are ignored, so line-number churn never fails CI.
#
# JS strings are extracted from the sources in assets/src/, but WordPress looks up the JSON by the
# md5 of the script it actually enqueues (build/index.js, see Admin\AdminPage::enqueue()). Every
# source file is therefore mapped to build/index.js when the JSON is made (--use-map).
#
# POSIX sh on purpose: the wp-env CLI image is Alpine (no bash).
set -eu

cd "$(dirname "$0")/.."

DOMAIN=x7velo
LANG_DIR=languages
POT="$LANG_DIR/$DOMAIN.pot"
# Bundle WordPress loads; must match AdminPage::enqueue().
SCRIPT=build/index.js
EXCLUDE='build,tests,vendor,node_modules,data,languages,assets/src/__tests__,bin'

command -v wp >/dev/null 2>&1 || { echo "i18n: WP-CLI (wp) not found; use 'make i18n'" >&2; exit 1; }

make_pot() {
	wp i18n make-pot . "$1" \
		--slug="$DOMAIN" \
		--domain="$DOMAIN" \
		--exclude="$EXCLUDE" \
		--headers='{"Report-Msgid-Bugs-To":"https://wordpress.org/support/plugin/x7velo/","Language-Team":"","Last-Translator":""}' \
		--allow-root
}

# Comparable form of a POT: no references, no creation date, no version (the release bump changes it).
normalise() {
	grep -v -e '^#: ' -e '^"Project-Id-Version: ' -e '^"POT-Creation-Date: ' -e '^"X-Generator: ' "$1"
}

if [ "${1:-}" = "check" ]; then
	tmp="$(mktemp)"
	trap 'rm -f "$tmp" "$tmp.a" "$tmp.b"' EXIT
	make_pot "$tmp" >/dev/null
	normalise "$tmp" >"$tmp.a"
	normalise "$POT" >"$tmp.b"
	if ! diff -u "$tmp.b" "$tmp.a"; then
		echo "i18n: $POT is stale. Run 'make i18n' and commit languages/." >&2
		exit 1
	fi
	echo "i18n: $POT is up to date."
	exit 0
fi

mkdir -p "$LANG_DIR"
make_pot "$POT"

# Map every JS source file to the enqueued bundle for make-json.
map="$(mktemp)"
trap 'rm -f "$map"' EXIT
{
	printf '{'
	sep=''
	for f in $(find assets/src -name '*.js' -not -path '*/__tests__/*' | sort); do
		printf '%s"%s":"%s"' "$sep" "$f" "$SCRIPT"
		sep=','
	done
	printf '}\n'
} >"$map"

found=0
for po in "$LANG_DIR"/"$DOMAIN"-*.po; do
	[ -f "$po" ] || continue
	found=1
	wp i18n update-po "$POT" "$po" --allow-root
done

if [ "$found" = 1 ]; then
	wp i18n make-mo "$LANG_DIR" --allow-root
	# Replace, not accumulate: a stale JSON for an old bundle path would never be loaded anyway.
	rm -f "$LANG_DIR"/"$DOMAIN"-*.json
	wp i18n make-json "$LANG_DIR" --no-purge --use-map="$map" --allow-root
fi

echo "i18n: done."
ls -l "$LANG_DIR"
