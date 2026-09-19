# FF Arena — Security Overview (Final Hardening)

## TLS / HTTPS

- Production must be HTTPS — `APP_URL` HTTPS, `TRUSTED_PROXIES` configured, `SESSION_SECURE_COOKIE=true`, HSTS max-age 31536000 includeSubDomains preload
- Certificates via `TLS_CERT_PATH`, `TLS_KEY_PATH`, `TLS_CA_PATH` — never hardcoded domain, env configurable
- Redis TLS: `REDIS_TLS=true` for remote, `--tls-port 6380`, cert files
- Nginx TLS: `deploy/nginx.tls.conf` with modern ciphers TLSv1.2 TLSv1.3, OCSP stapling, HSTS
- Mobile TLS: Flutter `Env.validate()` HTTPS only in production, Android usesCleartextTraffic false, iOS ATS arbitrary loads false

## Security Headers

Via `SecurityHeaders` middleware:

- Strict-Transport-Security: max-age 31536000 includeSubDomains preload (production HTTPS only)
- X-Content-Type-Options: nosniff
- X-Frame-Options: SAMEORIGIN (exempt OAuth and webhooks)
- Referrer-Policy: strict-origin-when-cross-origin
- Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()
- Cross-Origin-Opener-Policy: same-origin
- Cross-Origin-Resource-Policy: same-origin
- Content-Security-Policy: default-src self, script-src self unsafe-inline (Vite) + googletagmanager, style-src self unsafe-inline fonts.googleapis, font-src self fonts.gstatic data:, img-src self data: https: blob:, connect-src self api.ffarena.com wss://*.ffarena.com googleapis firebaseio, frame-ancestors self, object-src none, base-uri self, form-action self
- Cache-Control: no-store no-cache must-revalidate private for sensitive routes (api, admin, profile, wallet)
- X-Request-ID: correlation

CSP exceptions documented: unsafe-inline for Vite + Blade, unsafe-eval dev only for Vue devtools, googletagmanager for analytics if enabled, wss://*.ffarena.com for Reverb.

## Cookies / Session

- secure: true in production HTTPS
- HttpOnly: true
- SameSite: lax or strict
- domain: correct, e.g., .ffarena.com
- path: /
- Rotation on login: Session::regenerate()
- Invalidation on logout: invalidate + regenerateToken
- Password reset invalidation: all sessions
- Token revocation: Sanctum tokens revoked on logout
- Idle timeout: SESSION_LIFETIME 120 minutes
- Do not break API bearer-token auth

## Secrets Management

- No real secrets committed — .env.example uses CHANGE_ME placeholders
- Naming: {SERVICE}_{TYPE} e.g., DB_PASSWORD
- Environment separation: local placeholders, staging CI secret store, production Vault
- Rotation: 90 days DB/Redis/API keys, 180 days OAuth, 365 days encryption keys
- Leak prevention: RedactSensitiveDataProcessor scrubs auth headers, bearer tokens, cookies, passwords, OTPs, payment credentials, private keys, webhook signatures, OAuth secrets, DB credentials
- CI: secrets via secret store, never printed, forked PRs no production secrets

## Logging

- Channels: single, daily (14 days), json, central (syslog UDP/Papertrail/cloud), stderr_json (container), security (90 days immutable), payments (7 years immutable), webhooks (30 days), queue (14 days), audit (1 year+ immutable), errors (30 days), metrics (14 days), cache (7 days)
- Processors: PsrLogMessageProcessor, RequestContextProcessor (safe fields: timestamp, environment, request_id, correlation_id, route, method, status, duration, user_id, tournament_id, source, job, queue, error category), RedactSensitiveDataProcessor (scrubs secrets)
- Safe fields only, never passwords, OTP, full tokens, secret keys, card data, raw private auth material, unnecessary PII
- Format: LineFormatter human readable, JsonFormatter JSON-lines for observability
- Transport: local daily rotation, central configurable LOG_CENTRAL_ENABLED/HOST/PORT, container stderr JSON, fallback to local never crash
- Rotation: RotatingFileHandler maxFiles LOG_DAILY_DAYS, operational 14 days, security 90 days, audit 1 year+, financial 7 years+
- Failure: if central not configured or fails, fallback to local, never crash request

## Error Handling

- Production hides stack traces, internal paths, SQL, secrets, returns safe API errors with request_id, correlation_id, logs internal diagnostic safely via errors channel with redaction
- Development debuggable with debug info
- Do not expose env/debug details publicly
- Via ApiExceptionHandler

## Health / Readiness

