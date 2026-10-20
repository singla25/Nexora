#!/usr/bin/env bash
# Run PHP tests inside WordPress: tests/run.sh [tests/php/test-foo.php ...]
# With no args runs every tests/php/test-*.php. Exits non-zero if any file fails.
#   NX_TEST_DB=<database>  run against a copy of the site's database instead of the live one. Every wp call, including the child
#                          processes some tests start, uses it (through a wp-cli config file).
#   NX_SKIP_LIVE=1         skip the tests that make real web requests (the web server only sees the live database).
set -u
cd "$(dirname "$0")/.."
# Refuse to touch the site's real database: tests run on a scratch copy named in NX_TEST_DB (tests/live-guard.php does the same in PHP).
live_db="$(sed -n "s/.*define( *['\"]DB_NAME['\"] *, *['\"]\([^'\"]*\)['\"].*/\1/p" wp-config.php | head -n 1)"
readonly_only=0
if [ -z "${NX_TEST_DB:-}" ] || [ -z "$live_db" ] || [ "${NX_TEST_DB,,}" = "${live_db,,}" ]; then
  if [ "${NX_ALLOW_LIVE_READONLY:-}" = "1" ] && [ -n "$live_db" ]; then
    readonly_only=1
  else
    echo "REFUSED: tests run only on a scratch copy of the database. Set NX_TEST_DB=<scratch database> (not the one in wp-config.php)." >&2
    exit 3
  fi
fi
if [ -n "${NX_TEST_DB:-}" ] && [ "$readonly_only" -eq 0 ]; then
  cfg="$(mktemp)"
  printf "exec: \"define('DB_NAME','%s');\"\n" "${NX_TEST_DB}" > "$cfg"
  export WP_CLI_CONFIG_PATH="$cfg"
  trap 'rm -f "$cfg"' EXIT
fi
files=("$@")
if [ "$readonly_only" -eq 1 ]; then
  # The override: only the tests listed in tests/live-readonly.txt, and no purge (it deletes).
  allowed="$(grep -v '^#' tests/live-readonly.txt | sed '/^[[:space:]]*$/d')"
  [ ${#files[@]} -eq 0 ] && for a in $allowed; do files+=("tests/php/$a"); done
  for f in "${files[@]}"; do
    if ! printf '%s\n' "$allowed" | grep -qx "$(basename "$f")"; then
      echo "REFUSED: $f is not in tests/live-readonly.txt, so it may not run on the live database." >&2
      exit 3
    fi
  done
else
  wp eval-file tests/purge.php --path=. 2>&1 | grep -v "Constant DB_NAME already defined" || true
  [ ${#files[@]} -eq 0 ] && files=(tests/php/test-*.php)
fi
rc=0
for f in "${files[@]}"; do
  if [ -n "${NX_SKIP_LIVE:-}" ] && grep -q 'curl_init' "$f"; then echo "== $f (skipped: needs the live site)"; continue; fi
  echo "== $f"
  wp eval-file "$f" --path=. 2>&1 | grep -v "Constant DB_NAME already defined"
  [ "${PIPESTATUS[0]}" -ne 0 ] && rc=1
done
exit $rc
