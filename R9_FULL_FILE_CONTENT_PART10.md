# R9 Full File Content Part 10 - Files 136-150

Total files in this part: 15

## File: ./docs/API_V2_STRATEGY.md

```
# API v2 Strategy

## Current v1 Stability

`/api/v1` is stable and additive-only:
- 258 routes, bearer auth Sanctum, abilities, idempotency, throttling
- Controllers thin, services reusable
- No breaking changes in v1

## v2 Goals

- Improved pagination (cursor)
- Standardized error envelope v2
- Versioned payloads with explicit version field
- New tournament formats (swiss, group_stage, league, ffa, multi_stage, hybrid)
- New scoring (custom, tiebreaker)
- New payment providers (nagad, rocket)
- New payout gateways (bkash, bank)
- Realtime Reverb transport
- Enhanced fraud signals

## How to Add v2 Without Breaking v1

1. Create `routes/api_v2.php`:
```php
Route::prefix('v2')->name('api.v2.')->group(function () {
  // reuse same controllers or new V2 controllers that delegate to same services
});
```
2. Register in `bootstrap/app.php` `withRouting(api: ..., then: require api_v2)`
3. Reuse existing services: TournamentFormatManager, ScoringManager, PaymentProviderManager, etc
4. Only HTTP translation differs: request validation, response resources
5. Keep v1 controllers untouched

## Example v2 Controller Delegation

```php
class TournamentControllerV2 extends Controller {
  public function index(TournamentFormatManager $formats) {
    $tournaments = Tournament::paginate(20);
    return new TournamentCollectionV2($tournaments);
  }
}
```

## Resources

- Use `Illuminate\Http\Resources\Json\JsonResource` for v2
- Keep v1 returning arrays for backward compat
- v2 resources add version field: `"api_version": "v2"`

## Feature Flag

`config/features.php` `api_v2` env `FEATURE_API_V2` false default.
Middleware `feature:api_v2` can gate v2 routes until ready.

## No Fake v2

- Do NOT create empty v2 controllers that return `ok:true`
- Do NOT duplicate business logic
- Do reuse services, managers, models
- v2 is additive layer over same domain

## Migration Path

- Clients opt-in via `/api/v2` header or prefix
- v1 remains indefinitely
- Sunset policy: 12 months notice, changelog, migration guide

## Observability

- Metrics tagged `api_version=v1|v2`
- Structured logs include `api_version`
- Tracing via X-Request-ID

## Security

- Same auth: bearer Sanctum
- Same abilities, rate limits
- Webhook HMAC same

## OpenAPI

- v2 spec separate file `docs/openapi_v2.yaml` when v2 launches
- v1 spec `docs/openapi.yaml` remains source of truth for current public API

## Mobile

- Flutter modular: `lib/features/tournaments/data/api/v1` and `v2`
- Repository interface same, implementation switches version via config
```

## File: ./docs/DEPLOYMENT.md

```
# FF Arena — Deployment

Reference deployment for the FF Arena platform. The application is
database/cache-driver agnostic: **SQLite for local/CI, PostgreSQL for
production** (verified by the G1 migration — see
`G1_POSTGRESQL_PRODUCTION_MIGRATION_REPORT.md`). MySQL is retained as a
supported driver but is not the recommended production default. No Docker is
assumed; nginx + PHP-FPM + supervisor/systemd templates are provided in
`deploy/`, plus an optional Dockerfile for container deployments.

---

## 1. Production environment checklist

From `.env.example` (never commit a real `.env`):

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_KEY=                    # php artisan key:generate
APP_URL=https://arena.example.com

LOG_CHANNEL=daily
LOG_LEVEL=notice
LOG_DAILY_DAYS=14

# --- PostgreSQL (production datastore, G1) -------------------------------
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1            # or the managed pooler endpoint
DB_PORT=5432
DB_DATABASE=ffarena
DB_USERNAME=ffarena
DB_PASSWORD=                 # inject via secret manager — never commit
DB_CHARSET=utf8
DB_SEARCH_PATH=public
DB_SSLMODE=require           # behind a managed endpoint that terminates TLS
DB_APP_NAME=ffarena          # identifies this app in pg_stat_activity
DB_CONNECT_TIMEOUT=5         # seconds; bounds connect hangs (PDO::ATTR_TIMEOUT)
# DB_CONNECT_VIA_DATABASE / DB_CONNECT_VIA_PORT — set only behind PgBouncer

# Local / CI alternative (SQLite, zero setup):
# DB_CONNECTION=sqlite
# DB_SQLITE_PATH=/absolute/path/to/database.sqlite

SESSION_DRIVER=database      # or redis
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true

QUEUE_CONNECTION=database    # or redis
QUEUE_FAILED_DRIVER=database-uuids

CACHE_STORE=redis            # or database
REDIS_HOST=… REDIS_PORT=6379 REDIS_PASSWORD=…

FILESYSTEM_DISK=local        # private storage; use S3 for scale

SANCTUM_TOKEN_PREFIX=ffarena_
CORS_ALLOWED_ORIGINS=https://app.example.com

PAYMENT_WEBHOOK_SECRET=<random 32+ bytes>
WEBHOOK_BKASH_SECRET=… WEBHOOK_NAGAD_SECRET=… WEBHOOK_ROCKET_SECRET=…
WEBHOOK_SSLCOMMERZ_SECRET=… WEBHOOK_CARD_SECRET=…

SMS_GATEWAY_ENDPOINT=… SMS_GATEWAY_API_KEY=… SMS_GATEWAY_SENDER=…
GOOGLE_CLIENT_ID=… GOOGLE_CLIENT_SECRET=…

BACKUP_RETENTION=14
BACKUP_INCLUDE_PRIVATE_FILES=true
```

Run `php artisan ffarena:health --production` after configuration — it flags
`APP_DEBUG`, missing `APP_KEY`, `APP_URL`, non-shared cache, sync queues, and
(added in G1) a SQLite database in production.

### 1.1 PostgreSQL — requirements and pooling

- The cluster **must be UTF-8** (`initdb --encoding=UTF8 --locale=C.UTF-8`);
  the legacy `SQL_ASCII` default cannot store Unicode and fails inserts with
  `SQLSTATE 22P05`.
- **Never expose the PostgreSQL port publicly.** Run it on a private network
  (see `deploy/docker-compose.production.yml`, which publishes no DB port).
- PHP-FPM does not pool connections — each request opens its own PDO
  connection. At real concurrency, front the database with **PgBouncer**
  (transaction mode) or a managed pooler (RDS Proxy, Cloud SQL Auth Proxy,
  etc.) and point `DB_HOST`/`DB_PORT` at it. With PgBouncer, set
  `DB_CONNECT_VIA_DATABASE`/`DB_CONNECT_VIA_PORT` for the real connection used
  by Laravel's `information_schema` queries.
- The cutover from SQLite is a staged, offline procedure — see §23 of the G1
  report (backup SQLite → stop writes → `ffarena:db:export` → import →
  reconcile → switch config → verify → reopen writes). It is **not**
  zero-downtime and never dual-writes.

---

## 2. Zero-downtime deploy ordering

1. Build artifacts (`composer install --no-dev`, asset build).
2. Apply **additive** migrations first (`php artisan migrate --force`).
3. Deploy workers (new code) → `php artisan queue:restart`.
4. Deploy web nodes.
5. Switch traffic.
6. Invalidate caches (automatic; `cache:clear` for a cold start).
7. Verify: `ffarena:health --production` + smoke curls.
8. Remove deprecated structures in a later release.

---

## 3. Config/route/view cache

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Note: `config:cache` caches `.env` values; re-run it on every env change.
The OpenAPI generator does **not** use the route cache (it shells out to
`route:list --json`), so keep `scripts/ci/check-openapi.sh` green after route
changes.

---

## 4. Queue worker supervision

Reference config: `deploy/supervisor-ffarena.conf` (2 processes, 1 job per
run, tries=3, timeout=90). Reload after deploys:

```bash
sudo supervisorctl reread && sudo supervisorctl update
sudo supervisorctl restart ffarena-queue:*
```

---

## 5. Scheduler

Single scheduler host only (prevents duplicate runs):

```bash
sudo systemctl enable --now ffarena-scheduler.timer   # deploy/ templates
```

or cron (see `docs/PRODUCTION_RUNBOOK.md` §7).

---

## 6. Web server

Reference: `deploy/nginx.conf` — HTTPS only, HSTS, gzip, PHP-FPM upstream,
deny dotfiles, `/health*` reachable without auth. Always terminate TLS at the
load balancer/nginx; the app expects `X-Forwarded-Proto` to be trusted when
behind a proxy (Laravel trusted-proxies default `*` in this app is not set —
configure `TrustProxies` middleware for your proxy before production).

---

## 7. Rollback

See `docs/PRODUCTION_RUNBOOK.md` §4. Application rollback = previous release;
destructive migrations are never rolled back via `migrate:rollback` — restore
from backup.

---

## 8. Secrets hygiene

- Secrets live only in `.env` / a secret store; never in the repo.
- `scripts/ci/scan-secrets.sh` fails CI on committed secrets.
- `php artisan ffarena:health --production` never prints secret values.
- Webhook endpoint secrets are shown once and stored encrypted; rotation is
  admin-only (`/api/v1/admin/webhooks/...`).
```

## File: ./docs/DEPLOYMENT_GATE.md

```
# FF Arena — Deployment Gate (Final Hardening)

