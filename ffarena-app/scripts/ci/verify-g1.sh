#!/usr/bin/env bash
# Phase 19/G1 — full dual-driver verification battery (local/CI).
#
# Runs the same checks as the CI `tests` (SQLite) and `tests-postgres`
# (PostgreSQL) jobs in one pass:
#   - PHP extension check (pdo_sqlite + pdo_pgsql)
#   - PHP lint sweep (app, bootstrap, config, database, routes, tests)
#   - SQLite:      migrate:fresh --seed + full suite (php artisan test)
#   - PostgreSQL:  migrate:fresh --seed + full suite (phpunit -c phpunit.pgsql.xml)
#   - Pint (scoped gate), composer validate + audit, secret scan, OpenAPI
#
# Requirements: PHP 8.4 with pdo_sqlite + pdo_pgsql; a reachable PostgreSQL
# whose connection is given by DB_HOST/DB_PORT/DB_DATABASE/DB_USERNAME/
# DB_PASSWORD (defaults to the local ffarena_test cluster from bootstrap.sh).
# Exits non-zero on the first-failing group summary.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

PG_HOST="${DB_HOST:-127.0.0.1}"
PG_PORT="${DB_PORT:-5432}"
PG_DB="${DB_DATABASE:-ffarena_test}"
PG_USER="${DB_USERNAME:-ffarena}"
PG_PASS="${DB_PASSWORD:-ffarena}"
export PG_ENV="DB_CONNECTION=pgsql DB_HOST=${PG_HOST} DB_PORT=${PG_PORT} DB_DATABASE=${PG_DB} DB_USERNAME=${PG_USER} DB_PASSWORD=${PG_PASS}"

COMPOSER="$(command -v composer || true)"
[ -z "$COMPOSER" ] && COMPOSER="php /home/user/composer.phar"

PASS=0
FAIL=0
ok() { echo "  [PASS] $1"; PASS=$((PASS + 1)); }
bad() { echo "  [FAIL] $1"; FAIL=$((FAIL + 1)); }
step() { printf '\n=== %s ===\n' "$1"; }

php artisan config:clear >/dev/null 2>&1 || true

# 1. Extensions
step "PHP extensions"
php -m | grep -qi pdo_pgsql && ok "pdo_pgsql loaded" || bad "pdo_pgsql missing"
php -m | grep -qi pdo_sqlite && ok "pdo_sqlite loaded" || bad "pdo_sqlite missing"

# 2. Lint
step "PHP lint sweep"
if find app bootstrap config database routes tests -name '*.php' -print0 \
     | xargs -0 -n1 php -l > /tmp/g1-lint.log 2>&1; then
  ok "php -l clean across $(grep -c 'No syntax errors' /tmp/g1-lint.log) files"
else
  bad "php -l errors"; grep -v 'No syntax errors' /tmp/g1-lint.log | head -5
fi

# 3. SQLite
step "SQLite — migrate:fresh --seed"
if php artisan migrate:fresh --seed --force > /tmp/g1-sqlite-migrate.log 2>&1; then
  ok "migrate + seed"
else
  bad "migrate + seed"; tail -5 /tmp/g1-sqlite-migrate.log
fi

step "SQLite — full test suite (php artisan test)"
if php artisan test > /tmp/g1-sqlite-test.log 2>&1; then
  ok "$(grep -E 'Tests:' /tmp/g1-sqlite-test.log | tail -1 | sed 's/^ *//')"
else
  bad "SQLite suite"; tail -20 /tmp/g1-sqlite-test.log
fi

# 4. PostgreSQL
step "PostgreSQL — migrate:fresh --seed"
if env $PG_ENV php artisan migrate:fresh --seed --force > /tmp/g1-pg-migrate.log 2>&1; then
  ok "migrate + seed"
else
  bad "migrate + seed"; tail -5 /tmp/g1-pg-migrate.log
fi

step "PostgreSQL — full test suite (phpunit -c phpunit.pgsql.xml)"
if env $PG_ENV php vendor/bin/phpunit -c phpunit.pgsql.xml > /tmp/g1-pg-test.log 2>&1; then
  ok "$(grep -E 'Tests:' /tmp/g1-pg-test.log | tail -1 | sed 's/^ *//')"
else
  bad "PostgreSQL suite"; tail -25 /tmp/g1-pg-test.log
fi

# 5. Static checks
step "Pint (scoped gate)"
if bash scripts/ci/check-pint.sh > /tmp/g1-pint.log 2>&1; then
  ok "pint pass"
else
  bad "pint"; tail -5 /tmp/g1-pint.log
fi

step "composer validate --strict"
if $COMPOSER validate --no-check-publish --strict > /tmp/g1-cv.log 2>&1; then
  ok "composer.json valid"
else
  bad "composer validate"; tail -3 /tmp/g1-cv.log
fi

step "composer audit"
if $COMPOSER audit --no-interaction > /tmp/g1-ca.log 2>&1; then
  ok "no security advisories"
else
  bad "composer audit"; tail -3 /tmp/g1-ca.log
fi

step "secret scan"
if bash scripts/ci/scan-secrets.sh > /tmp/g1-secrets.log 2>&1; then
  ok "no committed secrets"
else
  bad "secret scan"; tail -3 /tmp/g1-secrets.log
fi

step "OpenAPI validation"
if bash scripts/ci/check-openapi.sh > /tmp/g1-openapi.log 2>&1; then
  ok "$(grep -oE '[0-9]+ documented paths' /tmp/g1-openapi.log | tail -1)"
else
  bad "OpenAPI"; tail -3 /tmp/g1-openapi.log
fi

printf '\n=== G1 verification summary: %d passed, %d failed ===\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
