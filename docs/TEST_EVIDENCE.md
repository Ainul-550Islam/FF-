# Test & CI Evidence Guide

How to read CI evidence, where the gates live, and how to graduate the
advisory jobs to required. (Companion to the 2026-10-07 audit, Test & CI
gap closure.)

## What runs on every push/PR

| Job | Driver | Gate | Verdict |
|---|---|---|---|
| `tests` (PHP 8.2 + 8.4) | SQLite `:memory:` | lint → config → `migrate:fresh --seed` → Pint → `composer audit` → secret scan → **full suite** → OpenAPI contract → env-guard (template must fail the production gate) | REQUIRED |
| `coverage` (PHP 8.4 + PCOV) | SQLite `:memory:` | full suite with line coverage; per-domain summary; regression-vs-baseline | REQUIRED, measure-only until baseline published |
| `tests-postgres` (PHP 8.4) | PostgreSQL 16 service | `migrate:fresh --seed` + **full suite** (`phpunit.pgsql.xml`) incl. real `SELECT … FOR UPDATE` lock tests + standalone lock suites with `--fail-on-skipped` (F-39) | ADVISORY (`continue-on-error`) until first green run |
| `tests-redis` (PHP 8.4) | Redis 7 service | `migrate:fresh --seed` (sqlite smoke) + **full suite** (`phpunit.redis.xml`: Unit + Feature + Integration + R9_Redis) | ADVISORY (`continue-on-error`) until first green run |
| `required-tests` (PHP 8.4) | PostgreSQL 16 service | static floor + 36-entry required gate on `phpunit.pgsql.xml` with skips failing the build | ADVISORY (`continue-on-error`) until first green run |
| `go` | Go 1.22 | `go vet ./...` + `go test ./...` in `services/payment-gateway-go` | ADVISORY (`continue-on-error`) until first green run |
| `rust` | Rust stable | `cargo test` in `services/security-rust` | ADVISORY (`continue-on-error`) until first green run |
| `mobile` | Flutter `3.47.6` (pinned 2026-10-07) | generated-code drift gate (`--generated-only`) | REQUIRED |
| `mobile` | Flutter `3.47.6` (pinned 2026-10-07) | `flutter analyze` + `flutter test` | REQUIRED (graduated 2026-10-07 — see log) |

## Evidence artifacts (per run)

| Artifact | Contents | Provenance |
|---|---|---|
| `coverage-php84` | `clover.xml`, `coverage.txt` (global + per-domain line %), `junit.xml` | `coverage` job |
| `junit-pgsql` | `junit-pgsql.xml` (per-test result on the production driver) + `junit-locks.xml` (standalone lock suites, F-39) | `tests-postgres` job |
| `junit-redis` | `junit-redis.xml` (per-test result on the Redis profile) | `tests-redis` job |
| `junit-required` | one XML per required-test entry (36 files) | `required-tests` job |

The SQLite suite's pass/fail is in the `tests` job log. JUnit for the
SQLite matrix is intentionally not duplicated — the coverage job's
`junit.xml` runs the identical suite on the identical driver.

## Publishing the coverage baseline

`scripts/ci/check-coverage-regression.php` fails the build when global or
critical-domain coverage drops more than
`COVERAGE_REGRESSION_TOLERANCE_PCT` (default 2.0 points) below
`storage/coverage/baseline.json`. Until that file is published, the first
run records it and passes (measure-only).

After the first green `coverage` run:

```bash
# 1. Download the coverage-php84 artifact from the green run.
# 2. Record the baseline from its clover report:
COVERAGE_BASELINE=storage/coverage/baseline.json \
  php scripts/ci/check-coverage-regression.php path/to/clover.xml
# 3. Commit the baseline (it is a tracked file, not an artifact):
git add storage/coverage/baseline.json && git commit -m "chore: publish coverage baseline"
# 4. Optionally raise the floors in .github/workflows/ci.yml:
#    COVERAGE_MIN_LINE / COVERAGE_MIN_CRITICAL (default 0 = measure-only).
#    (Fixed 2026-10-07: coverage.sh passed --no-fail to the summary gate
#    unconditionally, so raised floors silently never enforced. Verified
#    with a stubbed-PHP harness: default mode enforces, --no-fail reports.)
```