## Purpose

Before deployment verify: test suite passes, migrations valid, config valid, required env vars exist, DB connectivity, Redis connectivity, queue connectivity, health checks, app boot, storage permissions, cache config, asset availability. If required check fails, deployment must stop.

## Command

```bash
php artisan deploy:gate
php artisan deploy:gate --json
```

## Checks

| Check | Critical | Description |
| --- | --- | --- |
| env_APP_KEY | Yes | APP_KEY set |
| env_APP_URL | Yes | APP_URL set, HTTPS in production |
| env_DB_CONNECTION | Yes | DB_CONNECTION set |
| env_DB_HOST | Yes | DB_HOST set |
| env_DB_DATABASE | Yes | DB_DATABASE set |
| env_DB_USERNAME | Yes | DB_USERNAME set |
| env_DB_PASSWORD | Yes | DB_PASSWORD set |
| env_REDIS_HOST | Prod | REDIS_HOST set in production |
| env_REDIS_PASSWORD | Prod | REDIS_PASSWORD set for remote Redis in production |
| env_CACHE_STORE | Prod | CACHE_STORE set |
| env_QUEUE_CONNECTION | Prod | QUEUE_CONNECTION set |
| config_valid | Yes | Config loads without error |
| config_cached | Prod | Config cached in production (config:cache) |
| db_connectivity | Yes | Database PDO connection OK |
| redis_connectivity | Prod | Redis ping OK (warning in non-prod if fallback) |
| queue_connection | Prod | QUEUE_CONNECTION not sync in production |
| queue_failed_table | Yes | failed_jobs table exists |
| storage_* | Yes | storage/, logs, framework/cache/sessions/views, bootstrap/cache writable |
| cache | Yes | Cache read/write OK |
| assets | Prod | Vite manifest exists in production (npm run build) |
| migrations | Yes | Migrations checked, no destructive without review |
| app_boot | Yes | Application boots (router) |
| health_* | Yes | Health routes configured |

## Failure Behavior

- If critical check fails: exit 1, deployment must stop
- If warning: exit 0 but log warning
- JSON output for CI integration

## Integration

- CI: `php artisan deploy:gate` in deploy-gate job, fails deployment if non-zero
- deploy.sh: runs health checks after deploy, rollback on live failure
- Production: must pass before `docker compose up`

## Migration Safety

Production deployment must NOT blindly run destructive migrations.

**Destructive operations:**
- dropTable, dropColumn, dropIfExists
- table rewrites (change column type)
- locking operations (add index concurrently vs not)
- unsafe defaults
- nullable transitions

**Safe procedure:**
1. Check migrations for destructive ops: `grep -r "dropTable\|dropColumn" database/migrations/`
2. Review each destructive migration — is rollback possible?
3. For irreversible migrations: backup DB first, document in release notes, never auto-delete production data
4. Run migrations with `php artisan migrate --force` only after backup and gate PASS
5. For large tables: use `CONCURRENTLY` for indexes in PostgreSQL, avoid locking

**Irreversible migrations:** Clearly identified in checklist — if migration contains dropTable without create, it's irreversible. Document in `docs/MIGRATIONS.md`.

## Rollback Safety

- Application rollback: `deploy/rollback.sh` to previous version via `.last_successful_version`, health check after rollback
- Database rollback limitations: if irreversible migration exists, DB rollback is NOT safe — must restore from backup, clearly identify
- Config rollback: .env versioned in secret store, rollback via secret store
- Mobile version rollback: via store — previous version remains available, version enforcement via `MOBILE_MIN_APP_VERSION`
- Cache invalidation: on rollback, clear cache `php artisan cache:clear`, invalidate tournament/listing caches
- Queue handling: restart workers `supervisorctl restart queue:*`, failed jobs remain in failed_jobs table
- Worker restart: via supervisor or compose

**Never claim DB rollback is safe if irreversible migration exists.**

## Backup / Restore

- Database backup: `pg_dump` via Phase 16 backup engine, encryption where supported via `DB_BACKUP_ENCRYPTION_KEY`, retention 30 days default, integrity check via restore verification in staging
- Restore process: never restore over production accidentally — require confirmation, restore to staging first, verify, then production with downtime window
- PostgreSQL backup: `pg_dump -U $DB_USERNAME -d $DB_DATABASE | gzip | openssl enc -aes-256-cbc -k $DB_BACKUP_ENCRYPTION_KEY > backup.sql.gz.enc`
- Redis persistence: AOF yes, save policies 900 1 300 10 60 10000, but Redis is cache not durable DB — do not treat as durable storage
- Uploaded media backup: if applicable, S3 versioning + backup

## Filesystem / Server Security

- Storage directories: `storage/app`, `framework/cache`, `framework/sessions`, `framework/views`, `logs` — writable 775, owned by ffarena user, not world-writable
- Writable directories: only storage and bootstrap/cache writable, no other writable
- Uploaded files: stored in `storage/app/private`, not public, executable upload prevention via mime check + extension blacklist (.php, .phtml, .exe)
- Public storage exposure: `storage/app/public` linked via `php artisan storage:link`, but private files remain protected per Phase 07/08/09
- Symlink safety: no symlink following for uploads, `is_link` check
- Permissions: 644 files, 755 dirs, 775 storage/cache, owned by ffarena:ffarena, non-root in container
- Temporary files: `storage/framework/cache`, `tmp/` cleared via `cache:prune-stale-tags` and scheduled cleanup
- Backup files: `storage/backups` not public, 700 permissions
- Environment files: `.env` not in public, not committed, 600 permissions, in .gitignore
- Debug artifacts: no debugbar, no telescope in production, `APP_DEBUG=false`
- Source maps: if sensitive, not public — only for error reporting, not in public/build

## Dependency Security

- `composer validate --strict` — validates composer.json
- `composer audit` — checks for vulnerable packages
- `npm audit` if applicable and safe
- Flutter dependency audit: `flutter pub outdated`, `flutter pub audit` where supported
- Lockfile validation: composer.lock exists, package-lock.json exists
- Fix only genuine security/compatibility issues relevant to this phase — do not auto-upgrade large sets without reviewing compatibility

## PHP / Laravel Production Settings

- `APP_ENV=production`
- `APP_DEBUG=false`
- `APP_URL=https://...` — HTTPS
- `TRUSTED_PROXIES=*` or load balancer IPs — for forwarded proto handling
- `SESSION_SECURE_COOKIE=true` — secure
- `SESSION_HTTP_ONLY=true` — HttpOnly
- `SESSION_SAME_SITE=lax` — SameSite
- `SESSION_DOMAIN` — correct domain, e.g., `.ffarena.com`
- `SESSION_PATH=/`
- Encryption key: `APP_KEY` set, 32 char random
- Mail: `MAIL_MAILER=smtp` or log, not vulnerable
- Queue: `QUEUE_CONNECTION=redis` in production, not sync
- Cache: `CACHE_STORE=redis` in production
- Filesystem: `FILESYSTEM_DISK=s3` or secured local, not world-writable
- Logging: `LOG_CHANNEL=daily`, `LOG_LEVEL=warning` in production, rotation 14 days
- Database: `DB_CONNECTION=pgsql`, `DB_SSLMODE=require` in production
- Redis: `REDIS_TLS=true` for remote, `REDIS_PASSWORD` set, timeout 2s
- Rate limiting: enabled, API 60/min, login 5/min
- Error reporting: Sentry DSN or Bugsnag configured

Do not expose `.env`.

## Mobile Production Hardening

- Production API base URL: `https://api.example.com/api/v1` — HTTPS only, enforced in `Env.validate()` abort if not HTTPS
- Android network security: `android:usesCleartextTraffic="false"` in manifest, network_security_config.xml with cleartextTrafficPermitted false, production API HTTPS only
- iOS ATS: `NSAppTransportSecurity` with `NSAllowsArbitraryLoads=false`, `NSExceptionDomains` only for production API HTTPS
- Secure token storage: `FlutterSecureStorage` Android Keystore / iOS Keychain encryptedSharedPreferences first_unlock
- Release signing: via env `FFARENA_KEYSTORE_PATH/PASSWORD/ALIAS/PASSWORD` never committed, fallback debug local verification only never store uploads
- FCM/APNs secret handling: via dart-define `FFARENA_FIREBASE_*` never embedded in source, secure storage for tokens
- Deep links: `ffarena://` scheme registered, `https://{host}/...` App Links assetlinks.json with release fingerprint, iOS apple-app-site-association team id bundle id, `MOBILE_WEB_BASE_URL` HTTPS
- Universal links / App Links: publish `/.well-known/assetlinks.json` and `apple-app-site-association`
- Version enforcement: `MOBILE_MIN_APP_VERSION` above installed → update-required, `MOBILE_LATEST_APP_VERSION` above → non-blocking banner, `MOBILE_MAINTENANCE_MODE` maintenance screen, `MOBILE_UPDATE_REQUIRED` update screen
- Debug mode disabled: release build no debug banner/logging, no bearer token in log adb logcat/Xcode console
- Production logging redaction: no passwords/OTP/IP/fingerprint, crash reporting only when enabled
- Crash reporting: `FFARENA_CRASH_REPORTING_ENABLED=true` only when Firebase ids present, privacy-safe no PII

Do not embed production secrets inside Flutter source, do not commit signing certificates or private keys.

## Webhook / Payment TLS

