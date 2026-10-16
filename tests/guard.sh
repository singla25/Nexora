#!/usr/bin/env bash
# Isolation guard: proves the whole suite does not depend on, or damage, the site's own settings.
#   1. saves the exact raw database rows of every option the suite touches, the real Marketplace Item and the vendor role,
#   2. sets hostile, non-default values (Fake Store enabled, category creation off, vendor APIs on, host allow-list, custom
#      categories, "coming soon" on, guest checkout on),
#   3. runs the full suite and expects 0 failures,
#   4. expects the suite to leave the hostile values exactly as they were,
#   5. puts the originals back (also on Ctrl+C or any error) and checks they are byte-for-byte identical.
# Usage: tests/guard.sh            (about 5 minutes on the live database; the live site briefly uses the values the tests set)
#        NX_TEST_DB=<copy> NX_SKIP_LIVE=1 tests/guard.sh     run it on a copy of the database (skips the real-web-request tests)
set -u
cd "$(dirname "$0")/.."

tmp="$(mktemp -d)"
if [ -n "${NX_TEST_DB:-}" ]; then
  printf "exec: \"define('DB_NAME','%s');\"\n" "${NX_TEST_DB}" > "$tmp/wp-cli.yml"
  export WP_CLI_CONFIG_PATH="$tmp/wp-cli.yml"
fi
tool() { NX_GUARD="$1" NX_GUARD_FILE="$2" wp eval-file tests/tools/guard-state.php --path=. 2>&1 | grep -v "Constant DB_NAME already defined"; return "${PIPESTATUS[0]}"; }

restored=0
restore() { if [ "$restored" -eq 0 ]; then tool restore "$tmp/before.json" >/dev/null; restored=1; fi; }
trap restore EXIT INT TERM

status=0
tool save "$tmp/before.json" || exit 2
tool hostile "$tmp/hostile.json" || exit 2

echo "== running the full suite with hostile settings"
log="$tmp/suite.log"
NX_TEST_DB="${NX_TEST_DB:-}" tests/run.sh > "$log" 2>&1
fails="$(grep -c '^  FAIL' "$log")"
passed="$(grep -E '^[0-9]+ passed' "$log" | awk '{p+=$1} END {print p+0}')"
echo "suite: $passed passed, $fails failed"
if [ "$fails" -ne 0 ]; then
  echo "failures per test file:"; awk '/^== /{f=$2} /^  FAIL/{c[f]++} END{for (k in c) print "  " c[k] "  " k}' "$log"
  grep '^  FAIL' "$log" | head -15 | cut -c1-220
  status=1
fi

echo "== the suite must leave the hostile values exactly as it found them"
tool compare "$tmp/hostile.json" || status=1

echo "== putting the originals back"
restore
tool compare "$tmp/before.json" || status=1

if [ "$status" -eq 0 ]; then echo "GUARD PASSED: 0 failures with hostile settings, originals exactly back"; else echo "GUARD FAILED"; fi
rm -rf "$tmp"
exit "$status"
