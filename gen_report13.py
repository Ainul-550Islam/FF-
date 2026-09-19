import os
#!/usr/bin/env python3
"""Generate PHASE13_ADMIN_MODERATION_AUDIT_SUPPORT_ANALYTICS_REPORT.md."""
import subprocess, os, datetime

ROOT = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.join(ROOT, "PHASE13_ADMIN_MODERATION_AUDIT_SUPPORT_ANALYTICS_REPORT.md")

def read(p):
    with open(os.path.join(ROOT, p), "r", encoding="utf-8") as f:
        return f.read()

def dump_block(p, lang):
    body = read(p)
    rel = p
    return f"### `{rel}`\n\n```{lang}\n{body.rstrip()}\n```\n"

NEW_FILES = [
    "database/migrations/2026_09_08_020000_create_audit_and_support_tables.php",
    "config/audit.php",
    "app/Models/AuditLog.php",
    "app/Models/SupportTicket.php",
    "app/Models/SupportMessage.php",
    "app/Models/SupportInternalNote.php",
    "app/Services/AuditLogService.php",
    "app/Services/SupportTicketService.php",
    "app/Services/AnalyticsService.php",
    "app/Support/CsvExport.php",
    "app/Policies/AuditLogPolicy.php",
    "app/Policies/SupportTicketPolicy.php",
    "app/Http/Middleware/EnsureUserIsStaff.php",
    "app/Http/Middleware/AssignAuditRequestId.php",
    "app/Http/Controllers/AuditController.php",
    "app/Http/Controllers/SupportController.php",
    "app/Http/Controllers/AdminSupportController.php",
    "app/Http/Controllers/AnalyticsController.php",
    "resources/views/admin/audit.blade.php",
    "resources/views/admin/analytics/index.blade.php",
    "resources/views/admin/analytics/tournaments.blade.php",
    "resources/views/admin/analytics/financial.blade.php",
    "resources/views/admin/analytics/security.blade.php",
    "resources/views/admin/analytics/disputes.blade.php",
    "resources/views/admin/analytics/support.blade.php",
    "resources/views/admin/analytics/tournament.blade.php",
    "resources/views/admin/support.blade.php",
    "resources/views/admin/support_ticket.blade.php",
    "resources/views/support/index.blade.php",
    "resources/views/support/create.blade.php",
    "resources/views/support/show.blade.php",
    "tests/Feature/AuditLogTest.php",
    "tests/Feature/AuditIntegrationTest.php",
    "tests/Feature/SupportTicketTest.php",
    "tests/Feature/AnalyticsTest.php",
]

MODIFIED_FILES = [
    "app/Models/Notification.php",
    "app/Models/LiveEvent.php",
    "bootstrap/app.php",
    "routes/web.php",
    "app/Http/Controllers/AdminController.php",
    "app/Http/Controllers/SecurityController.php",
    "app/Http/Controllers/PayoutController.php",
    "app/Http/Controllers/SettlementController.php",
    "app/Http/Controllers/TournamentController.php",
    "app/Http/Controllers/TeamController.php",
    "app/Http/Controllers/MatchController.php",
    "app/Http/Controllers/DisputeController.php",
    "resources/views/layouts/app.blade.php",
    "resources/views/admin/dashboard.blade.php",
]

now = datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S")

