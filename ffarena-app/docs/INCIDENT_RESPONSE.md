# FF Arena — Incident Response

Escalation and response guide for operational and security incidents.

---

## 1. Severity levels

| Level | Definition | Response time |
|---|---|---|
| Sev-1 | Payments/payouts down, data corruption, security breach, total outage | immediate |
| Sev-2 | Partial outage (one surface), high error rate | < 30 min |
| Sev-3 | Degraded (slow, flapping webhooks) | < 4 h |
| Sev-4 | Cosmetic / non-blocking | next working day |

---

## 2. Roles

| Role | Responsibility |
|---|---|
| Incident commander | coordinates, decides mitigations, communicates |
| Engineering | diagnosis + fix |
| Operations | infra (queue, cache, DB, storage, backups) |
| Support/Moderation | user-facing comms (Phase 13 moderation queue) |
| Security (admin) | account compromise, fraud, data exposure |

---

## 3. Initial triage (any incident)

1. Check `https://<host>/health/ready` and `php artisan ffarena:health --production`.
2. Open `/admin/ops` (admin) — readiness, queue backlog, failed jobs, webhook
   failures, scheduler heartbeat, last backup.
3. Correlate errors by `X-Request-ID` in `storage/logs/*.log`.
4. Capture the window (start time, affected surfaces) before changing anything.

---

## 4. Security incident

- Suspected account compromise / token leak: revoke the token (admin accounts
  page), rotate `APP_KEY` if it may be exposed, force session revocation,
  write an `auth.suspicious_login`-style audit entry.
- Data exposure: contain the endpoint, preserve logs, do NOT delete audit rows
  (append-only by design).

---

## 5. Payment incident

- Never trust provider callbacks until internal state validates (Phase 08/15
  state machine enforces this).
- Replay lost webhooks: inbound events are idempotent by `external_event_id`
  and recorded in `webhook_events`.
- Do not manually flip a payment to `verified` — use the admin verification
  flow which performs the reconciliation checks.

---

## 6. Database outage

- `health/ready` reports 503; the readiness `database` check is red.
- Check disk space and connection. Restore from backup only after
  `ffarena:backup:verify` passes (see `docs/DISASTER_RECOVERY.md`).

---

## 7. Queue outage

- `php artisan ffarena:queue:health` reports backlog and failed counts.
- `php artisan queue:failed` + `/admin/ops/failed-jobs` inspect failed jobs.
- Retry with `/admin/ops` (audited) or `php artisan queue:retry all`.
- Delivery failures never roll back business state; retry the queued job.

---

## 8. Webhook outage

- Inbound: providers retry on their side; our events are idempotent.
- Outbound: `SendWebhookDelivery` retries with exponential backoff and
  disables an endpoint after 6 consecutive failures; inspect
  `webhook_deliveries` and re-enable the endpoint when the receiver is back.

---

## 9. Data corruption

- Stop writes (`php artisan down`).
- Verify latest backup; restore into a side path first.
- Investigate `audit_logs` for the mutating action that preceded corruption.

---

## 10. Account compromise

- Deactivate the account (admin accounts page) — Phase 14 `EnsureActiveAccount`
  blocks it immediately; revoke API tokens (admin ops + token revocation).
- Review `login_events` / `audit_logs` for the actor's recent actions.

---

## 11. Cheating / fraud escalation

- Phase 10 anti-fraud signals feed `risk_events`; restrict via the admin
  security page (audited). Evidence handling follows Phase 07 dispute rules —
  never expose private evidence publicly.

---

## 12. Post-incident

- Write a timeline; fix the monitoring gap (add an alert hook — see
  `docs/OBSERVABILITY.md`); keep the audit trail intact.
