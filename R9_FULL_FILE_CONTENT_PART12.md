# R9 Full File Content Part 12 - Files 166-180

Total files in this part: 15

## File: ./gen_report16.py

```
#!/usr/bin/env python3
"""Generate PHASE16_PRODUCTION_HARDENING_OBSERVABILITY_REPORT.md"""
import os

ROOT = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.join(ROOT, "PHASE16_PRODUCTION_HARDENING_OBSERVABILITY_REPORT.md")

NEW_FILES = [
    "config/observability.php",
    "config/backup.php",
    "config/cors.php",
    "pint.json",
    ".github/workflows/ci.yml",
    "scripts/ci/scan-secrets.sh",
    "scripts/ci/check-openapi.sh",
    "scripts/ci/check-pint.sh",
    "database/migrations/2026_09_09_110000_create_operations_heartbeats_table.php",
    "app/Contracts/ErrorReporterInterface.php",
    "app/Contracts/MetricsInterface.php",
    "app/Support/RequestContext.php",
    "app/Support/Metrics.php",
    "app/Support/CacheKeys.php",
    "app/Support/Logging/RequestContextProcessor.php",
    "app/Support/Logging/RedactSensitiveDataProcessor.php",
    "app/Support/Logging/DomainLogChannel.php",
    "app/Support/Metrics/NullMetrics.php",
    "app/Support/Metrics/LogMetrics.php",
    "app/Support/Metrics/MetricsManager.php",
    "app/Support/ErrorReporting/LogErrorReporter.php",
    "app/Support/ErrorReporting/ErrorReporterManager.php",
    "app/Services/HealthService.php",
    "app/Services/CacheInvalidationService.php",
    "app/Services/BackupService.php",
    "app/Services/OperationsService.php",
    "app/Http/Middleware/SecurityHeaders.php",
    "app/Http/Middleware/HttpMetrics.php",
    "app/Http/Controllers/HealthController.php",
    "app/Http/Controllers/OpsController.php",
    "routes/health.php",
    "app/Console/Commands/HealthCheckCommand.php",
    "app/Console/Commands/BackupCreateCommand.php",
    "app/Console/Commands/BackupVerifyCommand.php",
    "app/Console/Commands/BackupRestoreCommand.php",
    "app/Console/Commands/QueueHealthCommand.php",
    "app/Console/Commands/OpsHeartbeatCommand.php",
    "app/Console/Commands/CleanupOtpCommand.php",
    "app/Console/Commands/CleanupIdempotencyCommand.php",
    "app/Console/Commands/CleanupWebhookDeliveriesCommand.php",
    "app/Console/Commands/CleanupWebhookEventsCommand.php",
    "app/Console/Commands/CleanupNotificationsCommand.php",
    "app/Console/Commands/CleanupFailedJobsCommand.php",
    "app/Console/Commands/CleanupLiveEventsCommand.php",
    "resources/views/admin/ops/dashboard.blade.php",
    "resources/views/admin/ops/failed-jobs.blade.php",
    "docs/PRODUCTION_RUNBOOK.md",
    "docs/DISASTER_RECOVERY.md",
    "docs/INCIDENT_RESPONSE.md",
    "docs/DEPLOYMENT.md",
    "docs/OBSERVABILITY.md",
    "deploy/nginx.conf",
    "deploy/supervisor-ffarena.conf",
    "deploy/systemd-ffarena-scheduler.service",
    "deploy/systemd-ffarena-scheduler.timer",
    "deploy/Dockerfile",
    "deploy/docker-compose.production.yml",
    "tests/Feature/Phase16/Phase16TestCase.php",
    "tests/Feature/Phase16/Stubs/FailingTestJob.php",
    "tests/Feature/Phase16/Stubs/RecordingMetrics.php",
    "tests/Feature/Phase16/HealthTest.php",
    "tests/Feature/Phase16/RequestIdTest.php",
    "tests/Feature/Phase16/ErrorReportingTest.php",
    "tests/Feature/Phase16/QueueTest.php",
    "tests/Feature/Phase16/CacheTest.php",
    "tests/Feature/Phase16/BackupTest.php",
    "tests/Feature/Phase16/ConfigValidationTest.php",
    "tests/Feature/Phase16/SecurityHeadersTest.php",
    "tests/Feature/Phase16/AdminOpsTest.php",
    "tests/Feature/Phase16/CommandsTest.php",
]

MODIFIED_FILES = [
    "bootstrap/app.php",
    "config/logging.php",
    "app/Providers/AppServiceProvider.php",
    "app/Services/AuditLogService.php",
    "app/Services/RegistrationService.php",
    "app/Services/ScoringService.php",
    "app/Services/TournamentLifecycleService.php",
    "app/Services/PaymentGatewayManager.php",
    "app/Http/Middleware/AssignAuditRequestId.php",
    "routes/web.php",
    "routes/console.php",
    ".env.example",
]

NARRATIVE = r"""# PHASE16_PRODUCTION_HARDENING_OBSERVABILITY_REPORT.md

**Project:** FF Arena (Laravel 12.69.1, PHP 8.4.24, SQLite for local/CI)
**Status:** COMPLETE — all verification gates green.

---

## 1. Phase 16 Assumption

No dedicated Phase 16 specification existed, so Phase 16 was defined as
**production hardening + observability + queues + cache + backup + CI/CD +
disaster recovery**: the infrastructure/reliability layer that makes the
Phase 01–15 business platform production-operational. The boundary is strict:
Phase 17+ (ML/AI, external BI, native mobile apps, UX redesign, new business
systems) is not started.

## 2. Current Infrastructure Audit (inspection findings)

Inspected before any code was written:

- **PHP/Composer:** PHP 8.4.24, Composer 2.10.3.
- **`.env`:** `APP_ENV=local`, `APP_DEBUG=true`, `APP_URL=http://localhost`,
  `LOG_CHANNEL=stack`/`LOG_STACK=single`, `DB_CONNECTION=sqlite`,
  `SESSION_DRIVER=database`, `QUEUE_CONNECTION=database`,
  `CACHE_STORE=database`, `FILESYSTEM_DISK=local`, `MAIL_MAILER=log`,
  `BROADCAST_CONNECTION=log`. Redis is configured in `.env` but **not
  installed** as a PHP extension.
- **No** `app/Console/` directory (no custom commands). `routes/console.php`
  only had the `inspire` command.
- **Jobs:** exactly one job (`SendWebhookDelivery`, Phase 15 outbound webhook,
  retry-safe, exponential backoff, endpoint-disable threshold).
- **Logging:** stock Laravel `config/logging.php` (no structured context, no
  redaction, no domain separation).
- **Middleware:** global stack empty; `AssignAuditRequestId` (Phase 13) ran
  only in the `web` group; no security headers, no HTTP metrics, no HSTS.
- **Health:** only Laravel's `/up`.
- **Caching:** `PaymentGatewayManager::statuses()` rebuilt provider statuses
  on every call; no other read-caches existed; no invalidation seam.
- **Queue:** `database` driver, `database-uuids` failed provider, no worker
  config, no failed-job admin surface.
- **Backup:** none.
- **CI/CD, Docker, nginx, docs:** none (`.github/`, `docs/`, `deploy/` absent).
- **CORS:** no `config/cors.php`.
- **Sessions:** database driver; secure cookie only when `SESSION_SECURE_COOKIE`
  set (unset in `.env.example`).

## 3. Environment Validation

`HealthService::productionIssues()` validates the live configuration and
reports redacted, secret-free findings; `php artisan ffarena:health
--production` surfaces them. Detected: `APP_DEBUG=true` in production
(critical), missing `APP_KEY` (critical), `APP_URL` unset/localhost (error),
non-shared cache store (error), sync/null queue (warning). Nothing ever
prints a secret value.

## 4. Health Checks

Three public endpoints + CLI diagnostics (`HealthController`,
`HealthService`):

- `GET /health` → `{status:"ok", service:"FF Arena"}` (200).
- `GET /health/live` → `{status:"ok"}` (200).
- `GET /health/ready` → 200/503 over five checks: database, cache, filesystem,
  queue, config. Public responses expose per-check booleans only.
- `php artisan ffarena:health [--production]` — operator diagnostics
  (redacted reasons, queue stats, storage, production misconfig).

Health routes are loaded **without** the web/api middleware groups (no session,
no CSRF), stay reachable during maintenance mode, and are rate-limited
(`throttle:health`, 300/min/IP).

## 5. Request Correlation

`AssignAuditRequestId` now runs **globally** (web + api): it accepts an
inbound `X-Request-ID` only when it matches `[A-Za-z0-9-]{8,64}`, otherwise
generates a UUID v4; it echoes the id on the response, stores it on the
request (`request_id` + `audit_request_id`), and registers it in
`RequestContext` for logs/errors/metrics/audit. Oversized/control-char ids
are replaced.

## 6. Structured Logging

`config/logging.php` was rebuilt: `single`/`daily` keep their names and
defaults but now run three processors — `PsrLogMessageProcessor`
(placeholder interpolation), `RequestContextProcessor` (request_id, route,
method, user/token ids), `RedactSensitiveDataProcessor` (secret scrubbing).
Domain-separated channels write to their own daily-rotated files:
`security`, `payments`, `webhooks`, `queue`, `audit`, `errors`, `metrics`,
plus a `json` JSON-lines stream.

## 7. Log Rotation / Retention

All file channels rotate daily (`LOG_DAILY_DAYS`, default 14 files kept).
The `json` stream and `single` stream are plain streams; production uses
`LOG_CHANNEL=daily`. Retention, archive policy and the sensitive-data policy
are documented in `docs/OBSERVABILITY.md`.

## 8. Error Tracking Abstraction

`ErrorReporterInterface` with a provider-neutral contract (`report`,
`captureMessage`, `isConfigured`). `ErrorReporterManager` selects `log`
(default, always available) or `sentry` (only when the SDK + `SENTRY_DSN`
exist; otherwise it stays log-only and warns once — never fake reporting).
`LogErrorReporter` writes redacted lines to the `errors` channel.

## 9. Exception Reporting

`bootstrap/app.php` wires the reporter into Laravel's exception pipeline: on
every throwable, `ErrorReporterInterface::report()` receives the exception +
the `RequestContext` snapshot (correlation id, route, user/token ids). The
Phase 15 API renderer still returns the `{error:{code,message,details}}`
envelope with no internals.

## 10. Application Metrics

`MetricsInterface` + `Metrics` facade (exception-safe; a metric can never
break business code) + log-backed `LogMetrics` backend (`METRICS_DRIVER=log`
default, `null` disables). `HttpMetrics` middleware counts requests, response
status classes and latency; queue listeners count `queue.jobs_processed` /
`queue.jobs_failed`. Labels are low-cardinality; no passwords/tokens/IPs.

## 11. Queue Architecture

The database queue (Redis-ready via `REDIS_QUEUE_CONNECTION`) carries the
Phase 15 outbound-webhook job and mail. `QUEUE_CONNECTION=database` stays
SQLite/CI-compatible; production recommends Redis. Workers are supervised
(`deploy/supervisor-ffarena.conf`), and a scheduler heartbeat table
(`operations_heartbeats`) feeds readiness/alerting.

## 12. Queue Configuration

Documented in `deploy/supervisor-ffarena.conf` and the runbook: `--tries=3
--timeout=90 --backoff=30 --sleep=3`, 2 processes. The framework defaults
(retry_after, failed provider `database-uuids`) are unchanged and correct for
this workload.

## 13. Failed Jobs

`OperationsService::failedJobs()` lists failed jobs (uuid + closed columns),
`retryFailedJob`/`retryAllFailed`/`deleteFailedJob` wrap
`queue:retry`/`queue:forget` (uuid-keyed), all admin-only and audited. The
admin ops dashboard + `/admin/ops/failed-jobs` render the queue with retry /
retry-all / delete actions. Old failed jobs are pruned by a scheduled cleanup
(default 30 days).

## 14. Scheduler

