# Environment reference

R2 row, closed 2026-10-07. This is the authoritative map of every environment
key the tree reads: where it is read, what the code default is, whether
production requires it, and which automated gate enforces that.

Census reconciliation (all counts machine-verified, see §8):

| Universe | Count |
| --- | --- |
| `env()` reads in `config/` | 385 keys |
| Direct `env()` reads in `app/` | 5 keys |
| Compose-interpolated keys (root + `deploy/`) | 29 keys |
| Deploy-time shell variables (not dotenv keys) | 4 keys |
| Reserved template keys no code reads yet | 3 keys |

The "173 keys" in the R2 scope note was the count of PHP-read keys missing
from `.env.example` at scoping time (171 config reads + 2 app-direct reads;
the line-based census missed the multiline `SESSION_COOKIE` read in
`config/session.php`, so the true missing count was 174 — all appended).

## 1. The three layers

1. **Dotenv file** (`.env`, copied from `.env.example`). Holds deployment
   values. Never committed. The production gate (§2) validates it.
2. **Code defaults** (`config/*.php`). Every `env('KEY', default)` second
   argument. Scalar defaults are repeated verbatim in the template; computed
   defaults (paths, derived strings, class names) stay commented in the
   template so an explicit empty value cannot shadow the fallback.
3. **Test pins** (`phpunit*.xml`). The suite never reads `.env`: each profile
   pins its own identity with `<server force="true">` (F-40). Test-only keys
   live in the XML files, never in the dotenv template.

Consequence: `.env.example` documents layers 1+2 only. If a key appears only
in `tests/` or `phpunit*.xml`, it does not belong here.

## 2. The production gate

`deploy/validate-env.py` — 14 check functions, 29 failure/warning rules.
Exit codes: `0` pass, `1` production failure, `2` usage/file error.

```bash
# Hermetic: the FILE ALONE must satisfy production (tests, CI assertions).
python3 deploy/validate-env.py --env-file path/to/.env --production --no-process-env

# Merged: file PLUS the process environment (deploy; secrets are injected).
python3 deploy/validate-env.py --env-file path/to/.env --production
```

The two modes exist because production secrets are injected via the process
environment at deploy time, while assertions must not depend on ambient
environment:

| Wiring | Mode | Behaviour |
| --- | --- | --- |
| `deploy/deploy.sh` (production only) | merged | Fatal: a failing file aborts before anything is built. Staging skips the gate. |
| CI `tests` job, "Production env guard rejects shipped template" | hermetic | Fails the build if the gate ever accepts `.env.example`. |
| Static floor `test_completed_template_still_fails_production_gate` | hermetic | Pins the same property in-sandbox on every run. |

Locked decisions (do not "fix" without updating the F-001 register row):

- Secret-hygiene skips empty values: documented fallbacks are legal, so an
  empty secret is not itself a failure — a *placeholder-shaped* secret is.
- `127.0.0.1` is rejected only for `DB_HOST`; other hosts keep their own rules.
- An unset rate limit means the config default applies (not a failure).
- `DATABASE_URL` is not required (matches the config chain and the template).
- A `REQUIRED=false` offsite mirror warns instead of failing (DR-runbook-pinned).
- Absent keys cannot be required: the gate checks values, never key presence.

## 3. Gate rules

Generated from `deploy/validate-env.py` on 2026-10-07 (`‹value›` marks a
message slot the gate fills with the offending value):

