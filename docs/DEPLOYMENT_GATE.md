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