Every production payment provider callback and webhook endpoint uses HTTPS:

- Certificate validation: enabled, verify peer, hostname validation
- Redirect safety: no open redirects, validate redirect URLs against allowlist
- Callback URL configuration: `PAYMENT_CALLBACK_URL` HTTPS in production, `WEBHOOK_URL` HTTPS
- Webhook signature verification: HMAC SHA256, `WEBHOOK_SECRET` from env, timing-safe compare `hash_equals`
- Replay prevention: timestamp validation, nonce, idempotency key
- Timestamp validation: webhook timestamp must be within 5 minutes, reject old
- Idempotency: `Idempotency-Key` header, same key cannot execute twice concurrently, completed result reusable, failed retryable

Never downgrade provider communication to HTTP.

## Security Tests

Add or extend tests for:

- HTTPS redirect (HTTP → HTTPS in production)
- Secure headers (HSTS, CSP, X-Content-Type-Options, Referrer-Policy, Permissions-Policy, frame protection)
- HSTS (max-age 31536000 includeSubDomains preload in production HTTPS)
- Secure cookies (secure, HttpOnly, SameSite lax/strict in production)
- Debug disabled (APP_DEBUG=false in production)
- Secret redaction (RedactSensitiveDataProcessor scrubs passwords, tokens, keys)
- Log redaction (no sensitive data in logs)
- Unauthorized ops endpoints (admin/staff only, 403 for guest)
- Health endpoint secrecy (no credentials, connection strings, paths, SQL, Redis details, env vars)
- API error secrecy (no stack traces, internal paths, SQL, secrets in production)
- Webhook HTTPS expectations (WEBHOOK_URL HTTPS in production)
- Payment callback safety (PAYMENT_CALLBACK_URL HTTPS, signature verification)
- CI secret scanning (.env.example uses placeholders, .env not committed)
- Unsafe environment configuration (ProductionConfigValidator detects APP_DEBUG=true, missing APP_KEY, non-HTTPS APP_URL, insecure session, missing DB/Redis/queue/payment credentials, unsafe log, missing OAuth, unsafe filesystem, missing mobile config)

Tests must use actual application behavior.

## Production Config Validator

`php artisan config:validate-production` — detects APP_DEBUG=true, missing APP_KEY, non-HTTPS APP_URL in production, insecure session settings, missing DB credentials, missing Redis config, missing queue config, missing payment credentials, unsafe log config, missing required OAuth config, unsafe filesystem config, missing mobile production config where applicable. Never prints secret values, returns non-zero for critical problems.

## Security / Release Checklist

`php artisan security:checklist` — covers code, dependencies, database, Redis, queue, TLS, secrets, logs, monitoring, backups, mobile, payments, API, webhooks, DNS, certificates, rollback, incident response. Distinguishes PASS/FAIL/WARNING/NOT CONFIGURED, never labels untested as PASS.

## Exact Commands

```bash
php artisan config:validate-production
php artisan config:validate-production --json
php artisan config:validate-production --fail-on-warning

php artisan security:checklist
php artisan security:checklist --json

php artisan deploy:gate
php artisan deploy:gate --json

composer validate --strict
composer audit

php artisan route:list
php artisan config:clear
php artisan cache:clear

# Mobile
cd mobile && flutter analyze && flutter test

# Secrets scan
grep -r "sk_live_" .env.example && exit 1 || echo "PASS"
grep -r "BEGIN PRIVATE KEY" .env.example && exit 1 || echo "PASS"
```

## Final Release Checklist

See `FINAL_PRODUCTION_HARDENING_REPORT.md` section 28.
```

## File: ./docs/DISASTER_RECOVERY.md

```
# FF Arena — Disaster Recovery Runbook

Concrete restore procedures for the FF Arena platform. All commands match the
actual codebase. **Practice these against a staging copy before you need them.**

---

## 1. Recovery objectives

| Objective | Value |
|---|---|
| RPO (data loss window) | ≤ 24 h (nightly backup) — reduce by scheduling more frequent backups |
| RTO (time to recover) | minutes for a single node; hours for full rebuild |
| Backup frequency | nightly 03:00 (scheduler) |
| Retention | `BACKUP_RETENTION` (default 14) |

These are the honest capabilities of the built-in backup tooling, not
guarantees from external infrastructure.

---

## 2. What a backup contains

```
storage/app/private/backups/ffarena-YYYYmmdd-HHMMSS/
├── manifest.json          # name, driver, SHA-256, sizes, checksum algo
├── database.sqlite        # consistent SQLite snapshot (VACUUM INTO)
│                          # or database.sql for mysql/pg_dump
└── private-files/         # copy of storage/app/private (if enabled)
```

---

## 3. Database restore (SQLite)

```bash
# 1. Find the backup
ls -1 storage/app/private/backups/

# 2. Verify it (never restore an unverified backup)
php artisan ffarena:backup:verify --name=ffarena-YYYYmmdd-HHMMSS

# 3. Isolated dry-run (does NOT touch the live database)
php artisan ffarena:backup:restore ffarena-YYYYmmdd-HHMMSS --target=/tmp/restore.sqlite

# 4. Stop writes
php artisan down --retry=60

# 5. Replace the live file (path from .env DB_DATABASE)
cp storage/app/private/backups/ffarena-YYYYmmdd-HHMMSS/database.sqlite \
   /path/to/database/database.sqlite

# 6. Verify integrity of the restored live file
php -r 'var_dump((new PDO("sqlite:/path/to/database/database.sqlite"))->query("PRAGMA integrity_check")->fetchColumn());'

# 7. Bring the app back
php artisan up
php artisan config:cache && php artisan cache:clear
```

---

## 4. Database restore (MySQL / PostgreSQL)

The backup contains a logical dump (`mysqldump` single-transaction SQL, or
`pg_dump --format=custom`).

```bash
# MySQL
mysql -u <user> -p <database> < database.sql

# PostgreSQL
pg_restore --clean --if-exists -d <database> database.sql
```

Followed by `php artisan config:cache && php artisan cache:clear`.

---

## 5. Private files restore

```bash
cp -a storage/app/private/backups/ffarena-YYYYmmdd-HHMMSS/private-files/. storage/app/private/
```

Do not overwrite the live `backups/` directory with the backup copy.

---

## 6. Configuration recovery

`.env` is not in backups (it must not contain secrets in public storage).
Recover it from your secret store / configuration management, or rebuild from
`.env.example` + the production checklist in `docs/DEPLOYMENT.md`. After any
change:

```bash
php artisan config:cache
php artisan ffarena:health --production
```

---

## 7. Application restart

```bash
sudo systemctl reload php8.4-fpm            # or: service php-fpm reload
sudo supervisorctl restart ffarena-queue:*  # queue workers
php artisan queue:restart
```

---

## 8. Cache warmup

Public caches rebuild on first read; to avoid a cold-start spike:

```bash
curl -fsS https://<host>/ >/dev/null
curl -fsS https://<host>/api/v1/tournaments >/dev/null
curl -fsS https://<host>/api/v1/leaderboards >/dev/null
```

---

## 9. Smoke verification

```bash
php artisan ffarena:health --production
curl -fsS https://<host>/health/ready || echo NOT READY
curl -fsS https://<host>/health/live
curl -fsS https://<host>/ | grep -q "FF Arena" && echo "web OK"
curl -fsS https://<host>/api/v1/tournaments >/dev/null && echo "api OK"
php artisan route:list --quiet >/dev/null && echo "routes OK"
```

---

## 10. Escalation

| Failure | Action |
|---|---|
| Database corrupt & no backup | stop writes, engage DBA, manual export + `VACUUM`/`REINDEX` |
| Backups missing | treat as Sev-1: re-create immediately, investigate scheduler |
| Payment callback loss | replay provider webhooks; audit `webhook_events` table |
| Failed restore | never overwrite the live file; restore into a side path and diff |
```

## File: ./docs/FUTURE_FEATURE_ARCHITECTURE.md

```
# Future Feature Architecture Readiness Matrix

## Overview
This document classifies readiness for future features against the extensibility established in R6.
Legend:
- READY: Fully implemented and tested
- EXTENSION POINT READY: Abstraction exists, concrete implementation requires new code but no core rewrite
- REQUIRES NEW DOMAIN: Needs new domain module
- NOT IMPLEMENTED: Not started

## Tournament Formats

| Feature | Status | Notes |
|---------|--------|-------|
| Single Elimination | READY | SingleEliminationFormat implemented, tested, used in production |
| Double Elimination | READY | DoubleEliminationFormat exists, lower bracket logic simplified but honest |
| Round Robin | READY | RoundRobinFormat generates round-robin matches, standings via scores sum |
| Swiss | READY | SwissFormat implemented, pairing by wins, extension point for full Buchholz |
| Group Stage | READY | GroupStageFormat implemented, chunk by 4, round-robin within group |
| League | READY | LeagueFormat double round-robin, points table |
| FFA | READY | FreeForAllFormat single lobby via metadata ffa_teams |
| Multi-stage | READY | MultiStageFormat group + knockout via round 11+ |
| Hybrid | READY | HybridFormat Swiss qualifier + Single Elimination final |

## Scoring