- /health/live lightweight, must NOT fail solely because Redis unavailable, returns status live timestamp environment version, no credentials/paths/SQL/Redis details/env vars
- /health/ready may perform dependency checks, reports database status latency, redis reachable latency usable host [REDACTED], cache reachable fallback database, queue status depth, never passwords/connection strings/secrets, appropriate HTTP status 200 ready 503 not ready
- /health full for ops requires admin auth
- Routes in routes/health.php without web/api middleware, reachable during maintenance mode

## Admin / Ops Security

- Operational endpoints: authentication, authorization, staff/admin restrictions, no guest access, no accidental public monitoring, audit logging, request IDs, safe errors
- Middleware: admin, staff, bearer, api.token
- Route-model binding and authorization verified

## CI/CD Security

- Security gates: PHP lint, PHPUnit, coverage regression, PostgreSQL tests, Redis tests, OpenAPI, Composer validate/audit, secret scan, static analysis, formatting/lint, migration verification, frontend/mobile tests
- CI must fail on real errors — no || true
- Do not allow security checks to become informational only unless justified
- Workflows: .github/workflows/ci.yml and deploy.yml

## CI Secret Security

- Plaintext secrets: never, use secret store
- Echoing secrets: never
- Exposing env vars: never, mask via ::add-mask::
- Artifact leakage: never, artifacts must not contain secrets
- Debug shell output: never with env
- Unsafe PR secrets: forked PRs must not receive production secrets

## Deployment Gate

`php artisan deploy:gate` — verifies test suite passes, migrations valid, config valid, required env vars exist, DB connectivity, Redis connectivity, queue connectivity, health checks, app boot, storage permissions, cache config, asset availability. If required check fails, deployment must stop.

## Database Migration Safety

- Audit migrations for destructive operations: dropTable, dropColumn, dropIfExists, table rewrites, locking operations, unsafe defaults, nullable transitions, index creation, PostgreSQL-specific behavior
- Safe procedure: check destructive ops, review each, backup DB first, document in release notes, never auto-delete production data, use CONCURRENTLY for indexes in PG
- Never automatically delete production data
- Irreversible migrations clearly identified

## Rollback Safety

- Application rollback: deploy/rollback.sh to previous version via .last_successful_version, health check after rollback
- Database rollback limitations: if irreversible migration exists, DB rollback NOT safe — must restore from backup, clearly identify
- Config rollback: .env versioned in secret store
- Mobile version rollback: via store previous version remains available, version enforcement via MOBILE_MIN_APP_VERSION
- Cache invalidation: on rollback clear cache, invalidate tournament/listing
- Queue handling: restart workers, failed jobs remain
- Worker restart: via supervisor or compose
- Never claim DB rollback safe if irreversible exists

## Backup / Restore

- Database backup: pg_dump via BackupService, encryption via DB_BACKUP_ENCRYPTION_KEY openssl aes-256-cbc, retention 30 days, integrity via gzip -t, restore process never over production accidentally
- PostgreSQL backup: pg_dump -h host -p port -U username -d database --no-owner --no-privileges | gzip | openssl enc -aes-256-cbc -k key > backup.sql.gz.enc
- Redis persistence: AOF yes, save policies, but Redis is cache not durable DB — do not treat as durable storage
- Uploaded media backup: S3 versioning + backup if applicable
- Commands: php artisan backup:verify --create

## Filesystem / Server Security

- Storage directories: app, framework/cache/sessions/views, logs — writable 775 owned ffarena:ffarena not world-writable
- Writable directories: only storage and bootstrap/cache writable
- Uploaded files: storage/app/private not public, executable upload prevention mime check + extension blacklist .php .phtml .exe
- Public storage exposure: storage/app/public linked via storage:link but private files protected per Phase 07/08/09
- Symlink safety: no symlink following for uploads, is_link check
- Permissions: 644 files, 755 dirs, 775 storage/cache, owned ffarena:ffarena non-root in container
- Temporary files: framework/cache tmp cleared via cache:prune-stale-tags scheduled cleanup
- Backup files: storage/backups not public 700
- Environment files: .env not in public not committed 600 in .gitignore
- Debug artifacts: no debugbar telescope in production APP_DEBUG=false
- Source maps: if sensitive not public only for error reporting

## Dependency Security

- composer validate --strict
- composer audit
- npm audit if applicable and safe
- Flutter dependency audit: flutter pub outdated, flutter pub audit
- Lockfile validation: composer.lock exists, package-lock.json exists
- Fix only genuine security/compatibility issues relevant to this phase

