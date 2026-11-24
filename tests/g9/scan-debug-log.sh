#!/usr/bin/env bash
# G9 / R11 log hygiene: fail when a PHP log has any notice, warning, deprecation or error that
# comes from x7velo. "From x7velo" = the line names a file under plugins/x7velo/ (except our own
# tests/), or the message itself names x7velo (core's _doing_it_wrong notices, e.g. translations
# loaded too early for the "x7velo" domain, point at wp-includes, not at us).
#
# Usage: scan-debug-log.sh <log> [<log>...]   Missing files count as empty.
# Exit: 0 clean, 1 findings (printed), 2 usage.
set -euo pipefail

if [ "$#" -eq 0 ]; then
	echo "usage: $0 <debug.log> [...]" >&2
	exit 2
fi

levels='PHP (Notice|Warning|Deprecated|Fatal error|Parse error|Recoverable fatal error|Strict Standards|User Notice|User Warning|User Deprecated)'
found=0
for log in "$@"; do
	if [ ! -s "$log" ]; then
		echo "g9: $log: empty or missing (clean)"
		continue
	fi
	total=$(grep -cE "$levels" "$log" || true)
	hits=$(grep -E "$levels" "$log" | grep -E 'plugins/x7velo/|x7velo' | grep -vE 'plugins/x7velo/tests/' || true)
	if [ -n "$hits" ]; then
		n=$(printf '%s\n' "$hits" | wc -l | tr -d ' ')
		echo "g9: $log: $n line(s) from x7velo (of $total PHP notices/warnings/deprecations in the log):"
		printf '%s\n' "$hits" | sed 's/^/  /' | head -n 50
		found=1
	else
		echo "g9: $log: 0 lines from x7velo ($total PHP notices/warnings/deprecations from other code, ignored)"
	fi
done
exit "$found"