| Check | ID | Level | Keys read | Rule |
| --- | --- | --- | --- | --- |
| `check_app_fundamentals` | `APP_ENV_IS_PRODUCTION` | FAIL | `APP_DEBUG`, `APP_ENV`, `APP_KEY`, `APP_URL` | APP_ENV=<value>, want 'production'. |
| `check_app_fundamentals` | `APP_DEBUG_OFF` | FAIL | `APP_DEBUG`, `APP_ENV`, `APP_KEY`, `APP_URL` | APP_DEBUG is enabled; stack traces would leak to the internet. |
| `check_app_fundamentals` | `APP_KEY_SET` | FAIL | `APP_DEBUG`, `APP_ENV`, `APP_KEY`, `APP_URL` | APP_KEY is missing or malformed (want 'base64:' + 40+ chars). |
| `check_app_fundamentals` | `APP_URL_HTTPS` | FAIL | `APP_DEBUG`, `APP_ENV`, `APP_KEY`, `APP_URL` | APP_URL=<value>, want an https:// URL. |
| `check_database` | `DB_IS_PGSQL` | FAIL | `DB_CONNECTION`, `DB_HOST`, `DB_PASSWORD` | DB_CONNECTION=<value>, production must use pgsql. |
| `check_database` | `DB_HOST_REAL` | FAIL | `DB_CONNECTION`, `DB_HOST`, `DB_PASSWORD` | DB_HOST=<value> is a placeholder; point at the database host. |
| `check_database` | `DB_PASSWORD_SET` | FAIL | `DB_CONNECTION`, `DB_HOST`, `DB_PASSWORD` | DB_PASSWORD is missing. |
| `check_session` | `SESSION_SECURE_COOKIE` | FAIL | `SESSION_ENCRYPT`, `SESSION_SECURE_COOKIE` | SESSION_SECURE_COOKIE must be true behind TLS. |
| `check_session` | `SESSION_ENCRYPT` | FAIL | `SESSION_ENCRYPT`, `SESSION_SECURE_COOKIE` | SESSION_ENCRYPT=false stores readable session payloads. |
| `check_mail` | `MAIL_MAILER_REAL` | FAIL | `MAIL_FROM_ADDRESS`, `MAIL_MAILER` | MAIL_MAILER=<value> delivers nowhere; OTP/security mail needs smtp/sendmail. |
| `check_mail` | `MAIL_FROM_REAL` | FAIL | `MAIL_FROM_ADDRESS`, `MAIL_MAILER` | MAIL_FROM_ADDRESS=<value> is missing or the @example.com placeholder. |
| `check_mail` | `MAIL_SMTP_AUTH` | FAIL | `MAIL_FROM_ADDRESS`, `MAIL_MAILER` | <key> is required with MAIL_MAILER=smtp. |
| `check_webhook_trust_root` | `WEBHOOK_SECRET_STRENGTH` | FAIL | `PAYMENT_WEBHOOK_SECRET` | PAYMENT_WEBHOOK_SECRET is missing or under 32 chars. |
| `check_webhook_trust_root` | `WEBHOOK_SECRET_UNGUESSABLE` | FAIL | `PAYMENT_WEBHOOK_SECRET` | PAYMENT_WEBHOOK_SECRET contains placeholder text <value>. |
| `check_trusted_proxies` | `TRUSTED_PROXIES_SET` | FAIL | `TRUSTED_PROXIES` | TRUSTED_PROXIES is empty; every client would share the proxy's throttle bucket. |
| `check_trusted_proxies` | `TRUSTED_PROXIES_NARROW` | FAIL | `TRUSTED_PROXIES` | TRUSTED_PROXIES='*' believes forwarded headers from anyone. |
| `check_secret_hygiene` | `SECRET_MIN_LENGTH` | FAIL |  | <key> is under 16 chars. |
| `check_secret_hygiene` | `SECRET_NO_PLACEHOLDER` | FAIL |  | <key> contains placeholder text <value>. |
| `check_redis_auth` | `REDIS_PASSWORD_SET` | FAIL | `REDIS_PASSWORD` | CACHE/QUEUE/SESSION use redis but REDIS_PASSWORD is missing. |
| `check_redis_prefix` | `REDIS_PREFIX_RECOMMENDED` | WARN | `REDIS_PREFIX` | CACHE/QUEUE/SESSION use redis but REDIS_PREFIX is empty: sharing this cluster with another env would collide keys. |
| `check_companion_secrets` | `COMPANION_SECRETS` | FAIL |  | <key> is enabled but <key> is missing. |
| `check_rate_limits` | `RATE_LIMIT_POSITIVE` | FAIL |  | <key>=<value> disables the <key> throttle (0/non-numeric = unlimited). |
| `check_offsite` | `OFFSITE_RECOMMENDED` | WARN | `AWS_BUCKET`, `BACKUP_OFFSITE_BUCKET`, `BACKUP_OFFSITE_DISK`, `BACKUP_OFFSITE_REQUIRED` | BACKUP_OFFSITE_REQUIRED is not true: a local-only backup means one disk failure ends the company. |
| `check_offsite` | `OFFSITE_DISK_SET` | FAIL | `AWS_BUCKET`, `BACKUP_OFFSITE_BUCKET`, `BACKUP_OFFSITE_DISK`, `BACKUP_OFFSITE_REQUIRED` | BACKUP_OFFSITE_REQUIRED=true but BACKUP_OFFSITE_DISK is missing. |
| `check_offsite` | `OFFSITE_BUCKET_SET` | FAIL | `AWS_BUCKET`, `BACKUP_OFFSITE_BUCKET`, `BACKUP_OFFSITE_DISK`, `BACKUP_OFFSITE_REQUIRED` | BACKUP_OFFSITE_REQUIRED=true but no bucket (BACKUP_OFFSITE_BUCKET/AWS_BUCKET). |
| `check_backups` | `BACKUP_ENCRYPTION` | WARN | `BACKUP_ENCRYPTION_RECIPIENT` | BACKUP_ENCRYPTION_RECIPIENT is empty: dumps rest unencrypted. |
| `check_csp` | `CSP_POLICY_SHAPE` | FAIL | `SECURITY_CSP_ENABLE`, `SECURITY_CSP_POLICY`, `SECURITY_CSP_REPORT_ONLY` | SECURITY_CSP_POLICY has no default-src; refusing a shapeless policy. |
| `check_csp` | `CSP_POLICY_SHAPE` | FAIL | `SECURITY_CSP_ENABLE`, `SECURITY_CSP_POLICY`, `SECURITY_CSP_REPORT_ONLY` | SECURITY_CSP_POLICY contains '*'; a wildcard is not a policy. |
| `check_csp` | `CSP_ENFORCING_EXPLICIT` | FAIL | `SECURITY_CSP_ENABLE`, `SECURITY_CSP_POLICY`, `SECURITY_CSP_REPORT_ONLY` | CSP is enforcing (REPORT_ONLY=false) with no explicit SECURITY_CSP_POLICY; supply the policy being enforced. |

## 4. Required-in-production keys

A key is required when a FAIL rule reads it. This table names the key and the
rule; the exact message lives in §3. One warn-only rule (`REDIS_PREFIX`)
is listed for completeness and marked as such.

| Key | Rule | Notes |
| --- | --- | --- |
| `APP_ENV` | `APP_ENV_IS_PRODUCTION` | Must be `production`. |
| `APP_DEBUG` | `APP_DEBUG_OFF` | Must be off. |
| `APP_KEY` | `APP_KEY_SET` | `base64:` + 40+ chars. |
| `APP_URL` | `APP_URL_HTTPS` | `https://` URL. |
| `DB_CONNECTION` | `DB_IS_PGSQL` | Must be `pgsql`. |
| `DB_HOST` | `DB_HOST_REAL` | No placeholders/loopback. |
| `DB_PASSWORD` | `DB_PASSWORD_SET` | Must be set (presence only). |
| `REDIS_PASSWORD` | `REDIS_PASSWORD_SET` | Must be set when cache/queue/session use redis. |
| `PAYMENT_WEBHOOK_SECRET` | `WEBHOOK_SECRET_STRENGTH`, `WEBHOOK_SECRET_UNGUESSABLE` | ≥ 32 chars, placeholder screen. |
| `MAIL_MAILER`, `MAIL_FROM_ADDRESS` (+ SMTP credentials) | `MAIL_MAILER_REAL`, `MAIL_FROM_REAL`, `MAIL_SMTP_AUTH` | Mailer must be `smtp`/`sendmail`; sender must not be `@example.com`; SMTP requires auth. |
| `TRUSTED_PROXIES` | `TRUSTED_PROXIES_SET`, `TRUSTED_PROXIES_NARROW` | Must be set, never `*`. |
| `REDIS_PREFIX` | `REDIS_PREFIX_RECOMMENDED` (warn-only) | Warns when cache/queue/session use redis without a prefix (the gate cannot see cluster sharing, so this never fails). |
| Secrets screened by hygiene | `SECRET_MIN_LENGTH`, `SECRET_NO_PLACEHOLDER` | Under 16 chars fails; placeholder text fails (empty values are skipped — documented fallbacks are legal). |
| `GO_PAYMENT_*`, `RUST_SECURITY_*` (`_SECRET`, `_TOKEN`, `_HMAC_SECRET`) | `COMPANION_SECRETS` | All three legs required when the matching daemon is enabled. |
| `BACKUP_OFFSITE_DISK`, `BACKUP_OFFSITE_BUCKET`/`AWS_BUCKET` | `OFFSITE_DISK_SET`, `OFFSITE_BUCKET_SET` | Required when `BACKUP_OFFSITE_REQUIRED=true`. |
| `SECURITY_CSP_POLICY` | `CSP_POLICY_SHAPE`, `CSP_ENFORCING_EXPLICIT` | Shape-checked when present; required when enforcing. |
| Rate-limit keys | `RATE_LIMIT_POSITIVE` | Set values must be positive; unset = default. |

