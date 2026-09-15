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
