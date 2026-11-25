#!/usr/bin/env bash
# These tests assert observed defects, NOT correct behavior or release readiness.
set -euo pipefail
cd "$(dirname "$0")/.."
PHP_BIN="${PHP_BIN:-php}"
OUTPUT="$(mktemp)"
trap 'rm -f "$OUTPUT"' EXIT
for test in tests/audit-rc-api.php tests/audit-rc-state.php tests/audit-rc-worker.php; do
  "$PHP_BIN" "$test" 2>&1 | tee "$OUTPUT"
  if grep -Eq 'Fatal error:|Parse error:|NOT REPRODUCED:|FAIL:' "$OUTPUT" || ! grep -Eq '^[0-9]+ (worker )?audit observations reproduced' "$OUTPUT"; then
    echo "Audit reproducer did not complete: $test" >&2
    exit 1
  fi
done
node --test commerce-ui/audit/known-issues.mjs