## 5. Full key reference

### 5.1 Config reads (385 keys)

`—` under Default means the `env()` call has no default: the feature cannot
work until the key is set (the template marks these `REQUIRED`).

### `config/api.php` (2 keys)

| Key | Default | Required? |
| --- | ------- | --------- |
| `API_RATE_LIMIT_API` | `120` | no |
| `API_RATE_LIMIT_API_ANON` | `60` | no |

### `config/app.php` (11 keys)

| Key | Default | Required? |
| --- | ------- | --------- |
| `APP_NAME` | `'FF Arena'` | no _(also: `config/database.php`, `config/logging.php`, `config/mail.php`, `config/session.php`)_ |
| `APP_ENV` | `'production'` | no _(also: `config/cache.php`)_ |
| `APP_DEBUG` | `false` | no |
| `APP_URL` | `'http://localhost'` | no _(also: `config/filesystems.php`, `config/mail.php`, `config/services.php`)_ |
| `APP_LOCALE` | `'en'` | no |
| `APP_FALLBACK_LOCALE` | `'en'` | no |
| `APP_FAKER_LOCALE` | `'en_US'` | no |
| `APP_KEY` | — | **yes** |
| `APP_PREVIOUS_KEYS` | `''` | no |
| `APP_MAINTENANCE_DRIVER` | `'file'` | no |
| `APP_MAINTENANCE_STORE` | `'database'` | no |

### `config/auth.php` (5 keys)

| Key | Default | Required? |
| --- | ------- | --------- |
| `AUTH_GUARD` | `'web'` | no |
| `AUTH_PASSWORD_BROKER` | `'users'` | no |
| `AUTH_MODEL` | `User::class` | no |
| `AUTH_PASSWORD_RESET_TOKEN_TABLE` | `'password_reset_tokens'` | no |
| `AUTH_PASSWORD_TIMEOUT` | `10800` | no |

### `config/backup.php` (11 keys)

| Key | Default | Required? |
| --- | ------- | --------- |
| `BACKUP_DISK` | `'local'` | no |
| `BACKUP_PATH` | `'backups'` | no |
| `BACKUP_RETENTION` | `14` | no |
| `BACKUP_INCLUDE_PRIVATE_FILES` | `true` | no |
| `BACKUP_INTEGRITY_CHECK` | `true` | no |
| `BACKUP_NOTIFY_ADMINS` | `true` | no |
| `BACKUP_OFFSITE_DISK` | — | **yes** |
| `BACKUP_OFFSITE_REQUIRED` | `false` | no |
| `BACKUP_OFFSITE_PREFIX` | `'backups'` | no |
| `BACKUP_ENCRYPTION_RECIPIENT` | — | **yes** |
| `BACKUP_DECRYPTION_IDENTITY` | — | **yes** |

### `config/broadcasting.php` (15 keys)

| Key | Default | Required? |
| --- | ------- | --------- |
| `BROADCAST_CONNECTION` | `'log'` | no |
| `REVERB_APP_KEY` | — | **yes** _(also: `config/reverb.php`)_ |
| `REVERB_APP_SECRET` | — | **yes** _(also: `config/reverb.php`)_ |
| `REVERB_APP_ID` | — | **yes** _(also: `config/reverb.php`)_ |
| `REVERB_HOST` | `'127.0.0.1'` | no _(also: `config/reverb.php`)_ |
| `REVERB_PORT` | `8080` | no _(also: `config/reverb.php`)_ |
| `REVERB_SCHEME` | `'http'` | no _(also: `config/reverb.php`)_ |
| `PUSHER_APP_KEY` | — | **yes** |
| `PUSHER_APP_SECRET` | — | **yes** |
| `PUSHER_APP_ID` | — | **yes** |
| `PUSHER_APP_CLUSTER` | — | **yes** |
| `PUSHER_HOST` | — | **yes** |
| `PUSHER_PORT` | `443` | no |
| `PUSHER_SCHEME` | `'https'` | no |
| `ABLY_KEY` | — | **yes** |

### `config/cache.php` (18 keys)

| Key | Default | Required? |
| --- | ------- | --------- |
| `CACHE_STORE` | `'database'` | no |
| `DB_CACHE_CONNECTION` | — | **yes** |
| `DB_CACHE_TABLE` | `'cache'` | no |
| `DB_CACHE_LOCK_CONNECTION` | — | **yes** |
| `DB_CACHE_LOCK_TABLE` | — | **yes** |
| `MEMCACHED_PERSISTENT_ID` | — | **yes** |
| `MEMCACHED_USERNAME` | — | **yes** |
| `MEMCACHED_PASSWORD` | — | **yes** |
| `MEMCACHED_HOST` | `'127.0.0.1'` | no |
| `MEMCACHED_PORT` | `11211` | no |
| `REDIS_CACHE_CONNECTION` | `'cache'` | no |
| `REDIS_CACHE_LOCK_CONNECTION` | `'default'` | no |
| `AWS_ACCESS_KEY_ID` | — | **yes** _(also: `config/filesystems.php`, `config/queue.php`, `config/services.php`)_ |
| `AWS_SECRET_ACCESS_KEY` | — | **yes** _(also: `config/filesystems.php`, `config/queue.php`, `config/services.php`)_ |
| `AWS_DEFAULT_REGION` | `'us-east-1'` | no _(also: `config/filesystems.php`, `config/queue.php`, `config/services.php`)_ |
| `DYNAMODB_CACHE_TABLE` | `'cache'` | no |
| `DYNAMODB_ENDPOINT` | — | **yes** |
| `CACHE_PREFIX` | `'ffarena-'.env('APP_ENV', 'local').'-...` | no |

### `config/cors.php` (1 keys)

| Key | Default | Required? |
| --- | ------- | --------- |
| `CORS_ALLOWED_ORIGINS` | `''` | no |

### `config/database.php` (37 keys)

