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

