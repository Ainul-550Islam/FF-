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