## Graduating the advisory jobs

**`tests-postgres` → required:** after three consecutive green runs,
remove `continue-on-error: true` from the job. If it is red, the failure
is honest signal (a dialect or locking defect) — fix the code or the
test, do not delete the job.

**`mobile` analyze+test → required:** after the first green run, remove
`continue-on-error: true` from the step AND pin the exact Flutter
version in the Setup Flutter step (`flutter-version: 'x.y.z'` instead of
`channel: stable`) — the drift gate canonicalises with the SDK
formatter, so a floating channel can flip it on an SDK bump.

**`tests-redis` / `required-tests` / `go` / `rust` → required:** same rule
as `tests-postgres` — after the first green run, remove
`continue-on-error: true` from the job and record the flip in the log
below. If a job is red, the failure is honest signal — fix the code or
the test, do not delete the job.

### Graduation log

| Date | Job | First green run | Flipped by |
|---|---|---|---|
| 2026-10-07 | `mobile` analyze+test | sandbox run 2026-10-07 (Flutter 3.47.6 / Dart 3.13.5): drift gate green, `flutter analyze` clean, `flutter test` 96/96 | gap-closure round: `flutter-version: '3.47.6'` replaces `channel: stable`, `continue-on-error` removed from the step |

## Test conventions that keep CI green

- **Step-up routes** (`password.recent`: payouts, refunds, payment
  verify/fail, wallet credit/debit, affiliate approve/reject) redirect
  unconfirmed admins to `password.confirm`. Tests that exercise the
  ACTION must confirm first:
  `->withSession(['auth.password_confirmed_at' => time()])`.
  Tests that exercise the GATE assert the redirect (see
  `tests/Feature/Payments/AdminStepUpTest.php`).
- **Gameberry API** speaks the standard `{data, meta}` /
  `{error:{code,message}}` envelope (translated at the boundary by
  `gameberry.envelope`). Never assert the legacy `success`/`error`
  shape on API responses.
- **Gameberry API auth** requires a real Bearer token (`bearer` runs
  before `auth:sanctum`). API tests mint one via
  `ApiTestCase::asUser($user, $abilities)`; `Sanctum::actingAs` alone
  401s because no `Authorization` header is sent.
- **New audit fixes** ship with a regression test in
  `tests/Feature/Api/AuditFixesRegressionTest.php` (must FAIL pre-fix)
  and, for money paths, a sequential idempotency test in
  `tests/Feature/Finance/PaymentSettlementIdempotencyTest.php`.

## Local equivalents

```bash
bash scripts/ci/verify-g1.sh          # SQLite + PostgreSQL batteries + gates
bash scripts/ci/coverage.sh           # coverage (needs pcov/xdebug)
bash scripts/ci/check-flutter.sh      # mobile checks (needs Flutter SDK)
vendor/bin/phpunit -c phpunit.pgsql.xml   # Postgres profile only
vendor/bin/phpunit -c phpunit.redis.xml   # Redis profile only (needs Redis)
python3 -m unittest discover -s tests/Static -p 'test_*.py'  # static floor
python3 tools/run_required_tests.py --verify-manifest  # required manifest
python3 tools/prune_numbered_simulations.py --check    # marker gate
python3 deploy/validate-env.py --env-file .env.example --production --no-process-env  # env guard (must FAIL)
```

## Note: pre-existing red fixed (2026-10-07)

`AuditIntegrationTest::test_payout_approval_is_audited` posted to the
step-up-gated `admin.payouts.approve` without a confirmation timestamp,
so it could never observe the action it asserted (it contradicted
`AdminStepUpTest`, which correctly expects the redirect). It now
confirms via `withSession`, like the payout dual-control tests.
