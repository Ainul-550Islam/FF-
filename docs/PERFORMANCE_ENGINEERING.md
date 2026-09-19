# FF Arena — Performance Engineering (G3)

This document is the engineering reference for the G3 performance layer. It
describes **how** we measure, what the current **measured baselines** are, what
we consider a regression, and how performance gates are wired into CI. The
operational "how to run a load test" companion is
[`LOAD_TEST_RUNBOOK.md`](LOAD_TEST_RUNBOOK.md); the consolidated findings,
results and file inventory live in
[`G3_COVERAGE_LOAD_PERFORMANCE_REPORT.md`](../G3_COVERAGE_LOAD_PERFORMANCE_REPORT.md).

> **Honesty policy.** Every number in this document was produced by running the
> tooling on the dedicated non-production datastore. We never invent a
> percentage, never fabricate a latency, and never claim capacity we have not
> measured. Where a metric cannot be measured here (production capacity,
> branch/function coverage), it is stated as such.

---

## 1. Measurement stack

| Concern | Tool | Where |
|---|---|---|
| Code coverage (line) | PCOV (CI), Xdebug (local fallback) | `scripts/ci/coverage.sh`, `phpunit.coverage.xml` |
| Coverage thresholds + domains | Clover parser | `scripts/ci/coverage-summary.php` |
| Coverage regression | baseline diff | `scripts/ci/check-coverage-regression.php` |
| DB query latency + plans | in-process harness | `scripts/perf/benchmark-db.php` |
| API endpoint latency | in-process HTTP kernel | `scripts/perf/benchmark-api.php` |
| Leaderboard paths | service vs HTTP | `scripts/perf/benchmark-leaderboard.php` |
| Registration throughput | single-worker loop | `scripts/perf/benchmark-registration.php` |
| Payment lifecycle + replay | single-worker loop | `scripts/perf/benchmark-payments.php` |
| Report generation | Markdown + summary | `scripts/perf/generate-report.php` |
| HTTP load / concurrency | k6 | `tests/load/k6-*.js` |
| True process-level races | PHPUnit + pcntl forks | `tests/Feature/Concurrency/*` |

All harness scripts share `scripts/perf/bootstrap.php`, which boots Laravel
identically to `artisan` and provides the shared percentile/sampling helpers
(`perf_sample`, `perf_percentiles`, `perf_save`, `perf_driver`, `perf_env_int`).

## 2. Environment matrix

Performance is measured on **two** datastore drivers so the production driver
and the CI driver are both characterised:

| Driver | Role |
|---|---|
| `sqlite` | local dev + fast coverage/CI environment |
| `pgsql` | production driver; authoritative for concurrency |

Switch drivers with `DB_CONNECTION=pgsql` (plus `DB_HOST/DB_PORT/DB_DATABASE/
DB_USERNAME/DB_PASSWORD`). Persist both runs side-by-side with
`PERF_TAG=pgsql` so one driver does not overwrite the other.

> **These numbers are from the development sandbox, not production hardware.**
> They characterise relative behaviour (SQLite vs PostgreSQL, endpoint vs
> endpoint, before vs after a change). Production capacity must be measured on
> production-like hardware — see §6.

## 3. Coverage policy (measured, not invented)

PCOV reports **line coverage only**. Branch and function coverage are reported
as `n/a` — never fabricated.

Current measured baseline (2026-09-14, SQLite, full suite):

| Metric | Value |
|---|---|
| Tests | 934 run, 2967 assertions, 14 skipped |
| Global line coverage | **77.92 %** (8801 / 11295 statements) |
| Lowest critical domain | **67.59 %** (payouts) |
| Branch / function | n/a (PCOV driver) |

Critical-domain coverage (line):

| Domain | Coverage |
|---|---:|
| registration | 93.75 % |
| notifications | 92.44 % |
| wallet-ledger | 92.42 % |
| security-anti-fraud | 90.21 % |
| disputes | 90.17 % |
| scoring | 89.75 % |
| auth | 86.76 % |
| api | 80.57 % |
| payments | 78.14 % |
| audit | 76.70 % |
| reconciliation | 67.75 % |
| payouts | 67.59 % |

### Thresholds (configurable, derived from the baseline)

The gates are **not** an arbitrary "90 % or bust". They are set a few points
below the measured baseline so a single new file or a moved line does not flake
every PR, while a material drop still fails:

| Env var | Default | Meaning |
|---|---|---|
| `COVERAGE_MIN_LINE` | 75 | global line-coverage floor (%) |
| `COVERAGE_MIN_CRITICAL` | 65 | floor for every critical domain (%) |
| `COVERAGE_REGRESSION_TOLERANCE_PCT` | 2.0 | allowed drop vs stored baseline (percentage points) |

