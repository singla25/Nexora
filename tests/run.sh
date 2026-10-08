#!/usr/bin/env bash
# Run PHP tests inside WordPress: tests/run.sh [tests/php/test-foo.php ...]
# With no args runs every tests/php/test-*.php. Exits non-zero if any file fails.
set -u
cd "$(dirname "$0")/.."
files=("$@"); [ ${#files[@]} -eq 0 ] && files=(tests/php/test-*.php)
rc=0
for f in "${files[@]}"; do
  echo "== $f"
  wp eval-file "$f" --path=. || rc=1
done
exit $rc