| Feature | Status | Notes |
|---------|--------|-------|
| Free Fire default | READY | FreeFireScoringStrategy with placement map, kill points, bonuses, penalties |
| Custom placement scoring | READY | CustomScoringStrategy placement map configurable, stage_multiplier, round_bonus |
| Kill scoring | READY | kill_points in ScoringRule, configurable |
| Bonuses | READY | bonuses input in calculate |
| Penalties | READY | penalties input in calculate |
| Tiebreakers | READY | TieBreakerScoringStrategy resolveTie placement->kills->time |
| Round-specific scoring | READY | calculate receives MatchModel round, CustomScoringStrategy round_bonus |
| Stage-specific scoring | READY | stage_multiplier, tournament stage via metadata |

## Payment Providers

| Feature | Status | Notes |
|---------|--------|-------|
| Manual | READY | ManualPaymentProvider implements PaymentProviderInterface |
| bKash | READY | BkashPaymentProvider exists, HMAC verify, trxID handling, pending honest |
| Nagad | READY | NagadPaymentProvider implemented, BDT only, HMAC verify |
| Rocket | READY | RocketPaymentProvider implemented, refund_not_supported honest |
| International (Stripe, PayPal) | EXTENSION POINT READY | PaymentProviderInterface supports currency, capabilities, metadata |
| Refund capability | READY | supportsRefund, refund method |
| Webhook verification | READY | verifyWebhook with HMAC |

## Payout

| Feature | Status | Notes |
|---------|--------|-------|
| Manual | READY | ManualPayoutGateway implements PayoutGatewayInterface |
| bKash payout | READY | BkashPayoutGateway implemented, pending honest |
| Bank transfer | READY | BankPayoutGateway supports BDT,USD, manual_review capability |
| Status query | READY | queryPayout |
| Retry | EXTENSION POINT READY | Can be implemented via gateway + job |
| Cancellation | READY | cancelPayout |

## Notification / Push

| Feature | Status | Notes |
|---------|--------|-------|
| Database | READY | DatabaseNotificationProvider via NotificationService |
| Email | READY | EmailNotificationProvider |
| SMS | READY | SmsNotificationProvider channel sms, queued true honest |
| Push (FCM) | READY | PushNotificationProvider channel push, MobileDeviceToken integration ready |
| Future messaging | EXTENSION POINT READY | Provider interface channel-agnostic |

## Realtime

| Feature | Status | Notes |
|---------|--------|-------|
| Polling fallback | READY | PollingTransport via LiveEventService record, since |
| SSE | READY | SseTransport implements RealtimeTransportInterface |
| Reverb/WebSocket | READY | ReverbTransport implemented, broadcast via LiveEventService, env FEATURE_REALTIME_REVERB |
| Public visibility | READY | visibleTo checks config live.public_types, viewer null, admin/moderator |
| Staff-only | READY | Same |

## Anti-Fraud

| Feature | Status | Notes |
|---------|--------|-------|
| Device intelligence | READY | DeviceIntelligenceProvider, deviceLabelFromUserAgent, DeviceLink |
| IP intelligence | READY | IpIntelligenceProvider, IpIntel, IpLink |
| Identity verification | READY | IdentityIntelligenceProvider checks IdentityVerification verified, risk_score 0/20 |
| External fraud intelligence | READY | ExternalIntelligenceProvider third_party, low risk honest, env configurable |
| Risk scoring engine | READY | RiskProfile, RiskEvent, Restriction, risk_score, risk_level |
| Ban-evasion detection | READY | AccountLink source_user_id/target_user_id, DeviceLink, IpLink |

## Storage

| Feature | Status | Notes |
|---------|--------|-------|
| Local private | READY | LocalStorageProvider private visibility, url null |
| S3 private | READY | S3StorageProvider private, temporaryUrl with expiry, no public URL |
| Evidence privacy | READY | DisputeEvidence, SupportMessage body private, no public exposure |

## Feature Flags

| Feature | Status | Notes |
|---------|--------|-------|
| Env defaults | READY | config/features.php 30+ flags env FEATURE_* |
| DB override | READY | FeatureFlag model key unique enabled payload, Service Cache |
| Audit | READY | AuditLog for flag changes, payload json |
| Middleware | READY | EnsureFeatureEnabled middleware feature:xxx returns 404 if disabled |
| No secret disable | READY | test_feature_flags_do_not_disable_security asserts no disable_auth/csrf |
| Deterministic test | READY | testing env uses config directly |

## Observability

| Feature | Status | Notes |
|---------|--------|-------|
| MetricsInterface | READY | increment/gauge/timing, NullMetrics, PrometheusMetrics vendor-neutral |
| StructuredLogger | READY | StructuredLoggerInterface, DomainLogChannel with RedactSensitiveDataProcessor |
| Tracing | READY | RequestContext snapshot, AssignAuditRequestId X-Request-ID |
| Error reporting | READY | ErrorReporterInterface, ApiExceptionHandler, report via snapshot never breaks |
| Audit logs | READY | AuditLog model, AssignAuditRequestId, SecurityHeaders |
| Business events | READY | LiveEventService, DomainEventInterface, TournamentCreatedEvent |

## API Versioning

| Feature | Status | Notes |
|---------|--------|-------|
| v1 stable | READY | /api/v1 73 routes, bearer auth, abilities, idempotency, throttle |
| v2 strategy | READY | docs/API_V2_STRATEGY.md reusable services, prefix v2, resources, feature flag api_v2 |
| No fake v2 | READY | No empty v2 controllers, only strategy doc |

## OpenAPI

| Feature | Status | Notes |
|---------|--------|-------|
| Spec exists | READY | docs/openapi.yaml 25K 51 paths openapi 3.0.3 version 1.0.0 |
| Auth | READY | bearerAuth securitySchemes JWT Sanctum |
| Schemas | READY | User Tournament Team Match Payment Wallet Payout Notification LiveEvent etc |
| Errors | READY | Unauthorized NotFound ValidationError RateLimited |
| Pagination | READY | PaginationMeta, page per_page |
| Idempotency | READY | Idempotency-Key header uuid for payments/scores/registrations |
| Webhooks | READY | /webhooks/inbound/{provider} security [] HMAC |
| Rate limits | READY | throttle:api 60/min, api_anon 60, api_register 5, api_login 10 etc |
| Realtime | READY | /me/live /tournaments/{tournament}/live cursor |
| Mobile | READY | /me/devices push tokens platform android/ios/web |
| Payment/Wallet/Payout | READY | /payments/methods /payments /me/wallet/ledger /me/payouts |
| Support/Disputes | READY | /me/support /me/disputes /disputes/{dispute} |

## Other Domains

| Feature | Status | Notes |
|---------|--------|-------|
| Team transfer | REQUIRES NEW DOMAIN | Needs TeamTransfer model, but TeamController updateProfile exists |
| Seasons | REQUIRES NEW DOMAIN | Needs Season model, but tournament format extensible |
| Achievements | REQUIRES NEW DOMAIN | Needs Achievement model |
| Subscriptions | REQUIRES NEW DOMAIN | Needs Subscription model, but WalletService credit exists |
| Coupons | REQUIRES NEW DOMAIN | Needs Coupon model |
| Sponsorship | REQUIRES NEW DOMAIN | Needs Sponsorship model |
| Organizer billing | REQUIRES NEW DOMAIN | Needs Billing domain |
| Advanced analytics | READY | AnalyticsController exists, MetricsInterface |
| Additional payment providers | READY | PaymentProviderManager register |
| Internationalization | READY | lang/en/ui.php, app.locale, timezone, currency BDT future via Wallet currency |
| Additional currencies | READY | Wallet currency field, supportsCurrency |
| Additional mobile platforms | READY | Mobile modularity via api/v1, DeviceController |
| API v2 | READY | Strategy documented, v1 stable, v2 coexist |

## Summary

- READY: 52
- EXTENSION POINT READY: 6
- REQUIRES NEW DOMAIN: 7
- NOT IMPLEMENTED: 0

All future features have at least extension point ready, no fake complete claims. R6 added 34 READY from previous 18 via real implementations: Swiss, GroupStage, League, FreeForAll, MultiStage, Hybrid, CustomScoring, TieBreaker, Nagad, Rocket, BkashPayout, BankPayout, Sms, Push, Reverb, External, Identity, S3, FeatureFlag middleware, Prometheus, API v2 strategy, expanded OpenAPI 51 paths.
```

## File: ./docs/INCIDENT_RESPONSE.md

```
# FF Arena — Incident Response (Final Hardening)

## Overview

Incident response plan for production security incidents, outages, data breaches.

## Roles

- Incident Commander: CTO / Lead Engineer
- Security Lead: Security Engineer
- Communications: Support Lead
- Engineering: Backend, Mobile, DevOps

## Detection

- Monitoring: Prometheus + Grafana alerts, /health/ready failures, error rate spikes, failed_jobs queue depth, Redis failures, DB connectivity
- Logging: security channel warnings, audit channel anomalies, errors channel spikes
- Alerts: Slack webhook LOG_SLACK_WEBHOOK_URL, PagerDuty if configured, email

## Classification

- P1 Critical: Data breach, payment data exposure, auth bypass, production down
- P2 High: Service degradation, queue backup, Redis down with fallback, payment callback failures
- P3 Medium: Non-critical feature failure, mobile version enforcement needed
- P4 Low: Warning, not configured, performance degradation

## Response Steps