## PHP / Laravel Production Settings

- APP_ENV=production
- APP_DEBUG=false
- APP_URL=https
- TRUSTED_PROXIES=* or load balancer IPs
- SESSION_SECURE_COOKIE=true, SESSION_HTTP_ONLY=true, SESSION_SAME_SITE=lax, SESSION_DOMAIN=.ffarena.com, SESSION_PATH=/
- Encryption key: APP_KEY set 32 char random
- Mail: smtp or log not vulnerable
- Queue: redis in production not sync
- Cache: redis in production
- Filesystem: s3 or secured local not world-writable
- Logging: daily warning in production rotation 14 days
- Database: pgsql SSLMODE require in production
- Redis: TLS true for remote PASSWORD set timeout 2s
- Rate limiting: enabled API 60/min login 5/min
- Error reporting: Sentry DSN or Bugsnag configured
- Do not expose .env

## Mobile Production Hardening

- Production API base URL: https://api.example.com/api/v1 HTTPS only enforced Env.validate() abort if not HTTPS
- Android network security: usesCleartextTraffic false manifest network_security_config.xml cleartextTrafficPermitted false production API HTTPS only
- iOS ATS: NSAllowsArbitraryLoads false NSExceptionDomains only production API HTTPS
- Secure token storage: FlutterSecureStorage Android Keystore iOS Keychain encryptedSharedPreferences first_unlock
- Release signing: via env FFARENA_KEYSTORE_PATH/PASSWORD/ALIAS/PASSWORD never committed fallback debug local verification only never store uploads
- FCM/APNs secret handling: via dart-define FFARENA_FIREBASE_* never embedded in source secure storage for tokens
- Deep links: ffarena:// scheme registered https://{host}/... App Links assetlinks.json with release fingerprint iOS apple-app-site-association team id bundle id MOBILE_WEB_BASE_URL HTTPS
- Universal links / App Links: publish /.well-known/assetlinks.json and apple-app-site-association
- Version enforcement: MOBILE_MIN_APP_VERSION above installed → update-required MOBILE_LATEST_APP_VERSION above → non-blocking banner MOBILE_MAINTENANCE_MODE maintenance screen MOBILE_UPDATE_REQUIRED update screen
- Debug mode disabled: release build no debug banner/logging no bearer token in log adb logcat/Xcode console
- Production logging redaction: no passwords/OTP/IP/fingerprint crash reporting only when enabled
- Crash reporting: FFARENA_CRASH_REPORTING_ENABLED true only when Firebase ids present privacy-safe no PII
- Do not embed production secrets inside Flutter source do not commit signing certificates or private keys

## Webhook / Payment TLS

Every production payment provider callback and webhook endpoint uses HTTPS:

- Certificate validation: enabled verify peer hostname validation
- Redirect safety: no open redirects validate redirect URLs against allowlist
- Callback URL configuration: PAYMENT_CALLBACK_URL HTTPS in production WEBHOOK_URL HTTPS
- Webhook signature verification: HMAC SHA256 WEBHOOK_SECRET from env timing-safe compare hash_equals
- Replay prevention: timestamp validation nonce idempotency key
- Timestamp validation: webhook timestamp must be within 5 minutes reject old
- Idempotency: Idempotency-Key header same key cannot execute twice concurrently completed result reusable failed retryable

Never downgrade provider communication to HTTP.

## Security Tests

- HTTPS redirect, secure headers, HSTS, secure cookies, debug disabled, secret redaction, log redaction, unauthorized ops endpoints, health endpoint secrecy, API error secrecy, webhook HTTPS expectations, payment callback safety, CI secret scanning, unsafe environment configuration
- Tests use actual application behavior
- Location: tests/Feature/Security/

## Production Config Validator

`php artisan config:validate-production` — detects APP_DEBUG=true, missing APP_KEY, non-HTTPS APP_URL in production, insecure session settings, missing DB credentials, missing Redis config, missing queue config, missing payment credentials, unsafe log config, missing required OAuth config, unsafe filesystem config, missing mobile production config where applicable. Never prints secret values, returns non-zero for critical problems.

## Security / Release Checklist

`php artisan security:checklist` — covers code, dependencies, database, Redis, queue, TLS, secrets, logs, monitoring, backups, mobile, payments, API, webhooks, DNS, certificates, rollback, incident response. Distinguishes PASS/FAIL/WARNING/NOT CONFIGURED, never labels untested as PASS.

## Final Release Checklist

See FINAL_PRODUCTION_HARDENING_REPORT.md section 28.
