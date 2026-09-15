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