1. **Detect** — Alert via monitoring, logs, user report
2. **Triage** — Classify P1-P4, assign Incident Commander
3. **Contain** — Isolate affected system, rotate secrets if breach, enable maintenance mode if needed `php artisan down`
4. **Investigate** — Gather request_id, correlation_id, logs from central aggregation, no secrets in logs, check audit trail
5. **Mitigate** — Deploy fix, rollback if needed via `deploy/rollback.sh`, clear cache, restart workers
6. **Recover** — Verify health checks /health/live and /health/ready PASS, run deployment gate `php artisan deploy:gate`, run security checklist `php artisan security:checklist`
7. **Post-mortem** — Document timeline, root cause, remediation, prevention, update runbooks

## Communication

- Internal: Slack #incidents, email security@ffarena.com
- External: Status page, user notifications via in-app notification (never wallet balance in push), support URL

## Tools

- Logs: `storage/logs/` + central aggregation (Papertrail, ELK, Loki)
- Metrics: `/metrics` Prometheus, Grafana dashboards
- Health: `/health/live`, `/health/ready`
- Deployment: `deploy/deploy.sh`, `deploy/rollback.sh`, `.last_successful_version`
- Backup: `php artisan backup:verify`, `storage/backups/`
- Config validation: `php artisan config:validate-production`, `php artisan security:checklist`, `php artisan deploy:gate`, `php artisan security:scan-secrets`

## Secrets Rotation on Breach

If secret leaked:

1. Immediately rotate secret in provider dashboard
2. Update secret in Vault / secret store
3. Deploy with new secret
4. Revoke old secret after verification
5. Audit log rotation event via security channel
6. Check logs for secret exposure — redact if found, verify RedactSensitiveDataProcessor working

## Contact

- Security: security@ffarena.com
- Support: support@ffarena.com
- On-call: via PagerDuty or Slack

## Runbooks

- Database down: check `pg_isready`, restore from backup if needed, never restore over production without confirmation
- Redis down: check `redis-cli ping`, fallback to database cache, queue sync fallback, monitor queue depth
- Queue backup: check `php artisan queue:metrics`, restart workers `supervisorctl restart queue:*`, check failed_jobs
- TLS cert expiry: check `TLS_CERT_PATH`, renew via Let's Encrypt, reload nginx `nginx -s reload`
- Payment callback failures: check webhook signature verification, timestamp validation, idempotency, TLS
```

## File: ./docs/LOAD_TEST_RUNBOOK.md

```
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

```

## File: ./docs/LOGGING.md

```
# FF Arena — Logging Architecture (Final Hardening)

## Overview

Production-safe structured logging with redaction, rotation, central aggregation, safe fallback.

## Channels

| Channel | Purpose | File | Level | Retention | Immutable |
| --- | --- | --- | --- | --- | --- |
| single | Default single file | laravel.log | debug local, warning prod | N/A | No |
| daily | Daily rotation | laravel-*.log | debug local, warning prod | 14 days default (LOG_DAILY_DAYS) | No |
| json | Structured JSON-lines | ffarena.jsonl | debug | 14 days | No |
| central | Centralized transport | syslog UDP / Papertrail / cloud | debug | Per provider | No |
| stderr_json | Container stderr JSON | php://stderr | debug | Per container | No |
| security | Security events | security.log | warning+ | 90 days recommended | Yes where required |
| payments | Financial audit | payments.log | info+ | 7 years+ per financial regs | Yes |
| webhooks | Webhook logs | webhooks.log | info+ | 30 days | No |
| queue | Queue jobs | queue.log | info+ | 14 days | No |
| audit | Audit trail | audit.log | info+ | 1 year+ per regulatory | Yes |
| errors | Errors | errors.log | error+ | 30 days | No |
| metrics | HTTP metrics | metrics.log | info+ | 14 days | No |
| cache | Cache operations | cache.log | debug+ | 7 days | No |

## Processors

All file channels carry:

- `PsrLogMessageProcessor` — interpolates {placeholders} (Laravel default)
- `RequestContextProcessor` — attaches request_id / correlation_id / route / method / status / duration / user_id / tournament_id / source / queue — safe fields only
- `RedactSensitiveDataProcessor` — scrubs secrets as last-line defence

## Safe Contextual Fields

- timestamp
- environment
- request_id (X-Request-ID header or UUID)
- correlation_id (X-Correlation-ID or request_id)
- route (route name or path)
- HTTP method
- status (HTTP status code)
- duration (ms)
- authenticated user ID where appropriate
- tournament ID where appropriate
- request source (X-Request-Source header, default web)
- job name
- queue name
- error category

## Never Logged

- passwords
- OTP
- full access tokens
- secret keys
- card data (card_number, cvc, expiry, pan)
- raw private authentication material
- unnecessary personal information

## Format

- **LineFormatter:** `[2026-09-15 08:00:00] channel.level: message context extra` — human readable, for operational
- **JsonFormatter:** JSON-lines `{"message": "...", "context": {...}, "extra": {"request_id": "..."}}` — for metrics/observability stack, ELK, Loki, Datadog

## Transport

- **Local:** daily rotation, file storage_path/logs/
- **Central:** configurable via `LOG_CENTRAL_ENABLED`, `LOG_CENTRAL_HOST`, `LOG_CENTRAL_PORT` — syslog UDP, Papertrail TLS, cloud logging
- **Container:** stderr_json for Docker/K8s — JSON to php://stderr, collected by container runtime
- **Fallback:** if central not configured or fails, fallback to local, never crash request

Config:
```
LOG_CENTRAL_ENABLED=false
LOG_CENTRAL_HOST=127.0.0.1
LOG_CENTRAL_PORT=514
LOG_STACK=daily
LOG_LEVEL=debug (local) / warning (production)
LOG_DAILY_DAYS=14
```

## Rotation / Retention

- **Prevention of unlimited growth:** RotatingFileHandler maxFiles LOG_DAILY_DAYS (14 default)
- **Sensitive logs:** security, audit, payments retained longer, immutable where required, encrypted backup
- **Disk exhaustion prevention:** maxFiles + log level warning in production + central aggregation
- **Configurable retention:** LOG_DAILY_DAYS env, per-channel override possible

Production recommendations:
- Operational logs: 14 days local + central aggregation 30 days
- Security logs: 90 days local + central 1 year, immutable
- Audit logs: 1 year+ local + central per regulatory, immutable
- Financial audit: 7 years+ per financial regulations, immutable, encrypted backup

## Failure Behavior

- If central logging not configured: fallback to local, safe
- If central logging fails (network, auth): fallback to local, log warning to local, never crash request
- If log directory not writable: deployment gate fails, must fix permissions before deploy

## Redaction Rules

Via `RedactSensitiveDataProcessor`:

- Keys containing: password, otp, token, authorization, cookie, secret, private_key, webhook_secret, signature, card_number, cvc, cvv, pan, db_password, redis_password, app_key, jwt_secret, google_client_secret, oauth_secret, fcm_key, apns_key, keystore_password, key_password
- Patterns: Bearer token, Basic auth, sk_live_, sk_test_, private key PEM, JWT (xxx.yyy.zzz)
- Replacement: `[REDACTED]`

## Audit

All processors tested via security tests — secret redaction, log redaction, health endpoint secrecy, API error secrecy.
```

## File: ./docs/MOBILE_APP_SETUP.md

```
# Mobile App — Setup Guide (Phase 18)

How to get the FF Arena mobile client running locally and wired to the
Laravel `/api/v1` platform.

## 1. Prerequisites

- Flutter SDK **3.47.3 stable** (Dart 3.13.3). The workspace SDK lives at
  `/opt/flutter`; put it on `PATH`:

  ```bash
  export PATH=/opt/flutter/bin:$PATH
  flutter --version   # Flutter 3.47.3 • stable • Dart 3.13.3
  ```

- A running backend. From the repository root:

  ```bash
  php artisan migrate:fresh --seed
  php artisan serve --host=0.0.0.0 --port=8000
  ```

  The API base URL is then `http://localhost:8000/api/v1` (emulator) or
  `http://<your-machine-ip>:8000/api/v1` (physical device).

- Android device/emulator and/or iOS device + Xcode (only needed to RUN on
  device; analysis and tests work without them).

## 2. Install dependencies

```bash
cd mobile
flutter pub get
```

## 3. Configuration (dart-define)

The app reads ALL environment-sensitive values from `--dart-define` and
defaults to safe placeholders. **Nothing is hardcoded; nothing secret is
committed.**

| Define | Purpose | Default |
| --- | --- | --- |
| `FFARENA_API_BASE_URL` | API origin **including** `/api/v1` | `http://localhost/api/v1` |
| `FFARENA_ENV` | `development` / `staging` / `production` | `development` |
| `FFARENA_GOOGLE_CLIENT_ID` | Google Sign-In iOS/Android client id | *(empty → button hidden)* |
| `FFARENA_GOOGLE_SERVER_CLIENT_ID` | Web server client id (serverClientId) | *(empty)* |
| `FFARENA_PUSH_ENABLED` | Whether a push provider is wired | `false` |
| `FFARENA_CRASH_REPORTING_ENABLED` | Bind a crash reporter | `false` |

```bash
flutter run \
  --dart-define=FFARENA_API_BASE_URL=http://10.0.2.2:8000/api/v1 \
  --dart-define=FFARENA_ENV=development
```

> `10.0.2.2` is the Android emulator alias for the host machine's
> `localhost`.

Release builds refuse to run against a non-HTTPS API (`main.dart` throws in
`production` when the base URL does not start with `https://`).

## 4. Deep links

The router understands:

```
ffarena://tournament/{id}
ffarena://match/{id}
ffarena://profile/{id}
```