| Key | Default | Required? |
| --- | ------- | --------- |
| `DB_CONNECTION` | `'sqlite'` | no _(also: `config/queue.php`)_ |
| `DB_URL` | — | **yes** |
| `DB_SQLITE_PATH` | — | **yes** |
| `DB_DATABASE` | `database_path('database.sqlite')` | no _(also: `config/services_go_rust.php`)_ |
| `DB_FOREIGN_KEYS` | `true` | no |
| `DB_HOST` | `'127.0.0.1'` | no _(also: `config/services_go_rust.php`)_ |
| `DB_PORT` | `'3306'` | no _(also: `config/services_go_rust.php`)_ |
| `DB_USERNAME` | `'root'` | no _(also: `config/services_go_rust.php`)_ |
| `DB_PASSWORD` | `''` | no _(also: `config/services_go_rust.php`)_ |
| `DB_SOCKET` | `''` | no |
| `DB_CHARSET` | `'utf8mb4'` | no |
| `DB_COLLATION` | `'utf8mb4_unicode_ci'` | no |
| `MYSQL_ATTR_SSL_CA` | — | **yes** |
| `DB_SEARCH_PATH` | `'public'` | no |
| `DB_SCHEMA` | `'public'` | no |
| `DB_SSLMODE` | `'prefer'` | no |
| `DB_APP_NAME` | `'ffarena'` | no |
| `DB_CONNECT_VIA_DATABASE` | — | **yes** |
| `DB_CONNECT_VIA_PORT` | — | **yes** |
| `DB_CONNECT_TIMEOUT` | `5` | no |
| `DB_ENCRYPT` | `'yes'` | no |
| `DB_TRUST_SERVER_CERTIFICATE` | `'false'` | no |
| `REDIS_CLIENT` | `'phpredis'` | no |
| `REDIS_CLUSTER` | `'redis'` | no |
| `REDIS_PREFIX` | `Str::slug((string) env('APP_NAME', 'l...` | no _(also: `config/services_go_rust.php`)_ |
| `REDIS_PERSISTENT` | `false` | no |
| `REDIS_URL` | — | **yes** _(also: `config/services_go_rust.php`)_ |
| `REDIS_HOST` | `'127.0.0.1'` | no _(also: `config/services_go_rust.php`)_ |
| `REDIS_USERNAME` | — | **yes** |
| `REDIS_PASSWORD` | — | **yes** _(also: `config/services_go_rust.php`)_ |
| `REDIS_PORT` | `'6379'` | no _(also: `config/services_go_rust.php`)_ |
| `REDIS_DB` | `'0'` | no _(also: `config/services_go_rust.php`)_ |
| `REDIS_MAX_RETRIES` | `3` | no |
| `REDIS_BACKOFF_ALGORITHM` | `'decorrelated_jitter'` | no |
| `REDIS_BACKOFF_BASE` | `100` | no |
| `REDIS_BACKOFF_CAP` | `1000` | no |
| `REDIS_CACHE_DB` | `'1'` | no _(also: `config/services_go_rust.php`)_ |

### `config/features.php` (32 keys)

| Key | Default | Required? |
| --- | ------- | --------- |
| `FEATURE_ROUND_ROBIN` | `false` | no |
| `FEATURE_DOUBLE_ELIMINATION` | `false` | no |
| `FEATURE_SWISS` | `false` | no |
| `FEATURE_GROUP_STAGE` | `false` | no |
| `FEATURE_LEAGUE` | `false` | no |
| `FEATURE_FFA` | `false` | no |
| `FEATURE_MULTI_STAGE` | `false` | no |
| `FEATURE_HYBRID` | `false` | no |
| `FEATURE_PAYMENT_BKASH` | `true` | no |
| `FEATURE_PAYMENT_NAGAD` | `false` | no |
| `FEATURE_PAYMENT_ROCKET` | `false` | no |
| `FEATURE_PAYOUT_BKASH` | `false` | no |
| `FEATURE_PAYOUT_BANK` | `true` | no |
| `FEATURE_REALTIME_SSE` | `true` | no |
| `FEATURE_REALTIME_REVERB` | `false` | no |
| `FEATURE_REALTIME_POLLING` | `true` | no |
| `FEATURE_FRAUD_DEVICE` | `true` | no |
| `FEATURE_FRAUD_IP` | `true` | no |
| `FEATURE_FRAUD_EXTERNAL` | `false` | no |
| `FEATURE_FRAUD_IDENTITY` | `true` | no |
| `FEATURE_NOTIFICATION_SMS` | `false` | no |
| `FEATURE_NOTIFICATION_PUSH` | `true` | no |
| `FEATURE_NOTIFICATION_EMAIL` | `true` | no |
| `FEATURE_STORAGE_S3` | `false` | no |
| `FEATURE_STORAGE_LOCAL` | `true` | no |
| `FEATURE_MOBILE_PUSH` | `true` | no |
| `FEATURE_SCORING_CUSTOM` | `false` | no |
| `FEATURE_SCORING_TIEBREAKER` | `true` | no |
| `FEATURE_ADMIN_BETA` | `false` | no |
| `FEATURE_API_V2` | `false` | no |
| `FEATURE_PROMETHEUS` | `false` | no _(also: `config/observability.php`)_ |
| `GAMEBERRY_NUMBERED_SIMULATIONS` | `false` | no |

### `config/filesystems.php` (13 keys)

| Key | Default | Required? |
| --- | ------- | --------- |
| `FILESYSTEM_DISK` | `'local'` | no |
| `BACKUP_OFFSITE_DRIVER` | `'local'` | no |
| `BACKUP_OFFSITE_ROOT` | `storage_path('app/offsite-backups')` | no |
| `BACKUP_OFFSITE_KEY` | `env('AWS_ACCESS_KEY_ID')` | no |
| `BACKUP_OFFSITE_SECRET` | `env('AWS_SECRET_ACCESS_KEY')` | no |
| `BACKUP_OFFSITE_REGION` | `env('AWS_DEFAULT_REGION')` | no |
| `BACKUP_OFFSITE_BUCKET` | `env('AWS_BUCKET')` | no |
| `AWS_BUCKET` | — | **yes** |
| `BACKUP_OFFSITE_ENDPOINT` | `env('AWS_ENDPOINT')` | no |
| `AWS_ENDPOINT` | — | **yes** |
| `BACKUP_OFFSITE_USE_PATH_STYLE_ENDPOINT` | `false` | no |
| `AWS_URL` | — | **yes** |
| `AWS_USE_PATH_STYLE_ENDPOINT` | `false` | no |

### `config/finance.php` (4 keys)

| Key | Default | Required? |
| --- | ------- | --------- |
| `PLATFORM_COMMISSION_TYPE` | `'percentage'` | no |
| `PLATFORM_COMMISSION_BP` | `0` | no |
| `PLATFORM_COMMISSION_FIXED_MINOR` | `0` | no |
| `DEFAULT_PAYOUT_PROVIDER` | `'wallet'` | no |

### `config/gameberry.php` (11 keys)