Exit codes of `coverage-summary.php`: `0` pass · `1` no clover · `2` below
global floor · `3` critical domain below floor. `check-coverage-regression.php`
records `storage/coverage/baseline.json` on first run and fails on drops larger
than the tolerance thereafter.

Reports (HTML + Clover + text) are written to `storage/coverage/` and are
published as a CI artifact — never committed.

## 4. Query performance baseline

`scripts/perf/benchmark-db.php` measures the seven hot read queries with
percentiles and captures the planner output (`EXPLAIN ANALYZE` on PostgreSQL,
`EXPLAIN QUERY PLAN` on SQLite). p50 in milliseconds, n=100:

| Query | SQLite p50 | PostgreSQL p50 |
|---|---:|---:|
| tournaments list | 0.063 | 0.272 |
| teams by tournament | 0.120 | 0.721 |
| standings (scores GROUP BY team_id) | 0.067 | 0.330 |
| notifications inbox | 0.098 | 0.495 |
| audit log page | 0.046 | 0.257 |
| payments by status | 0.074 | 0.265 |
| webhook events | 0.046 | 0.234 |

### Query-plan findings

* **payments by status** — `Bitmap Index Scan on payments_status_index`
  (PostgreSQL). Index present and used. ✅
* **teams by tournament** — `Index Scan on teams_tournament_waitlisted_index`
  (PostgreSQL). Index present and used. ✅
* **standings aggregation** — `Seq Scan on scores` → `HashAggregate (Group Key:
  team_id)` → `Sort`. The `scores` table has unique `(match_id, team_id)` and
  `(match_id, placement)` indexes but **no standalone `scores(team_id)` index**,
  so the leaderboard aggregation scans the table. Instant at demo scale
  (0.030 ms over 24 rows) but the first index to add when score volume grows.

### N+1 audit

* `ScoringService::standings()` eager-loads teams (`->with('team')`) — no N+1
  on the team relation.
* It loads **all** scores for a tournament and aggregates in PHP. This is a
  deliberate single-pass design (one query, deterministic tie-breaking) but is
  O(all scores) in memory. Fine for ≤ hundreds of matches; flagged as a scaling
  boundary, not a bug.
* API resources (`TournamentResource`, `MatchResource`,
  `LeaderboardEntryResource`) load relations eagerly on the routes they serve;
  the `ApiCoverageTest` discovery/registration/me/wallet/notification surfaces
  all execute under the harness without new N+1 queries.

## 5. Endpoint latency baseline (in-process HTTP kernel)

`scripts/perf/benchmark-api.php` fires real requests through the Laravel kernel
(middleware, routing, controllers, DB) with a dedicated perf token. The harness
raises the API rate limit for the sample (configurable via `PERF_RATE_LIMIT`)
and reports throttled-429s separately so latency percentiles reflect the
application, not the limiter.

p50 in ms, n=50:

| Endpoint | SQLite | PostgreSQL |
|---|---:|---:|
| GET /health | 3.124 | 3.034 |
| GET /api/v1/tournaments | 5.349 | 8.085 |
| GET /api/v1/tournaments/{slug}/leaderboard | 4.100 | 7.336 |
| GET /api/v1/me (auth) | 3.776 | 4.156 |
| GET /api/v1/me/wallet (auth) | 3.507 | 3.694 |
| GET /api/v1/me/wallet/ledger (auth) | 3.978 | 4.394 |

`benchmark-leaderboard.php` (service vs HTTP), p50 ms:

| Path | SQLite | PostgreSQL |
|---|---:|---:|
| `ScoringService::standings()` | 2.052 | 3.393 |
| GET leaderboard (HTTP) | 7.240 | 8.966 |

`benchmark-registration.php` (single worker, 100 registrations):

| Metric | SQLite | PostgreSQL |
|---|---:|---:|
| `register()` p50 | 18.576 ms | 15.367 ms |
| throughput | 3.95 /s | 4.24 /s |
| failures / oversubscription | 0 / 0 | 0 / 0 |

`benchmark-payments.php` (single worker), p50 ms:

| Operation | SQLite | PostgreSQL |
|---|---:|---:|
| createForTeam | 2.972 | 3.019 |
| verifyManually | 6.357 | 7.864 |
| idempotent replay | 1.951 | 1.326 |

Payment replay invariant: **100 replays of one payment produced exactly 1
verified event** on both drivers — no fabricated success.

## 6. Production capacity model

We do **not** claim a production request-per-second number here — that would
require production-like hardware, which this sandbox is not. Instead the model
is:

