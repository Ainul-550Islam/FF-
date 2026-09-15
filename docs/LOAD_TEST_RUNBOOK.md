# FF Arena — Load Test Runbook (G3)

How to run every load/concurrency/benchmark tool in this repository, safely.
The engineering reference (baselines, thresholds, SLOs) is in
[`PERFORMANCE_ENGINEERING.md`](PERFORMANCE_ENGINEERING.md).

---

## 0. Safety rules (non-negotiable)

1. **Never load-test production unless explicitly unlocked twice.** Every k6
   script refuses `APP_ENV=production` unless BOTH
   `ALLOW_PRODUCTION_LOAD_TEST=true` AND
   `CONFIRM_PRODUCTION_LOAD_TEST=I_UNDERSTAND` are set. One stray variable can
   never DDoS production.
2. **Never run payment load against a real production provider.** The k6
   payment script touches read surfaces only; payment *creation* load is done
   in-process by `benchmark-payments.php`, which never contacts a provider and
   never fabricates success.
3. **Load/concurrency tests never touch real production data.** They target a
   dedicated throwaway datastore (`DB_DATABASE=ffarena_test` for PostgreSQL, or
   a scratch SQLite file) and a dedicated test tournament.
4. **Credentials are never hardcoded.** Scripts read `BASE_URL`, `API_TOKEN`,
   `TEST_TOURNAMENT_ID`, `TEST_TEAM_ID`, `TEST_MATCH_ID` from the environment.

---

## 1. Prerequisites

```bash
bash /home/user/bootstrap.sh          # PHP 8.4 + pcov + PostgreSQL + k6
cd /home/user/ffarena-app
php /home/user/composer.phar install --no-interaction   # if vendor/ is missing
k6 version                            # v0.57.0 (also at /home/user/bin/k6)
php -m | grep -iE 'pcov|pgsql|sqlite3'
```

## 2. In-process performance harness (SQLite first, then PostgreSQL)

```bash
cd /home/user/ffarena-app

# SQLite (fast, CI-style)
php artisan migrate:fresh --seed --force
php scripts/perf/benchmark-db.php
php scripts/perf/benchmark-api.php
php scripts/perf/benchmark-leaderboard.php
php scripts/perf/benchmark-registration.php
php scripts/perf/benchmark-payments.php

# PostgreSQL comparison (side-by-side, tagged)
export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 \
       DB_DATABASE=ffarena_test DB_USERNAME=ffarena DB_PASSWORD=ffarena \
       PERF_TAG=pgsql
php artisan migrate:fresh --seed --force
php scripts/perf/benchmark-db.php
php scripts/perf/benchmark-api.php
php scripts/perf/benchmark-leaderboard.php
php scripts/perf/benchmark-registration.php
php scripts/perf/benchmark-payments.php

# Render the report
php scripts/perf/generate-report.php      # → storage/perf/REPORT.md + summary.json
```

Knobs: `PERF_ITERATIONS`, `PERF_REGISTRATIONS`, `PERF_RATE_LIMIT`, `PERF_QUIET=1`.

> `benchmark-leaderboard.php` needs at least one non-draft tournament; seed
> some scores if you want non-empty standings. The seeder provides the
> `squad-showdown-32-teams` tournament.

## 3. k6 load tests

All scripts share `BASE_URL` (default `http://127.0.0.1:8000`), `API_TOKEN`,
`TEST_TOURNAMENT_ID` and the double production-unlock gate.

### 3.1 Start a dedicated test server

```bash
cd /home/user/ffarena-app
php artisan migrate:fresh --seed --force

# Raise the API read rate limits for the synthetic single-IP run (test server
# only — a k6 runner shares one IP, so the 60/120 per-minute limits would
# otherwise throttle the run instead of exercising the application).
API_RATE_LIMIT_API=100000 API_RATE_LIMIT_API_ANON=100000 \
  php artisan serve --host=0.0.0.0 --port=8000
```

`artisan serve` is a **single** worker — good for correctness and relative
latency, not for capacity. Use php-fpm + nginx for capacity work.

### 3.2 The scripts

| Script | Purpose | Needs |
|---|---|---|
| `k6-smoke.js` | 3 VUs × 30 iters sanity | optional `TEST_TOURNAMENT_ID`, `API_TOKEN` |
| `k6-baseline.js` | ramping 1→20 VUs, read-heavy journey | `TEST_TOURNAMENT_ID` |
| `k6-spike.js` | 2→120 VUs in 10 s, hold, recover | `TEST_TOURNAMENT_ID` |
| `k6-stress.js` | 5→150 VUs staged to saturation | `TEST_TOURNAMENT_ID` |
| `k6-registration.js` | 10 VUs × 100 iters: register + idempotent replay | `API_TOKEN`, `TEST_TOURNAMENT_ID` |
| `k6-payment.js` | payment READ surfaces under load | `API_TOKEN` |
| `k6-leaderboard.js` | leaderboard read load | `TEST_TOURNAMENT_ID` |
| `k6-api-mixed.js` | weighted mixed user journey | optional `API_TOKEN`, `TEST_TOURNAMENT_ID` |

### 3.3 Commands