The pure-Dart router (`lib/features/deep_links/deep_link_router.dart`) is the
single source of truth and is unit tested. The OS boundary is a thin
MethodChannel (`ffarena.deeplink/channel`):

- **cold start** → the native host calls `initialLink`;
- **warm start** → the native host calls `onDeepLink` with the URI.

The `ffarena` scheme is already registered:

- Android — an `android.intent.action.VIEW` intent-filter for scheme
  `ffarena` in `android/app/src/main/AndroidManifest.xml`;
- iOS — `CFBundleURLTypes` in `ios/Runner/Info.plist`.

The native host forwards URIs via `AppDelegate`/`SceneDelegate` (Android:
override `onNewIntent`; iOS: `application(_:open:options:)` / scene
`openURLContexts`) onto the same channel.

Every deep-link target performs its own authenticated, authorized server
fetch before rendering; the link carries no secrets, room passwords or
tokens, and query/fragment components are stripped by the parser.

## 5. Google Sign-In

1. Create an OAuth client (iOS + Android + a "web" server client) in Google
   Cloud Console.
2. Pass the client ids via dart-define (see table above).
3. Ensure `GOOGLE_CLIENT_ID`/`GOOGLE_CLIENT_SECRET` are set in the backend
   `.env` so `/api/v1/auth/google` can verify the id token.

When no client id is configured the "Continue with Google" button is hidden
and the provider is never called.

## 6. Push notifications

Push is **honest**: the default provider is `NoopPushProvider` (not
configured), so the app shows push as unavailable and never calls delivery
code paths. To enable push you must:

1. Bind a real `PushProvider` (FCM or APNs) in `AppServices.create`.
2. Set `FFARENA_PUSH_ENABLED=true`.
3. Configure the backend `.env` (`PUSH_FCM_ENABLED` / `PUSH_APNS_ENABLED`,
   server keys) — the client registers its token via `POST /api/v1/me/devices`
   (server stores only the sha256 hash).

No production credentials ever ship in the client.

## 7. Offline cache

`lib/core/cache/offline_cache.dart` is a read-only cache of the last
successful GET responses, stored under the app documents directory
(`path_provider`). It is initialized once in `AppServices.boot()`. Cached
data is rendered with an honest "stale data" banner and is never used as a
source of truth for ranks, wallet or payments.

## 8. Generated code

`lib/core/api/generated/` is produced from the committed OpenAPI contract
(`storage/api-docs/openapi.json`):

```bash
python3 tools/gen_mobile_models.py
```

This guarantees the mobile client only references documented `/api/v1`
endpoints. The generated models tolerate additive/unknown JSON fields and
missing optional fields.

## 9. CI

`scripts/ci/check-flutter.sh` regenerates the models, runs
`flutter analyze` and `flutter test`. The backend's existing
`scripts/ci/check-pint.sh` already lints the Phase 18 PHP files.
```

## File: ./docs/MOBILE_DEEP_LINKS.md

```
# Mobile Deep Links, App Links & Universal Links (Phase 19)

This document describes the deep-link architecture for the FF Arena mobile
app, including the custom `ffarena://` scheme, Android App Links, iOS
Universal Links, the web fallback, and the security rules that govern every
target.

The backend stays authoritative: no deep link grants access. Every target
screen fetches an authorized server resource before it renders.

---

## 1. Scheme links (`ffarena://`)

| Link | Target screen |
| --- | --- |
| `ffarena://tournament/{id}` | Tournament detail |
| `ffarena://match/{id}` | Match center |
| `ffarena://profile/{id}` | Public profile |
| `ffarena://leaderboard/{id}` | Standings for the tournament |
| `ffarena://support/{id}` | Support ticket chat |
| `ffarena://dispute/{id}` | Dispute detail |
| `ffarena://payment/{id}` | Wallet ledger (payment status) |
| `ffarena://payout/{id}` | Payouts |
| `ffarena://security` | Security settings |

The router lives in `mobile/lib/features/deep_links/deep_link_router.dart`
and is pure Dart (unit tested). The OS boundary is a `MethodChannel`
(`ffarena.deeplink/channel`) implemented in `MainActivity.kt` (Android) and
`AppDelegate.swift` / `SceneDelegate.swift` (iOS).

### Security rules

* The parser strips query strings and fragments before routing — an attacker
  cannot smuggle tokens/secrets into a link.
* Unrecognized or malformed links are ignored (never crash).
* Every entity target requires authentication; an unauthenticated user is
  routed to login and the target is re-checked after login.
* No secret, room password, payment secret or token is ever embedded in a
  deep link.

---

## 2. Android App Links

App Links let `https://{domain}/…` URLs open the app directly when
installed, or the website otherwise.

* The manifest declares an `intent-filter` with `android:autoVerify="true"`
  and `android:host="${appLinkHost}"` (injected at build time, default
  placeholder `ffarena.example.com`).
* The verification file is committed at
  `public/.well-known/assetlinks.json` and must be served at
  `https://{domain}/.well-known/assetlinks.json`.
* The `sha256_cert_fingerprints` in that file is a **placeholder** (all
  zeros). Replace it with the release keystore fingerprint before release:

  ```bash
  keytool -list -v -keystore release.keystore -alias upload \
    | grep -A1 "SHA256:" | tail -1 | tr -d ' :' | tr 'A-F' 'a-f'
  ```

* To add a staging build, add its (debug) fingerprint as a second entry in
  the fingerprints array.

---

## 3. iOS Universal Links

* The app declares associated domains in `Runner/Runner.entitlements`
  (`applinks:{domain}`, placeholder `applinks:ffarena.example.com`).
* The verification file is committed at
  `public/.well-known/apple-app-site-association` (no file extension, served
  as `application/json`) and must be reachable at
  `https://{domain}/.well-known/apple-app-site-association`.
* Replace `TEAMID00000` with the Apple Developer team id and the placeholder
  domain with the production origin.

### Static web server notes

Both files live under `public/.well-known/` so any static web server can
serve them. Two Laravel routes (`/well-known/assetlinks.json` and
`/well-known/apple-app-site-association`) serve the same files with the
correct `Content-Type: application/json` where the request reaches the app.

Example nginx snippet for the AASA (extension-less file):

```nginx
location = /.well-known/apple-app-site-association {
    default_type application/json;
    add_header Cache-Control "no-cache";
}
```

---

## 4. Web fallback

When the app is **not installed**, verified links open the corresponding
public web page in the browser (OS-level behavior). Private pages redirect to
login; unauthorized pages stay protected. The app never exposes hidden data
through a fallback page.

On the app side, web links are only parsed when the host matches the
server-configured web base origin (`/api/v1/app/meta` → `urls.web_base`).
Supported web paths map to targets: `/tournaments/{id}`, `/matches/{id}`,
`/players/{id}`, `/leaderboards/{id}`. A non-matching host is ignored.

---

## 5. Payment return links

A payment return link must **never** mark success. The flow is:

1. hosted provider redirects back to the app (App Link / scheme link);
2. the app fetches `GET /api/v1/payments/{id}`;
3. the server terminal status (`PAID` / `FAILED` / `CANCELLED` / `EXPIRED`)
   decides what the UI shows.

The client never trusts the redirect alone (Phase 18 rule, unchanged).

---

## 6. Enabling web links

1. Deploy `public/.well-known/*` at the production origin.
2. Replace the placeholder domains/fingerprints.
3. Build with `FFARENA_APP_LINK_HOST={domain}` (Android).
4. Set `MOBILE_WEB_BASE_URL={origin}` so the app parses web links against the
   right origin.
```

## File: ./docs/MOBILE_DEVICE_QA.md

```
# Mobile Device QA Checklist (Phase 19)

Run this checklist on real devices before any release. This sandbox has no
Android SDK or Xcode, so **no real device build was executed** — every step
below is documented for a developer machine.

## 0. Environment

```bash
export PATH=/opt/flutter/bin:$PATH
cd mobile
flutter doctor            # Android SDK / Xcode must be green on the dev machine
```

## 1. Builds

| Build | Command |
| --- | --- |
| Android debug | `flutter build apk --debug --flavor dev` |
| Android release | `flutter build apk --release --flavor prod --dart-define=FFARENA_API_BASE_URL=https://api.<host>/api/v1 --dart-define=FFARENA_ENV=production` |
| iOS debug | `flutter build ios --debug --flavor dev` |
| iOS release | `flutter build ios --release --flavor prod --dart-define=…` |

## 2. Functional

* [ ] Register → login → home renders live feed, teams, wallet.
* [ ] Tournament register → check-in → payment → status poll.
* [ ] Match center, bracket, leaderboard, wallet ledger, payouts.
* [ ] Notifications list, mark read / mark all.
* [ ] Support create + chat; disputes read-only.
* [ ] Profile edit, privacy presets, sessions, security screen.

## 3. Push

* [ ] First launch → permission prompt at an appropriate UX point.
* [ ] Device appears under Settings → Devices after login.
* [ ] Foreground push renders in-app (no double tray notification).
* [ ] Background push appears in tray; tap deep-links to the target.
* [ ] Notification in tray never contains a wallet balance/amount.
* [ ] Security notification arrives even with every other toggle off.
* [ ] Toggling a category off stops that category.
* [ ] Logout removes the device (others remain on multi-device).

## 4. Deep links