`routes/console.php` registers, every minute: `ffarena:ops:heartbeat`; daily
03:00 `ffarena:backup`; weekly Monday 04:00 `ffarena:backup:verify --all`;
hourly `ffarena:cleanup:otp` + `ffarena:cleanup:idempotency`; daily cleanup of
webhook deliveries, webhook events, read notifications, failed jobs and live
events. All jobs are idempotent and only touch operational/temporary rows —
never immutable business or audit records.

## 15. Cache

`CacheKeys` is the central key vocabulary. `PaymentGatewayManager::statuses()`
now caches provider statuses for 60s (exception-safe). Public read-caches for
tournament availability, leaderboards and match views are the documented
targets; **nothing sensitive** (wallet state, sessions, risk data, private
disputes) is ever cached.

## 16. Cache Invalidation

`CacheInvalidationService` is the single seam. Wired into the domain services:
`RegistrationService` invalidates tournament availability;
`ScoringService::submitScore` invalidates leaderboard + match;
`TournamentLifecycleService` invalidates the tournament on publish/close/
start/complete/cancel; provider-status invalidation is explicit. Every
invalidation is exception-safe (can never break the write it follows).

## 17. Session Scaling

Sessions already use the database driver (multi-instance safe). Production
requires `SESSION_SECURE_COOKIE=true` + `SESSION_HTTP_ONLY=true` + HTTPS;
logout/revocation already works across instances via `SessionManagementService`
(Phase 14). Documented in the runbook and `.env.example`.

## 18. Filesystem / Storage

Private files (dispute evidence, identity docs, avatars, exports) live on the
`local` disk = `storage/app/private` (never publicly served). The `public`
disk is only for explicitly-public assets. `BackupService` snapshots the
private directory into each backup. `HealthService::storageStats()` reports
private-storage size/files on the ops dashboard.

## 19. Database Hardening

The existing schema already carries justified indexes (registration
uniqueness, roster integrity, check-in/waitlist, live-event cursors,
notification user/read, OTP expiry). No speculative index additions were
made. The ops dashboard queries use indexed status/date columns only.

## 20. Connection Safety

Backups resolve the **default connection's** driver/database (not a hardcoded
name). No production credentials are hardcoded anywhere; everything is env
driven. SQLite remains the local/CI default; MySQL/PostgreSQL configuration
is documented (`.env.example`, `docs/DEPLOYMENT.md`).

## 21. Transaction Safety

No business transaction was modified. Infrastructure retries are idempotent:
`SendWebhookDelivery` re-uses its `delivery_id`; failed-job retry re-dispatches
the exact payload; backup/restore never touch the live database in dry-run.
Payment/wallet/payout/scoring/registration state machines are untouched.

## 22. Backup

`BackupService::create()` writes a timestamped, chmod-0600 backup directory
into private storage: `manifest.json` (SHA-256, driver, sizes),
`database.sqlite` (SQLite `VACUUM INTO` consistent snapshot; MySQL/PgSQL via
mysqldump/pg_dump when present — credentials passed by env, never the process
list), and `private-files/` (recursion-safe). Failures are **never** silent:
a failing backup deletes its partial dir, notifies admins, audits the failure
and returns `ok:false`.

## 23. Backup Verification

`BackupService::verify()` checks existence, non-emptiness, SHA-256 checksum
and (SQLite) `PRAGMA integrity_check`. `php artisan ffarena:backup:verify
[--name|--all]` exits non-zero on failure; the scheduler verifies weekly;
failure notifies admins and audits.

## 24. Restore

`php artisan ffarena:backup:restore <name> [--target=…]` copies the snapshot
to an isolated target, integrity-checks it, and **never** overwrites the live
database. The manual live-restore procedure (with exact commands) is in
`docs/DISASTER_RECOVERY.md`.

## 25. Disaster Recovery

`docs/DISASTER_RECOVERY.md` documents RPO/RTO (honest: ≤24h/backup), the
backup anatomy, SQLite + MySQL/PgSQL restore, private-file restore, config
recovery, restart, cache warmup, smoke verification and escalation. It does
not invent guarantees the tooling cannot provide.

## 26. Deployment

`docs/DEPLOYMENT.md` documents the zero-downtime ordering (build → additive
migrate → workers → web → traffic switch → cache invalidate → verify → deprecate),
config/route/view caching, and the production environment checklist.

## 27. Rollback

Application rollback = previous release + `queue:restart` + cache clear.
Destructive migrations are never rolled back with `migrate:rollback`; restore
from backup instead (documented in both the runbook and DR runbook).

## 28. CI/CD

`.github/workflows/ci.yml` (GitHub Actions, PHP 8.2 + 8.4 matrix, SQLite, no
services needed) runs: composer validate, dependency install, `.env` + key
generate, **PHP lint** (every file), **config validation + config:cache +
route:list**, **migrate:fresh --seed**, **Pint (Phase 16 file set)**,
**composer audit**, **secret scan**, **php artisan test**, **OpenAPI
validation**. A local equivalent is `bash scripts/ci/*.sh`.

## 29. Static Analysis

Pint is configured (`pint.json`, Laravel preset). The Phase 16-owned file set
(55 + 4 config files) is Pint-clean and CI enforces it via
`scripts/ci/check-pint.sh`. The legacy Phase 01–15 codebase predates the
config and is adopted incrementally (honest limitation, §64).

## 30. Composer Security

`composer audit --no-interaction` runs in CI and locally:
**"No security vulnerability advisories found."** Dependencies are never
auto-updated blindly.

## 31. Secret Scanning

`scripts/ci/scan-secrets.sh` fails the build on committed real secrets
(app keys, AWS keys, private key blocks, GitHub tokens, live Stripe keys,
Slack tokens, Google API keys), reporting only the pattern class — never the
matched value. `.env`/`.env.example` are excluded. Local run: **passed**.

## 32. Code Quality

Verified: no `dd()`/`dump()`/`var_dump`/`print_r` left in app code, no test
bypasses, no fake provider success, no hardcoded secrets, no disabled
authorization. (Grep-verified during implementation.)

## 33. Maintenance Mode

`php artisan down` shows the standard maintenance page; `/up`, `/health`,
`/health/live`, `/health/ready` stay reachable (preventRequestsDuringMaintenance
exceptions). Admins keep access via the documented procedure; ongoing
financial operations are never interrupted (queued jobs drain first).

## 34. Security Headers

`SecurityHeaders` (global) emits `X-Content-Type-Options: nosniff`,
`Referrer-Policy: strict-origin-when-cross-origin`,
`X-Frame-Options: SAMEORIGIN`, `Permissions-Policy` (camera/mic/geolocation/
payment off), and `Strict-Transport-Security` **only over HTTPS** (opt-in
`includeSubDomains`, opt-in CSP via `SECURITY_CSP_ENABLE` so it can be rolled
out without breaking the UI). Verified by tests + live curl.

## 35. HTTPS

`docs/DEPLOYMENT.md` requires HTTPS, secure cookies, correct `APP_URL`,
HTTPS callbacks for OAuth/payments/webhooks. `deploy/nginx.conf` terminates
TLS and redirects HTTP→HTTPS. HSTS is emitted at both the nginx and app
layers.

## 36. Rate-Limit Infrastructure

All Phase 10/14/15 limiters are keyed for shared state (Redis/database)
already; the new `health` limiter (300/min/IP) covers the probes. Rate limits
are never local-memory counters — they go through the cache store.

## 37. Operational Dashboard

`/admin/ops` (admin only): readiness, queue backlog/oldest age, failed jobs,
scheduler heartbeat, webhook endpoint/failure counts, storage usage, latest
backup, production-config issues, and audited controls (failed-job retry/
delete/retry-all, cache flush by whitelisted namespace, backup, backup
verify). `/admin/ops/health` returns JSON checks.

## 38. Alerting

Provider-neutral: critical events land in `errors`/`queue` channels;
`BackupService` notifies admins (Phase 11) on backup/verify failure. External
alerting can consume the `metrics` JSON-lines stream; suggested thresholds are
in `docs/OBSERVABILITY.md`. No SaaS integration is hard-wired.

## 39. Incident Response

`docs/INCIDENT_RESPONSE.md`: severity levels, roles, triage, and playbooks for
security, payment, database, queue, webhook, data-corruption, account-
compromise and cheating/fraud incidents.

## 40. Production Runbook

`docs/PRODUCTION_RUNBOOK.md`: layout, deploy, migrations, rollback, cache,
queue workers, scheduler, logs (channel table), health checks, backups,
maintenance and incident procedure — every command verified against the code.

## 41. Disaster-Recovery Runbook

`docs/DISASTER_RECOVERY.md`: RPO/RTO, backup anatomy, restore commands for
SQLite/MySQL/PostgreSQL, file restore, config recovery, restart, cache
warmup, smoke verification and escalation matrix.

## 42. Admin Operational Controls

`OpsController` + `OperationsService`: failed-job inspection/retry/retry-all/
delete, cache flush (whitelisted namespaces `providers`|`public`), backup
trigger and backup verification — every action audited through Phase 13.

## 43. Audit Integration

New closed audit actions added to `AuditLogService::ACTIONS`:
`ops.backup_created`, `ops.backup_verified`, `ops.backup_restored`,
`ops.cache_flushed`, `ops.failed_job_retried`, `ops.failed_jobs_retried`,
`ops.failed_job_deleted`. Admin actions record the acting admin; backup
attempts are audited even on failure. Secrets are never audited.

## 44. Notification Integration

`BackupService::notifyAdmins()` uses the existing Phase 11
`NotificationService::sendToMany()` (admins only) for backup/verify failures.
No second notification engine was created.

## 45. Realtime Integration

No new realtime streams were added; the existing Phase 12 `LiveEventService`
is unchanged. Operational signals go to logs/metrics, never to live streams.

## 46. API Integration

No operational endpoints were added to the public API. The Phase 15
architecture (versioned `/api/v1`, bearer-only, admin scopes reserved) is
unchanged; infra controls are web/admin-only, keeping ordinary clients away
from infrastructure.

## 47. Observability Data Retention

`config/observability.php` `retention.*` + scheduled cleanup: OTP 1d,
idempotency 2d, read notifications 180d, webhook deliveries 30d, inbound
webhook events 90d, live events 30d, failed jobs 30d, logs 14d, backups 14
(count). Immutable business/audit records are never deleted.

## 48. Database Changes

One new migration:
`2026_09_09_110000_create_operations_heartbeats_table.php` — a single-row-per-
source heartbeat table (`source` unique, `last_beat_at`). No other schema
changes; no index churn. Total migrations: **28**.

## 49. New Files

71 new files: 3 config, 1 pint config, 1 CI workflow, 3 CI scripts,
1 migration, 2 contracts, 13 support classes, 4 services, 2 middleware,
2 controllers, 1 route file, 13 console commands, 2 admin ops views,
5 docs, 5 deploy templates, 13 test files. Complete content in §67.

## 50. Modified Files

12 modified files: `bootstrap/app.php`, `config/logging.php`,
`app/Providers/AppServiceProvider.php`, `app/Services/AuditLogService.php`,
`app/Services/RegistrationService.php`, `app/Services/ScoringService.php`,
`app/Services/TournamentLifecycleService.php`,
`app/Services/PaymentGatewayManager.php`,
`app/Http/Middleware/AssignAuditRequestId.php`, `routes/web.php`,
`routes/console.php`, `.env.example`. Complete final content in §67.

## 51. Tests

13 new test classes (65 tests) in `tests/Feature/Phase16/`: health (live/
ready/DB/cache/filesystem failure/redaction/maintenance), request id
(generated/accepted/invalid/oversized/control-chars/header/processor/redaction),
error reporting (reporter resolution/log fallback/redaction/envelope), queue
(dispatch/failed/retry/retry-all/closed columns), cache (hit/miss/invalidation/
provider statuses/namespace whitelist/no-private-data), backup (in-memory
refusal/create/verify/restore dry-run/corrupt manifest/retention/empty list/
unknown), config validation (production issues/safe diagnostics), security
headers (baseline/HSTS-http/HSTS-https/health limiter/API/CORS), admin ops
(access matrix/dashboard/health/cache flush audit/backup audit), commands
(health/queue health/heartbeat idempotence/cleanup pruning).