```bash
k6 run tests/load/k6-smoke.js        -e BASE_URL=http://127.0.0.1:8000 -e APP_ENV=testing -e TEST_TOURNAMENT_ID=squad-showdown-32-teams
k6 run tests/load/k6-baseline.js     -e BASE_URL=http://127.0.0.1:8000 -e APP_ENV=testing -e TEST_TOURNAMENT_ID=squad-showdown-32-teams
k6 run tests/load/k6-spike.js        -e BASE_URL=http://127.0.0.1:8000 -e APP_ENV=testing -e TEST_TOURNAMENT_ID=squad-showdown-32-teams
k6 run tests/load/k6-stress.js       -e BASE_URL=http://127.0.0.1:8000 -e APP_ENV=testing -e TEST_TOURNAMENT_ID=squad-showdown-32-teams
k6 run tests/load/k6-leaderboard.js  -e BASE_URL=http://127.0.0.1:8000 -e APP_ENV=testing -e TEST_TOURNAMENT_ID=squad-showdown-32-teams
k6 run tests/load/k6-payment.js      -e BASE_URL=http://127.0.0.1:8000 -e APP_ENV=testing -e API_TOKEN=<player-token>
k6 run tests/load/k6-api-mixed.js    -e BASE_URL=http://127.0.0.1:8000 -e APP_ENV=testing -e TEST_TOURNAMENT_ID=squad-showdown-32-teams
```

Persist results: append `--summary-export=/tmp/k6-<name>.json`.

### 3.4 Registration load — dedicated tournament + token

The registration script needs a tournament that accepts registration and a
player token with `tournaments:register`. One captain (token) can register
**once** — the script asserts the duplicate-captain 409 guard engages after the
first 201, and that the idempotency replay returns the stored team:

```bash
# One-time fixture (tinker-free):
php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\User; use App\Models\Tournament;
$org = User::firstOrCreate(["email"=>"load-org@ffarena.local"], ["name"=>"Load Organizer","password"=>bcrypt("load-only"),"email_verified_at"=>now()]);
$org->role="organizer"; $org->account_status="active"; $org->save();
$t = Tournament::firstOrCreate(["slug"=>"load-reg-tournament"], [
  "organizer_id"=>$org->id,"name"=>"Load Registration Tournament","game_mode"=>"squad",
  "map"=>"Bermuda","entry_fee"=>0,"prize_pool"=>5000,"team_slots"=>500,"team_size"=>4,
  "starts_at"=>now()->addDay(),"format"=>"single_elim","status"=>"open"]);
$p = User::firstOrCreate(["email"=>"load-player@ffarena.local"], ["name"=>"Load Player","password"=>bcrypt("load-only"),"email_verified_at"=>now()]);
$p->role="player"; $p->account_status="active"; $p->save();
echo $p->createToken("load", ["tournaments:register","tournaments:read","profile:read","wallet:read","payments:read","notifications:read"])->plainTextToken;
'

k6 run tests/load/k6-registration.js \
  -e BASE_URL=http://127.0.0.1:8000 -e APP_ENV=testing \
  -e API_TOKEN=<token> -e TEST_TOURNAMENT_ID=load-reg-tournament
```

Expected outcome: exactly 1×201 (pending), the rest 409 (duplicate captain),
0 errors, and every replay marked `Idempotency-Replayed: true`.

### 3.5 Interpreting results

* `http_req_failed` — non-2xx/3xx rate. Above ~0.5 % is a problem.
* `http_req_duration` p(95) — the latency SLO driver.
* `errors` / `server_errors` / `rate_limited` — the custom metrics added by the
  spike/stress scripts; 429s are the rate limiter doing its job, 5xx are bugs.
* A sharp p95 knee while VUs climb marks the saturation point of the server
  under test (see the G3 report for the single-worker knee we measured).

## 4. Concurrency PHP tests (PostgreSQL authoritative)

```bash
cd /home/user/ffarena-app

# SQLite: sequential replay of the same invariants (fast, CI)
php artisan test tests/Feature/Concurrency/

# PostgreSQL + pcntl: true process-level forks
export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 \
       DB_DATABASE=ffarena_test DB_USERNAME=ffarena DB_PASSWORD=ffarena
php artisan migrate:fresh --seed --force
php artisan test tests/Feature/Concurrency/ -c phpunit.pgsql.xml
```

The tests fork N real child processes (one per racer) over separate database
connections and assert the slot/idempotency/wallet/webhook invariants. SQLite
limitations are labelled honestly in the test output (`[note] … ran
sequentially — true parallel writers need pcntl + PostgreSQL`).

## 5. Coverage gate

```bash
cd /home/user/ffarena-app

# Measure + enforce (PCOV preferred, Xdebug fallback, refuses if neither)
COVERAGE_MIN_LINE=75 COVERAGE_MIN_CRITICAL=65 COVERAGE_REGRESSION_TOLERANCE_PCT=2.0 \
  bash scripts/ci/coverage.sh

# Individual pieces
php scripts/ci/coverage-summary.php storage/coverage/clover.xml
COVERAGE_BASELINE=storage/coverage/baseline.json php scripts/ci/check-coverage-regression.php storage/coverage/clover.xml
```

Reports land in `storage/coverage/` (HTML, Clover, text). The baseline file is
recorded on first run and guards against drops larger than the tolerance
thereafter.

## 6. CI wiring

* **PR / main push** → `coverage` job + `load-smoke` job (k6 smoke).
* **Nightly** (`17 2 * * *` UTC) → `load-nightly` job: baseline → spike →
  stress.
* Definitions: `.github/workflows/ci.yml`.

## 7. Troubleshooting

| Symptom | Cause / fix |
|---|---|
| `no coverage driver loaded` | run `bash /home/user/bootstrap.sh` (pcov is wiped between sandbox resets) |
| k6: `Permission denied` | `sudo ln -sf /home/user/bin/k6 /usr/local/bin/k6` |
| k6 baseline all 429 | you are hitting the per-IP limiter — raise `API_RATE_LIMIT_API*` on the **test** server only |
| concurrency tests fail on PostgreSQL | DB was reset — `migrate:fresh --seed` against `ffarena_test` first |
| registration k6 100 % 422 | `game_uid` must match `/^[A-Za-z0-9]{4,30}$/` (no separators) |
| benchmark exits `no tournament available` | seed the database and/or add scores |
