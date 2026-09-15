#!/usr/bin/env bash
# G3 — coverage engine (PCOV) + threshold gate.
#
#   bash scripts/ci/coverage.sh           run coverage + enforce thresholds
#   bash scripts/ci/coverage.sh --no-fail run coverage, report only (no gate)
#
# Produces storage/coverage/{html,clover.xml,coverage.txt} and enforces:
#   * COVERAGE_MIN_LINE      (default 0 — first run only measures; CI sets a
#                            real number after the baseline is published)
#   * COVERAGE_MIN_CRITICAL  (default 0 — same policy)
#
# The coverage regression gate (check-coverage-regression.php) keeps a stored
# baseline in storage/coverage/baseline.json and fails on drops larger than
# COVERAGE_REGRESSION_TOLERANCE_PCT (default 2.0 percentage points).
#
# Driver policy: PCOV is preferred. Xdebug is accepted as a fallback. If
# neither is loaded the script REFUSES to report coverage — it never invents
# numbers.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

NO_FAIL=0
for arg in "$@"; do
  [ "$arg" = "--no-fail" ] && NO_FAIL=1
done

if php -m | grep -qi '^pcov$'; then
  DRIVER="pcov"
elif php -m | grep -qi '^xdebug$'; then
  DRIVER="xdebug"
  export XDEBUG_MODE=coverage
else
  echo "ERROR: no coverage driver loaded (need pcov or xdebug)." >&2
  echo "       Install with: sudo apt-get install php8.4-pcov   (Debian/Ubuntu)" >&2
  echo "       Coverage has NOT been measured; refusing to report a number." >&2
  exit 1
fi

echo "=== coverage driver: ${DRIVER} ==="

mkdir -p storage/coverage
rm -rf storage/coverage/html
rm -f storage/coverage/clover.xml storage/coverage/coverage.txt

php artisan config:clear >/dev/null 2>&1 || true

echo "=== running PHPUnit with coverage (SQLite, full suite) ==="
php -d pcov.enabled=1 -d pcov.directory=app \
  vendor/bin/phpunit -c phpunit.coverage.xml

echo ""
echo "=== coverage summary ==="
php scripts/ci/coverage-summary.php storage/coverage/clover.xml ${NO_FAIL:+--no-fail}

echo ""
echo "=== coverage regression gate ==="
COVERAGE_BASELINE="${COVERAGE_BASELINE:-storage/coverage/baseline.json}" \
COVERAGE_REGRESSION_TOLERANCE_PCT="${COVERAGE_REGRESSION_TOLERANCE_PCT:-2.0}" \
php scripts/ci/check-coverage-regression.php storage/coverage/clover.xml

echo ""
echo "Reports: storage/coverage/{html,clover.xml,coverage.txt}"