| Key | Default | Required? |
| --- | ------- | --------- |
| `GAMEBERRY_ANTI_CHEAT_ENABLED` | `true` | no |
| `GAMEBERRY_MIN_ACTION_INTERVAL_MS` | `120` | no |
| `GAMEBERRY_MAX_ACTIONS_PER_MINUTE` | `120` | no |
| `GAMEBERRY_MAX_ACTIONS_PER_SESSION` | `2000` | no |
| `GAMEBERRY_MAX_SESSION_MINUTES` | `90` | no |
| `GAMEBERRY_ANTI_CHEAT_FLAG_SCORE` | `3` | no |
| `GAMEBERRY_ANTI_CHEAT_BLOCK_SCORE` | `6` | no |
| `GAMEBERRY_SESSION_STALE_MINUTES` | `180` | no |
| `GAMEBERRY_SESSION_RECONCILE_BATCH` | `200` | no |
| `GAMEBERRY_SETTLEMENT_RECONCILE_BATCH` | `50` | no |
| `GAMEBERRY_SETTLEMENT_RECONCILE_BATCH_MAX` | `500` | no |

### `config/logging.php` (14 keys)

| Key | Default | Required? |
| --- | ------- | --------- |
| `LOG_CHANNEL` | `'stack'` | no |
| `LOG_DEPRECATIONS_CHANNEL` | `'null'` | no |
| `LOG_DEPRECATIONS_TRACE` | `false` | no |
| `LOG_STACK` | `'single'` | no |
| `LOG_LEVEL` | `'debug'` | no |
| `LOG_DAILY_DAYS` | `14` | no _(also: `config/observability.php`)_ |
| `LOG_SLACK_WEBHOOK_URL` | — | **yes** |
| `LOG_SLACK_USERNAME` | `env('APP_NAME', 'Laravel')` | no |
| `LOG_SLACK_EMOJI` | `':boom:'` | no |
| `LOG_PAPERTRAIL_HANDLER` | `SyslogUdpHandler::class` | no |
| `PAPERTRAIL_URL` | — | **yes** |
| `PAPERTRAIL_PORT` | — | **yes** |
| `LOG_STDERR_FORMATTER` | — | **yes** |
| `LOG_SYSLOG_FACILITY` | `LOG_USER` | no |

### `config/mail.php` (13 keys)

| Key | Default | Required? |
| --- | ------- | --------- |
| `MAIL_MAILER` | `'log'` | no |
| `MAIL_SCHEME` | — | **yes** |
| `MAIL_URL` | — | **yes** |
| `MAIL_HOST` | `'127.0.0.1'` | no |
| `MAIL_PORT` | `2525` | no |
| `MAIL_USERNAME` | — | **yes** |
| `MAIL_PASSWORD` | — | **yes** |
| `MAIL_EHLO_DOMAIN` | `parse_url((string) env('APP_URL', 'ht...` | no |
| `POSTMARK_MESSAGE_STREAM_ID` | — | **yes** |
| `MAIL_SENDMAIL_PATH` | `'/usr/sbin/sendmail -bs -i'` | no |
| `MAIL_LOG_CHANNEL` | — | **yes** |
| `MAIL_FROM_ADDRESS` | `'hello@example.com'` | no |
| `MAIL_FROM_NAME` | `env('APP_NAME', 'Laravel')` | no |

### `config/marketing.php` (13 keys)

| Key | Default | Required? |
| --- | ------- | --------- |
| `MARKETING_FIRST_PARTY_ENABLED` | `true` | no |
| `MARKETING_ANONYMOUS_COOKIE` | `'ff_aid'` | no |
| `MARKETING_EVENTS_ENABLED` | `true` | no |
| `MARKETING_CONSENT_ENABLED` | `true` | no |
| `MARKETING_CONSENT_COOKIE` | `'ff_consent'` | no |
| `MARKETING_CONSENT_VERSION` | `'2026-09'` | no |
| `MARKETING_GA4_ID` | — | **yes** |
| `MARKETING_GTM_ID` | — | **yes** |
| `MARKETING_META_PIXEL_ID` | — | **yes** |
| `MARKETING_TIKTOK_PIXEL_ID` | — | **yes** |
| `MARKETING_OG_DEFAULT_IMAGE` | `'img/og-default.png'` | no |
| `MARKETING_TWITTER_SITE` | — | **yes** |
| `MARKETING_CONTACT_EMAIL` | `'support@ffarena.test'` | no |

### `config/mobile.php` (24 keys)

| Key | Default | Required? |
| --- | ------- | --------- |
| `MOBILE_MIN_APP_VERSION` | `'1.0.0'` | no |
| `MOBILE_LATEST_APP_VERSION` | `'1.0.0'` | no |
| `MOBILE_UPDATE_REQUIRED` | `false` | no |
| `MOBILE_MAINTENANCE_MODE` | `false` | no |
| `MOBILE_MAINTENANCE_MESSAGE` | `'FF Arena is under maintenance. Pleas...` | no |
| `MOBILE_DEEP_LINK_SCHEME` | `'ffarena'` | no |
| `MOBILE_SUPPORT_URL` | `''` | no |
| `MOBILE_PRIVACY_URL` | `''` | no |
| `MOBILE_TERMS_URL` | `''` | no |
| `MOBILE_RELEASE_NOTES_URL` | `''` | no |
| `MOBILE_WEB_BASE_URL` | `''` | no |
| `MOBILE_STORE_URL` | `''` | no |
| `PUSH_FCM_ENABLED` | `false` | no |
| `PUSH_APNS_ENABLED` | `false` | no |
| `FCM_PROJECT_ID` | `''` | no |
| `FCM_CLIENT_EMAIL` | `''` | no |
| `FCM_PRIVATE_KEY` | `''` | no |
| `FCM_PRIVATE_KEY_PATH` | `''` | no |
| `APNS_KEY_ID` | `''` | no |
| `APNS_TEAM_ID` | `''` | no |
| `APNS_BUNDLE_ID` | `''` | no |
| `APNS_PRIVATE_KEY` | `''` | no |
| `APNS_PRIVATE_KEY_PATH` | `''` | no |
| `APNS_SANDBOX` | `false` | no |

### `config/notifications.php` (1 keys)

| Key | Default | Required? |
| --- | ------- | --------- |
| `NOTIFICATIONS_EMAIL` | `true` | no |

### `config/observability.php` (25 keys)

