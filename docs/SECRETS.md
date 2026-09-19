# FF Arena — Secrets Management (Final Hardening)

## No Real Secrets Committed

No real secret may remain committed to source control. All secrets use obvious placeholders in `.env.example`.

## Secret Naming Standard

Format: `{SERVICE}_{TYPE}` — uppercase, underscore

Examples:
- `DB_PASSWORD` — database password
- `REDIS_PASSWORD` — Redis password
- `APP_KEY` — Laravel encryption key
- `BKASH_APP_SECRET` — bKash app secret
- `NAGAD_PRIVATE_KEY` — Nagad private key
- `WEBHOOK_SECRET` — webhook signing secret
- `GOOGLE_CLIENT_SECRET` — OAuth client secret
- `FCM_SERVER_KEY` — Firebase Cloud Messaging server key
- `APNS_KEY_PATH` — APNs key path (file path, not key content)
- `DB_BACKUP_ENCRYPTION_KEY` — backup encryption key
- `GRAFANA_PASSWORD` — Grafana admin password

## Environment Separation

| Environment | Source | Storage |
| --- | --- | --- |
| local | `.env` with placeholders | Local file, never committed |
| test | CI secret store or `.env.testing` | CI platform secret store |
| staging | CI secret store | Vault / CI secret store, staging-specific |
| production | Vault / secret manager | Vault (HashiCorp Vault, AWS Secrets Manager, etc.), production-specific, never in repo |

**Rules:**
- Local uses `CHANGE_ME_*_PLACEHOLDER` values
- Staging uses staging secrets from CI secret store
- Production uses production secrets from vault — never in repo, never in CI logs
- Forked pull requests must not receive production secrets

## Rotation Procedure

1. Generate new secret in provider dashboard (e.g., bKash, Nagad, Google Cloud Console, Redis, PostgreSQL)
2. Update secret in secret store (Vault, AWS Secrets Manager, CI secret store)
3. Deploy application with new secret — verify health checks pass
4. Revoke old secret after verification (24h grace period)
5. Audit log rotation event

**Rotation frequency:**
- Database passwords: 90 days
- Redis passwords: 90 days
- API keys: 90 days
- OAuth secrets: 180 days or on breach
- Encryption keys: 365 days with re-encryption plan
- Webhook secrets: 90 days

## Secret Leak Prevention

Implemented via `RedactSensitiveDataProcessor`:

Redacts from logs, exceptions, validation errors, audit payloads, queue payloads, webhook logs, API responses, debug pages, health endpoints, monitoring, CI logs:

- authorization headers
- bearer tokens
- cookies
- passwords
- OTPs
- payment credentials
- private keys
- webhook signatures where appropriate
- OAuth secrets
- database credentials

**Processors:**
- `RedactSensitiveDataProcessor` — scrubs secrets as last-line defence, patterns for Bearer, Basic, sk_live, private keys, JWT
- `RequestContextProcessor` — safe fields only: timestamp, environment, request_id, correlation_id, route, method, status, duration, user_id, tournament_id, request source, job name, queue name, error category — never passwords, OTP, full tokens, secret keys, card data, raw private auth material, unnecessary PII

## CI Secret Security

- Secrets must use CI platform's secret store (GitHub Secrets, GitLab CI variables)
- Never print secrets — no `echo $SECRET`, no debug shell output with env
- Forked PRs must not receive production secrets
- Artifacts must not contain secrets
- Use `::add-mask::` in GitHub Actions to mask secrets in logs

## .env.example Safety

`.env.example` contains only placeholders like `CHANGE_ME_*_PLACEHOLDER`, never real credentials.

Validation in `ProductionConfigValidator` checks `.env.example` for suspicious real secret patterns (sk_live_, private keys, google client IDs) and fails if found.

## What Is NOT Logged

- passwords
- OTP
- full access tokens
- secret keys
- card data (card_number, cvc, expiry, pan)
- raw private authentication material
- unnecessary personal information

## Audit

All secret rotations are audit logged via `security` channel with safe context (request_id, user_id, service) but never secret values.
