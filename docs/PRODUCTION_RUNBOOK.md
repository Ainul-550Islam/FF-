# FF Arena — Production Runbook

Operational runbook for the FF Arena platform (Laravel 12, Phase 16). Every
command below is verified against the actual codebase. Paths assume the
application root is the current working directory.

---

## 1. Application layout

| Concern | Location |
|---|---|
| App root | `./` (Laravel 12 app) |
| Environment | `.env` (git-ignored), template in `.env.example` |
| Logs | `storage/logs/*.log` (daily-rotated) |
| Private storage | `storage/app/private` (never publicly served) |
| Public storage | `storage/app/public` (symlinked to `public/storage`) |
| Backups | `storage/app/private/backups/<name>/` |
| Cache/config cache | `bootstrap/cache/` |
| API docs | `storage/api-docs/openapi.json` |

---

## 2. Deployment

See `docs/DEPLOYMENT.md` for the full zero-downtime procedure. The canonical
order is:

```bash
composer install --no-dev --optimize-autoloader
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan migrate --force            # additive migrations only
php artisan queue:restart
php artisan storage:link
```

Then restart PHP-FPM and the queue workers, and verify with:

```bash
php artisan ffarena:health --production
curl -fsS https://<host>/health/ready
```

---

## 3. Migrations

- **Always** `--force` in production.
- **Additive migrations only** while serving traffic. Destructive changes are
  staged: deploy code that no longer reads the old column, migrate, then drop
  the column in a later release.
- Never run `migrate:rollback` blindly against a destructive change; restore
  from a backup instead (see `docs/DISASTER_RECOVERY.md`).

```bash
php artisan migrate --force
php artisan migrate:status
```

---

## 4. Rollback

1. Redeploy the previous release artifact (or `git revert` the change).
2. `php artisan config:cache && php artisan route:cache && php artisan view:cache`
3. `php artisan queue:restart`
4. `php artisan cache:clear` (public read caches rebuild automatically)
5. If a migration was destructive, restore the database from the last good
   backup (see DR runbook) — do **not** rely on `migrate:rollback`.

---

## 5. Cache management

```bash
# Clear runtime caches (safe; public data rebuilds on next read)
php artisan cache:clear
php artisan config:clear && php artisan route:clear && php artisan view:clear

# Admin-approved namespace flush (audited)
php artisan tinker --execute="app(\App\Services\CacheInvalidationService::class)->flush('providers');"
```

Invalidation is automatic for tournament/leaderboard/match/provider-status
caches (wired into the domain services). No manual step is normally required.

---

## 6. Queue workers

The outbound-webhook and mail workloads run on the `database` queue
(default) — use Redis in production where available.

```bash
php artisan queue:work --tries=3 --timeout=90 --backoff=30
php artisan queue:restart          # graceful restart after deploys
php artisan queue:failed           # list failed jobs
php artisan queue:retry all        # retry everything
php artisan ffarena:queue:health   # backlog + heartbeat report
```

Workers are managed by `deploy/supervisor-ffarena.conf` (supervisor) in the
reference deployment. A worker heartbeat is reported on the admin ops
dashboard; keep `HEALTH_WORKER_STALE_SECONDS` tuned to your supervisor config.

---

## 7. Scheduler

The cron must run once per minute:

```cron
* * * * * cd /path/to/ffarena && php artisan schedule:run >> storage/logs/scheduler.log 2>&1
```

Or use `deploy/systemd-ffarena-scheduler.service` + `.timer`. The scheduler
runs: backup (03:00), weekly backup verify (Monday 04:00), cleanup of OTP /
idempotency / webhook / notification / failed-job / live-event rows, and the
minute-level scheduler heartbeat.

---

## 8. Logs

| Channel | File | Content |
|---|---|---|
| `single`/`daily` | `storage/logs/laravel.log` | default application log |
| `security` | `storage/logs/security.log` | security events |
| `payments` | `storage/logs/payments.log` | payment events |
| `webhooks` | `storage/logs/webhooks.log` | inbound/outbound webhook events |
| `queue` | `storage/logs/queue.log` | queue failures |
| `audit` | `storage/logs/audit.log` | audit-channel messages |
| `errors` | `storage/logs/errors.log` | error reporter output |
| `metrics` | `storage/logs/metrics.log` | metrics lines |

Rotation: daily, `LOG_DAILY_DAYS` (default 14) files retained. Logs are
redacted by a processor (passwords, tokens, secrets never persisted).

```bash
tail -f storage/logs/laravel.log
grep '<request-id>' storage/logs/*.log   # correlate a request
```

---

## 9. Health checks

| URL | Use | Auth |
|---|---|---|
| `/up` | Laravel default liveness | none |
| `/health` | service liveness | none |
| `/health/live` | process liveness | none |
| `/health/ready` | readiness (200/503) | none |

```bash
curl -fsS https://<host>/health/ready || echo "NOT READY"
php artisan ffarena:health --production   # operator diagnostics (redacted)
```

Health endpoints remain available during maintenance mode and are
rate-limited at 300/min per IP.

---

## 10. Backups

```bash
php artisan ffarena:backup            # create one backup
php artisan ffarena:backup:verify     # verify latest
php artisan ffarena:backup:verify --all
php artisan ffarena:backup:restore <name> --target=/tmp/restore.sqlite
```

Backups land in `storage/app/private/backups/`, are chmod 0600, carry a
SHA-256 manifest, and are retention-pruned (`BACKUP_RETENTION`, default 14).
The scheduler runs a nightly backup and a weekly verification.

**Driver behaviour (G1):** SQLite uses a consistent `VACUUM INTO` snapshot
(`database.sqlite`); PostgreSQL uses `pg_dump --format=custom`
(`database.sql`), verified on restore with `pg_restore --list` (the
`ffarena:backup:restore` dry-run never touches the live database). The
restore command is a dry-run for both drivers — restoring over the live
database is a manual, documented procedure (`docs/DISASTER_RECOVERY.md`).

**SQLite → PostgreSQL cutover:** the staged, offline procedure (backup →
stop writes → `ffarena:db:export` → migrate → `ffarena:db:import` with
row-count + checksum + financial reconciliation → config switch → health
check → smoke tests → reopen writes) lives in §23 of
`G1_POSTGRESQL_PRODUCTION_MIGRATION_REPORT.md`, with the rollback plan in §24.

---

## 13. Maintenance mode

```bash
php artisan down --retry=60 --refresh=30   # brief maintenance page
php artisan up
```

`/health*` stays reachable during maintenance (orchestrators keep probing).
Never enter maintenance while a payment settlement / payout run is in flight —
let queued jobs drain first (`queue:work --stop-when-empty`).

---

## 14. Incident procedure

See `docs/INCIDENT_RESPONSE.md`. In short: triage → correlate via
`X-Request-ID` → check `storage/logs/errors.log` and the admin ops dashboard
(`/admin/ops`) → mitigate → post-mortem.