## 52. Exact Test Results

```
php artisan test
Tests: 760 passed (2327 assertions)   ← Phase 15 baseline was 695/2140
```

Phase 16 isolated: `65 passed (187 assertions)`. Phase 01–15 regression: all
green.

## 53. Migration Results

```
php artisan migrate:fresh --seed --force   → success (28 migrations)
```

## 54. Lint Results

`php -l` over every PHP file in `app/`, `bootstrap/`, `config/`,
`database/migrations`, `routes/`, `tests/`: **all clean**.

## 55. Static-Analysis Results

- Pint: Phase 16 file set (55 files + 4 config files) — **PASS**
  (`php vendor/bin/pint --test …`).
- Composer audit: **No security vulnerability advisories found.**
- `composer validate --no-check-publish --strict`: **valid**.
- Legacy Phase 01–15 code predates the Pint config and is excluded from the
  CI gate (adopted incrementally) — documented honestly in §64.

## 56. Route Results

`php artisan route:list`: **256 routes** (was 244): +3 health, +9 admin ops.
Existing 244 routes semantically unchanged. `/health*` carry only the
`health` throttle; `/admin/ops/*` carry `web`+`auth`+`admin`.

## 57. HTTP Smoke (live server)

```
GET /health/live        → 200 {"status":"ok"}
GET /health             → 200 {"status":"ok","service":"FF Arena"}
GET /health/ready       → 200 (database/cache/filesystem/queue/config all ok)
Headers on /health      → X-Content-Type-Options: nosniff, Referrer-Policy,
                          X-Frame-Options: SAMEORIGIN, Permissions-Policy,
                          X-Request-ID: <uuid>
GET /admin/ops (guest)  → 302 (login redirect)
GET /api/v1/tournaments → 200
```

## 58. Production Build Verification

`config:cache`, `route:cache`, `view:cache` all succeed; `composer install`
(and `--no-dev` compatibility) verified; `queue:work` command runs; scheduler
commands listed and executed. No credentials printed during verification.

## 59. Backup/Restore Verification

`php artisan ffarena:backup` → created `ffarena-20260909-134731` (794,624
bytes, SHA-256 recorded). `ffarena:backup:verify` → exists / non_empty /
checksum / integrity all ✓. `ffarena:backup:restore <name>` → restored into an
isolated target, integrity passed, live DB untouched. The live database was
never overwritten.

## 60. CI Verification

Local equivalents of every CI step ran green: lint, config validation,
migrations, Pint (scoped), composer audit, secret scan, full tests, OpenAPI
validation.

## 61. Deployment Architecture

nginx + PHP-FPM + supervisor (queue) + systemd timer (scheduler) as the
primary reference; optional Dockerfile + docker-compose.production.yml for
container deployments. Templates in `deploy/`.

## 62. SQLite vs Production Database

SQLite remains the local/CI/test default (`:memory:` for tests, file for dev).
Production recommendation: MySQL/PostgreSQL + Redis (shared cache/queue) +
persistent private storage — documented, not silently enforced.

## 63. Performance Findings

The ops dashboard and health probes run on indexed status/date columns.
`PaymentGatewayManager::statuses()` is cached (60s) to avoid per-request
rebuilds. No N+1 or micro-optimization work was performed (deliberately
avoided speculative changes).

## 64. Known Limitations

- Pint is only enforced on the Phase 16 file set; the Phase 01–15 codebase is
  adopted incrementally.
- Backup for MySQL/PostgreSQL requires `mysqldump`/`pg_dump` on PATH (honest
  failure otherwise).
- Redis is not installed in this sandbox; Redis-backed cache/queue/rate-limit
  paths are configuration-complete but exercised via the database/array
  equivalents.
- Sentry reporting is a documented seam, not an installed dependency.
- No external alerting SaaS is integrated (metrics stream is the hook).

## 65. Security Considerations

Secrets never in logs (redaction processor), never in health responses,
never in backups' public space (private disk, 0600), never in CI output
(pattern-class reporting). Request ids are format-validated. Health probes
are maintenance-proof and throttled. Ops controls are admin-only + audited.
CORS is an explicit origin list (never `*`) with credentials off.

## 66. Production Checklist

- [x] `APP_ENV=production`, `APP_DEBUG=false`, strong `APP_KEY`, real
  `APP_URL` (validated by `ffarena:health --production`)
- [x] Shared cache (redis/database), database/redis queue
- [x] `SESSION_SECURE_COOKIE=true`, HTTPS
- [x] `SANCTUM_TOKEN_PREFIX` set
- [x] `CORS_ALLOWED_ORIGINS` explicit
- [x] Payment/webhook secrets set; `PAYMENT_WEBHOOK_SECRET` random 32+ bytes
- [x] Scheduler running on one host; queue workers supervised
- [x] Backups scheduled + weekly verification
- [x] CI green (lint, migrations, tests, audit, secret scan, OpenAPI)
- [x] Docs + runbooks present and matching the code

---

# 67. Complete Final Content of Every New/Modified File

Every file is reproduced below in full, byte-for-byte against the workspace.
No placeholders, omissions, TODOs or "existing code" markers.
"""


def render_blocks(files, heading):
    out = [f"\n## {heading}\n"]
    for rel in files:
        path = os.path.join(ROOT, rel)
        with open(path, encoding="utf-8", errors="replace") as fh:
            content = fh.read()
        ext = rel.rsplit(".", 1)[-1] if "." in rel else "txt"
        lang = {
            "php": "php", "json": "json", "py": "python", "md": "text",
            "sh": "bash", "yml": "yaml", "conf": "text", "service": "text",
            "timer": "text", "example": "text", "blade.php": "blade",
        }.get(ext, "text")
        if rel.endswith(".blade.php"):
            lang = "blade"
        out.append(f"\n### `{rel}`\n\n```{lang}\n{content.rstrip()}\n```\n")
    return "".join(out)


def main():
    parts = ["# PHASE16_PRODUCTION_HARDENING_OBSERVABILITY_REPORT.md\n\n", NARRATIVE]
    parts.append(render_blocks(NEW_FILES, "New Files"))
    parts.append(render_blocks(MODIFIED_FILES, "Modified Files (final content)"))
    with open(OUT, "w", encoding="utf-8") as fh:
        fh.write("".join(parts))
    print(f"Wrote {OUT} ({sum(len(p) for p in parts)} chars)")


if __name__ == "__main__":
    main()
```

## File: ./gen_report17.py

```
#!/usr/bin/env python3
"""Generate PHASE17_UI_UX_ACCESSIBILITY_SEO_PERFORMANCE_REPORT.md"""
import os

ROOT = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.join(ROOT, "PHASE17_UI_UX_ACCESSIBILITY_SEO_PERFORMANCE_REPORT.md")

NEW_TEXT_FILES = [
    "app/Support/Seo.php",
    "app/Http/Controllers/SitemapController.php",
    "resources/views/seo/sitemap.blade.php",
    "resources/views/components/alert.blade.php",
    "resources/views/components/status-pill.blade.php",
    "resources/views/components/empty-state.blade.php",
    "resources/views/components/button.blade.php",
    "resources/views/vendor/pagination/tailwind.blade.php",
    "resources/views/vendor/pagination/simple-tailwind.blade.php",
    "public/css/app.css",
    "public/js/app.js",
    "public/favicon.svg",
    "lang/en/ui.php",
    "tests/Feature/Phase17/Phase17TestCase.php",
    "tests/Feature/Phase17/SeoTest.php",
    "tests/Feature/Phase17/SitemapRobotsTest.php",
    "tests/Feature/Phase17/AccessibilityTest.php",
    "tests/Feature/Phase17/ResponsiveTest.php",
    "tests/Feature/Phase17/PerformanceTest.php",
    "tests/Feature/Phase17/DiscoveryTest.php",
    "tests/Feature/Phase17/UiComponentsTest.php",
    "tests/Feature/Phase17/SmokeMatrixTest.php",
    "package-lock.json",
]

NEW_BINARY_FILES = [
    "public/favicon.ico (Windows ICO, 32x32, PNG-compressed, 374 bytes)",
    "public/apple-touch-icon.png (PNG 180x180 RGBA, generated via PHP GD)",
]

MODIFIED_FILES = [
    "resources/views/layouts/app.blade.php",
    "resources/views/home.blade.php",
    "resources/views/tournaments/index.blade.php",
    "resources/views/tournaments/show.blade.php",
    "resources/views/leaderboard/show.blade.php",
    "resources/views/profile/show.blade.php",
    "resources/views/auth/login.blade.php",
    "resources/views/auth/register.blade.php",
    "resources/views/notifications/index.blade.php",
    "resources/views/live/poll.blade.php",
    "resources/views/matches/_bracket_card.blade.php",
    "resources/css/app.css",
    "resources/js/bootstrap.js",
    "app/Http/Controllers/HomeController.php",
    "app/Http/Controllers/TournamentController.php",
    "app/Http/Controllers/LeaderboardController.php",
    "app/Http/Controllers/ProfileController.php",
    "app/Providers/AppServiceProvider.php",
    "routes/web.php",
    "config/app.php",
    ".env.example",
    "scripts/ci/check-pint.sh",
    # Second pass — long-tail views hand-rewritten against the design system
    "resources/views/auth/forgot-password.blade.php",
    "resources/views/auth/phone-login.blade.php",
    "resources/views/auth/phone-verify.blade.php",
    "resources/views/auth/reset-password.blade.php",
    "resources/views/auth/verify-email.blade.php",
    "resources/views/profile/edit.blade.php",
    "resources/views/settings/security.blade.php",
    "resources/views/settings/sessions.blade.php",
    "resources/views/settings/login-history.blade.php",
    "resources/views/settings/connected-accounts.blade.php",
    "resources/views/settings/payment-methods.blade.php",
    "resources/views/wallet/index.blade.php",
    "resources/views/payment/methods.blade.php",
    "resources/views/payment/pending.blade.php",
    "resources/views/payment/show.blade.php",
    "resources/views/teams/register.blade.php",
    "resources/views/teams/show.blade.php",
    "resources/views/tournaments/create.blade.php",
    "resources/views/tournaments/edit.blade.php",
    "resources/views/tournaments/scoring.blade.php",
    "resources/views/matches/show.blade.php",
    "resources/views/disputes/create.blade.php",
    "resources/views/disputes/show.blade.php",
    "resources/views/support/create.blade.php",
    "resources/views/support/index.blade.php",
    "resources/views/support/show.blade.php",
    "resources/views/moderation/index.blade.php",
    "resources/views/moderation/security.blade.php",
    "resources/views/admin/dashboard.blade.php",
    "resources/views/admin/audit.blade.php",
    "resources/views/admin/accounts/index.blade.php",
    "resources/views/admin/accounts/show.blade.php",
    "resources/views/admin/wallet.blade.php",
    "resources/views/admin/payments.blade.php",
    "resources/views/admin/payouts.blade.php",
    "resources/views/admin/settlements.blade.php",
    "resources/views/admin/settlement.blade.php",
    "resources/views/admin/security/dashboard.blade.php",
    "resources/views/admin/security/events.blade.php",
    "resources/views/admin/security/incidents.blade.php",
    "resources/views/admin/security/user.blade.php",
    "resources/views/admin/security/users.blade.php",
    "resources/views/admin/support.blade.php",
    "resources/views/admin/support_ticket.blade.php",
    "resources/views/admin/ops/dashboard.blade.php",
    "resources/views/admin/ops/failed-jobs.blade.php",
    "resources/views/admin/analytics/index.blade.php",
    "resources/views/admin/analytics/tournament.blade.php",
    "resources/views/admin/analytics/tournaments.blade.php",
    "resources/views/admin/analytics/financial.blade.php",
    "resources/views/admin/analytics/disputes.blade.php",
    "resources/views/admin/analytics/security.blade.php",
    "resources/views/admin/analytics/support.blade.php",
]

NARRATIVE = r"""**Project:** FF Arena (Laravel 12.69.1, PHP 8.4.24, SQLite, Tailwind CSS v4 + Vite 7)
**Status:** COMPLETE — all verification gates green.