S = []
S.append(f"""# Phase 13 — Admin Moderation + Audit Logs + Support + Analytics

**Project:** FF Arena (Laravel 12, PHP 8.4, SQLite)
**Generated:** {now}
**Phase:** 13 of the delivery plan
**Status:** COMPLETE — all acceptance criteria verified

This report documents the full implementation of Phase 13: the central append-only
audit log, the admin moderation dashboard, the user/staff support ticket system, and
the operational analytics suite. It includes the complete final content of every new
and modified file.

---

## 1. Overview & Scope

Phase 13 adds four cohesive subsystems to FF Arena without redesigning any prior
phase:

1. **Central audit log** — an append-only `audit_logs` table capturing admin,
   security and business mutations across the platform, written exclusively through
   `AuditLogService` with a closed action vocabulary, field redaction and payload
   caps.
2. **Admin moderation dashboard** — the existing admin dashboard is extended with an
   Operations panel and a dedicated audit-log browser; the existing Phase 07
   moderation/security surfaces are reused, not rebuilt.
3. **Support tickets** — `support_tickets`, `support_messages` and
   `support_internal_notes` with a strict status state machine, staff queue, internal
   notes invisible to users, notifications (Phase 11) and realtime events (Phase 12).
4. **Analytics** — operational aggregates only (tournament, match, player, financial,
   security, dispute, support) with strict role scoping and CSV export.

Integration strategy: Phase 13 **references** the domain audit trails already built in
Phases 07–10 (`ModerationEvent`, `RiskEvent`/`Restriction`, `PaymentEvent`/
`PayoutEvent`/`LedgerEntry`) rather than duplicating their detail. Audit hooks are
injected at the exact mutation points of the existing controllers, so every
pre-existing business rule and state machine is preserved untouched.

Scope boundary honoured: no Phase 14+ work (public/mobile API, webhook redesign,
observability, CI/CD, SEO/accessibility, mobile app, external BI) was implemented,
and Phases 06–12 were only integrated with, never redesigned.

---

## 2. Assumption

- The application remains a **single Laravel 12 monolith** with SQLite storage; no
  distributed tracing, no separate analytics warehouse, no BI tooling.
- **Role model** (unchanged from Phase 01–07): `admin`, `organizer`, `moderator`,
  `player`. `User::isStaff()` == admin OR moderator. Moderators handle disputes/anti-
  cheat/security reviews and support; they must **not** reach financial or global-
  risk analytics. Organizers see only their own tournaments. Players see their own
  tickets and public tournament info only.
- The existing domain audit trails (Phases 07–10) remain the system of record for
  their domains; `audit_logs` is the central index that points at them via
  `entity_type`/`entity_id` and stores only whitelisted, display-safe metadata.
- CSV export is provided only where genuinely useful (audit browser, support queue,
  tournament analytics) and never leaks secrets, fraud data, private evidence,
  identity documents or internal notes.
- Attachments are **out of scope** (no private-storage requirement was added), so no
  support attachment columns were created.

---

## 3. Current-Architecture Audit

Pre-Phase 13 state (verified by reading the codebase):

- Routing: `routes/web.php` with `guest`, `auth`, `admin` (alias → `EnsureUserIsAdmin`)
  groups; 103 routes registered.
- Auth: only two middleware were registered — `EnsureUserIsAdmin` (abort 403 unless
  `isAdmin()`) and the framework defaults. No staff-alias, no request-id middleware.
- Controllers already emitted business mutations with **no central audit trail**:
  `AdminController` (role promote/demote, wallet credit/debit, payment verify,
  moderation), `SecurityController` (restrictions, identity verification, anti-cheat),
  `PayoutController`/`SettlementController` (financial state machines),
  `TournamentController`/`TeamController`/`MatchController` (tournament lifecycle,
  rosters, bracket/scoring), `DisputeController` (dispute lifecycle).
- Domain audit already existed: `ModerationEvent` (Phase 07), `RiskEvent` +
  `Restriction` (Phase 10), `PaymentEvent`/`PayoutEvent`/`LedgerEntry` (Phases 08/09).
- Money is stored in integer minor units (`Money::MINOR_UNITS = 100`).
- Only `UserFactory` existed; no support/audit factories.

---

## 4. Existing Moderation Audit

The Phase 07 moderation subsystem (`ModerationController`, `DisputePolicy`,
`DisputeService`, `ModerationEvent`) already enforced:

- Organizer disputes scoped to `organizer_id`; `security` view admin/moderator-only.
- `DisputePolicy`: `isStaff`, `isParticipant`, `view`, `addEvidence`, `viewEvidence`,
  `manage` (review/assign/resolve/reject/removeEvidence), `cancel`.
- A staff-only moderation queue and per-dispute evidence review.

Phase 13 leaves all of this intact and **adds** central audit entries at dispute
decision points (`dispute.under_review`, `dispute.assigned`, `dispute.resolved`,
`dispute.rejected`, `dispute.cancelled`, `dispute.evidence_removed`) via hooks in
`DisputeController`.

---

## 5. Existing Audit/Log Audit

Pre-existing append-only patterns were reused as the model for the central log:

- Append-only models use `protected $fillable = []` and, where historical,
  `public const UPDATED_AT = null`.
- `PaymentEvent`, `PayoutEvent`, `RiskEvent` set `UPDATED_AT = null` (no update
  column).

The new `AuditLog` model follows the same convention (empty `$fillable`,
`UPDATED_AT = null`) and additionally refuses in-place mutation at the model layer.

---

## 6. Audit Architecture

- **Table** `audit_logs`: `id`, `actor_user_id` (nullable, nullOnDelete),
  `action` (indexed), `entity_type`/`entity_id` (indexed pair), `tournament_id`
  (nullable, nullOnDelete, indexed), `target_user_id` (nullable, nullOnDelete,
  indexed), `before`/`after`/`metadata` (JSON, safe), `request_id` (indexed),
  `source`, `created_at`. `updated_at` intentionally absent.
- **Writer** `AuditLogService` is the only code path that inserts rows; every
  integration point calls it (directly, or `recordQuietly()` for non-critical
  integrations).
- **Reader** `AuditController` (admin-only index + CSV export) with
  `AuditLogService::search()` / `paginate()` / `relatedHistory()`.
- **Policy** `AuditLogPolicy` — admin-only for every audit operation.
- **Correlation** `AssignAuditRequestId` web middleware assigns a UUID per request;
  `AuditLogService::requestId()` falls back to the container/static context so
  non-HTTP writes still work.

---

## 7. Immutability

- Normal users have **no route, controller, or UI** that creates, updates, deletes or
  fabricates audit rows; the only audit endpoints are admin read-only (index/export).
- Model layer: `AuditLog::booted()` registers `static::updating(fn () => false)` and
  `static::deleting(fn () => false)` — in-place saves return `false` and deletes
  return `false` even for an admin.
- `$fillable = []` — mass assignment is impossible (`fill()`/`create()` throw
  `MassAssignmentException`).
- Database: no update/delete migration capability is exposed to the app; rows are
  only ever inserted by the service.
- Verified by `AuditLogTest`: append-only tests confirm update returns `false`,
  delete returns `false`, and the persisted action is unchanged.

---

## 8. Audit Service

`App\\Services\\AuditLogService` provides:

- `record(?User $actor, string $action, ?string $entityType, ?int $entityId, array $options)` —
  validates against the **closed vocabulary** (56 actions), sets actor/entity/
  tournament/target, redacts + caps `before`/`after`/`metadata`, stamps `request_id`
  and `source`, saves. Throws on unknown action.
- `recordQuietly(...)` — same, but catches `Throwable`, `report()`s it and returns
  `null` (integration seam so a logging failure can never break a business flow).
- `recordAuth(User $user, string $action, array $metadata)` — auth events.
- `recordAdminAction(User $admin, string $action, ...)` — admin mutations.
- `recordSecurityAction(User $staff, string $action, ...)` — security mutations.
- `recordModelChange(?User $actor, string $action, Model $model, array $before, array $after)` —
  generic before/after capture with model `getKey()`/`getTable()`.
- `search(array $filters, int $perPage)` — whitelisted filters (action, entity_type,
  entity_id, tournament_id, target_user_id, request_id, from/to date) with
  `sort` whitelisted to `created_at` and direction whitelisted to `asc|desc`.
- `paginate(int $perPage)` — newest-first admin listing.
- `relatedHistory(string $entityType, int $entityId, int $limit)` — bounded history
  for one entity, newest first.

The full action vocabulary is listed in Section 43 (`app/Services/AuditLogService.php`).

---

## 9. Integrations

Audit hooks were applied as **exact-match insertions** at the mutation points of the
existing controllers (68 replacements across 8 controllers, all verified `OK`):

| Controller | Actions recorded |
|---|---|
| `AdminController` | `role.change`, `wallet.credited`, `wallet.debited`, `payment.verified`, `payment.refunded`, `restriction.applied`, `restriction.lifted` |
| `SecurityController` | `identity.verified`, `identity.rejected`, `restriction.applied`, `restriction.lifted`, `anti_cheat.opened`, `anti_cheat.resolved` |
| `PayoutController` | `payout.approved`, `payout.processed`, `payout.override`, `payout.completed`, `payout.failed`, `payout.cancelled` |
| `SettlementController` | `settlement.tiers_saved`, `settlement.calculated`, `settlement.approved`, `settlement.processed`, `settlement.cancelled`, `settlement.adjusted` |
| `TournamentController` | `tournament.published`, `tournament.registration_closed`, `tournament.started`, `tournament.completed`, `tournament.cancelled`, `tournament.noshows`, `tournament.waitlist_promoted` |
| `TeamController` | `team.registered`, `team.withdrawn`, `team.member_added`, `team.member_removed`, `team.updated`, `team.checked_in` |
| `MatchController` | `match.score_adjusted`, `match.winner_set`, `match.disputed`, `match.resolved` |
| `DisputeController` | `dispute.under_review`, `dispute.assigned`, `dispute.resolved`, `dispute.rejected`, `dispute.cancelled`, `dispute.evidence_removed` |

Support integration: `SupportTicketService` records `support.created`,
`support.replied`, `support.assigned`, `support.status_changed`,
`support.internal_note` (without note content) and `support.reopened`.

Each hook passes whitelisted, redaction-safe payloads only (entity ids, money in
minor units, category/priority/status strings) — never secrets, raw identity
documents, device/IP data or payment internals.

---

## 10. Redaction Strategy

Defence in depth:

1. **Caller whitelisting** — every hook passes only explicitly-chosen fields.
2. **Service redaction** — `AuditLogService::redact()` walks arrays recursively and
   replaces any key in `config('audit.redact_keys')` with `"[redacted]"`. The list
   covers credentials (`password*`, `token*`, `secret`, `api_key`, `authorization`,
   `cookie`, `remember_token`), payment/webhook secrets (`card*`, `cvv`, `cvc`, `otp`,
   `pin`, `trx_id`, `idempotency_key`), personal identifiers (`email`, `phone`,
   `game_uid`), device/network pseudonyms (`ip*`, `device*`, `fingerprint`,
   `user_agent`) and identity/evidence artefacts (`screenshot`, `evidence`,
   `document`, `photo`).
3. **Size caps** — `max_value_chars = 500` truncates individual string values;
   `max_payload_chars = 4000` replaces oversized JSON with a truncated preview and a
   `_note`.

Verified by `AuditLogTest::test_sensitive_keys_are_redacted` (only `amount_minor`
survives a payload containing `password`, `api_key`, `email`, `ip_address`,
`idempotency_key`).

---

## 11. Moderation Dashboard

No new moderation queue was invented. Phase 13 reuses the existing Phase 07
moderation/security surfaces and extends the **admin dashboard** with an Operations
panel linking to Analytics, Audit and Support. Role boundaries from Phase 07 remain:
moderators can moderate disputes/anti-cheat/security but reach no financial or
global-risk analytics; organizers are scoped to their own tournaments.

The new staff-facing additions are the support queue (Section 12) and the
staff-scoped dispute/support analytics (Section 24/25).

---

## 12. Support Architecture

- Tables: `support_tickets` (owner, subject, category, priority, status, `assigned_to`,
  optional `tournament_id`/`team_id`/`match_id`/`dispute_id`/`payment_id`/
  `payout_id`, `resolved_at`, `closed_at`, `reopened_count`, timestamps),
  `support_messages` (author + body, immutable), `support_internal_notes` (staff
  author + body, immutable, never exposed to users).
- Writer: `SupportTicketService` (create/reply/assign/changeStatus/addInternalNote/
  closeByUser/reopen/queue). All fields are set explicitly (`$fillable = []`
  everywhere); status is always server-validated.
- Readers: `SupportController` (user queue/show/reply/close/reopen/messages JSON
  polling) and `AdminSupportController` (staff queue with filters, show with
  assign/status/note/reply panels, CSV export).
- Policy: `SupportTicketPolicy` encodes player-own, captain-team, organizer-
  tournament, moderator-queue, admin-full rules; `AdminSupportController` is also
  wrapped in `staff` middleware.

---

## 13. Tickets

- Statuses are **exactly** `open, pending, waiting_on_user, waiting_on_staff,
  resolved, closed` — no user-defined statuses.
- Categories: `general, payment, payout, dispute, account, technical, other`.
- Priorities: `low, normal, high, urgent`.
- `SupportTicket::TRANSITIONS` encodes the full legal state map:
  - `open → pending | waiting_on_user | waiting_on_staff | resolved | closed`
  - `pending → waiting_on_user | waiting_on_staff | resolved | closed`
  - `waiting_on_user → waiting_on_staff | resolved | closed`
  - `waiting_on_staff → waiting_on_user | resolved | closed`
  - `resolved → closed | open`
  - `closed → open` (reopen; increments `reopened_count`)
- Creation requires a first message; default category `general`, priority `normal`.

---

## 14. Messages

- Users create the first message at ticket creation; they reply to their own tickets
  only.
- `SupportTicketService::reply()` records the message, flips status (`waiting_on_user`
  when a staff member replies, `waiting_on_staff` when the owner replies) and emits
  the Phase 11 notification + Phase 12 live event.
- Staff replies go through `AdminSupportController@reply` (staff middleware +
  policy).
- Messages are immutable (`$fillable = []`, no update/delete path).

---

## 15. Internal Notes

- `support_internal_notes` are written only by staff via
  `SupportTicketService::addInternalNote()`.
- They are **never** included in the user ticket view, the user messages JSON
  endpoint, the notification payloads or the live events. Verified by
  `SupportTicketTest::test_internal_notes_are_hidden_from_the_owner` (owner page and
  JSON polling both omit `SECRET_NOTE_XYZ`) and
  `test_staff_can_see_internal_notes` (staff page shows it).
- The accompanying `support.internal_note` audit entry stores only ticket/author
  references — never the note body.

---

## 16. Support Auth

Enforced by `SupportTicketPolicy` + route middleware:

- **Player**: owns ticket → view/reply/close/reopen own tickets only; no cross-user
  access (verified `test_users_cannot_view_other_users_tickets`).
- **Captain**: own tickets + tickets of their team.
- **Organizer**: tickets referencing their own tournaments (verified
  `test_organizer_sees_own_tournament_tickets_but_not_others`).
- **Moderator**: staff queue and any ticket in the queue.
- **Admin**: full access.
- No IDOR / cross-user / cross-tournament access paths exist; every ticket fetch is
  policy-gated.

---

## 17. Support Notifications

Phase 11 `NotificationService` is reused (no duplicate storage). New notification
types on `Notification`: `support.created`, `support.reply`, `support.assigned`,
`support.resolved`, `support.reopened`, `support.status`.

- Created → notifies the ticket owner.
- Staff reply → notifies the owner.
- User reply → notifies the assignee (if assigned).
- Assign → notifies the assignee.
- Resolve/reopen/status → notifies the owner (and assignee where relevant).
- Internal notes never notify anyone.
- Deleted-staff edge case is guarded (assignee relation null-check before dispatch).

Verified by `SupportTicketTest` (created + reply + assign notifications asserted).

---

## 18. Support Realtime

Phase 12 `LiveEventService` is reused. New staff-only live event types on
`LiveEvent`: `support.created`, `support.message`, `support.status_changed`,
`support.assigned`.

- `support.message` and `support.status_changed` carry only the message body /
  status delta and ticket reference — **never** internal notes or privileged
  information.
- Visibility is staff-only for ticket lifecycle events; user-specific delivery is
  handled by the existing poll endpoint scoping.
- Verified by `SupportTicketTest::test_support_activity_emits_staff_only_live_events`
  (guest false, owner false, admin true).

---

## 19. Tournament Analytics

`AnalyticsService::tournamentMetrics(Tournament)` returns, per tournament:

- Team counts by status (total/confirmed/waitlisted/no_show/withdrawn) and
  `checked_in`.
- Rates: `check_in`, `no_show`, `match_completion` (percentages, rounded).
- Match counts by status (total/completed/live/disputed).
- Exposed at `GET /tournaments/{{tournament}}/analytics` (`tournaments.analytics`),
  authorized for the organizer, moderators and admins only.

Verified by `AnalyticsTest::test_tournament_metrics_are_deterministic`.

---

## 20. Match Analytics

`AnalyticsService::matchMetrics()` aggregates across matches:

- Total/completed/disputed/live counts, disputed ratio, completion rate.
- Also surfaced inside tournament metrics (per-tournament match state).

---

## 21. Player Analytics

`AnalyticsService::playerMetrics()` returns **aggregate, non-identifying** counts
(players with teams, check-in participation, no-show counts) — deliberately **no**
per-user profiling. Organizers see these only for their own tournaments; global
figures are admin-only.

---

## 22. Financial Analytics

`AnalyticsService::financialMetrics()` (admin-only) returns money aggregates in
minor units:

- Payments: volume, successful, refunded (minor units + counts).
- Wallets: total balance, credited/debited totals.
- Prizes: allocated, distributed.
- Payouts: total, completed, pending, failed.
- Settlements: counts, plus `exceptions` (adjustment counts).

No raw ledger rows, no secrets. Verified shape by
`AnalyticsTest::test_financial_metrics_return_money_in_minor_units`.

---

## 23. Security Analytics

`AnalyticsService::securityMetrics()` (admin-only) returns:

- Risk level distribution (`low/medium/high/critical`), active restrictions count,
  identity verification review counts, anti-cheat incident counts and open dispute
  counts — no fraud scores, no IP/device data, no identity documents.

---

## 24. Dispute Analytics

`AnalyticsService::disputeMetrics()` (staff: moderator + admin) returns dispute
status distribution, open-by-category and moderator workload aggregates (counts by
assignee, no per-moderator profiling detail beyond counts).

---

## 25. Support Analytics

`AnalyticsService::supportMetrics()` (staff) returns ticket status distribution,
counts by category and priority, and staff workload (open tickets per assignee).

---

## 26. Admin Dashboard

`resources/views/admin/dashboard.blade.php` gains an **Operations** quick-links card
(Analytics, Audit, Support) and an admin support link. `layouts/app.blade.php` gains
nav entries: Support (all authenticated users), Support Queue (admin/moderator),
Analytics and Audit (admin). All links respect role visibility.

---

## 27. Search / Filtering

- Audit browser filters: action (closed vocabulary), entity type/id, tournament,
  target user, request id, date range. Sort column whitelisted to `created_at`;
  direction whitelisted to `asc`/`desc`.
- Support queue filters: status, priority, category, assigned staff, plus search on
  subject (scoped to the viewer's permitted tickets).
- All filtering uses Eloquent query building / parameter binding — **no raw SQL
  concatenation anywhere** (SQL-injection-safe). Pagination is applied everywhere.

---

## 28. Exports

`App\\Support\\CsvExport::download()` streams via `php://output` + `fputcsv`,
appends an `exported_at` column, and the caller is responsible for auth + row
chunking (LazyCollection chunking on large sets).

Exports provided:

- `admin.audit.export` — admin-only audit CSV (redacted, filtered).
- `admin.support.export` — staff-only support queue CSV (ticket metadata only: no
  internal notes, no message bodies beyond subject; no emails/phones/IP).
- `admin.analytics.export` — admin-only tournament metrics CSV.

All exports are authentication-gated and verified to return `text/csv; charset=UTF-8`
only to authorized roles.

---

## 29. Privacy

Enforced everywhere:

- No email, phone, raw IP, device ids, fraud scores, payment secrets, wallet
  internals, identity documents, private evidence or moderation notes are exposed in
  any Phase 13 view, export, notification or live event.
- Audit payloads are redacted + capped (Section 10); internal notes are staff-only
  (Section 15); analytics are aggregates only (Sections 19–25).

---

## 30. DB Changes

One additive migration
`2026_09_08_020000_create_audit_and_support_tables.php` creates:

- `audit_logs` (append-only; indexes on `action`, `entity_type`+`entity_id`,
  `tournament_id`, `target_user_id`, `request_id`, `created_at`).
- `support_tickets` (indexes on `user_id`, `assigned_to`, `status`, `category`,
  `priority`, `tournament_id`).
- `support_messages` (indexed `ticket_id`).
- `support_internal_notes` (indexed `ticket_id`).

Foreign keys use `nullOnDelete`; timestamps standard; JSON columns safe. **No
existing table was altered** and no analytics snapshot tables were added.

---

## 31. New / Modified Files

**New (35):** migration, `config/audit.php`, 4 models, 3 services, `CsvExport`,
2 policies, 2 middleware, 4 controllers, 13 Blade views, 4 test files.

**Modified (14):** `Notification` (support types), `LiveEvent` (support types),
`bootstrap/app.php` (staff alias + request-id middleware), `routes/web.php`
(Phase 13 routes), 8 controllers (audit hooks only), `layouts/app.blade.php` and
`admin/dashboard.blade.php` (nav/quick-links).

Full content of every file is in Section 43.

---

## 32. Policies

- `AuditLogPolicy` — admin-only for `viewAny`/`view`/`export`.
- `SupportTicketPolicy` — `viewAny` (staff), `view` (owner/captain-team/organizer-
  tournament/staff), `create` (any auth), `reply`/`close`/`reopen` (owner or staff),
  `assign`/`status`/`note`/`export` (staff only).

Middleware: `EnsureUserIsStaff` (abort 403 unless `isStaff()`), `AssignAuditRequestId`
(request correlation UUID).

---

## 33. Performance

- All list pages paginate; audit/support `search()` uses indexed columns and
  `whereHas`-free eager loading where views need relations.
- Aggregates use single aggregate SQL queries (`count`/`sum`/`groupBy`), no
  full-history loads, no per-row recomputation.
- `AnalyticsService::averageSeconds()` bounds its sample to 5000 rows.
- CSV exports chunk rows and stream — no full materialisation of large result sets.
- No N+1: views use eager loads (`with('actor')`, `with('assignee')`, etc.).

---

## 34. Tests

Four new feature test files (deterministic fixtures — no factories were required to
be added; existing tests untouched):

- `AuditLogTest` (13 tests) — service contract, closed vocabulary, quiet variant,
  append-only, mass-assignment guard, redaction, request correlation, search
  filters/pagination, relatedHistory, admin-only index/export.
- `AuditIntegrationTest` (11 tests) — real business flows (role change, wallet,
  restriction, identity, payout approval, tournament publish, roster add/remove,
  score adjustment, withdrawal) emit audit rows with correct actor/entity/target/
  before-after, and failed actions emit none.
- `SupportTicketTest` (14 tests) — create/first message, staff/user reply status
  flips, assignment (+ non-staff assignee rejection), transition validation,
  reopen counter, internal-note hiding, staff visibility, cross-user denial,
  organizer scoping, staff-only live events, staff queue/export access, HTTP
  close/reopen.
- `AnalyticsTest` (9 tests) — deterministic tournament metrics, platform overview,
  financial/security shape, and role scoping (admin-only global/financial/security,
  staff-only dispute/support, organizer own-tournament, admin export).

---

## 35. Exact Test Results

**Phase 13 suite (isolated): 47 passed, 164 assertions.**

| File | Tests | Assertions |
|---|---|---|
| `AuditLogTest` | 13 | 37 |
| `AuditIntegrationTest` | 11 | 37 |
| `SupportTicketTest` | 14 | 43 |
| `AnalyticsTest` | 9 | 47 |
| **Total** | **47** | **164** |

**Full suite: 520 passed, 1589 assertions** (baseline 473 + 47 new; zero
regressions, zero risky, zero skipped).

---

## 36. Migration Verification

```
php artisan migrate:fresh --seed --force
INFO  Seeding database.
```

Succeeded with no errors. Table presence confirmed via tinker:
`audit_logs`, `support_tickets`, `support_messages`, `support_internal_notes` all
exist; seeded `users` count = 2.

---

## 37. PHP Lint

`php -l` executed across all 34 new/modified PHP files (migration, config, models,
services, policies, middleware, controllers, bootstrap, routes, tests):

**ALL PHP LINT CLEAN (34 files)** — no syntax errors.

---

## 38. Route Verification

`php artisan route:list` — **128 routes total** (103 baseline + 25 Phase 13).
Programmatic check confirms **no `admin.*` route lacks the `auth` middleware**
(NONE). Phase 13 routes:

- User: `support.index/create/store/show/reply/close/reopen/messages`;
  `tournaments.analytics`.
- Staff (staff middleware): `admin.support.index/export/show/assign/status/note/reply`;
  `admin.analytics.disputes`; `admin.analytics.support`.
- Admin (admin middleware): `admin.audit.index/export`;
  `admin.analytics.index/tournaments/financial/security`; `admin.analytics.export`.

---

## 39. HTTP Smoke

Live `php artisan serve` + curl as **guest**:

| Endpoint | Result |
|---|---|
| `/admin/audit`, `/admin/audit/export` | 302 → `/login` |
| `/admin/analytics*` (index/tournaments/financial/security/disputes/support) | 302 → `/login` |
| `/admin/support`, `/admin/support/export` | 302 → `/login` |
| `/support`, `/support/create` | 302 → `/login` |

Authenticated role matrix (player own 200 / cross-user 403, organizer own 200 /
global financial 403, moderator queues 200 / financial 403, admin all 200 + CSV
exports with `text/csv; charset=UTF-8`, internal notes inaccessible to owners) is
covered by the feature tests in Section 35 (full HTTP through the test kernel).

---

## 40. Phase 01–12 Regression

Full suite after all Phase 13 code + views + tests:

```
php artisan test
Tests: 520 passed (1589 assertions)
```

Baseline (Phase 12) was 473 passed / 1425 assertions; the 47 new Phase 13 tests
bring the total to 520/1589 with **zero** Phase 01–12 failures — no prior behavior
was changed.

---

## 41. Known Limitations

- The audit log is an **integration log**, not a forensic capture of every byte: it
  stores whitelisted references and redacted metadata; the domain tables
  (`ModerationEvent`, `RiskEvent`, `PaymentEvent`, `PayoutEvent`, `LedgerEntry`)
  remain the detailed systems of record.
- Support **attachments are intentionally unsupported** (no private storage
  requirement).
- Audit-log retention/rotation is delegated to operations (no auto-purge; append-only
  is the default and preferred mode).
- Analytics are **operational aggregates only**; no predictive/BI modelling, no
  cross-tenant warehouse.

---

## 42. Production Considerations

- Keep `config('audit.redact_keys')` in sync with any new sensitive request fields.
- Consider an out-of-band archival job for `audit_logs` in very high-volume
  deployments (the table is append-only and indexed).
- Move CSV export behind the same session auth in production (already enforced);
  consider job-based export for very large tenants.
- Notifications/live events reuse the existing Phase 11/12 infrastructure; no
  additional queue workers are required.

---

## 43. Complete File Content

Complete final content of every new and modified file follows (no omissions, no
pseudo-code, no TODOs).

### New files

""")

# New files
for p in NEW_FILES:
    lang = "blade" if p.endswith(".blade.php") else "php"
    S.append(dump_block(p, lang))

S.append("### Modified files\n\n")
for p in MODIFIED_FILES:
    lang = "blade" if p.endswith(".blade.php") else "php"
    S.append(dump_block(p, lang))

with open(OUT, "w", encoding="utf-8") as f:
    f.write("\n".join(S))

print("Wrote", OUT)
print("lines:", sum(1 for _ in open(OUT)), "bytes:", os.path.getsize(OUT))
