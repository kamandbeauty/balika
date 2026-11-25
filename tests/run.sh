#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
PHP_BIN="${PHP_BIN:-php}"
OUTPUT="$(mktemp)"
trap 'rm -f "$OUTPUT"' EXIT
for test in tests/regression.php tests/queue.php tests/bale-only.php tests/tracking.php tests/features.php tests/import.php tests/import-controller.php tests/commerce.php tests/support.php tests/settings-safety.php tests/overview.php tests/admin-link-reports.php tests/daily-delivery.php tests/security-hardening.php tests/order-scope.php tests/admin-destination.php tests/api-queue-contract.php tests/reply-producers.php tests/report-dates.php tests/admin-pair-persistence.php; do
  "$PHP_BIN" "$test" 2>&1 | tee "$OUTPUT"
  # Some WASM PHP runners return zero even after a PHP fatal error.
  if grep -Eq 'Fatal error:|Parse error:|FAIL:' "$OUTPUT" || ! grep -Eq '[0-9]+ checks passed' "$OUTPUT"; then
    echo "Test did not complete successfully: $test" >&2
    exit 1
  fi
done