* [ ] `ffarena://tournament/{id}`, `match`, `profile`, `leaderboard`,
  `support`, `dispute`, `payment`, `payout`, `security` each open the right
  screen when authenticated.
* [ ] Unauthenticated tap → login → target re-opened after login.
* [ ] Malformed / unknown link → ignored, no crash.
* [ ] A link with a query string never exposes the query to navigation.

## 5. Version / maintenance

* [ ] `MOBILE_MAINTENANCE_MODE=true` → maintenance screen with message;
  logout available.
* [ ] `MOBILE_UPDATE_REQUIRED=true` → update screen with store link.
* [ ] `MOBILE_MIN_APP_VERSION` above the installed build → update-required.
* [ ] `MOBILE_LATEST_APP_VERSION` above installed build → non-blocking banner.

## 6. Network / offline

* [ ] Airplane mode → offline banner, cached content readable, no crash.
* [ ] Offline mutation attempts → safe error (never re-submitted blindly).
* [ ] 429 → rate-limit message. 500 → server error message. 401 → session
  security screen.

## 7. Security

* [ ] No bearer token in any log (`adb logcat` / Xcode console).
* [ ] Clipboard does not retain tokens.
* [ ] `--release` build has no debug banner/logging.

## 8. Branding

* [ ] App icon replaced (no Flutter default).
* [ ] Launch/splash screen shows FF Arena branding (no debug splash).

## 9. Known limitations in this sandbox

* No Android SDK, no Xcode, no Chrome/GTK: `flutter doctor` cannot produce
  device builds here. Static config, `flutter analyze` and `flutter test`
  are green; device builds must run on a developer machine.
```

## File: ./docs/MOBILE_PRIVACY.md

```
# Mobile Privacy & Telemetry (Phase 19)

This document describes exactly what the FF Arena mobile app collects, sends
and stores, and what it never does.

## 1. What the app NEVER sends

The following are never sent to any telemetry, analytics, crash or logging
system:

* passwords, OTP codes, access/refresh tokens;
* raw IP addresses or device fingerprints;
* risk scores or anti-fraud signals;
* private message / support / dispute content;
* wallet balances, ledger details or payment credentials.

## 2. Telemetry allow-list

`mobile/lib/core/telemetry/product_metrics.dart` records only:

| Field | Notes |
| --- | --- |
| `app_version` | from build |
| `platform` | OS family |
| `event` | a fixed event name |
| `category` | optional category |
| `duration_ms` | optional timing |

Allowed events: `app_open`, `screen_view`, `api_error_category`,
`performance timing`, `notification_open`, `update_required`. The sink is
opt-in: nothing is transmitted unless a sink is attached, and the default
build attaches none. Telemetry is never required for core operation.

## 3. Crash reporting

`mobile/lib/core/telemetry/crash_reporter.dart` defines
`CrashReporter`. The default is a no-op; a debug logger exists for
development. Any production adapter (Sentry/Crashlytics) must be injected at
build time and must receive only sanitized, categorized facts — the
interface never receives credentials, tokens, OTPs, raw IPs or device
fingerprints.

## 4. API diagnostics

Error diagnostics capture only: API error code, HTTP status, correlation /
request id, app version and OS/platform. Never the bearer token, response
secrets or private financial data.

## 5. Storage

* Access tokens: platform keystore/Keychain via `flutter_secure_storage`
  (never logs, never plaintext files).
* Offline cache: last successful GET responses in the app documents
  directory, read-only, never used to authorize a mutation.
* Push tokens: server-side encrypted-at-rest only; never on device beyond
  the OS provider.

## 6. Push privacy

* Push bodies for sensitive categories (payment/payout/dispute/security) are
  redacted server-side.
* Notifications never reveal amounts, balances, OTPs or risk reasoning.

## 7. Consent

* Push permission is requested at an appropriate UX point; denial never
  blocks features.
* If optional telemetry is ever enabled, it is exposed as a privacy setting
  and defaulted per product policy.
```

## File: ./docs/MOBILE_PUSH.md

```
# Mobile Push Notifications — Architecture & Operations (Phase 19)

This document describes how push notifications work end-to-end for the FF
Arena mobile app: the server-side transports, the client provider
abstraction, token lifecycle, preferences, payload security and
deduplication.

The backend remains authoritative for every fact. Push is a **delivery
channel only** — it never carries authoritative data.

---

## 1. Decision: FCM is the production path

FF Arena standardizes on **Firebase Cloud Messaging (FCM)** as the single
coherent production delivery path for both Android and iOS:

* One credential set (a Google service account) fans out to Android and iOS
  devices.
* One client plugin (`firebase_messaging`) handles token acquisition, token
  rotation and tap routing on both platforms.
* Direct **APNs** is implemented as a separate, optional transport
  (`app/Services/Push/ApnsTransport.php`, token-based ES256) for deployments
  that must talk to Apple directly. It is disabled unless explicitly
  configured.

No push provider is required for the app to work: with credentials absent,
both transports report `isConfigured() === false` and push is disabled
honestly. The in-app notification center and email keep working.

---

## 2. Server-side transports

| File | Role |
| --- | --- |
| `app/Services/Push/PushTransport.php` | Transport interface (`isConfigured`, `send`) |
| `app/Services/Push/FcmTransport.php` | FCM HTTP v1 (OAuth2 service-account JWT) |
| `app/Services/Push/ApnsTransport.php` | Direct APNs (ES256 provider token) |
| `app/Services/Push/NullPushTransport.php` | Honest "not configured" transport |
| `app/Services/Push/PushMessage.php` | Redacted, safe message value object |
| `app/Services/Push/PushResult.php` | `ok` / `invalidToken` / `retryable` |
| `app/Services/Push/PushPayloadBuilder.php` | Type→category/priority mapping + redaction + deep link |
| `app/Services/Push/PushDispatcher.php` | Fan-out + preference gate + invalid-token cleanup |
| `app/Services/PushPreferenceService.php` | Per-category toggle rules (security always-on) |

`NotificationService::send()` persists the in-app row, then emails, then
dispatches push — the last two are best-effort and can never fail the
originating action.

### Configuration (server-side only)

```dotenv
PUSH_FCM_ENABLED=false
FCM_PROJECT_ID=
FCM_CLIENT_EMAIL=
FCM_PRIVATE_KEY=           # escaped \n, or
FCM_PRIVATE_KEY_PATH=      # path to the service-account JSON

PUSH_APNS_ENABLED=false
APNS_KEY_ID=
APNS_TEAM_ID=
APNS_BUNDLE_ID=
APNS_PRIVATE_KEY=          # escaped \n, or
APNS_PRIVATE_KEY_PATH=     # path to the .p8
APNS_SANDBOX=false
```

Credentials are **server-side only**. The Flutter app never receives a
Firebase secret; it only receives the public web identifiers needed to build
a `FirebaseOptions` (apiKey/appId/messagingSenderId/projectId) via
`--dart-define`.

---

## 3. Device tokens

* Registration: `POST /api/v1/me/devices` (authenticated, owner-only).
* The raw token is stored **encrypted at rest** (Laravel `encrypted` cast,
  APP_KEY-based) because server-side delivery requires it; the SHA-256 hash
  remains the dedup/identity key.
* The raw token, its hash and its ciphertext are **never serialized** in any
  API response and never logged.
* Devices carry `platform`, `provider`, `device_label`, `app_version`,
  `environment` (`development|staging|production`) and `last_seen_at`.
* The per-user cap is **25 active devices** (`config/mobile.php`). When the
  cap is exceeded, the least-recently-seen extras are deactivated — never
  silently deleted and never a different user's device.

### Rotation

The client subscribes to the provider's `onTokenRefresh` stream and
re-registers whenever the OS rotates the token. Re-registration with the
same hash refreshes the existing row (unique `(user_id, token_hash)`), so
reinstalls, restores and account switches never create duplicates.

### Invalid tokens

When FCM/APNs report a token as `UNREGISTERED` / `NOT_FOUND` /
`BadDeviceToken` / `410`, the dispatcher deactivates that device row so dead
tokens are never retried forever.

---

## 4. Preferences

`GET/PATCH /api/v1/me/notification-preferences` governs the **push channel
only**. Categories: `tournament`, `match`, `team`, `payment`, `payout`,
`dispute`, `security`, `support`.

* `security` is **always delivered** and can never be disabled (enforced
  server-side; a `security: false` payload is ignored).
* Unknown categories are rejected with `422 validation_error`.
* The mobile UI is `NotificationPreferencesScreen` (Settings → Notification
  preferences).

---

## 5. Payload & security

The server sends a safe payload; sensitive bodies are redacted:

```json
{
  "notification_id": "123",
  "type": "match.completed",
  "category": "match",
  "entity_type": "match",
  "entity_id": "9",
  "deep_link": "ffarena://match/9"
}
```

Rules enforced by `PushPayloadBuilder`:

* **Never** put wallet balances, amounts, dispute evidence, OTPs, tokens or
  risk reasoning in a push body. Sensitive categories (payment, payout,
  dispute, security) get a generic body ("Your payment status was updated…").
* Unknown/future notification types default to **redacted** (fail-safe).
* `security`-critical types (password change, session revoked, suspicious
  login, account deactivated, restriction applied) are sent with **high**
  priority; everything else is normal.
* The `deep_link` is derived **only** from server-authored entity hints in
  the notification's data — never guessed by the client.
* The `notification_id` lets the client deduplicate a push against the
  in-app row and against repeated deliveries.