**Verification (final):** full suite **812 tests / 2560 assertions** green
(Phase 17: **52 tests / 233 assertions** across 9 files); scoped Pint
**PASS 77 files**; `php -l` clean; `view:cache` compiles every Blade view;
`route:list` **258 routes**; `migrate:fresh --seed` **28 migrations** run;
`npm install && npm run build` green (CSS 47.97 kB / gzip 11.96 kB, JS
0.00 kB); `public/build/` removed after verification; no stray `artisan
serve` processes.

---

## 1. Phase 17 Assumption

No dedicated Phase 17 specification existed. Phase 17 was defined as
**world-class UI/UX + accessibility + SEO + frontend performance** — the
presentation layer over the Phase 01–16 business platform. Backend business
logic, domain services, scoring/payment/wallet/payout/dispute/authorization
rules, API contracts and webhook signatures are **unchanged**.

## 2. Current UI Audit (inspection findings)

Inspected before writing any code:

- 67 Blade views, **one shared layout** (`layouts/app.blade.php`) whose entire
  design system was a ~150-line inline `<style>` block; `welcome.blade.php`
  was the untouched Laravel starter page (unused — `/` is `home.blade.php`).
- No Blade components directory; no vendor pagination views (Laravel's
  unstyled Tailwind pagination was used with no Tailwind actually loaded).
- No `@vite` wiring in the layout; `resources/css/app.css` was a 15-line
  Tailwind skeleton; `resources/js/app.js` only bundled **Axios, which nothing
  used** (all AJAX uses native `fetch`) — 51.5 kB of dead weight.
- `public/build/` empty (never built); `public/favicon.ico` was **0 bytes**;
  `public/robots.txt` was permissive (`Disallow:` nothing); no sitemap; no
  canonical/meta/OG/JSON-LD anywhere; no skip-link, landmarks, focus styles,
  aria-live, reduced-motion or mobile menu.
- Forms used bare `<label>` without `for`, no autocomplete, no
  `aria-invalid`/`aria-describedby` error wiring.
- Live feed (`live/poll.blade.php`) and the unread badge polled every 10 s
  **even in hidden tabs**, with no backoff; the nav had no mobile layout.

## 3. Design-System Audit

No reusable vocabulary existed beyond a handful of ad-hoc inline-styled
classes (`.btn`, `.card`, `.pill`, `.flash`, `.grid`, `.stat`, `.bracket-*`,
`.tag`, `.muted`) and CSS custom properties (`--bg`, `--panel`, …) embedded in
the layout's inline `<style>`.

## 4. Design System

A complete design system was built in **one source of truth**
(`public/css/app.css`, mirrored to the Vite entry `resources/css/app.css`):

- tokens (surfaces, text, brand, semantic colors, radii, a 4px spacing scale,
  focus ring, min touch-target, fonts, motion);
- light reset + base typography + link focus affordances;
- `.skip-link`; layout shell (`site-header`/`nav-bar`/`site-main`/
  `site-footer`/`container`);
- buttons (`btn`, variants primary/cyan/green/danger/ghost, sizes sm/lg,
  disabled, block, `[data-loading]`), status pills (paired dot **and** text),
  cards, stats, grids, forms (fields, labels, checkbox/radio, help-text,
  `aria-invalid` styling), responsive `.table-wrap` tables with sticky headers
  + captions, alerts (`.alert-*`), empty states, accessible pagination,
  breadcrumbs, bracket components, avatars, spinners/skeletons, utilities and
  `.sr-only`;
- `@media (prefers-reduced-motion: reduce)`, `@media (pointer: coarse)` (44px
  targets), `@media (max-width: 900px)` mobile nav, print styles.

The existing visual identity (dark navy/cyan/purple esports theme) and the
existing class/variable names were preserved, so every untouched page adopts
the system automatically.

## 5. Layout Architecture

`layouts/app.blade.php` was rewritten: semantic `header > nav` / `main`
(`id="main"` + skip link target) / `footer`; consistent header with brand
(SVG mark), a responsive **mobile menu toggle** (`aria-expanded`/
`aria-controls`), role-gated links (unchanged), a server-rendered unread badge
(`aria-live="polite"`), auth-state aware CTA, and a footer with Sitemap /
robots.txt links. Admin links remain visible only to the matching roles.

## 6. Mobile Responsiveness

- `<meta name="viewport">` retained; nav collapses to a hamburger at
  ≤ 900 px; tap targets grow to 44 px on coarse pointers.
- Tables wrap horizontally in `.table-wrap` (leaderboard, registered teams,
  waitlist); tournament cards use auto-fill grids (no fixed widths); bracket
  columns scroll horizontally with `scroll-snap`.

## 7. Accessibility Standard

Targeted **WCAG 2.2 Level AA** (not certified). Implemented and tested:
keyboard operation, focus visibility + not-obscured (no sticky header),
target size (≥ 40 px desktop / 44 px touch), labels, error identification,
accessible authentication (autocomplete + paste-friendly, no puzzles),
status messages, contrast (muted text lifted to ≥ 4.5:1), semantic structure.

## 8. Semantic HTML

`header`, `nav`, `main`, `footer`, `section`, `article`, `dl/dt/dd` (stats),
`table/caption/thead/tbody/th[scope]`, `fieldset/legend`, `button` (no
div-as-button) are used on every rewritten page.

## 9. Keyboard Navigation

Skip-to-content link; visible `:focus-visible` outline; logical tab order;
Escape closes the mobile menu and returns focus to the toggle; click-outside
closes it; auto-collapse on desktop resize; no focus trapping.

## 10. Screen Readers

`aria-expanded`/`aria-controls` on the menu; `aria-live="polite"` unread
badge + live feed; `role="status"`/`role="alert"` flash messages; `sr-only`
table captions, filter labels and toggle labels; accessible names on bracket
links. No ARIA was added where native semantics suffice.

## 11. Focus

Global `:focus-visible` ring; skip link; bracket/breadcrumb/pagination focus
targets; no sticky header to obscure focus.

## 12. Target Size

Buttons/nav/pagination/checkbox hit areas ≥ 40 px (44 px on touch) via
`--target-min` and the `pointer: coarse` media query.

## 13. Color Contrast

Muted text raised to `#9aa4c8`/`#b6bfe0` (≥ 4.5:1 on the dark surfaces);
status pills always pair a color dot **with text**; winner marked by a "W"
badge + text, never color alone; error/success use border + text + glyph.

## 14. Reduced Motion

`prefers-reduced-motion: reduce` disables all transitions/animations
(spinner, skeleton, hover transforms); the JS also sets `scroll-behavior:
auto` when the user prefers reduced motion.

## 15. Forms

Auth forms (login/register) fully wired: `label[for]`, correct input types,
autocomplete (`name`, `username`, `email`, `tel`, `current-password`,
`new-password`), `aria-invalid` + `aria-describedby` on errors, per-field
`.form-error` messages. In the second pass every long-tail form (wallet,
payment, support, disputes, teams, tournaments create/edit/scoring,
settings, profile/edit, admin moderation/settlement/accounts) was
hand-rewritten with the same field/input/error semantics, labelled controls,
and correct input types, while preserving every business conditional and
route.

## 16. Authentication UX

Login/register redesigned; password-manager-friendly autocomplete kept; no
inaccessible CAPTCHA or puzzles; Google sign-in and phone-login entry points
preserved verbatim.

## 17. Account / Settings UX

Profile page redesigned (avatar fallback initial, username, role pill, bio,
country/region, joined date, edit link). Settings pages inherit the design
system.

## 18. Tournament Discovery

`/tournaments` gained GET-based filters — search (`q`, LIKE-wildcard-escaped),
status, game mode — with `withQueryString()` pagination, accessible labels,
and a "Clear" reset. Default (unfiltered) listing is unchanged.

## 19. Tournament Detail UX

`tournaments.show` rebuilt with breadcrumbs, prize/entry/rules stat cards,
organizer controls, "your team" card, register/waitlist CTAs, bracket, and
responsive captioned tables — every business conditional preserved.

## 20. Registration UX / 21. Check-in / 22. Waitlist

States surfaced with text+icon pills (check-in open/closed, waitlist
position, checked-in) on the tournament page; no false confirmations; no
other team's private data exposed (waitlist positions only to the team's
captain).

## 23. Bracket UX / 24. Match UX

Bracket cards (`matches/_bracket_card`) restyled with text statuses (Done/
Disputed/Live/Ready/Bye) + winner "W" badge and accessible names; horizontal
scroll with snap; room credentials remain visibility-gated (unchanged logic).

## 25. Score Submission UX

Unchanged (server-authoritative, Phase 06) — inherits the design system.
No client-side point totals were introduced.

## 26. Leaderboard UX

`leaderboard.show` rebuilt: breadcrumbs, responsive captioned table
(rank/team/matches/kills/place/kill/total), empty state, deterministic-
ordering note retained. Ranking calculation untouched.

## 27. Profile UX / 29. Wallet / 28. Payment / 30. Dispute / 31. Support /
32. Admin / 33. Notifications / 34. Realtime

Notifications page redesigned (semantic list, status pills, empty state,
pagination); live feed made visibility-aware with backoff, capped DOM (12
items), `aria-live` list and reduced-motion-safe. Wallet, payment, dispute,
support and admin pages adopt the shared design system (buttons, tables,
pills, alerts, forms) without business-logic changes; internal moderation
notes and financial internals remain staff-only (unchanged authorization).

## 35. Loading / 36. Empty / 37. Error / 38. Success States

Shared `x-alert` (role=status/alert), `x-empty-state`, `.spinner`, `.skeleton`
and `[data-loading]` patterns; duplicate-submit-safe buttons; no
inaccessible spinners without status text. Server errors never expose stack
traces (Phase 15/16 envelope preserved).

## 39. SEO Architecture

Indexable public pages opt in via a request-scoped `Seo` manager; **every
other page is noindex by default**, so SEO can never override authorization.
Indexable: homepage, tournament listing, public tournament details, live/
finished leaderboards, public profiles. Not indexable: admin, wallet,
payments, notifications, settings, support, disputes, private/limited
profiles, drafts/cancelled tournaments, auth pages.

## 40. Titles / 41. Descriptions / 42. Canonical URLs

Unique server-rendered titles + ≤ 160-char descriptions + canonical URLs for
every public page; description fallbacks never contain private data. The
listing page canonicalizes to its clean URL regardless of filters.

## 43. robots.txt / 44. XML Sitemap

`/robots.txt` is now a dynamic route (absolute `Sitemap:` URL) blocking
`/admin`, `/wallet`, `/settings`, `/notifications`, `/support`, `/disputes`,
`/moderation`, `/organizer`, `/profile/edit`, `/login`, `/register`, auth/
OTP, `/api/`, `/webhooks/`, and allowing `/tournaments` + `/profile/`.
`/sitemap.xml` lists only indexable resources (homepage, listing, public
tournaments, live/finished leaderboards, public profiles), capped at 50 000
URLs, cached 1 h, `X-Robots-Tag: noindex` on the sitemap itself. robots.txt
is documented as a crawl hint, never a security control.

## 45. Structured Data

Accurate JSON-LD only: `WebSite` (homepage), `Event` (public tournaments —
with `eventStatus`, `eventAttendanceMode: Online`, `VirtualLocation`,
organizer, `startDate`, and `offers` when entry fee > 0; **never** for
cancelled tournaments), `ProfilePage`/`Person` (public profiles only). All
JSON is encoded with HTML-escaping flags so DB text can never break out of
the script tag; no fake ratings/reviews/event data.

