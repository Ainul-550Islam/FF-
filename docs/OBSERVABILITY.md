# FF Arena — Observability

What Phase 16 emits, where it goes, and how to consume it. All values are
redacted at the source; secrets, raw IPs, device fingerprints and internal
paths are never logged.

---

## 1. Request correlation

Every HTTP request carries a correlation id:

- Header: `X-Request-ID` (echoed on the response).
- A well-formed inbound id (8–64 chars of `[A-Za-z0-9-]`) is trusted; anything
  else is replaced with a UUID v4.
- The id is attached to structured logs, error reports, metrics lines and the
  Phase 13 audit trail (`audit_logs.request_id`).

---

## 2. Structured logging

Configured in `config/logging.php`. All file channels run three processors:
`PsrLogMessageProcessor` (placeholder interpolation), `RequestContextProcessor`
(request id / route / method / user & token ids) and
`RedactSensitiveDataProcessor` (last-line secret scrubbing).

| Channel | Purpose |
|---|---|
| `single` / `daily` | default (LOG_CHANNEL/LOG_STACK unchanged) |
| `json` | JSON-lines stream `storage/logs/ffarena.jsonl` |
| `security`, `payments`, `webhooks`, `queue`, `audit`, `errors`, `metrics` | domain-separated daily files |

Retention: daily rotation, `LOG_DAILY_DAYS` (default 14).

---

## 3. Error reporting

`ErrorReporterInterface` → `ErrorReporterManager`:

- `log` (default) — `LogErrorReporter` writes to the `errors` channel.
- `sentry` — selected via `ERROR_REPORTING_DRIVER=sentry` + `SENTRY_DSN`; if
  the SDK isn't installed the app stays log-only (never fakes reporting).

The bootstrap exception pipeline reports every throwable with the request
correlation snapshot; the API renderer still returns the Phase 15 error
envelope with no internals.

---

## 4. Metrics

`MetricsInterface` → log-backed counters/gauges/timings written to the
`metrics` channel (`METRICS_DRIVER=log` default, `null` disables). Emitted by:

- `HttpMetrics` middleware — requests, response status classes, latency.
- Queue event listeners — `queue.jobs_processed`, `queue.jobs_failed`.

Business seams (registration, scoring, payments, webhooks) share the
`App\Support\Metrics` facade for future counters. Labels are strictly
low-cardinality (no user ids, IPs, or unbounded input).

---

## 5. Health checks

- `/health/live` — liveness (public, `{status:"ok"}`).
- `/health/ready` — readiness (200/503) over database, cache, filesystem,
  queue and config; per-check booleans only.
- `php artisan ffarena:health [--production]` — operator diagnostics
  (redacted) + production-misconfiguration detection.
- Admin `/admin/ops/health` — JSON checks (admin only).

---

## 6. Operational dashboard

`/admin/ops` (admin only) shows: readiness, queue backlog/oldest-age,
failed jobs, scheduler heartbeat, webhook endpoint/failure counts, storage
usage, latest backup, production-config issues, plus failed-job retry/delete,
cache-flush (whitelisted namespaces), backup and backup-verify controls. All
mutations are audited via Phase 13.

---

## 7. Alerting hooks

Provider-neutral today: critical events land in the `errors`/`queue` channels
and admin notifications (backup failure). To wire an external alerting
service, consume the `metrics` JSON-lines stream or tail the domain channels —
no SaaS integration is hard-wired.

Suggested thresholds:

- 5xx rate > 1% over 5 min
- `queue.jobs_failed` spike
- webhook `failures_24h` > N
- queue backlog / oldest-pending age > 5 min
- backup create/verify failure
- `/health/ready` = not_ready

---

## 8. Retention

| Data | Window | Config |
|---|---|---|
| Logs | 14 days (rotating) | `LOG_DAILY_DAYS` |
| OTP challenges | 1 day | `observability.retention.otp_challenges_days` |
| Idempotency keys | 2 days | `observability.retention.idempotency_keys_days` |
| Notifications (read) | 180 days | `observability.retention.notifications_days` |
| Webhook deliveries | 30 days | `observability.retention.webhook_deliveries_days` |
| Webhook events (inbound) | 90 days | `observability.retention.webhook_events_days` |
| Live events | 30 days | `observability.retention.live_events_days` |
| Failed jobs | 30 days | `observability.retention.failed_jobs_days` |
| Backups | 14 (count) | `BACKUP_RETENTION` |

Immutable business and audit records are **never** deleted by cleanup jobs.