| Key | Default | Required? |
| --- | ------- | --------- |
| `REQUEST_ID_HEADER` | `'X-Request-ID'` | no |
| `REQUEST_ID_ACCEPT_INBOUND` | `true` | no |
| `METRICS_DRIVER` | `'log'` | no |
| `METRICS_LOG_CHANNEL` | `'metrics'` | no |
| `METRICS_SCRAPE_TOKEN` | — | **yes** |
| `METRICS_TTL_SECONDS` | `7200` | no |
| `ERROR_REPORTING_DRIVER` | `'log'` | no |
| `SENTRY_DSN` | — | **yes** |
| `HEALTH_WORKER_STALE_SECONDS` | `300` | no |
| `HEALTH_SCHEDULER_STALE_SECONDS` | `300` | no |
| `HEALTH_CACHE_PROBE_TTL` | `30` | no |
| `SECURITY_HSTS_ENABLE` | `true` | no |
| `SECURITY_HSTS_MAX_AGE` | `31536000` | no |
| `SECURITY_HSTS_INCLUDE_SUBDOMAINS` | `false` | no |
| `SECURITY_CSP_ENABLE` | `false` | no |
| `SECURITY_CSP_REPORT_ONLY` | `true` | no |
| `SECURITY_CSP_REPORT_URI` | — | **yes** |
| `SECURITY_CSP_POLICY` | `implode('; ', [ "default-src 'self'",...` | no |
| `OBS_RETENTION_OTP_DAYS` | `1` | no |
| `OBS_RETENTION_IDEMPOTENCY_DAYS` | `2` | no |
| `OBS_RETENTION_NOTIFICATIONS_DAYS` | `180` | no |
| `OBS_RETENTION_WEBHOOK_DELIVERIES_DAYS` | `30` | no |
| `OBS_RETENTION_WEBHOOK_EVENTS_DAYS` | `90` | no |
| `OBS_RETENTION_LIVE_EVENTS_DAYS` | `30` | no |
| `OBS_RETENTION_FAILED_JOBS_DAYS` | `30` | no |

### `config/payments.php` (37 keys)

| Key | Default | Required? |
| --- | ------- | --------- |
| `BKASH_ENABLED` | `true` | no |
| `BKASH_MODE` | `'sandbox'` | no |
| `BKASH_BASE_URL` | — | **yes** |
| `BKASH_APP_KEY` | — | **yes** |
| `BKASH_APP_SECRET` | — | **yes** |
| `BKASH_USERNAME` | — | **yes** |
| `BKASH_PASSWORD` | — | **yes** |
| `BKASH_MERCHANT_NUMBER` | — | **yes** |
| `NAGAD_ENABLED` | `true` | no |
| `NAGAD_MODE` | `'sandbox'` | no |
| `NAGAD_BASE_URL` | — | **yes** |
| `NAGAD_MERCHANT_ID` | — | **yes** |
| `NAGAD_MERCHANT_PRIVATE_KEY` | — | **yes** |
| `NAGAD_PG_PUBLIC_KEY` | — | **yes** |
| `NAGAD_MERCHANT_NUMBER` | — | **yes** |
| `ROCKET_ENABLED` | `true` | no |
| `ROCKET_MODE` | `'sandbox'` | no |
| `ROCKET_BASE_URL` | — | **yes** |
| `ROCKET_MERCHANT_ID` | — | **yes** |
| `ROCKET_MERCHANT_SECRET` | — | **yes** |
| `CARD_ENABLED` | `false` | no |
| `CARD_MODE` | `'sandbox'` | no |
| `CARD_GATEWAY` | — | **yes** |
| `CARD_MERCHANT_ID` | — | **yes** |
| `CARD_MERCHANT_SECRET` | — | **yes** |
| `BANK_ENABLED` | `true` | no |
| `BANK_ACCOUNT_NAME` | — | **yes** |
| `BANK_ACCOUNT_NUMBER` | — | **yes** |
| `SSLCOMMERZ_ENABLED` | `false` | no |
| `SSLCOMMERZ_MODE` | `'sandbox'` | no |
| `SSLCOMMERZ_STORE_ID` | — | **yes** |
| `SSLCOMMERZ_STORE_PASSWORD` | — | **yes** |
| `SSLCOMMERZ_BASE_URL` | — | **yes** |
| `PAYOUT_DUAL_CONTROL_THRESHOLD_MINOR` | `0` | no |
| `PAYMENT_WEBHOOK_RATE_LIMIT` | `240` | no |
| `PAYMENT_CALLBACK_RATE_LIMIT` | `120` | no |
| `PAYMENTS_CALLBACK_REQUIRE_STATE` | `true` | no |

### `config/queue.php` (15 keys)

| Key | Default | Required? |
| --- | ------- | --------- |
| `QUEUE_CONNECTION` | `'database'` | no |
| `DB_QUEUE_CONNECTION` | — | **yes** |
| `DB_QUEUE_TABLE` | `'jobs'` | no |
| `DB_QUEUE` | `'default'` | no |
| `DB_QUEUE_RETRY_AFTER` | `90` | no |
| `BEANSTALK_HOST` | `'localhost'` | no |
| `BEANSTALK_QUEUE` | `'default'` | no |
| `BEANSTALK_QUEUE_RETRY_AFTER` | `90` | no |
| `SQS_PREFIX` | `'https://sqs.us-east-1.amazonaws.com/...` | no |
| `SQS_QUEUE` | `'default'` | no |
| `SQS_SUFFIX` | — | **yes** |
| `REDIS_QUEUE_CONNECTION` | `'queue'` | no |
| `REDIS_QUEUE` | `'default'` | no |
| `REDIS_QUEUE_RETRY_AFTER` | `90` | no |
| `QUEUE_FAILED_DRIVER` | `'database-uuids'` | no |

### `config/reverb.php` (14 keys)

| Key | Default | Required? |
| --- | ------- | --------- |
| `REVERB_SERVER` | `'reverb'` | no |
| `REVERB_SERVER_HOST` | `'0.0.0.0'` | no |
| `REVERB_SERVER_PORT` | `8080` | no |
| `REVERB_MAX_REQUEST_SIZE` | `10000` | no |
| `REVERB_SCALING_ENABLED` | `false` | no |
| `REVERB_SCALING_CHANNEL` | `'reverb'` | no |
| `REVERB_SCALING_SERVER_URL` | — | **yes** |
| `REVERB_SCALING_SERVER_HOST` | `'127.0.0.1'` | no |
| `REVERB_SCALING_SERVER_PORT` | `6379` | no |
| `REVERB_SCALING_SERVER_USERNAME` | — | **yes** |
| `REVERB_SCALING_SERVER_PASSWORD` | — | **yes** |
| `REVERB_SCALING_SERVER_DB` | `0` | no |
| `REVERB_APP_PING_INTERVAL` | `60` | no |
| `REVERB_APP_MAX_MESSAGE_SIZE` | `10000` | no |