## 46. Open Graph / 47. Social / 48. Favicon / 49. URL Quality

`og:site_name/title/description/type/url` (+ `twitter:*`) emitted for every
page; `og:image` only where a public image exists (currently none — honest).
New SVG favicon + generated 32×32 ICO + 180×180 apple-touch-icon + theme
color. Tournament URLs were already slug-based (kept); profile URLs remain
ID-based (unchanged, stable) with canonicalization to prevent duplicates.

## 50. Core Web Vitals / 51. LCP / 52. INP / 53. CLS

Targets (LCP ≤ 2.5 s, INP < 200 ms, CLS < 0.1) are **not claimed — not
measured with a real browser here**. Structural work done: no render-
blocking inline CSS (external stylesheet), deferred JS, no unused Axios
bundle, server-rendered HTML, no image CLS (avatars sized; no hero images),
visibility-aware polling, capped live-feed DOM. Real CWV must be measured
with Lighthouse/CrUX in production (§75).

## 54. JavaScript / 55. CSS / 56. Images / 57. Fonts / 58. Caching

- JS: dead Axios bundle removed (51.52 kB → 0 kB); shared behaviours in one
  deferred `public/js/app.js`; visibility-aware pollers with backoff.
- CSS: single design system (47.97 kB raw / 11.96 kB gzip when built), no
  inline `<style>` blocks, no duplicated selectors.
- Images: only decorative avatars/SVG icons; no large images; favicons
  optimized.
- Fonts: system font stack only (no external font requests).
- Caching: static assets cacheable by URL; private pages never publicly
  cached; sitemap cached 1 h.

## 59. Performance Budgets / 62. Performance Automation

Budget ceilings enforced by `PerformanceTest`: CSS < 120 kB, JS < 40 kB;
asserts external stylesheet, `defer`, favicon links, no inline `<style>`.
Measured build output recorded in §68.

## 60. Accessibility Automation / 61. SEO Automation

`tests/Feature/Phase17`: landmarks/skip-link/focus/reduced-motion/contrast
CSS checks, form labels + autocomplete, alert roles, table captions, status
pill text, breadcrumbs (AccessibilityTest); titles/descriptions/canonical/
noindex/JSON-LD/OG/robots/sitemap validity + exclusions (SeoTest,
SitemapRobotsTest). Manual keyboard guidance in §84.

## 63. Browser Testing / 64. Mobile Testing

No browser driver was introduced (kept lightweight). Critical flows are
covered by HTTP feature tests at the route/HTML level; mobile-specific CSS
behaviours are regression-tested via the stylesheet assertions. Manual
browser/mobile test guidance is in §84.

## 65. Internationalization Readiness

Shared layout/component chrome uses `__()` with `lang/en/ui.php` (English
fallback). Bengali (`lang/bn`) can be added without touching business copy.
Existing page copy stays English (unchanged).

## 66. Date/Currency/Number Formatting

BDT stays `number_format()` (integer/poisha precision untouched); dates via
existing `format('d M Y, h:i A')` conventions.

## 67. Security + SEO / 68. Accessible Authentication / 69. Security Headers

SEO never overrides authorization (noindex-by-default + privacy-gated
metadata). Accessible auth keeps autocomplete/paste and adds no puzzles, and
never weakens security. Phase 16 security headers/CSP remain compatible —
the layout removed its inline `<style>` (so a strict CSP `style-src` is
possible); JSON-LD uses a script tag that a nonce-based CSP should cover
(document §77).

## 70. Performance + Realtime / 71. Accessibility + Live Regions

Pollers pause in hidden tabs, resume on visibility, back off on failure, cap
the DOM (12 items), never duplicate events (revision cursor), and announce
via `aria-live="polite"` without focus stealing.

## 72. SEO Content Quality

All SEO content (titles, descriptions, JSON-LD) is derived from actual DB
content; no fake reviews/ratings/events.

## 73. Admin/Public Separation

No public page imports or renders admin analytics, audit logs, fraud state,
financial internals, support notes or identity data.

## 74. Responsive Table Audit

Every data table on every rewritten page is wrapped in `.table-wrap`
(horizontal scroll on narrow viewports) with a `<caption class="sr-only">`
and `<th scope="col">` headers: leaderboard, registered teams, waitlist,
wallet/ledger, payments, payouts, settlements, audit log, support queue,
analytics, accounts, security (devices/risk events/incidents), moderation
queue and dispute timelines. No table relies on colour alone for status —
every status cell carries a text+dot pill.

## 75. Design Consistency

One vocabulary: Primary/Secondary(cyan)/Success(green)/Danger/Neutral/Ghost
buttons; one status-pill set; one alert set; one table/form/card style.

## 76. Reusable Components

`x-alert`, `x-status-pill`, `x-empty-state`, `x-button` (link vs button,
variant/size), plus accessible pagination views — created to standardize
repeated patterns, not to rename single tags.

## 77. Known Limitations

- Second-pass completion: every long-tail view (auth, profile/edit,
  settings, wallet, payment, teams, tournaments create/edit/scoring,
  matches/show, disputes, support, moderation, organizer dashboards, and the
  full admin/analytics/security/ops/settlement set) is now hand-rewritten
  against the design system — no page still depends on the legacy
  inline-style markup. `welcome.blade.php` (unused Laravel starter page, not
  routed) is the sole untouched exception.
- Core Web Vitals were not measured in a real browser; only structural
  optimizations + budget tests are in place.
- No `og:image` yet (no public banner asset exists); social previews use text
  cards until one is added.
- Axios remains in `package.json` (dev dependency) though no longer bundled;
  removing it would churn the lockfile unnecessarily.
- The `public/build/` Vite output and `node_modules/` are build artifacts
  that do not persist in this sandbox; the layout serves
  `public/css/app.css`/`public/js/app.js` when no build manifest exists, so
  the app is fully styled with or without `npm run build`.

## 78. Production Recommendations

- Run `npm install && npm run build` and serve the hashed assets behind a
  CDN with long-lived cache headers.
- Enable `SECURITY_CSP_ENABLE` only after adding a nonce for the JSON-LD
  script and confirming all assets are self-hosted (the system font stack
  already is).
- Add a 1200×630 public banner image to activate `og:image`.
- Measure LCP/INP/CLS with Lighthouse/CrUX; the structural work is in place
  but the metrics must be verified on real hardware/networks.
- Add `lang/bn` translations when the Bangla rollout is planned.

---

## 79. COMPLETE FINAL CONTENT OF EVERY NEW/MODIFIED FILE

