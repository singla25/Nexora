#!/usr/bin/env bash
# Run PHP tests inside WordPress: tests/run.sh [tests/php/test-foo.php ...]
# With no args runs every tests/php/test-*.php. Exits non-zero if any file fails.
#   NX_TEST_DB=<database>  run against a copy of the site's database instead of the live one. Every wp call, including the child
#                          processes some tests start, uses it (through a wp-cli config file).
#   NX_SKIP_LIVE=1         skip the tests that make real web requests (the web server only sees the live database).
set -u
cd "$(dirname "$0")/.."
if [ -n "${NX_TEST_DB:-}" ]; then
  cfg="$(mktemp)"
  printf "exec: \"define('DB_NAME','%s');\"\n" "${NX_TEST_DB}" > "$cfg"
  export WP_CLI_CONFIG_PATH="$cfg"
  trap 'rm -f "$cfg"' EXIT
fi
wp eval-file tests/purge.php --path=. 2>&1 | grep -v "Constant DB_NAME already defined" || true
files=("$@"); [ ${#files[@]} -eq 0 ] && files=(tests/php/test-*.php)
rc=0
for f in "${files[@]}"; do
  if [ -n "${NX_SKIP_LIVE:-}" ] && grep -q 'curl_init' "$f"; then echo "== $f (skipped: needs the live site)"; continue; fi
  echo "== $f"
  wp eval-file "$f" --path=. 2>&1 | grep -v "Constant DB_NAME already defined"
  [ "${PIPESTATUS[0]}" -ne 0 ] && rc=1
done
exit $rc