### `config/sanctum.php` (3 keys)

| Key | Default | Required? |
| --- | ------- | --------- |
| `SANCTUM_STATEFUL_DOMAINS` | `sprintf( '<key><key>', 'localhost,localhost...` | no |
| `SANCTUM_EXPIRATION` | `129600` | no |
| `SANCTUM_TOKEN_PREFIX` | `''` | no |

### `config/services.php` (8 keys)

| Key | Default | Required? |
| --- | ------- | --------- |
| `POSTMARK_API_KEY` | — | **yes** |
| `RESEND_API_KEY` | — | **yes** |
| `SLACK_BOT_USER_OAUTH_TOKEN` | — | **yes** |
| `SLACK_BOT_USER_DEFAULT_CHANNEL` | — | **yes** |
| `PAYMENT_WEBHOOK_SECRET` | `'ffarena-local-webhook-secret'` | no |
| `GOOGLE_CLIENT_ID` | — | **yes** |
| `GOOGLE_CLIENT_SECRET` | — | **yes** |
| `GOOGLE_REDIRECT_URI` | `env('APP_URL', 'http://localhost').'/...` | no |

### `config/services_go_rust.php` (31 keys)

| Key | Default | Required? |
| --- | ------- | --------- |
| `GO_PAYMENT_ENABLED` | `false` | no |
| `GO_PAYMENT_URL` | `'http://localhost:8081'` | no |
| `GO_PAYMENT_SECRET` | `'CHANGE_ME_GO_PAYMENT_SECRET_PLACEHOL...` | no |
| `GO_PAYMENT_TOKEN` | `'CHANGE_ME_GO_PAYMENT_TOKEN_PLACEHOLDER'` | no |
| `GO_PAYMENT_TIMEOUT` | `5` | no |
| `GO_PAYMENT_RETRY_MAX` | `3` | no |
| `GO_PAYMENT_CB_THRESHOLD` | `5` | no |
| `GO_PAYMENT_SERVICE_ID` | `'payment-gateway-go'` | no |
| `GO_PAYMENT_HMAC_SECRET` | `'CHANGE_ME_GO_PAYMENT_HMAC_SECRET_PLA...` | no |
| `GO_PAYMENT_RATE_LIMIT` | `60` | no |
| `RUST_SECURITY_ENABLED` | `false` | no |
| `RUST_SECURITY_URL` | `'http://localhost:8082'` | no |
| `RUST_SECURITY_SECRET` | `'CHANGE_ME_RUST_SECURITY_SECRET_PLACE...` | no |
| `RUST_SECURITY_TOKEN` | `'CHANGE_ME_RUST_SECURITY_TOKEN_PLACEH...` | no |
| `RUST_SECURITY_TIMEOUT` | `5` | no |
| `RUST_SECURITY_RETRY_MAX` | `3` | no |
| `RUST_SECURITY_SERVICE_ID` | `'security-rust'` | no |
| `RUST_SECURITY_HMAC_SECRET` | `'CHANGE_ME_RUST_SECURITY_HMAC_SECRET_...` | no |
| `RUST_SECURITY_RATE_LIMIT` | `60` | no |
| `POSTGRES_HOST` | `env('DB_HOST', '127.0.0.1')` | no |
| `POSTGRES_PORT` | `env('DB_PORT', '5432')` | no |
| `POSTGRES_DB` | `env('DB_DATABASE', 'ffarena')` | no |
| `POSTGRES_USER` | `env('DB_USERNAME', 'ffarena')` | no |
| `POSTGRES_PASSWORD` | `env('DB_PASSWORD', 'CHANGE_ME_POSTGRE...` | no |
| `REDIS_ENABLED` | `false` | no |
| `REDIS_QUEUE_DB` | `2` | no |
| `EVENTS_ENABLED` | `false` | no |
| `EVENTS_VERSION` | `'v1'` | no |
| `SERVICE_ID` | `'ffarena-laravel'` | no |
| `SERVICE_HMAC_SECRET` | `'CHANGE_ME_SERVICE_HMAC_SECRET_PLACEH...` | no |
| `SERVICE_AUTH_TOLERANCE` | `300` | no |

### `config/session.php` (14 keys)

| Key | Default | Required? |
| --- | ------- | --------- |
| `SESSION_DRIVER` | `'database'` | no |
| `SESSION_LIFETIME` | `120` | no |
| `SESSION_EXPIRE_ON_CLOSE` | `false` | no |
| `SESSION_ENCRYPT` | `false` | no |
| `SESSION_CONNECTION` | — | **yes** |
| `SESSION_TABLE` | `'sessions'` | no |
| `SESSION_STORE` | — | **yes** |
| `SESSION_COOKIE` | `Str::slug((string) env('APP_NAME', 'l...` | no |
| `SESSION_PATH` | `'/'` | no |
| `SESSION_DOMAIN` | — | **yes** |
| `SESSION_SECURE_COOKIE` | `null` | no |
| `SESSION_HTTP_ONLY` | `true` | no |
| `SESSION_SAME_SITE` | `'lax'` | no |
| `SESSION_PARTITIONED_COOKIE` | `false` | no |

### `config/trustedproxy.php` (2 keys)

| Key | Default | Required? |
| --- | ------- | --------- |
| `TRUSTED_PROXIES` | `null` | no |
| `TRUSTED_PROXY_HEADERS` | `'X_FORWARDED_FOR\|X_FORWARDED_HOST\|X_F...` | no |

### `config/webhooks.php` (11 keys)

| Key | Default | Required? |
| --- | ------- | --------- |
| `WEBHOOK_BKASH_SECRET` | — | **yes** |
| `WEBHOOK_NAGAD_SECRET` | — | **yes** |
| `WEBHOOK_ROCKET_SECRET` | — | **yes** |
| `WEBHOOK_SSLCOMMERZ_SECRET` | — | **yes** |
| `WEBHOOK_CARD_SECRET` | — | **yes** |
| `PAYMENT_BUSINESS_BKASH_SECRET` | — | **yes** |
| `PAYMENT_BUSINESS_NAGAD_SECRET` | — | **yes** |
| `PAYMENT_BUSINESS_ROCKET_SECRET` | — | **yes** |
| `PAYMENT_BUSINESS_SSLCOMMERZ_SECRET` | — | **yes** |
| `PAYMENT_BUSINESS_CARD_SECRET` | — | **yes** |
| `PAYMENT_BUSINESS_TIMESTAMP_TOLERANCE` | `300` | no |