Every text file is reproduced below in full (byte-for-byte). The two
generated binary assets (favicon.ico, apple-touch-icon.png) are listed with
their specs — their bytes are binary and reproducible via the documented
generation steps.
"""


def render_blocks(files, heading):
    out = [f"\n## {heading}\n"]
    for rel in files:
        path = os.path.join(ROOT, rel)
        with open(path, encoding="utf-8", errors="replace") as fh:
            content = fh.read()
        ext = rel.rsplit(".", 1)[-1] if "." in rel else "txt"
        if rel.endswith(".blade.php"):
            lang = "blade"
        else:
            lang = {
                "php": "php", "json": "json", "py": "python", "md": "text",
                "sh": "bash", "yml": "yaml", "css": "css", "js": "js",
                "svg": "xml", "example": "text", "conf": "text",
            }.get(ext, "text")
        out.append(f"\n### `{rel}`\n\n```{lang}\n{content.rstrip()}\n```\n")
    return "".join(out)


def main():
    parts = ["# PHASE17_UI_UX_ACCESSIBILITY_SEO_PERFORMANCE_REPORT.md\n\n", NARRATIVE]
    parts.append(render_blocks(NEW_TEXT_FILES, "New Files (complete content)"))
    parts.append(render_blocks(MODIFIED_FILES, "Modified Files (complete final content)"))
    parts.append("\n## Generated Binary Assets\n\n- `public/favicon.ico` — Windows ICO, 1 icon, 32×32, PNG-compressed RGBA, 374 bytes.\n- `public/apple-touch-icon.png` — PNG 180×180, 8-bit RGBA.\n\nBoth were generated with PHP GD (rounded-square brand tile + FF glyph).\n")
    with open(OUT, "w", encoding="utf-8") as fh:
        fh.write("".join(parts))
    print(f"Wrote {OUT} ({sum(len(p) for p in parts)} chars)")


if __name__ == "__main__":
    main()
```

## File: ./mobile/.gitignore

```
# Flutter / Dart build artifacts
.dart_tool/
build/
.flutter-plugins
.flutter-plugins-dependencies
.packages

# Platform build outputs (never commit native build trees)
android/.gradle/
android/app/build/
android/local.properties
ios/Pods/
ios/.symlinks/
ios/Flutter/ephemeral/

# Secrets — never commit. Copy these to a local-only file and fill in real
# values per environment. They are read via --dart-define at build time.
lib/config/secrets.dart
*.keystore
*.jks
key.properties
google-services.json
GoogleService-Info.plist
ServiceAccountKey.json

# IDE
.idea/
*.iml
.vscode/
```

## File: ./mobile/.metadata

```
# This file tracks properties of this Flutter project.
# Used by Flutter tool to assess capabilities and perform upgrades etc.
#
# This file should be version controlled and should not be manually edited.

version:
  revision: "e8113bf45620cbeb8aff64947ee4c93e16adb4cf"
  channel: "stable"

project_type: app

# Tracks metadata for the flutter migrate command
migration:
  platforms:
    - platform: root
      create_revision: e8113bf45620cbeb8aff64947ee4c93e16adb4cf
      base_revision: e8113bf45620cbeb8aff64947ee4c93e16adb4cf
    - platform: android
      create_revision: e8113bf45620cbeb8aff64947ee4c93e16adb4cf
      base_revision: e8113bf45620cbeb8aff64947ee4c93e16adb4cf
    - platform: ios
      create_revision: e8113bf45620cbeb8aff64947ee4c93e16adb4cf
      base_revision: e8113bf45620cbeb8aff64947ee4c93e16adb4cf

  # User provided section

  # List of Local paths (relative to this file) that should be
  # ignored by the migrate tool.
  #
  # Files that are not part of the templates will be ignored by default.
  unmanaged_files:
    - 'lib/main.dart'
    - 'ios/Runner.xcodeproj/project.pbxproj'
```

## File: ./mobile/README.md

```
# FF Arena — Native Mobile App (Phase 18/G7)

Flutter client for FF Arena tournament platform. Consumes Laravel `/api/v1` — backend authoritative.

## G7 Release

| Flavor | ApplicationId | API Base |
| --- | --- | --- |
| dev | com.ffarena.ffarena_mobile.dev | localhost |
| staging | com.ffarena.ffarena_mobile.staging | https://staging.example.com/api/v1 |
| prod | com.ffarena.ffarena_mobile | https://api.example.com/api/v1 |

Production builds abort if API base not HTTPS.

## Commands

```bash
export PATH=/opt/flutter/bin:$PATH
cd mobile
flutter pub get
flutter analyze
flutter test

# Dev
flutter run --flavor dev --dart-define=FFARENA_API_BASE_URL=http://10.0.2.2:8000/api/v1 --dart-define=FFARENA_ENV=development

# Staging APK
flutter build apk --release --flavor staging --dart-define=FFARENA_API_BASE_URL=https://staging.example.com/api/v1 --dart-define=FFARENA_ENV=staging

# Prod APK (requires keystore env)
export FFARENA_KEYSTORE_PATH=/secure/release.keystore
export FFARENA_KEYSTORE_PASSWORD=...
export FFARENA_KEY_ALIAS=upload
export FFARENA_KEY_PASSWORD=...
flutter build apk --release --flavor prod --dart-define=FFARENA_API_BASE_URL=https://api.example.com/api/v1 --dart-define=FFARENA_ENV=production --dart-define=FFARENA_FIREBASE_API_KEY=... --dart-define=FFARENA_FIREBASE_APP_ID=... --dart-define=FFARENA_FIREBASE_MESSAGING_SENDER_ID=... --dart-define=FFARENA_FIREBASE_PROJECT_ID=...

# Prod App Bundle
flutter build appbundle --release --flavor prod ...

# iOS (Mac + Xcode)
flutter build ios --release --flavor prod --dart-define=FFARENA_API_BASE_URL=https://api.example.com/api/v1 --dart-define=FFARENA_ENV=production
```

## Structure

- lib/config/env.dart — dart-define env
- lib/core/api/api_client.dart — HTTP client, backend authoritative
- lib/core/session/session_manager.dart — secure token storage (Keystore/Keychain)
- lib/core/push/push_provider.dart — NoOp vs Firebase, honest disabled default
- lib/features/deep_links/deep_link_router.dart — ffarena:// parser, strips query/fragment
- lib/screens/* — UI
- android/app/build.gradle — flavors dev/staging/prod, signing via env, deep link intent-filter
- test/deep_link_router_test.dart — unit tested

## Security

- No token in logs, clipboard does not retain tokens, secure storage encrypted
- Deep link carries no secrets, room passwords, tokens, query stripped
- Production HTTPS enforced
- No passwords/OTP/IP/fingerprint collected (privacy-safe telemetry)

## Known Limitations (sandbox)

- No Android SDK/Xcode in sandbox — device builds must run on dev machine
- flutter analyze/test green, Gradle/Xcode validated statically
```

## File: ./mobile/analysis_options.yaml

```
# FF Arena mobile — static analysis configuration (Phase 18).
#
# Extends the standard flutter_lints ruleset and adds a few mobile-specific
# rules relevant to security and correctness:
#   - avoid_print / avoid_print: debug logging must go through the app's
#     redacting logger, never straight to the console (token safety).
#   - Security-sensitive strings are handled by the core layer.

include: package:flutter_lints/flutter.yaml

analyzer:
  exclude:
    - build/**
    - .dart_tool/**
    - android/**
    - ios/**
  errors:
    missing_required_param: error
    missing_return: error

linter:
  rules:
    - avoid_print
    - avoid_relative_lib_imports
    - avoid_slow_async_io
    - cancel_subscriptions
    - close_sinks
    - directives_ordering
    - empty_statements
    - prefer_const_constructors
    - prefer_const_declarations
    - prefer_final_locals
    - prefer_is_empty
    - prefer_single_quotes
    - sort_constructors_first
    - unawaited_futures
    - unnecessary_brace_in_string_interps
    - unnecessary_const
    - use_key_in_widget_constructors
    - use_super_parameters

```

## File: ./mobile/pubspec.lock

```
# Generated by pub
# See https://dart.dev/tools/pub/glossary#lockfile
packages:
  _flutterfire_internals:
    dependency: transitive
    description:
      name: _flutterfire_internals
      sha256: f6966125633a34f82d9be2ee895b755cff3554087b1d00a9eb884e5947f4bca9
      url: "https://pub.dev"
    source: hosted
    version: "1.3.77"
  args:
    dependency: transitive
    description:
      name: args
      sha256: d0481093c50b1da8910eb0bb301626d4d8eb7284aa739614d2b394ee09e3ea04
      url: "https://pub.dev"
    source: hosted
    version: "2.7.0"
  async:
    dependency: transitive
    description:
      name: async
      sha256: e2eb0491ba5ddb6177742d2da23904574082139b07c1e33b8503b9f46f3e1a37
      url: "https://pub.dev"
    source: hosted
    version: "2.13.1"
  boolean_selector:
    dependency: transitive
    description:
      name: boolean_selector
      sha256: "8aab1771e1243a5063b8b0ff68042d67334e3feab9e95b9490f9a6ebf73b42ea"
      url: "https://pub.dev"
    source: hosted
    version: "2.1.2"
  characters:
    dependency: transitive
    description:
      name: characters
      sha256: faf38497bda5ead2a8c7615f4f7939df04333478bf32e4173fcb06d428b5716b
      url: "https://pub.dev"
    source: hosted
    version: "1.4.1"
  clock:
    dependency: transitive
    description:
      name: clock
      sha256: e51d50bca3217c9a9fa2b41a30e4a38971133f5f9ec7a3d57bae095007f1d28e
      url: "https://pub.dev"
    source: hosted
    version: "1.1.3"
  code_assets:
    dependency: transitive
    description:
      name: code_assets
      sha256: bf394f466ba9205f1812a0433b392d6af280f155f56651eda7c18cc32ed493b8
      url: "https://pub.dev"
    source: hosted
    version: "1.2.1"
  collection:
    dependency: transitive
    description:
      name: collection
      sha256: "2f5709ae4d3d59dd8f7cd309b4e023046b57d8a6c82130785d2b0e5868084e76"
      url: "https://pub.dev"
    source: hosted
    version: "1.19.1"
  crypto:
    dependency: transitive
    description:
      name: crypto
      sha256: c8ea0233063ba03258fbcf2ca4d6dadfefe14f02fab57702265467a19f27fadf
      url: "https://pub.dev"
    source: hosted
    version: "3.0.7"
  cupertino_icons:
    dependency: "direct main"
    description:
      name: cupertino_icons
      sha256: "41e005c33bd814be4d3096aff55b1908d419fde52ca656c8c47719ec745873cd"
      url: "https://pub.dev"
    source: hosted
    version: "1.0.9"
  fake_async:
    dependency: transitive
    description:
      name: fake_async
      sha256: "5368f224a74523e8d2e7399ea1638b37aecfca824a3cc4dfdf77bf1fa905ac44"
      url: "https://pub.dev"
    source: hosted
    version: "1.3.3"
  ffi:
    dependency: transitive
    description:
      name: ffi
      sha256: "6d7fd89431262d8f3125e81b50d3847a091d846eafcd4fdb88dd06f36d705a45"
      url: "https://pub.dev"
    source: hosted
    version: "2.2.0"
  firebase_core:
    dependency: "direct main"
    description:
      name: firebase_core
      sha256: "2343710d8a164e3157a5e2fbb00663503c669be4aa06185311df48626760fdf1"
      url: "https://pub.dev"
    source: hosted
    version: "4.14.0"
  firebase_core_platform_interface:
    dependency: transitive
    description:
      name: firebase_core_platform_interface
      sha256: "9bfbc85faca09346471ac56fbcee00757ebcfd85887c6f602764eff0001902d8"
      url: "https://pub.dev"
    source: hosted
    version: "8.1.1"
  firebase_core_web:
    dependency: transitive
    description:
      name: firebase_core_web
      sha256: de1e678209a0c974d8aa4cff39f17d61d2dc263aa64993168360f9be4efb8e91
      url: "https://pub.dev"
    source: hosted
    version: "3.11.0"
  firebase_messaging:
    dependency: "direct main"
    description:
      name: firebase_messaging
      sha256: "31feb5db1fcbdececefd0feb5520013b7cb5d362018008c2889e7ae7e0da4dcb"
      url: "https://pub.dev"
    source: hosted
    version: "16.6.0"
  firebase_messaging_platform_interface:
    dependency: transitive
    description:
      name: firebase_messaging_platform_interface
      sha256: f9707e185b8ce1155d58e7e29348245072d99981f899fa8d3214abb2652c4ccb
      url: "https://pub.dev"
    source: hosted
    version: "4.10.0"
  firebase_messaging_web:
    dependency: transitive
    description:
      name: firebase_messaging_web
      sha256: "311b16c75fc645c7a70c9ade38908abb12310dfce449fc3d3cee1771f196351e"
      url: "https://pub.dev"
    source: hosted
    version: "4.2.5"
  flutter:
    dependency: "direct main"
    description: flutter
    source: sdk
    version: "0.0.0"
  flutter_lints:
    dependency: "direct dev"
    description:
      name: flutter_lints
      sha256: "3105dc8492f6183fb076ccf1f351ac3d60564bff92e20bfc4af9cc1651f4e7e1"
      url: "https://pub.dev"
    source: hosted
    version: "6.0.0"
  flutter_localizations:
    dependency: "direct main"
    description: flutter
    source: sdk
    version: "0.0.0"
  flutter_secure_storage:
    dependency: "direct main"
    description:
      name: flutter_secure_storage
      sha256: "9cad52d75ebc511adfae3d447d5d13da15a55a92c9410e50f67335b6d21d16ea"
      url: "https://pub.dev"
    source: hosted
    version: "9.2.4"
  flutter_secure_storage_linux:
    dependency: transitive
    description:
      name: flutter_secure_storage_linux
      sha256: be76c1d24a97d0b98f8b54bce6b481a380a6590df992d0098f868ad54dc8f688
      url: "https://pub.dev"
    source: hosted
    version: "1.2.3"
  flutter_secure_storage_macos:
    dependency: transitive
    description:
      name: flutter_secure_storage_macos
      sha256: "6c0a2795a2d1de26ae202a0d78527d163f4acbb11cde4c75c670f3a0fc064247"
      url: "https://pub.dev"
    source: hosted
    version: "3.1.3"
  flutter_secure_storage_platform_interface:
    dependency: transitive
    description:
      name: flutter_secure_storage_platform_interface
      sha256: cf91ad32ce5adef6fba4d736a542baca9daf3beac4db2d04be350b87f69ac4a8
      url: "https://pub.dev"
    source: hosted
    version: "1.1.2"
  flutter_secure_storage_web:
    dependency: transitive
    description:
      name: flutter_secure_storage_web
      sha256: f4ebff989b4f07b2656fb16b47852c0aab9fed9b4ec1c70103368337bc1886a9
      url: "https://pub.dev"
    source: hosted
    version: "1.2.1"
  flutter_secure_storage_windows:
    dependency: transitive
    description:
      name: flutter_secure_storage_windows
      sha256: b20b07cb5ed4ed74fc567b78a72936203f587eba460af1df11281c9326cd3709
      url: "https://pub.dev"
    source: hosted
    version: "3.1.2"
  flutter_test:
    dependency: "direct dev"
    description: flutter
    source: sdk
    version: "0.0.0"
  flutter_web_plugins:
    dependency: transitive
    description: flutter
    source: sdk
    version: "0.0.0"
  google_identity_services_web:
    dependency: transitive
    description:
      name: google_identity_services_web
      sha256: "5d187c46dc59e02646e10fe82665fc3884a9b71bc1c90c2b8b749316d33ee454"
      url: "https://pub.dev"
    source: hosted
    version: "0.3.3+1"
  google_sign_in:
    dependency: "direct main"
    description:
      name: google_sign_in
      sha256: d0a2c3bcb06e607bb11e4daca48bd4b6120f0bbc4015ccebbe757d24ea60ed2a
      url: "https://pub.dev"
    source: hosted
    version: "6.3.0"
  google_sign_in_android:
    dependency: transitive
    description:
      name: google_sign_in_android
      sha256: d5e23c56a4b84b6427552f1cf3f98f716db3b1d1a647f16b96dbb5b93afa2805
      url: "https://pub.dev"
    source: hosted
    version: "6.2.1"
  google_sign_in_ios:
    dependency: transitive
    description:
      name: google_sign_in_ios
      sha256: "102005f498ce18442e7158f6791033bbc15ad2dcc0afa4cf4752e2722a516c96"
      url: "https://pub.dev"
    source: hosted
    version: "5.9.0"
  google_sign_in_platform_interface:
    dependency: transitive
    description:
      name: google_sign_in_platform_interface
      sha256: "5f6f79cf139c197261adb6ac024577518ae48fdff8e53205c5373b5f6430a8aa"
      url: "https://pub.dev"
    source: hosted
    version: "2.5.0"
  google_sign_in_web:
    dependency: transitive
    description:
      name: google_sign_in_web
      sha256: "460547beb4962b7623ac0fb8122d6b8268c951cf0b646dd150d60498430e4ded"
      url: "https://pub.dev"
    source: hosted
    version: "0.12.4+4"
  hooks:
    dependency: transitive
    description:
      name: hooks
      sha256: "9a62a50b50b769a737bc0a8ff381f333529df3ab746b2f6b02e83760231455ba"
      url: "https://pub.dev"
    source: hosted
    version: "2.0.2"
  http:
    dependency: "direct main"
    description:
      name: http
      sha256: "87721a4a50b19c7f1d49001e51409bddc46303966ce89a65af4f4e6004896412"
      url: "https://pub.dev"
    source: hosted
    version: "1.6.0"
  http_parser:
    dependency: transitive
    description:
      name: http_parser
      sha256: "178d74305e7866013777bab2c3d8726205dc5a4dd935297175b19a23a2e66571"
      url: "https://pub.dev"
    source: hosted
    version: "4.1.2"
  intl:
    dependency: "direct main"
    description:
      name: intl
      sha256: "1ca20c894b1717686a2319b8548763d812bc0aabdac580420a44c5178c57a867"
      url: "https://pub.dev"
    source: hosted
    version: "0.20.3"
  jni:
    dependency: transitive
    description:
      name: jni
      sha256: f038e58b4dc2c9037f50e233175086337e0b305e356d28211bf55f21c504cbd3
      url: "https://pub.dev"
    source: hosted
    version: "1.0.3"
  jni_flutter:
    dependency: transitive
    description:
      name: jni_flutter
      sha256: b2310cdd4c18c65c081ab141a41efa94aa26c65431803703ece51996f174f351
      url: "https://pub.dev"
    source: hosted
    version: "1.0.3"
  jni_util:
    dependency: transitive
    description:
      name: jni_util
      sha256: "1ba86da04a5f2bf18fde2edb235587e70c5b0fc5bd4ba955f46b00942c3fc35f"
      url: "https://pub.dev"
    source: hosted
    version: "1.0.0"
  js:
    dependency: transitive
    description:
      name: js
      sha256: f2c445dce49627136094980615a031419f7f3eb393237e4ecd97ac15dea343f3
      url: "https://pub.dev"
    source: hosted
    version: "0.6.7"
  leak_tracker:
    dependency: transitive
    description:
      name: leak_tracker
      sha256: "33e2e26bdd85a0112ec15400c8cbffea70d0f9c3407491f672a2fad47915e2de"
      url: "https://pub.dev"
    source: hosted
    version: "11.0.2"
  leak_tracker_flutter_testing:
    dependency: transitive
    description:
      name: leak_tracker_flutter_testing
      sha256: "1dbc140bb5a23c75ea9c4811222756104fbcd1a27173f0c34ca01e16bea473c1"
      url: "https://pub.dev"
    source: hosted
    version: "3.0.10"
  leak_tracker_testing:
    dependency: transitive
    description:
      name: leak_tracker_testing
      sha256: "8d5a2d49f4a66b49744b23b018848400d23e54caf9463f4eb20df3eb8acb2eb1"
      url: "https://pub.dev"
    source: hosted
    version: "3.0.2"
  lints:
    dependency: transitive
    description:
      name: lints
      sha256: "12f842a479589fea194fe5c5a3095abc7be0c1f2ddfa9a0e76aed1dbd26a87df"
      url: "https://pub.dev"
    source: hosted
    version: "6.1.0"
  logging:
    dependency: transitive
    description:
      name: logging
      sha256: c8245ada5f1717ed44271ed1c26b8ce85ca3228fd2ffdb75468ab01979309d61
      url: "https://pub.dev"
    source: hosted
    version: "1.3.0"
  matcher:
    dependency: transitive
    description:
      name: matcher
      sha256: "31bd099b47c10cd1aeb55146a2d46ce0277630ecef3f7dae54ad7873f36696cd"
      url: "https://pub.dev"
    source: hosted
    version: "0.12.20"
  material_color_utilities:
    dependency: transitive
    description:
      name: material_color_utilities
      sha256: "9c337007e82b1889149c82ed242ed1cb24a66044e30979c44912381e9be4c48b"
      url: "https://pub.dev"
    source: hosted
    version: "0.13.0"
  meta:
    dependency: transitive
    description:
      name: meta
      sha256: c82594181e3312f3d0695fc95aaaf7758d75b8d4ae2bbecf223b9fd5109a059d
      url: "https://pub.dev"
    source: hosted
    version: "1.18.3"
  objective_c:
    dependency: transitive
    description:
      name: objective_c
      sha256: b7fb95a6d9a4f009edd63dc5ac69f07420b23a16161c6dd8660290b59c602e8e
      url: "https://pub.dev"
    source: hosted
    version: "9.5.0"
  package_config:
    dependency: transitive
    description:
      name: package_config
      sha256: ffcf4cf3d6c0b74ac43708d9f56625506e8a68aa935abe9d267a7330f320eb5d
      url: "https://pub.dev"
    source: hosted
    version: "3.0.0"
  path:
    dependency: transitive
    description:
      name: path
      sha256: "75cca69d1490965be98c73ceaea117e8a04dd21217b37b292c9ddbec0d955bc5"
      url: "https://pub.dev"
    source: hosted
    version: "1.9.1"
  path_provider:
    dependency: "direct main"
    description:
      name: path_provider
      sha256: a7f4874f987173da295a61c181b8ee71dab59b332a486b391babf26a1b884825
      url: "https://pub.dev"
    source: hosted
    version: "2.1.6"
  path_provider_android:
    dependency: transitive
    description:
      name: path_provider_android
      sha256: "69cbd515a62b94d32a7944f086b2f82b4ac40a1d45bebfc00813a430ab2dabcd"
      url: "https://pub.dev"
    source: hosted
    version: "2.3.1"
  path_provider_foundation:
    dependency: transitive
    description:
      name: path_provider_foundation
      sha256: "2a376b7d6392d80cd3705782d2caa734ca4727776db0b6ec36ef3f1855197699"
      url: "https://pub.dev"
    source: hosted
    version: "2.6.0"
  path_provider_linux:
    dependency: transitive
    description:
      name: path_provider_linux
      sha256: "58c2005f147315b11e9b4a7bc889cd5203e250cba8e3f012dae259b4972b5c16"
      url: "https://pub.dev"
    source: hosted
    version: "2.2.2"
  path_provider_platform_interface:
    dependency: transitive
    description:
      name: path_provider_platform_interface
      sha256: "484838772624c3a4b94f1e44a3e19897fee738f2d5c4ce448443b0417f7c9dda"
      url: "https://pub.dev"
    source: hosted
    version: "2.1.3"
  path_provider_windows:
    dependency: transitive
    description:
      name: path_provider_windows
      sha256: bd6f00dbd873bfb70d0761682da2b3a2c2fccc2b9e84c495821639601d81afe7
      url: "https://pub.dev"
    source: hosted
    version: "2.3.0"
  platform:
    dependency: transitive
    description:
      name: platform
      sha256: a36d119c13416516a7b5913fbe8af8531e11633d784c550b2125f76c758524ec
      url: "https://pub.dev"
    source: hosted
    version: "3.2.0"
  plugin_platform_interface:
    dependency: transitive
    description:
      name: plugin_platform_interface
      sha256: "4820fbfdb9478b1ebae27888254d445073732dae3d6ea81f0b7e06d5dedc3f02"
      url: "https://pub.dev"
    source: hosted
    version: "2.1.8"
  pub_semver:
    dependency: transitive
    description:
      name: pub_semver
      sha256: "261236774e8b1d69cfc6b9eabbc96c40f25e7a2d6b171f3385d4f65d5734fb24"
      url: "https://pub.dev"
    source: hosted
    version: "2.2.1"
  record_use:
    dependency: transitive
    description:
      name: record_use
      sha256: "2551bd8eecfe95d14ae75f6021ad0248be5c27f138c2ec12fcb52b500b3ba1ed"
      url: "https://pub.dev"
    source: hosted
    version: "0.6.0"
  sky_engine:
    dependency: transitive
    description: flutter
    source: sdk
    version: "0.0.0"
  source_span:
    dependency: transitive
    description:
      name: source_span
      sha256: "56a02f1f4cd1a2d96303c0144c93bd6d909eea6bee6bf5a0e0b685edbd4c47ab"
      url: "https://pub.dev"
    source: hosted
    version: "1.10.2"
  stack_trace:
    dependency: transitive
    description:
      name: stack_trace
      sha256: "277654b3034d17ac6f9f1cb5595db011b1d5d41e8806866db28e0abaa101c490"
      url: "https://pub.dev"
    source: hosted
    version: "1.12.2"
  stream_channel:
    dependency: transitive
    description:
      name: stream_channel
      sha256: "969e04c80b8bcdf826f8f16579c7b14d780458bd97f56d107d3950fdbeef059d"
      url: "https://pub.dev"
    source: hosted
    version: "2.1.4"
  string_scanner:
    dependency: transitive
    description:
      name: string_scanner
      sha256: "921cd31725b72fe181906c6a94d987c78e3b98c2e205b397ea399d4054872b43"
      url: "https://pub.dev"
    source: hosted
    version: "1.4.1"
  term_glyph:
    dependency: transitive
    description:
      name: term_glyph
      sha256: "7f554798625ea768a7518313e58f83891c7f5024f88e46e7182a4558850a4b8e"
      url: "https://pub.dev"
    source: hosted
    version: "1.2.2"
  test_api:
    dependency: transitive
    description:
      name: test_api
      sha256: "2a122cbe059f8b610d3a5415f42e255b6c17b1f21eee1d960f31080237fb4f11"
      url: "https://pub.dev"
    source: hosted
    version: "0.7.12"
  typed_data:
    dependency: transitive
    description:
      name: typed_data
      sha256: f9049c039ebfeb4cf7a7104a675823cd72dba8297f264b6637062516699fa006
      url: "https://pub.dev"
    source: hosted
    version: "1.4.0"
  url_launcher:
    dependency: "direct main"
    description:
      name: url_launcher
      sha256: f6a7e5c4835bb4e3026a04793a4199ca2d14c739ec378fdfe23fc8075d0439f8
      url: "https://pub.dev"
    source: hosted
    version: "6.3.2"
  url_launcher_android:
    dependency: transitive
    description:
      name: url_launcher_android
      sha256: "611e87fb320b70d1dd721dc46af89c98aceccea9b31fde49e084591414e0c610"
      url: "https://pub.dev"
    source: hosted
    version: "6.3.33"
  url_launcher_ios:
    dependency: transitive
    description:
      name: url_launcher_ios
      sha256: "8faa1aab294f1ab4040b43660c887b0418d5fa4f0cffef76a484e6aa1092eb4a"
      url: "https://pub.dev"
    source: hosted
    version: "6.4.2"
  url_launcher_linux:
    dependency: transitive
    description:
      name: url_launcher_linux
      sha256: "10f86fef4c2c43563fa6c211ff9cf757adf4d3ab762c56bd430664a947d70cd0"
      url: "https://pub.dev"
    source: hosted
    version: "3.2.3"
  url_launcher_macos:
    dependency: transitive
    description:
      name: url_launcher_macos
      sha256: "5e835a3b869c2d70325349c81c5a45c28e20791265b67b2669da6b08c5cd5201"
      url: "https://pub.dev"
    source: hosted
    version: "3.2.6"
  url_launcher_platform_interface:
    dependency: transitive
    description:
      name: url_launcher_platform_interface
      sha256: "552f8a1e663569be95a8190206a38187b531910283c3e982193e4f2733f01029"
      url: "https://pub.dev"
    source: hosted
    version: "2.3.2"
  url_launcher_web:
    dependency: transitive
    description:
      name: url_launcher_web
      sha256: "85c81589622fbc87c1c683aaea164d3604a7777495a79d91e39ffcdec39ddb34"
      url: "https://pub.dev"
    source: hosted
    version: "2.4.3"
  url_launcher_windows:
    dependency: transitive
    description:
      name: url_launcher_windows
      sha256: "6c5ad3f22cd4c38e089b81963b3cd7bb83b111b2df5dce008bb066162f42e429"
      url: "https://pub.dev"
    source: hosted
    version: "3.1.6"
  vector_math:
    dependency: transitive
    description:
      name: vector_math
      sha256: "1d774bbdf6b72a0b12122fc1560c9c2d2a67db5a4a4cc2bd8a5c990ab20e3188"
      url: "https://pub.dev"
    source: hosted
    version: "2.4.0"
  vm_service:
    dependency: transitive
    description:
      name: vm_service
      sha256: "5f37239c4851efcef929cea7824e76df7f2f0970aef85d66bbc430afa40e72f0"
      url: "https://pub.dev"
    source: hosted
    version: "15.3.0"
  web:
    dependency: transitive
    description:
      name: web
      sha256: "868d88a33d8a87b18ffc05f9f030ba328ffefba92d6c127917a2ba740f9cfe4a"
      url: "https://pub.dev"
    source: hosted
    version: "1.1.1"
  win32:
    dependency: transitive
    description:
      name: win32
      sha256: d7cb55e04cd34096cd3a79b3330245f54cb96a370a1c27adb3c84b917de8b08e
      url: "https://pub.dev"
    source: hosted
    version: "5.15.0"
  xdg_directories:
    dependency: transitive
    description:
      name: xdg_directories
      sha256: "7a3f37b05d989967cdddcbb571f1ea834867ae2faa29725fd085180e0883aa15"
      url: "https://pub.dev"
    source: hosted
    version: "1.1.0"
  yaml:
    dependency: transitive
    description:
      name: yaml
      sha256: f67cdd8e07d3c6329146aaef1ba043542b3134c12489f553ca9a7435d1068aea
      url: "https://pub.dev"
    source: hosted
    version: "3.1.4"
sdks:
  dart: ">=3.12.0 <4.0.0"
  flutter: ">=3.44.0"
```

## File: ./mobile/pubspec.yaml

```
name: ffarena_mobile
description: FF Arena native mobile client (Phase 18). Consumes the Laravel /api/v1 platform; the backend stays authoritative.
publish_to: 'none'
version: 1.0.0+1

environment:
  sdk: '>=3.4.0 <4.0.0'

dependencies:
  flutter:
    sdk: flutter
  flutter_localizations:
    sdk: flutter
  # Lightweight HTTP + localization formatting. No heavy state-management
  # framework: the app uses built-in ChangeNotifier/ValueNotifier.
  http: ^1.2.2
  intl: ^0.20.2
  # Secure token storage — Android Keystore / iOS Keychain.
  flutter_secure_storage: ^9.2.2
  # Google Sign-In (only active when a client id is configured).
  google_sign_in: ^6.2.1
  # External links (privacy policy / terms / support).
  url_launcher: ^6.3.0
  # App documents directory for the offline read-only cache.
  path_provider: ^2.1.4
  cupertino_icons: ^1.0.8
  firebase_core: ^4.14.0
  firebase_messaging: ^16.6.0

dev_dependencies:
  flutter_test:
    sdk: flutter
  flutter_lints: ^6.0.0

flutter:
  uses-material-design: true

```

## File: ./package.json

```
{
    "$schema": "https://www.schemastore.org/package.json",
    "private": true,
    "type": "module",
    "scripts": {
        "build": "vite build",
        "dev": "vite"
    },
    "devDependencies": {
        "@tailwindcss/vite": "^4.0.0",
        "axios": "^1.11.0",
        "concurrently": "^9.0.1",
        "laravel-vite-plugin": "^2.0.0",
        "tailwindcss": "^4.0.0",
        "vite": "^7.0.7"
    }
}
```

## File: ./phpunit.coverage.xml

```
<?xml version="1.0" encoding="UTF-8"?>
<!--
    G3 — coverage configuration (PCOV).

    Separate from phpunit.xml so normal test runs stay fast (no coverage
    overhead) and coverage runs are an explicit, cacheable CI step.

    Driver policy:
      * PCOV is the CI driver (fast, stable, no debugger conflicts).
      * Xdebug remains supported for local development — set
        XDEBUG_MODE=coverage and this same config works unchanged.
      * The driver is detected at runtime by scripts/ci/coverage.sh, which
        refuses to report numbers when neither extension is loaded.

    Scope: app/ only. vendor/, generated code, storage/, tests/ and build
    artifacts are excluded by PHPUnit automatically (they are not under the
    <include> directory).

    Reports are written to storage/coverage/ (gitignored; published as a CI
    artifact, never committed).
-->
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
         cacheDirectory="storage/framework/cache/.phpunit.coverage.cache"
>
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="Feature">
            <directory>tests/Feature</directory>
        </testsuite>
    </testsuites>

    <source>
        <include>
            <directory>app</directory>
        </include>
    </source>

    <coverage>
        <report>
            <html outputDirectory="storage/coverage/html"/>
            <clover outputFile="storage/coverage/clover.xml"/>
            <text outputFile="storage/coverage/coverage.txt" showUncoveredFiles="false" showOnlySummary="true"/>
        </report>
    </coverage>

    <php>
        <env name="APP_ENV" value="testing"/>
        <env name="APP_MAINTENANCE_DRIVER" value="file"/>
        <env name="BCRYPT_ROUNDS" value="4"/>
        <env name="BROADCAST_CONNECTION" value="null"/>
        <env name="CACHE_STORE" value="array"/>
        <env name="DB_CONNECTION" value="sqlite"/>
        <env name="DB_DATABASE" value=":memory:"/>
        <env name="DB_URL" value=""/>
        <env name="MAIL_MAILER" value="array"/>
        <env name="QUEUE_CONNECTION" value="sync"/>
        <env name="SESSION_DRIVER" value="array"/>
        <env name="PULSE_ENABLED" value="false"/>
        <env name="TELESCOPE_ENABLED" value="false"/>
        <env name="NIGHTWATCH_ENABLED" value="false"/>
    </php>
</phpunit>
```

## File: ./phpunit.pgsql.xml

```
<?xml version="1.0" encoding="UTF-8"?>
<!--
    G1 — PostgreSQL test matrix + R9 Real PostgreSQL Integration
    Identical to phpunit.xml except the database runs on PostgreSQL so the
    full suite is exercised against the production driver.
-->
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
>
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="Feature">
            <directory>tests/Feature</directory>
        </testsuite>
        <testsuite name="Integration">
            <directory>tests/Integration</directory>
        </testsuite>
        <testsuite name="R9_PostgreSQL">
            <directory>tests/Feature/R9</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>app</directory>
        </include>
    </source>
    <php>
        <env name="APP_ENV" value="testing"/>
        <env name="APP_KEY" value="base64:bgN0yS08tC5r/rDRWdX8EeqxrkOIJezGxtmpEqXSGhI="/>
        <env name="APP_MAINTENANCE_DRIVER" value="file"/>
        <env name="BCRYPT_ROUNDS" value="4"/>
        <env name="BROADCAST_CONNECTION" value="null"/>
        <env name="CACHE_STORE" value="array"/>
        <env name="DB_CONNECTION" value="pgsql" force="false"/>
        <env name="DB_HOST" value="127.0.0.1" force="false"/>
        <env name="DB_PORT" value="5432" force="false"/>
        <env name="DB_DATABASE" value="ffarena_test" force="false"/>
        <env name="DB_USERNAME" value="ffarena" force="false"/>
        <env name="DB_PASSWORD" value="ffarena" force="false"/>
        <env name="DB_URL" value="" force="false"/>
        <env name="MAIL_MAILER" value="array"/>
        <env name="QUEUE_CONNECTION" value="sync"/>
        <env name="SESSION_DRIVER" value="array"/>
        <env name="PULSE_ENABLED" value="false"/>
        <env name="TELESCOPE_ENABLED" value="false"/>
        <env name="NIGHTWATCH_ENABLED" value="false"/>
    </php>
</phpunit>
```

## File: ./phpunit.redis.xml

```
<?xml version="1.0" encoding="UTF-8"?>
<!-- G4 + R9 — Redis integration test suite — Real Redis Integration -->
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
>
    <testsuites>
        <testsuite name="G4">
            <directory>tests/Feature/G4</directory>
        </testsuite>
        <testsuite name="R9_Redis">
            <directory>tests/Feature/R9</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>app</directory>
        </include>
    </source>
    <php>
        <env name="APP_ENV" value="testing"/>
        <env name="APP_KEY" value="base64:bgN0yS08tC5r/rDRWdX8EeqxrkOIJezGxtmpEqXSGhI="/>
        <env name="BCRYPT_ROUNDS" value="4"/>
        <env name="CACHE_STORE" value="redis"/>
        <env name="DB_CONNECTION" value="sqlite"/>
        <env name="DB_DATABASE" value=":memory:"/>
        <env name="QUEUE_CONNECTION" value="sync"/>
        <env name="SESSION_DRIVER" value="array"/>
        <env name="REDIS_HOST" value="127.0.0.1"/>
        <env name="REDIS_PORT" value="6379"/>
        <env name="REDIS_DB" value="15"/>
        <env name="REDIS_CACHE_DB" value="14"/>
        <env name="REDIS_QUEUE_DB" value="13"/>
        <env name="REDIS_PREFIX" value="ffarena-testing-database-"/>
        <env name="CACHE_PREFIX" value="ffarena-testing-cache-"/>
        <env name="REDIS_CLIENT" value="phpredis"/>
    </php>
</phpunit>
```

## File: ./phpunit.xml

```
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
>
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="Feature">
            <directory>tests/Feature</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>app</directory>
        </include>
    </source>
    <php>
        <env name="APP_ENV" value="testing"/>
        <env name="APP_KEY" value="base64:bgN0yS08tC5r/rDRWdX8EeqxrkOIJezGxtmpEqXSGhI="/>
        <env name="APP_MAINTENANCE_DRIVER" value="file"/>
        <env name="BCRYPT_ROUNDS" value="4"/>
        <env name="BROADCAST_CONNECTION" value="null"/>
        <env name="CACHE_STORE" value="array"/>
        <env name="DB_CONNECTION" value="sqlite"/>
        <env name="DB_DATABASE" value=":memory:"/>
        <env name="DB_URL" value=""/>
        <env name="MAIL_MAILER" value="array"/>
        <env name="QUEUE_CONNECTION" value="sync"/>
        <env name="SESSION_DRIVER" value="array"/>
        <env name="PULSE_ENABLED" value="false"/>
        <env name="TELESCOPE_ENABLED" value="false"/>
        <env name="NIGHTWATCH_ENABLED" value="false"/>
        <env name="REDIS_HOST" value="127.0.0.1"/>
        <env name="REDIS_PORT" value="6379"/>
        <env name="REDIS_DB" value="15"/>
        <env name="REDIS_CACHE_DB" value="14"/>
        <env name="REDIS_QUEUE_DB" value="13"/>
        <env name="REDIS_PREFIX" value="ffarena-testing-database-"/>
        <env name="CACHE_PREFIX" value="ffarena-testing-cache-"/>
    </php>
</phpunit>
```

## File: ./pint.json

```
{
    "preset": "laravel",
    "rules": {
        "concat_space": {
            "spacing": "none"
        }
    }
}
```

## File: ./public/.htaccess

```
<IfModule mod_rewrite.c>
    <IfModule mod_negotiation.c>
        Options -MultiViews -Indexes
    </IfModule>

    RewriteEngine On

    # Handle Authorization Header
    RewriteCond %{HTTP:Authorization} .
    RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]

    # Handle X-XSRF-Token Header
    RewriteCond %{HTTP:x-xsrf-token} .
    RewriteRule .* - [E=HTTP_X_XSRF_TOKEN:%{HTTP:X-XSRF-Token}]

    # Redirect Trailing Slashes If Not A Folder...
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_URI} (.+)/$
    RewriteRule ^ %1 [L,R=301]

    # Send Requests To Front Controller...
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteRule ^ index.php [L]
</IfModule>
```

