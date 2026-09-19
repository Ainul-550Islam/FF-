# FF Arena — Incident Response (Final Hardening)

## Overview

Incident response plan for production security incidents, outages, data breaches.

## Roles

- Incident Commander: CTO / Lead Engineer
- Security Lead: Security Engineer
- Communications: Support Lead
- Engineering: Backend, Mobile, DevOps

## Detection

- Monitoring: Prometheus + Grafana alerts, /health/ready failures, error rate spikes, failed_jobs queue depth, Redis failures, DB connectivity
- Logging: security channel warnings, audit channel anomalies, errors channel spikes
- Alerts: Slack webhook LOG_SLACK_WEBHOOK_URL, PagerDuty if configured, email

## Classification

- P1 Critical: Data breach, payment data exposure, auth bypass, production down
- P2 High: Service degradation, queue backup, Redis down with fallback, payment callback failures
- P3 Medium: Non-critical feature failure, mobile version enforcement needed
- P4 Low: Warning, not configured, performance degradation

## Response Steps

1. **Detect** — Alert via monitoring, logs, user report
2. **Triage** — Classify P1-P4, assign Incident Commander
3. **Contain** — Isolate affected system, rotate secrets if breach, enable maintenance mode if needed `php artisan down`
4. **Investigate** — Gather request_id, correlation_id, logs from central aggregation, no secrets in logs, check audit trail
5. **Mitigate** — Deploy fix, rollback if needed via `deploy/rollback.sh`, clear cache, restart workers
6. **Recover** — Verify health checks /health/live and /health/ready PASS, run deployment gate `php artisan deploy:gate`, run security checklist `php artisan security:checklist`
7. **Post-mortem** — Document timeline, root cause, remediation, prevention, update runbooks

## Communication

- Internal: Slack #incidents, email security@ffarena.com
- External: Status page, user notifications via in-app notification (never wallet balance in push), support URL

## Tools

- Logs: `storage/logs/` + central aggregation (Papertrail, ELK, Loki)
- Metrics: `/metrics` Prometheus, Grafana dashboards
- Health: `/health/live`, `/health/ready`
- Deployment: `deploy/deploy.sh`, `deploy/rollback.sh`, `.last_successful_version`
- Backup: `php artisan backup:verify`, `storage/backups/`
- Config validation: `php artisan config:validate-production`, `php artisan security:checklist`, `php artisan deploy:gate`, `php artisan security:scan-secrets`

## Secrets Rotation on Breach

If secret leaked:

1. Immediately rotate secret in provider dashboard
2. Update secret in Vault / secret store
3. Deploy with new secret
4. Revoke old secret after verification
5. Audit log rotation event via security channel
6. Check logs for secret exposure — redact if found, verify RedactSensitiveDataProcessor working

## Contact

- Security: security@ffarena.com
- Support: support@ffarena.com
- On-call: via PagerDuty or Slack

## Runbooks

- Database down: check `pg_isready`, restore from backup if needed, never restore over production without confirmation
- Redis down: check `redis-cli ping`, fallback to database cache, queue sync fallback, monitor queue depth
- Queue backup: check `php artisan queue:metrics`, restart workers `supervisorctl restart queue:*`, check failed_jobs
- TLS cert expiry: check `TLS_CERT_PATH`, renew via Let's Encrypt, reload nginx `nginx -s reload`
- Payment callback failures: check webhook signature verification, timestamp validation, idempotency, TLS