<!-- config-key total: 385 -->

### 5.2 App-direct reads (5 keys)

| Key | Reader | Default | Notes |
| --- | --- | --- | --- |
| `JAEGER_ENDPOINT` | `app/Services/TracingService.php` | none | Preferred tracing endpoint. |
| `OTEL_EXPORTER_JAEGER_ENDPOINT` | `app/Services/TracingService.php` | none | Fallback when `JAEGER_ENDPOINT` is empty. |
| `SMS_GATEWAY_ENDPOINT` | phone OTP delivery | none | Phase 14 block in the template. |
| `SMS_GATEWAY_API_KEY` | phone OTP delivery | none | Phase 14 block in the template. |
| `SMS_GATEWAY_SENDER` | phone OTP delivery | none | Phase 14 block in the template. |

### 5.3 Compose-interpolated keys (root + `deploy/`)

These are read by `docker compose` / nginx config interpolation, not by PHP.
Bare uses (no `:-` default) fail the local stack when unset.

| Key | Consumer | Template value |
| --- | --- | --- |
| `JWT_SECRET` | root compose → app/worker/daemons; Go gateway (no fallback) | empty + REQUIRED comment |
| `WEBHOOK_SECRET` | root compose → app/worker/daemons; Go gateway (no fallback) | empty + REQUIRED comment |
| `APP_PORT` | root compose port mapping | `8000` (compose default) |
| `APP_DOMAIN` | `deploy/nginx.tls.conf` server_name | empty (compose default is the production domain) |
| `TLS_CA_PATH`, `TLS_CERT_PATH`, `TLS_KEY_PATH` | TLS nginx container | compose defaults verbatim |
| `WAL_LEVEL`, `WAL_ARCHIVE_ENABLED` | Postgres service tuning | compose defaults verbatim |
| `LOG_CENTRAL_ENABLED` | log shipping toggle | `false` (compose default) |

The remaining compose variables (`APP_KEY`, `DB_*`, `REDIS_*`,
`TRUSTED_PROXIES`, `POSTGRES_*`, `SERVICE_HMAC_SECRET`, `APP_DEBUG`,
`APP_ENV`, `APP_NAME`, `APP_URL`) are PHP keys first and covered in §5.1.

### 5.4 Deploy-time shell variables (NOT dotenv keys)

Read by `deploy.sh` / CI from the process environment. Deliberately absent
from `.env.example`:

| Variable | Meaning |
| --- | --- |
| `REGISTRY` | Container registry (default `ghcr.io`). |
| `APP_VERSION` | Image tag being deployed. |
| `ROLLBACK_VERSION` | Target for `deploy.sh ... rollback`. |
| `PROD_ENV_FILE` | Production env file path for the deploy gate (default `.env.production`). |

### 5.5 Reserved keys (template holds them, no code reads them)

| Key | Status |
| --- | --- |
| `PAYMENT_CALLBACK_SECRET` | No config entry maps it; the callback signer falls back to `PAYMENT_WEBHOOK_SECRET`, then an `APP_KEY`-derived key. Kept for forward compatibility. |
| `BKASH_TOKEN_TTL_SECONDS` | No consumer anywhere in the tree. Kept as reserved. |
| `BCRYPT_ROUNDS` | Standard Laravel key; this tree has no `config/hashing.php`, so nothing reads it. Kept for tooling. |

### 5.6 Frontend

`VITE_APP_NAME` is a Vite build-time variable (`"${APP_NAME}"`), not a PHP
read. It stays in the template so frontend builds resolve it.

## 6. Adding a key

1. Add the `env()` read (or compose `${VAR}`) where it is consumed.
2. Add the key to `.env.example` in the matching group (R2 block convention:
   scalar code default verbatim and active; computed default commented with
   the expression; no default → empty with a `REQUIRED` comment).
3. Run the static floor: `python3 -m unittest discover -s tests/Static -p 'test_*.py'`.
   The env-coverage floor fails until step 2 is done — that is the drift pin.
4. Only production-critical keys need a validator rule (§3). Most keys need
   no gate change.

## 7. Observations (not in R2 scope, recorded)

- Companion-daemon auth, verified 2026-10-07 (GAP-R9): the pieces exist
  but are unenforced. Boot secrets: Rust fails closed (`main.rs:61` calls
  `validate_secret_strength`); Go half-validates (server boot rejects
  set-but-short JWT/HMAC at `cmd/server/main.go:40`, but EMPTY secrets pass
  in every environment, webhook secrets are never screened, the weak-word
  `ValidateSecretStrength` is dead code, and worker/migrate skip validation).
  Request auth: Go mounts no auth middleware (chain at
  `cmd/server/main.go:216-228`; `BearerAuth` length-checks tokens without
  verifying, `VerifyJWT` has zero callers, `HMACVerify` runs only in tests)
  in front of money endpoints (`POST /api/v1/payments|wallets|payouts`,
  webhooks inbound); Rust's correct `with_auth` family is never applied to
  the evaluate routes. Provider webhooks ARE verified per-provider
  (`webhooks/service.go:142`), but bkash/manual/rocket HMAC `Secret` is not
  required when enabled (`base.go:71` checks `BaseURL` only), so an empty
  secret voids the check. Mitigations (all hold): FIX-18 expose-only
  (`docker-compose.yml:154,192`, no published ports), both daemons default
  to disabled, production composes never run them, and Laravel verifies
  inbound daemon calls (`EnsureServiceHmac`). Close-criterion: packet R9 in
  `docs/RUNTIME_WORKPACKETS.md`.
- `APP_DOMAIN` defaults to `api.ffarena.com` in compose/nginx interpolation:
  a production domain as a default. Local checkouts should set it explicitly.
- `SESSION_COOKIE` is read by a multiline `env()` call
  (`config/session.php:40-43`); line-based censuses miss it. The drift floor
  scans whole-file text so this class stays covered.
- `REDIS_PREFIX` was observed (R2) without a gate rule; since 2026-10-07
  the warn-only `REDIS_PREFIX_RECOMMENDED` rule covers it (warn, not fail:
  the gate cannot see cluster sharing).

## 8. Census method

`tests/Static/test_env_coverage_floor.py` re-runs the census on every
invocation: whole-text `env('KEY')` scan over `config/`, `app/`, `database/`,
`routes/` plus `${KEY}` scan over the root and `deploy/` compose/nginx files,
asserted against active-or-commented template entries. The tables in §5.1
were generated from the same scan on 2026-10-07; if they ever disagree with
the floor, the floor wins and this doc needs a regen (see §6).