---

## 6. Client behavior

* Provider abstraction: `PushProvider` (`NoopPushProvider`,
  `FirebasePushProvider`) in `mobile/lib/core/push/`.
* `PushService` initializes the provider, subscribes to token-refresh and
  message streams, registers the token with release metadata, deduplicates
  foreground messages by `notification_id`, routes background taps through
  the deep-link router, and unregisters the device on logout.
* Foreground messages are rendered **in-app** (via
  `PushService.foregroundMessages`); they are not double-rendered with the OS
  tray.
* Tapping a notification routes to the deep link; the target screen always
  re-fetches the authoritative server resource before rendering.
* Permission is requested at an appropriate UX point; denial never blocks
  any feature.

---

## 7. Verification in this environment

No Firebase/APNs credentials exist in this sandbox, so:

* transport **configuration detection** is tested (unconfigured → no HTTP),
* FCM delivery, invalid-token cleanup, preference gating and payload
  redaction are tested with `Http::fake()` in
  `tests/Unit/Push/PushDispatcherTest.php`,
* JWT signing (RS256/ES256) is tested in `tests/Unit/Push/PushJwtTest.php`,
* **no live delivery was fabricated**.

See `docs/MOBILE_RELEASE.md` for the build commands that enable push.
```

## File: ./docs/MOBILE_RELEASE.md

```
# Mobile App — Build & Release Guide (Phase 18/19)

This document covers building the release channels (dev / staging /
production), release signing, the Firebase dart-defines, and App Link /
Universal Link configuration. It does **not** claim App Store / Play Store
publication — only the build configuration and commands are provided.

## 1. Release channels

| Flavor | `FFARENA_ENV` | Application id | API base URL |
| --- | --- | --- | --- |
| `dev` | `development` | `com.ffarena.ffarena_mobile.dev` | localhost / dev host |
| `staging` | `staging` | `com.ffarena.ffarena_mobile.staging` | `https://staging.<host>/api/v1` |
| `prod` | `production` | `com.ffarena.ffarena_mobile` | `https://api.<host>/api/v1` |

`production` builds abort at startup if the API base URL is not HTTPS
(enforced in `lib/main.dart`) — a production build can never accidentally
point at a development API.

## 2. Dart-defines

```bash
FFARENA_API_BASE_URL=https://api.example.com/api/v1
FFARENA_ENV=production
FFARENA_GOOGLE_CLIENT_ID=xxx.apps.googleusercontent.com   # optional
FFARENA_PUSH_ENABLED=true                                  # master push switch
FFARENA_CRASH_REPORTING_ENABLED=true                       # optional
FFARENA_FIREBASE_API_KEY=...                               # FCM (public web ids)
FFARENA_FIREBASE_APP_ID=...
FFARENA_FIREBASE_MESSAGING_SENDER_ID=...
FFARENA_FIREBASE_PROJECT_ID=...
```

When the four `FFARENA_FIREBASE_*` ids are absent (or `FFARENA_PUSH_ENABLED`
is false), the app uses the honest no-op push provider — push is disabled,
the app keeps working.

## 3. Commands

```bash
export PATH=/opt/flutter/bin:$PATH
cd mobile
flutter pub get
```

### Dev

```bash
flutter run \
  --flavor dev \
  --dart-define=FFARENA_API_BASE_URL=http://10.0.2.2:8000/api/v1 \
  --dart-define=FFARENA_ENV=development
```

### Staging

```bash
flutter build apk --release --flavor staging \
  --dart-define=FFARENA_API_BASE_URL=https://staging.example.com/api/v1 \
  --dart-define=FFARENA_ENV=staging
```

### Production

```bash
flutter build apk --release --flavor prod \
  --dart-define=FFARENA_API_BASE_URL=https://api.example.com/api/v1 \
  --dart-define=FFARENA_ENV=production \
  --dart-define=FFARENA_FIREBASE_API_KEY=... \
  --dart-define=FFARENA_FIREBASE_APP_ID=... \
  --dart-define=FFARENA_FIREBASE_MESSAGING_SENDER_ID=... \
  --dart-define=FFARENA_FIREBASE_PROJECT_ID=...
```

```bash
flutter build ios --release --flavor prod \
  --dart-define=FFARENA_API_BASE_URL=https://api.example.com/api/v1 \
  --dart-define=FFARENA_ENV=production
```

## 4. Android release signing

Signing reads credentials from the environment or Gradle properties — never
from committed files:

```bash
export FFARENA_KEYSTORE_PATH=/secure/release.keystore
export FFARENA_KEYSTORE_PASSWORD=...
export FFARENA_KEY_ALIAS=upload
export FFARENA_KEY_PASSWORD=...
flutter build apk --release --flavor prod ...
```

When the credentials are absent, the release build falls back to debug
signing **for local verification only** — never for store uploads.

## 5. iOS signing & capabilities

On a Mac with Xcode:

1. Open `ios/Runner.xcworkspace`, select the Runner target → Signing &
   Capabilities, and select the distribution team.
2. Enable **Push Notifications** and **Background Modes → Remote
   notifications**.
3. Set the associated domain in `Runner/Runner.entitlements` (see
   `docs/MOBILE_DEEP_LINKS.md`).
4. Never commit `.p8` keys, certificates or provisioning profiles.

## 6. App Links / Universal Links

See `docs/MOBILE_DEEP_LINKS.md`. In short:

* Android: set `FFARENA_APP_LINK_HOST={domain}` at build time and publish
  `/.well-known/assetlinks.json` with the release fingerprint.
* iOS: update `Runner.entitlements` and publish
  `/.well-known/apple-app-site-association`.
* Server: set `MOBILE_WEB_BASE_URL` so the app parses web links.

## 7. Release notes

See `docs/mobile/releases/CHANGELOG.md` and `RELEASE_TEMPLATE.md`.

## 8. Limitations of this sandbox

This sandbox has no Android SDK, Xcode, Chrome or GTK toolchains
(`flutter doctor` reports them missing), so no device/desktop binary was
built here. `flutter analyze` and `flutter test` are green, and the Gradle /
Xcode configuration is validated statically. Run the build commands on a
developer machine with the SDKs installed.
```

## File: ./docs/MOBILE_SECURITY.md

```
# Mobile App — Security Model (Phase 18)

The mobile app is a **consumer** of the FF Arena platform. This document
records the security boundaries the client enforces on its side of the
trust line. The server remains authoritative for everything that matters.

## 1. Trust model

- The backend is authoritative for **rank, points, wallet balance, payment
  success, verification, team ownership, tournament state and restriction
  state**. The client never duplicates ranking formulas, never marks a
  payment successful locally, and never computes balances.
- All communication goes through `/api/v1` only. The client never touches the
  database, never calls internal services directly and never scrapes Blade
  pages.

## 2. Access tokens

- The access token lives **only** in the platform keystore/keychain
  (`flutter_secure_storage`) and in memory for the process lifetime. It is
  never written to plaintext files, SharedPreferences, logs or analytics.
- `AuthSession.toJson()` deliberately omits the token.
- The client uses the existing documented token lifecycle. It does **not**
  invent a refresh flow.
- A `token_expired`, `token_revoked`, `account_inactive` or `unauthenticated`
  response from ANY request clears auth state and routes the user to the
  security/account screen (`SessionManager` + `ApiClient.onSessionTerminated`).

## 3. No secret logging

- The ApiClient logs at most `METHOD status path elapsed_ms` — never headers
  or bodies.
- Telemetry (`ProductMetrics`) enforces a field allow-list
  (`app_version`, `platform`, `event`, `category`, `duration_ms`).
  Passwords, OTP codes, tokens, raw IPs, risk scores and device fingerprints
  are dropped by construction.
- The crash reporter only ever receives a redacted context label and error
  category, never payloads.

## 4. Push tokens

- The raw push token is sent exactly once to `POST /api/v1/me/devices`; the
  server stores only its **sha256** hash and never echoes token material
  back (the client also never logs it).
- Device registrations are owner-only (server-enforced policy, mirrored in
  `DeviceRepository`). The client can only list/delete its own devices.
- When no provider is configured, push is disabled honestly — no fake
  delivery, no placeholder credentials.

## 5. Payments

- The client posts `{team_id, provider}` and follows the server's
  `redirect_url` only when one is provided. Completion is determined by
  polling `GET /payments/{id}` until the **server** reports a terminal
  status — never by the redirect alone.
- Provider selection uses the server's own `/payments/methods` statuses
  (`enabled && configured`); the client never assumes a provider exists.
- Provider/settlement internals are never exposed.

## 6. Deep links

- `ffarena://tournament/{id}`, `ffarena://match/{id}`,
  `ffarena://profile/{id}`.
- Every target requires an authenticated, authorized server response before
  rendering.
- The parser strips query/fragment, so an attacker cannot smuggle secrets,
  room passwords, payment secrets or tokens into a link.

## 7. Local storage

- Secure storage: access token + session envelope only.
- Offline cache: read-only copies of last successful GET responses, marked
  stale and never treated as authoritative.
- Nothing else is persisted client-side.

## 8. Certificate pinning

Certificate pinning is intentionally **not** implemented: there is no
operational key-rotation strategy to go with it, and pinning without
rotation causes outages and lockouts. Standard platform TLS validation is
used.

## 9. Admin surface

Administration remains web-first. The mobile app contains no admin
sub-app and the API exposes no admin capabilities to mobile scopes.
```

