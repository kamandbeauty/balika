#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
PHP_BIN="${PHP_BIN:-php}"
for test in tests/regression.php tests/queue.php tests/bale-only.php tests/tracking.php tests/features.php tests/import.php tests/import-controller.php tests/commerce.php tests/support.php tests/settings-safety.php tests/overview.php tests/admin-link-reports.php; do
  "$PHP_BIN" "$test"
done
