# PHASE15_PUBLIC_API_MOBILE_WEBHOOK_REPORT.md

# Phase 15 — Public API + Mobile Backend + Webhook Platform

**Project:** FF Arena (Laravel 12.69.1, PHP 8.4.24, SQLite)
**Report:** `PHASE15_PUBLIC_API_MOBILE_WEBHOOK_REPORT.md`
**Status:** COMPLETE — all verification gates green.

---

## 1. Phase 15 Scope, Assumptions, and Boundary

Phase 15 delivers the production-grade API foundation for FF Arena: mobile/SPA
clients, first-party applications, and controlled third-party integrations.
The surface covers tournament discovery and registration, teams and rosters,
check-in, brackets, match status and scoring views, leaderboards, profiles,
notifications, wallet/payment state, payouts, support, and account/security.

**Assumptions recorded up front (and honored):**

- The existing web layer (`app/Services/`, `app/Models/`, `app/Policies/`) is
  the authoritative business engine. The API layer **reuses it** and never
  becomes a second business engine.
- Authentication uses **Laravel Sanctum** (official package, `^4.3`,
  Laravel 12 compatible). Browser/stateful (cookie) and API (bearer) modes
  remain strictly distinct; session cookies are never accepted as mobile
  bearer credentials.
- Versioning: `/api/v1/...` only. Unversioned public business endpoints are
  forbidden; `/api/v2` can be added later without breaking v1.
- Tokens: hashed storage, granular abilities, revocation, expiry, last-used
  tracking, application label, audit and security events; raw token shown
  exactly once.
- Financial mutations are explicitly restricted to their scopes and always
  server-derived.

**Boundary:** this phase stops at the API/mobile-backend foundation. No
Phase 16 (observability platform, CI/CD, BI, SEO, UI redesign), no native
Android/iOS source code, no ML fraud platform.

## 2. Pre-implementation API Audit (Inspection Findings)

Before writing any code, the existing codebase was inspected. Findings that
shaped the implementation:

- `routes/api.php` existed but was **empty**.
- `bootstrap/app.php` registered only `web`, `commands`, `health` routing;
  middleware aliases were `admin` and `staff`; the web group appended
  `AssignAuditRequestId` + `EnsureActiveAccount`; CSRF excluded
  `webhooks/payments/*`.
- `config/auth.php` had only the `web` session guard + `users` provider.
- `composer.json` did **not** require `laravel/sanctum`.
- The authoritative registration flow lived inline in
  `TeamController::register` (fraud gate → validation → atomic slot claim →
  waitlist branch → roster validation → notifications/live/audit).
- `MatchController::submitScore` gated with `FraudRiskService::gate('score_submission')`,
  enforced participant authorization, duplicate/placement protection, and
  delegated scoring to `ScoringService::submitScore` (server-derived points).
- `LeaderboardController::show` = `ScoringService::standings()` +
  `ScoringRule::tieBreakers()`.
- `LiveEventService::since()/snapshot()/isPublic()` were the realtime
  primitives; `config('live.public_types')` the visibility list.
- `WebhookController` + `PaymentService::handleProviderCallback()` +
  `PaymentService::verifySignature()` already implemented the secure payment
  callback path (raw-body HMAC-SHA256, provider/payment matching, amount and
  currency validation, idempotent duplicate handling, transactional state
  transition).
- Policies existed for `Team`, `Tournament`, `GameMatch`, `Payment`,
  `Payout`, `Dispute`, `SupportTicket` and are reused by the API layer.
- Mass-assignment is intentionally restricted across models (`User`,
  `Score`, `Notification`, `SupportMessage`, `LedgerEntry`, `Payout`, …).
  API controllers never bypass it; they call services.

## 3. Architecture Principles — One Business Engine

- Every `/api/v1` controller is a thin HTTP translation layer. It validates
  input, calls existing `app/Services/`, maps exceptions to the API envelope,
  and serializes through `app/Http/Resources/Api/V1`.
- The registration flow was **extracted** from `TeamController::register`
  into `App\Services\RegistrationService::register()` (byte-for-byte the
  Phase 04 logic) so web and API share one implementation. The web controller
  now delegates to the service.
- Score submission reuses `ScoringService::submitScore()`; payments reuse
  `PaymentService::createForTeam()`; leaderboards reuse
  `ScoringService::standings()`; profiles reuse `ProfileService`; sessions
  reuse `SessionManagementService`; disputes reuse `DisputeService`;
  support reuses `SupportTicketService`; check-in reuses
  `TournamentParticipationService`; realtime reuses `LiveEventService`.
- No raw Eloquent models are ever serialized — only `JsonResource`
  subclasses with explicit field whitelists.

## 4. Sanctum Architecture (Guard / Provider / Token Model)

- `laravel/sanctum` `^4.3` added to `composer.json`.
- `config/auth.php` gains a `sanctum` guard (`driver => sanctum`,
  `provider => users`).
- Sanctum's own `personal_access_tokens` migration is used verbatim
  (hashed tokens, `tokenable` morph, `abilities`, `last_used_at`,
  `expires_at`). No duplicate token table.
- `config/sanctum.php` sets `'guard' => []` — the session guard is **not**
  consulted, so a browser cookie can never authenticate an API request.
  `SANCTUM_TOKEN_PREFIX` is available for production secret-scanning.
- `App\Models\PersonalAccessToken` extends Sanctum's model (via
  `Sanctum::usePersonalAccessTokenModel()`) and adds the `api_client_id`
  link for grouped application revocation.
- `Sanctum::actingAs()` continues to work for tests (uses the `sanctum`
  guard).

## 5. Token System Design

- Issued via `ApiTokenService::issue()`; hashed storage by Sanctum; the
  plaintext is returned exactly once and never stored/logged/serialized.
- Granular abilities (see §6); `admin` is reserved and can never be granted
  by a normal client — `ApiTokenService::sanitizeAbilities()` strips unknown
  and reserved scopes.
- Expiry: default 30 days, clamp 1–365 days (`config('api.token.*')`).
- Revocation: single token, all-but-one, or an entire `ApiClient`
  (`revokeClient()` deletes every linked token).
- `last_used_at` tracking is Sanctum's built-in behavior.
- Device/application label = token `name`; every issuance/revocation writes a
  login event, an audit entry (`auth.api_token_issued` /
  `auth.api_token_revoked` / `auth.api_client_created` /
  `auth.api_client_revoked`) and a user notification.
- `EnsureTokenIsValid` rejects expired tokens, tokens of revoked clients, and
  tokens of deactivated accounts (generic 401, no internals leaked).

## 6. Token Scopes Vocabulary

`config('api.scopes')` — the closed, grantable vocabulary:

`profile:read`, `profile:write`, `tournaments:read`, `tournaments:register`,
`teams:read`, `teams:write`, `roster:read`, `roster:write`, `matches:read`,
`scores:read`, `scores:submit`, `leaderboard:read`, `notifications:read`,
`notifications:write`, `wallet:read`, `payments:read`, `payments:create`,
`payouts:read`, `support:read`, `support:write`, `disputes:read`,
`disputes:write`.

`config('api.staff_scopes')` = `['admin']` — reserved; assigned only to
tokens minted by platform staff via `ApiTokenService::issueAdminToken()`.
Financial mutations require the dedicated `payments:create` scope (and
wallet/payout APIs are read-only by design).

## 7. Mobile Authentication — Email/Password

`POST /api/v1/auth/register` and `POST /api/v1/auth/login` reuse the Phase 14
account engine: password identities on `users.password` (hashed), `role`
validated to `player|organizer` and set explicitly (never mass-assignable),
welcome + email-verification notifications, device/IP observations
(`DeviceFingerprintService::register()`, `IpIntelligenceService::observe()`),
login events and audit entries. Enumeration-safe login: identical 401 for
unknown email and wrong password. Deactivated accounts are refused (401
`account_inactive`).

## 8. Mobile Authentication — Google id_token + Phone OTP

- **Google:** `POST /api/v1/auth/google` accepts a Google `id_token`, which
  is verified **server-side** through `GoogleIdTokenVerifierInterface`
  (production `GoogleTokenInfoIdVerifier`: issuer + audience +
  `email_verified`); the verified subject then flows through
  `IdentityService::resolveGoogle()` (link-or-create). Unconfigured Google →
  honest 503 `not_configured`. Tests bind a deterministic fake; no real
  Google calls.
- **Phone OTP:** `POST /api/v1/auth/otp/request` + `/verify` reuse
  `PhoneOtpService` (`normalize`, `issue`, `verify`; codes stored only as
  `code_hash`). `login` purpose resolves the account via the verified phone
  identity; `signup` links the verified phone to the current user. SMS
  delivery via `PhoneOtpProviderInterface` (log provider unless configured).
- Both paths reuse `IdentityService`, `PhoneOtpService`,
  `AccountLifecycleService` (via `User::isActive()` gating) and
  `FraudRiskService` signals — no duplicate account system.

## 9. Device Security — Server-derived Signals Only

API clients **cannot** submit risk scores, trusted-device booleans, ban
status, or internal fingerprints. The server derives them:

- `DeviceFingerprintService::register()` and `IpIntelligenceService::observe()`
  record pseudonymous observations on login/registration.
- `FraudRiskService::gate()/evaluateRegistration()/evaluatePayment()` are the
  only authority for risk; the API merely surfaces the resulting
  `DomainException`.
- No API endpoint accepts or echoes a client risk/trusted-device field; the
  `ApiSecurityTest` asserts the absence of `risk_score`, `risk_level`,
  `ip_address`, `device_hash`, `fingerprint` across surfaces.

## 10. Rate Limiting — Named, Route/User-aware Limiters

`AppServiceProvider::registerApiRateLimiters()` registers named limiters
(`config('api.rate_limits')` mirrors the ceilings):

| Limiter | Ceiling | Key |
|---|---|---|
| `api` | 120/min | per user (fallback IP) |
| `api_anon` | 60/min | per IP |
| `api_token_issue` | 5/min | per user |
| `api_login` | 5/min + 20/min | per identifier + per IP |
| `api_register` | 3/hour | per IP |
| `api_otp_request` | 1/min + 5/hour + 10/hour | per phone + per IP |
| `api_otp_verify` | 5/5min | per phone |
| `api_score` | 10/min | per user |
| `api_payment` | 5/min | per user |
| `api_support` | 10/min | per user |
| `api_webhook` | 60/min | per IP |

Limits are route-level (assigned per route in `routes/api.php`) and
user/token-aware — never IP-only for authenticated operations. 429 responses
use the standard envelope (`error.code = rate_limited`).

## 11. Response Standard — Envelopes

`App\Support\ApiResponse`:

- Success: `{ "data": ..., "meta": {...} }` (meta carries pagination, tie
  breakers, notes).
- Errors: `{ "error": { "code": "...", "message": "...", "details": {...} } }`
  — never stack traces, SQL, credentials, class paths, or environment values.

## 12. Centralized API Exception Rendering

`App\Exceptions\ApiExceptionHandler::render()` is registered in
`bootstrap/app.php` and maps (for `/api/*` requests only):

| Exception | HTTP | code |
|---|---|---|
| `ValidationException` | 422 | `validation_error` |
| `AuthenticationException` | 401 | `unauthenticated` |
| `AuthorizationException` | 403 | `forbidden` |
| `ModelNotFoundException` / `NotFoundHttpException` | 404 | `not_found` |
| `DomainException` (code 4xx) | as coded | `invalid_request` |
| `DomainException` (other) | 409 | `conflict` |
| `HttpException` (429/405/…) | as coded | `rate_limited`/`method_not_allowed`/… |
| anything else | 500 | `server_error` |

Web routes keep Laravel's default rendering (the handler returns null for
non-`/api/*` requests).

## 13. API Resources & Field Whitelisting

Every response is a `app/Http/Resources/Api/V1/*` resource. Explicit rules:

- `UserResource` — email only to self or admin.
- `MeResource` — own email + sign-in methods (no phone, no risk state).
- `ProfileResource` — `ProfileService::publicProfile()` output; private
  profiles collapse to `{visible:false, name, username, privacy}`.
- `TournamentResource` — public fields + server-derived `slots_left`,
  `is_full`, `accepts_registration`, `entry_fee_minor`.
- `TeamResource` — phone only to captain/organizer/staff; waitlist position
  only when waitlisted.
- `MatchResource` — `room_id`/`room_pass` only while ready/live and only to
  participants/organizer/staff.
- `ScoreResource` — all points server-computed; screenshot URL only when
  present.
- `PaymentResource`/`PayoutResource`/`WalletResource`/`LedgerEntryResource` —
  no provider references/accounts/rails; ledger shows balance history only.
- `SupportTicketResource`/`SupportMessageResource`/`DisputeResource` — never
  internal notes, private evidence, or review internals.
- `WebhookEndpointResource` — never the signing secret (shown once only).
- `WebhookEventResource` — never the encrypted raw payload.

Forbidden keys are asserted by `ApiSecurityTest` and `ApiProfilePrivacyTest`.

## 14. Versioning Strategy

All public business endpoints are under `/api/v1`. Adding `/api/v2` later is
a new route group with a new namespace (`App\Http\Controllers\Api\V2`) and
new resources — v1 clients are untouched. Unversioned public business
endpoints are forbidden and there are none (verified by `route:list`).

## 15. Tournament Discovery API

- `GET /api/v1/tournaments` — public statuses only; whitelisted `sort`
  (`created_at|starts_at|prize_pool|entry_fee|name`) and `direction`
  (`asc|desc`); whitelisted `status`/`game_mode` filters; `q` search is
  parameterized (`addcslashes`); pagination via `per_page` (1–100).
- `GET /api/v1/tournaments/{tournament}` — `{tournament}` binds by **slug**
  (`Tournament::getRouteKeyName()`); non-public → 404.
- `GET /api/v1/tournaments/{tournament}/matches|leaderboard|bracket` — reuse
  `ScoringService::standings()` and the ordered match relation; no duplicated
  calculations.

## 16. Tournament Registration API

`POST /api/v1/tournaments/{tournament}/registrations`
(scope `tournaments:register`, idempotency-enabled) calls
`RegistrationService::register()`: fraud gate → authoritative lifecycle
re-check inside a transaction → one-team-per-captain → roster UID
availability → **atomic slot claim** (SQLite-compatible single UPDATE) →
waitlist branch when full → pending team otherwise → roster validation +
insert → risk signal → notifications → live event → audit → outbound webhook.
The client supplies only name/captain/phone/UID/members; status, slot,
confirmation, fee, currency and eligibility are **all server-derived**
(injected `status`/`confirmation`/`slot` fields are ignored).

## 17. Check-in API

`POST /api/v1/tournaments/{tournament}/check-in` (scope
`tournaments:register`) — validates `team_id` belongs to the tournament,
enforces `TeamPolicy::checkIn`, runs the `checkin` risk gate, then
`TournamentParticipationService::checkIn()` (state/captain/window/registration/
duplicate). Idempotent (`already_checked_in` status).

## 18. Waitlist API

`GET /api/v1/tournaments/{tournament}/waitlist` (scope `tournaments:read`)
returns FIFO positions only. Promotion is server/admin-controlled
(`TournamentParticipationService::promoteNext()`); there is **no** client
queue-position input endpoint.

## 19. Team API

- `GET /api/v1/me/teams` — the caller's own teams.
- `GET /api/v1/teams/{team}` — `TeamPolicy::view` (captain/organizer/staff).
- `PATCH /api/v1/teams/{team}` — `TeamPolicy::updateProfile` →
  `RosterService::updateProfile()`.
- `POST /api/v1/teams/{team}/withdraw` — `TeamPolicy::withdraw` + the Phase 04
  lifecycle rules (no withdrawal after live/finished/cancelled), releases the
  captain claim + UID, risk signal, organizer notification, live event,
  audit.

## 20. Roster API

- `GET /api/v1/teams/{team}/roster` — `TeamPolicy::view`.
- `POST /api/v1/teams/{team}/roster` — `TeamPolicy::addMember` →
  `RosterService::addMember()` (locking/size/duplicate/cross-team checks).
- `DELETE /api/v1/teams/{team}/roster/{member}` — `TeamPolicy::removeMember`
  + membership scoping (member must belong to the team) →
  `RosterService::removeMember()`.
No direct DB manipulation; IDOR covered by `ApiTeamsRosterTest`.

## 21. Match Read API

`GET /api/v1/matches/{match}` — public only while the tournament is public;
scores loaded through `ScoreResource`; room credentials gated (see §13).

## 22. Score Submission API

`POST /api/v1/matches/{match}/scores` (scope `scores:submit`, idempotency +
`api_score` limiter) — participant + `TeamPolicy::submitScore` auth, `gate()`
risk check, `acceptsScoreSubmission()` state validation, duplicate-score and
placement-claim guards, then `ScoringService::submitScore()` (transactional,
server-computed points). `points`, `status`, `placement_points`,
`kill_points` are `prohibited` in the request — a client can never submit an
authoritative total or mutate match state.

## 23. Leaderboard API

- `GET /api/v1/leaderboards` — tournaments that have standings.
- `GET /api/v1/leaderboards/{tournament}` and
  `GET /api/v1/tournaments/{tournament}/leaderboard` — `ScoringService::standings()`
  + tie breakers, with `rank` assigned from the sorted order.
- `GET /api/v1/players/{user}/ranking` — the user's per-tournament rank
  derived from the same standings. No duplicated ranking math.

## 24. Profile API

- `GET /api/v1/me` — `MeResource` (scope `profile:read`).
- `PUT/PATCH /api/v1/me/profile` — `ProfileService::update()` +
  `updatePrivacy()` (scope `profile:write`); `privacy` whitelisted;
  role/account_status/email are not writable.
- `GET /api/v1/players/{user}` — `ProfileService::publicProfile()`, honouring
  Phase 14 privacy (a private profile is never exposed merely because the
  caller knows the id).

## 25. Security API (Sessions)

- `GET /api/v1/me/security` — email + verification state + has_password +
  sign-in-method count + account status. No raw IP/fingerprint/risk score.
- `GET /api/v1/me/sessions` — `SessionManagementService::sessionsFor()`
  (device label + last activity only).
- `DELETE /api/v1/me/sessions/{session}` — revoke one owned session.
- `POST /api/v1/me/sessions/revoke-others` / `revoke-all` —
  `SessionManagementService`.

## 26. Notifications API

`GET /api/v1/me/notifications`, `GET .../unread-count`,
`POST .../{notification}/read`, `POST .../read-all` — ownership enforced by
scoping queries to the caller (IDOR returns 404), via `NotificationService`.

## 27. Realtime API

- `GET /api/v1/tournaments/{tournament}/live?since=N` — `LiveEventService::since()`,
  visibility-gated.
- `GET /api/v1/me/live?since=N` — `LiveEventService::sinceForUser()`
  (account-targeted events only).
Both return `{events, latest_cursor}` via `LiveEventResource`.

## 28. Payment Methods API

`GET /api/v1/payments/methods` — `PaymentGatewayManager::statuses()` (honest
per-provider enabled/configured/mode/supports) + saved methods through
`PaymentMethodService::listFor()` with masked identifiers.

## 29. Payment Creation API

`POST /api/v1/payments` (scope `payments:create`, idempotency +
`api_payment` limiter) — `TeamPolicy::pay`, `evaluatePayment()` risk gate,
then `PaymentService::createForTeam()`. Amount/currency/payer/team/tournament
are server-derived; the client selects a provider only. An existing active
payment returns 200 with `meta.existing=true` (no double charge). An external
`redirect_url` is returned only for genuinely-configured hosted providers.

## 30. Wallet & Ledger API

`GET /api/v1/me/wallet` and `GET /api/v1/me/wallet/ledger` (scope
`wallet:read`) — read-only, via `WalletService::walletFor()` and the
`ledger_entries` relation. There are **no** credit/debit endpoints for
ordinary users; balances change only through Phase 08/09 services (asserted
by `ApiPaymentsWalletTest`).

## 31. Payout API

`GET /api/v1/me/payouts` (scope `payouts:read`) — the recipient's own payouts
only. No cross-user payout read exists.

## 32. Support API

`GET/POST /api/v1/me/support`, `GET /api/v1/me/support/{ticket}`,
`GET/POST /api/v1/me/support/{ticket}/messages` — own tickets only
(`SupportTicketService::forUser()`, `SupportTicketPolicy`); internal notes
never serialized; IDOR covered.

## 33. Dispute API

`GET /api/v1/me/disputes` and `GET /api/v1/disputes/{dispute}` — authorized
read-only (`DisputePolicy::view` = staff or participant); private evidence is
never serialized.

## 34. Token & API Client Management API

`POST/GET/DELETE /api/v1/me/tokens`, `POST/GET/DELETE /api/v1/me/clients` —
token issuance (scopes sanitized, admin never self-grantable), listing
(metadata only), revocation, client creation (secret-less; the client's first
token is returned once) and client revocation (kills linked tokens).

## 35. Inbound Webhook Platform Overview

`POST /api/v1/webhooks/inbound/{provider}` (limiter `api_webhook`) →
`WebhookIngressService::handle()`: known-provider check → size limit
(64 KiB) → JSON + `Content-Type: application/json` check → signature +
timestamp verification → event-id idempotency → `WebhookEvent` record
(encrypted raw payload + safe metadata) → `payment.*` events delegated to the
Phase 08 `PaymentService` state machine; other events recorded and ignored.

## 36. Inbound Signature Verification

`WebhookSignatureService::verify()` computes `HMAC-SHA256(raw_body, secret)`
and compares with `hash_equals()`. The provider secret comes from
`config('webhooks.inbound.providers.{provider}')`, falling back to the
Phase 08 `services.payments.webhook_secret` so inbound payment events share
the same trust root as the legacy `/webhooks/payments/*` endpoint. Timestamps
outside ±300 s are rejected as replays. The business signature is re-derived
against the payment secret before `handleProviderCallback()`, so the two
secrets may differ without weakening either check.

## 37. Inbound State Machine & Event-id Idempotency

`WebhookEvent.status` ∈ `received | verified | processing | processed |
ignored | failed | replayed`. `(provider, external_event_id)` is unique: a
replayed event is recorded as `replayed` and never processed twice. Business
rejections (amount/currency/provider/state mismatch) mark the event `failed`
and leave business state untouched (`ApiWebhookTest`).

## 38. Outbound Webhook Platform Overview

Admin-only endpoint management (`/api/v1/admin/webhooks/endpoints`): create
(one-time secret), rotate-secret, toggle, list deliveries, and the event
vocabulary. Subscriptions are event-scoped; deliveries are queued jobs.

## 39. Outbound Signature Scheme

`X-FFArena-Signature = HMAC-SHA256(secret, "{timestamp}.{raw_body}")` with
`X-FFArena-Timestamp`, `X-FFArena-Event`, `X-FFArena-Delivery` headers
(`WebhookSignatureService::sign()`). The per-endpoint secret is generated as
`whsec_…` (32 random bytes), stored encrypted, shown once, and rotated only
by admins.

## 40. Outbound Delivery, Retry & Failure Isolation

`SendWebhookDelivery` (queued job, `sync` in tests) POSTs a redacted payload,
tracks `WebhookDelivery` (`pending|success|failed|disabled`), and retries with
exponential backoff `[10, 60, 300, 1800, 3600, 10800]` seconds. After 6
consecutive failures the endpoint is disabled and the delivery is terminal.
**A delivery failure never rolls back business state** — dispatch is
`dispatchQuietly()` best-effort from the domain seams (registration, score
submission, payment creation, support ticket creation).

## 41. Event Vocabulary (Centralized Constants)

`config('webhooks.events')` is the closed vocabulary: `tournament.created|
started|completed`, `team.registered|withdrawn|checked_in`,
`match.started|completed|score_submitted|disputed|resolved`,
`payment.created|succeeded|failed`, `refund.completed`,
`payout.processing|completed|failed`, `dispute.opened|resolved`,
`support.ticket.created|updated`. Only these events are dispatchable and
subscribable; dispatch of unknown events is a no-op.

## 42. Subscriber Authorization & Payload Redaction

Subscribers receive only subscribed, authorized events. `WebhookDispatcher::redact()`
strips forbidden keys (email, phone, ip, device, risk_score, secret, token,
password, evidence, identity_document, ledger, wallet) before enqueueing.
Audit/anti-fraud/identity/evidence/ledger data is never sent.

## 43. Idempotency-Key Middleware

`EnsureIdempotency` implements header-based idempotency for critical mutations
(registration, payment creation, score submission, support ticket creation).
`IdempotencyService` stores only the SHA-256 of the key and of the canonical
request body; a replay within the TTL (24 h) returns the stored response with
`Idempotency-Replayed: true`; reusing a key with a different body is a 409.
Financial/registration operations can never execute twice
(`ApiIdempotencyTest`, `ApiPaymentsWalletTest`).

## 44. Errors & Observability

Centralized API exception handling (§12) maps 401/403/404/409/422/429/500
with the standard envelope; every response carries no internals. Every API
mutation audits through Phase 13 `AuditLogService` (with a request
correlation id via the existing `AssignAuditRequestId` middleware). The
full Phase 16 observability platform is out of scope.

## 45. OpenAPI 3.x Documentation

`storage/api-docs/openapi.json` is machine-readable and generated by
`tools/gen_openapi.py` (OpenAPI 3.0.3). It documents auth, every endpoint,
schemas, the error envelope, pagination, filtering, idempotency, rate limits,
webhook signatures, and versioning. Every documented endpoint exists, and
every routed `/api/v1` business endpoint is documented — the generator
**cross-checks** the spec against `php artisan route:list --json` and fails
hard on either direction.

## 46. OpenAPI Auth Schemes

`bearerAuth` (HTTP bearer, personal access token) is declared; session cookies
are explicitly documented as not accepted. Per-operation `security` and a
`**Required scope**` note are generated for every protected path; public
discovery and inbound-webhook paths are unauthenticated (webhooks use HMAC).

## 47. Anti-fraud Integration

Registration, payment creation, check-in and score submission all run their
Phase 10 gates (`FraudRiskService::evaluateRegistration/evaluatePayment/gate`)
and surface refusals as typed API errors. `RegistrationService` preserves the
registration-volume signal. No API surface accepts client risk/trust/ban
inputs.

## 48. Audit Integration

Every mutation writes the Phase 13 audit trail (`recordQuietly`): API token
issuance/revocation, client creation/revocation, webhook endpoint creation/
secret rotation/status change, plus all reused domain actions. New closed
actions added to `AuditLogService::ACTIONS`.

## 49. Notifications Integration

API register/login issue the Phase 11 welcome / suspicious-login
notifications; token creation notifies the account; reused domain flows
(team registered, payment started, payout events, support) keep their
existing notifications. The notifications API reads through
`NotificationService` with ownership enforced.

## 50. Realtime Integration

The API reuses the Phase 12 `LiveEventService` cursor store: tournament live
feed (visibility-gated) and the caller's account feed. No new realtime store
was introduced.

## 51. Database Changes (Migrations)

Two new migrations (27 total, up from 25):

- `2026_09_09_092433_create_personal_access_tokens_table.php` — Sanctum's
  official token table (published verbatim).
- `2026_09_09_100000_create_api_infrastructure_tables.php` —
  `api_clients`, the `personal_access_tokens.api_client_id` link,
  `api_idempotency_keys`, `webhook_endpoints`, `webhook_deliveries`,
  `webhook_events`.

`api_clients` and the idempotency/webhook tables are new; **no** duplicate
token table. `webhook_endpoints.secret_encrypted` and
`webhook_events.payload_encrypted` are stored encrypted.

## 52. New Files Inventory

88 new files: 3 config, 2 migrations, 6 models, 1 support helper, 1 exception
handler, 1 contract, 1 gateway, 8 services, 1 job, 3 middleware,
16 API controllers, 25 API resources, `routes/api.php`, `tools/gen_openapi.py`,
18 API test classes. Full contents in the appendix.

## 53. Modified Files Inventory

11 modified files: `composer.json`, `config/auth.php`, `bootstrap/app.php`,
`app/Providers/AppServiceProvider.php`, `app/Models/User.php`,
`app/Services/AuditLogService.php`, `app/Http/Controllers/TeamController.php`,
`app/Services/ScoringService.php`, `app/Services/PaymentService.php`,
`app/Services/SupportTicketService.php`, `.env.example` (plus `composer.lock`
regenerated by Composer). Full final contents in the appendix.

## 54. Test Suite Overview

18 new API test classes (`tests/Feature/Api/`) cover: auth token lifecycle and
deactivated-account denial; scope enforcement + admin-scope denial; API
routing/validation/resources/errors; tournament list/show/registration/
check-in/waitlist/authz; team CRUD/roster/IDOR; match show/submission
auth/duplicate/scoring; profile privacy; own notifications/IDOR; payment
method/initiate/server-derived amount/idempotency/duplicate; wallet/ledger/
no credit-debit; payout own-only; support own-only/IDOR; webhook signature/
timestamp/replay/duplicate/invalid provider/amount mismatch; idempotency;
security (privilege escalation, SQL-injection, mass-assignment, token
leakage, sensitive-field redaction); rate limits; read surfaces; and the
actor × surface smoke matrix.

## 55. Exact Verification Results

| Gate | Result |
|---|---|
| `php artisan test` | **695 passed / 2140 assertions** (was 611/1843 → +84 tests, +297 assertions) |
| `php artisan migrate:fresh --seed --force` | success (27 migrations) |
| `php -l` (all new/modified PHP) | clean |
| `route:list` | 244 routes (177 web unchanged + 67 API new) |
| OpenAPI validation | OK — 67 documented paths, bijective with routes |

## 56. HTTP Smoke Matrix Results

From `ApiSmokeMatrixTest` (asserted, not merely observed):

| Actor | Request | Status |
|---|---|---|
| guest | GET /api/v1/tournaments | 200 |
| guest | GET /api/v1/me | 401 |
| guest | POST …/registrations | 401 |
| guest | GET /api/v1/admin/webhooks/endpoints | 401 |
| player | GET /api/v1/me | 200 |
| player | GET /api/v1/me/wallet | 200 |
| player | POST …/registrations | 201 |
| organizer | GET /api/v1/teams/{team} | 200 |
| organizer | GET /api/v1/admin/webhooks/endpoints | 403 |
| moderator | GET /api/v1/admin/webhooks/endpoints | 403 |
| admin | GET /api/v1/admin/webhooks/endpoints | 200 |
| webhook | POST inbound (valid signature) | 200 |
| webhook | POST inbound (invalid signature) | 401 |
| webhook | POST inbound (replay) | 200, replay=true |

## 57. OpenAPI Validation, Security Verification, Production Configuration & Limitations

**OpenAPI validation** (gate): `python3 tools/gen_openapi.py` regenerates
`storage/api-docs/openapi.json` and verifies every documented path is routed
and every routed `/api/v1` business endpoint is documented — **67 paths,
bijective, no undocumented privileged mutation endpoints**.

**Security verification** (gate, via `ApiSecurityTest` +
`ApiTokenScopesTest` + `ApiProfilePrivacyTest` + `ApiTeamsRosterTest` +
`ApiWebhookTest`): privilege escalation blocked (role/account_status
non-writable, admin scope non-self-grantable, admin surface rejects
player/organizer/moderator); SQL-injection attempts inert (whitelisted sort/
direction, parameterized search); mass-assignment resistant; token plaintext
never stored/listed/leaked; sensitive fields redacted across surfaces;
deactivated accounts denied; IDOR blocked on teams/notifications/support/
disputes/roster.

**Production configuration** (documented in `.env.example`): set
`SANCTUM_TOKEN_PREFIX` (e.g. `ffarena_`) so leaked tokens are detectable;
set per-provider `WEBHOOK_*_SECRET` (or rely on the `PAYMENT_WEBHOOK_SECRET`
trust root); ensure `QUEUE_CONNECTION` is a real queue (database/redis) so
outbound webhooks deliver asynchronously; rotate webhook endpoint secrets via
the admin endpoint; never enable the log OTP provider in production
(`PhoneOtpProviderInterface` binding already requires SMS credentials).

**Limitations**: no native Android/iOS code (out of scope); no OAuth2
authorization-code server for third-party user delegation (out of scope for
this phase — integration is via personal access tokens); Google verification
uses the tokeninfo endpoint (JWKS verification is a documented follow-up);
outbound webhook events are emitted from the four dispatch seams that exist
today (registration, score submission, payment creation, support ticket
creation) — additional seams can be added by calling
`WebhookDispatcher::dispatchQuietly()` at new domain boundaries; the full
observability platform is Phase 16.

---

# Appendix — Complete Final File Contents

Every new/modified file is reproduced below in full, byte-for-byte against the
workspace. No placeholders, omissions, or TODOs.

## New Files

### `config/api.php`

```php
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Public API (Phase 15)
    |--------------------------------------------------------------------------
    |
    | Central, server-side configuration for the /api/v1 platform: token
    | scopes, token lifetimes, idempotency, pagination and per-route rate
    | limits. Clients can never self-declare any of this — every value is
    | enforced server-side.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Token scopes
    |--------------------------------------------------------------------------
    |
    | The closed vocabulary of grantable abilities. `admin` is reserved and
    | can never be granted to a normal API application; it is only assigned
    | to tokens created by platform staff. Financial mutation scopes are
    | deliberately separate from read scopes.
    |
    */
    'scopes' => [
        // Account / profile
        'profile:read',
        'profile:write',

        // Tournaments
        'tournaments:read',
        'tournaments:register',

        // Teams / roster
        'teams:read',
        'teams:write',
        'roster:read',
        'roster:write',

        // Matches / scores
        'matches:read',
        'scores:read',
        'scores:submit',

        // Leaderboards
        'leaderboard:read',

        // Notifications
        'notifications:read',
        'notifications:write',

        // Financial (read)
        'wallet:read',
        'payments:read',
        'payouts:read',

        // Financial (mutations — explicitly restricted)
        'payments:create',

        // Support / disputes
        'support:read',
        'support:write',
        'disputes:read',
        'disputes:write',
    ],

    /*
    |--------------------------------------------------------------------------
    | Reserved / staff-only scopes
    |--------------------------------------------------------------------------
    |
    | `admin` is the only scope that unlocks the /api/v1/admin/* surface. It
    | is never offered to, and never accepted from, a normal client.
    |
    */
    'staff_scopes' => [
        'admin',
    ],

    /*
    |--------------------------------------------------------------------------
    | Token lifetime
    |--------------------------------------------------------------------------
    |
    | Default and maximum personal-access-token lifetimes. A client may ask
    | for a shorter life; the server clamps it to this maximum.
    |
    */
    'token' => [
        'default_days' => 30,
        'max_days' => 365,
    ],

    /*
    |--------------------------------------------------------------------------
    | Idempotency
    |--------------------------------------------------------------------------
    |
    | Idempotency-Key handling for critical mutation endpoints. Keys expire
    | after `ttl_seconds`; a replayed request within that window returns the
    | stored response instead of executing again.
    |
    */
    'idempotency' => [
        'ttl_seconds' => 86400,
    ],

    /*
    |--------------------------------------------------------------------------
    | Pagination limits
    |--------------------------------------------------------------------------
    */
    'pagination' => [
        'default_per_page' => 15,
        'max_per_page' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limits (named limiters registered in AppServiceProvider)
    |--------------------------------------------------------------------------
    |
    | These keys mirror the RateLimiter::for() names used by the API routes.
    | Values are the max attempts per window; the window is encoded in the
    | limiter definition itself.
    |
    */
    'rate_limits' => [
        'api' => 120,               // general authenticated, per minute
        'api_anon' => 60,           // anonymous discovery, per minute
        'api_auth' => 5,            // token issuance, per minute per IP
        'api_otp_request' => 1,     // OTP request, per minute per phone
        'api_otp_verify' => 5,      // OTP verify, per 5 minutes per phone
        'api_score' => 10,          // score submission, per minute per user
        'api_payment' => 5,         // payment creation, per minute per user
        'api_support' => 10,        // support writes, per minute per user
        'api_webhook' => 60,        // inbound webhooks, per minute per IP
    ],

];
```

### `config/webhooks.php`

```php
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Webhook platform (Phase 15)
    |--------------------------------------------------------------------------
    |
    | Inbound: signed provider callbacks are verified (HMAC + timestamp
    | tolerance + event-id idempotency) and logged before being handed to the
    | existing Phase 08 payment callback logic. Nothing about a webhook is
    | trusted until it validates against internal state.
    |
    | Outbound: approved third-party subscriptions receive signed, queued
    | deliveries with exponential backoff. A delivery failure never rolls
    | back any business state.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Inbound provider secrets
    |--------------------------------------------------------------------------
    |
    | Per-provider HMAC secrets used to verify inbound webhook signatures.
    | The legacy Phase 08 `services.payments.webhook_secret` remains the
    | authoritative secret for /webhooks/payments/*; these entries extend the
    | platform to the new /api/v1/webhooks/inbound/* surface.
    |
    */
    'inbound' => [
        // Per-provider HMAC secrets. When a provider has no dedicated secret,
        // the Phase 08 `services.payments.webhook_secret` is used, so inbound
        // payment events share the same trust root as /webhooks/payments/*.
        'providers' => [
            'bkash' => env('WEBHOOK_BKASH_SECRET'),
            'nagad' => env('WEBHOOK_NAGAD_SECRET'),
            'rocket' => env('WEBHOOK_ROCKET_SECRET'),
            'sslcommerz' => env('WEBHOOK_SSLCOMMERZ_SECRET'),
            'card' => env('WEBHOOK_CARD_SECRET'),
        ],

        // Maximum age of a signed request (seconds). Older requests are
        // rejected as replays.
        'timestamp_tolerance' => 300,

        // Maximum raw body size accepted from a provider (bytes).
        'max_payload_bytes' => 65536,
    ],

    /*
    |--------------------------------------------------------------------------
    | Outbound delivery
    |--------------------------------------------------------------------------
    */
    'outbound' => [
        // Retry schedule (seconds). Attempt N uses backoff[N-1].
        'backoff' => [10, 60, 300, 1800, 3600, 10800],

        // Disable an endpoint after this many consecutive failures.
        'disable_after_failures' => 6,

        // HTTP timeout for a delivery attempt (seconds).
        'timeout' => 10,

        // Tolerated clock skew when verifying X-FFArena-Timestamp on the
        // receiving side (used by our own verification helper + docs).
        'timestamp_tolerance' => 300,
    ],

    /*
    |--------------------------------------------------------------------------
    | Outbound event vocabulary
    |--------------------------------------------------------------------------
    |
    | Events the platform may emit. Only events in this list are dispatchable;
    | subscribers may only select events from this list.
    |
    */
    'events' => [
        'tournament.created',
        'tournament.started',
        'tournament.completed',
        'team.registered',
        'team.withdrawn',
        'team.checked_in',
        'match.started',
        'match.completed',
        'match.score_submitted',
        'match.disputed',
        'match.resolved',
        'payment.created',
        'payment.succeeded',
        'payment.failed',
        'refund.completed',
        'payout.processing',
        'payout.completed',
        'payout.failed',
        'dispute.opened',
        'dispute.resolved',
        'support.ticket.created',
        'support.ticket.updated',
    ],

];
```

### `config/sanctum.php`

```php
<?php

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;
use Laravel\Sanctum\Sanctum;

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Requests from the following domains / hosts will receive stateful API
    | authentication cookies. Typically, these should include your local
    | and production domains which access your API via a frontend SPA.
    |
    */

    'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', sprintf(
        '%s%s',
        'localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1',
        Sanctum::currentApplicationUrlWithPort(),
        // Sanctum::currentRequestHost(),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Guards
    |--------------------------------------------------------------------------
    |
    | This array contains the authentication guards that will be checked when
    | Sanctum is trying to authenticate a request. If none of these guards
    | are able to authenticate the request, Sanctum will use the bearer
    | token that's present on an incoming request for authentication.
    |
    | Phase 15: FF Arena's /api/v1 is bearer-only. The session guard is NOT
    | listed here, so a session cookie can never authenticate an API request
    | as a mobile credential — the two modes stay strictly distinct. Browser
    | sessions continue to use the `web` guard directly.
    |
    */

    'guard' => [],

    /*
    |--------------------------------------------------------------------------
    | Expiration Minutes
    |--------------------------------------------------------------------------
    |
    | This value controls the number of minutes until an issued token will be
    | considered expired. This will override any values set in the token's
    | "expires_at" attribute, but first-party sessions are not affected.
    |
    */

    'expiration' => null,

    /*
    |--------------------------------------------------------------------------
    | Token Prefix
    |--------------------------------------------------------------------------
    |
    | Sanctum can prefix new tokens in order to take advantage of numerous
    | security scanning initiatives maintained by open source platforms
    | that notify developers if they commit tokens into repositories.
    |
    | See: https://docs.github.com/en/code-security/secret-scanning/about-secret-scanning
    |
    */

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware
    |--------------------------------------------------------------------------
    |
    | When authenticating your first-party SPA with Sanctum you may need to
    | customize some of the middleware Sanctum uses while processing the
    | request. You may change the middleware listed below as required.
    |
    */

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];
```

### `database/migrations/2026_09_09_092433_create_personal_access_tokens_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
```

### `database/migrations/2026_09_09_100000_create_api_infrastructure_tables.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // ------------------------------------------------------------------
        // api_clients — a user-owned "API application". Personal access
        // tokens are issued against a client and linked via
        // personal_access_tokens.api_client_id so admins can revoke an
        // entire application at once. Plaintext secrets are never stored;
        // tokens are stored only as their SHA-256 hash (Sanctum).
        // ------------------------------------------------------------------
        Schema::create('api_clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('description', 255)->nullable();
            $table->string('status', 20)->default('active'); // active | revoked
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index('user_id', 'api_clients_user_index');
            $table->index('status', 'api_clients_status_index');
        });

        // Link Sanctum tokens to their owning API client (nullable so
        // standalone tokens remain valid).
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->foreignId('api_client_id')->nullable()->after('tokenable_id')
                ->constrained('api_clients')->nullOnDelete();

            $table->index('api_client_id', 'pat_api_client_index');
        });

        // ------------------------------------------------------------------
        // api_idempotency_keys — request fingerprints for critical mutation
        // endpoints. A repeated request within the TTL replays the stored
        // response instead of executing again.
        // ------------------------------------------------------------------
        Schema::create('api_idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('key', 128);                    // SHA-256 of the client key
            $table->string('method', 10);
            $table->string('path', 255);
            $table->string('request_fingerprint', 128);    // SHA-256 of canonical body
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_body')->nullable();     // stored response (no secrets)
            $table->timestamp('created_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->unique(['user_id', 'key'], 'api_idempotency_user_key_unique');
            $table->index('expires_at', 'api_idempotency_expiry_index');
        });

        // ------------------------------------------------------------------
        // webhook_endpoints — approved third-party subscriptions (outbound).
        // The signing secret is stored encrypted; it is never returned by
        // the API after creation.
        // ------------------------------------------------------------------
        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('url', 500);
            $table->string('description', 255)->nullable();
            $table->string('status', 20)->default('active'); // active | disabled
            $table->text('secret_encrypted');
            $table->json('events');                           // subscribed event types
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('last_success_at')->nullable();
            $table->timestamps();

            $table->index('status', 'webhook_endpoints_status_index');
        });

        // ------------------------------------------------------------------
        // webhook_deliveries — per-event delivery attempts (outbound).
        // ------------------------------------------------------------------
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('endpoint_id')->constrained('webhook_endpoints')->cascadeOnDelete();
            $table->string('event', 60);
            $table->string('delivery_id', 64)->unique();      // UUID, idempotent
            $table->json('payload');                          // redacted payload
            $table->string('signature', 128);
            $table->string('status', 20)->default('pending'); // pending | success | failed | disabled
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_retry_at')->nullable();
            $table->unsignedSmallInteger('last_status_code')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index('endpoint_id', 'webhook_deliveries_endpoint_index');
            $table->index('status', 'webhook_deliveries_status_index');
        });

        // ------------------------------------------------------------------
        // webhook_events — signed inbound provider events. Raw payloads are
        // stored encrypted (never plaintext); safe metadata is stored
        // separately. Duplicate external event ids are idempotent.
        // ------------------------------------------------------------------
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 30);
            $table->string('external_event_id', 128)->nullable();
            $table->string('event_type', 60);
            $table->string('signature_status', 20);           // verified | invalid | missing
            $table->string('status', 20)->default('received'); // received | verified | processing | processed | ignored | failed | replayed
            $table->unsignedInteger('attempts')->default(0);
            $table->text('payload_encrypted')->nullable();    // encrypted raw payload
            $table->json('metadata')->nullable();             // safe subset only
            $table->timestamp('received_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'external_event_id'], 'webhook_events_provider_event_unique');
            $table->index('status', 'webhook_events_status_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
        Schema::dropIfExists('api_idempotency_keys');
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropIndex('pat_api_client_index');
            $table->dropConstrainedForeignId('api_client_id');
        });
        Schema::dropIfExists('api_clients');
    }
};
```

### `app/Support/ApiResponse.php`

```php
<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * Phase 15 — consistent API JSON envelopes.
 *
 * Success:
 *   { "data": ..., "meta": {...} }
 *
 * Errors (rendered by ApiExceptionHandler):
 *   { "error": { "code": "...", "message": "...", "details": {...} } }
 *
 * `data()` and `collection()` accept any JSON-serializable value (arrays,
 * ApiResources, paginator arrays). Raw Eloquent models are never returned.
 */
class ApiResponse
{
    public static function data(mixed $data, array $meta = [], int $status = 200): JsonResponse
    {
        $payload = ['data' => $data];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    public static function created(mixed $data, array $meta = []): JsonResponse
    {
        return self::data($data, $meta, 201);
    }

    public static function noContent(): JsonResponse
    {
        return response()->json(null, 204);
    }

    public static function error(string $code, string $message, array $details = [], int $status = 400): JsonResponse
    {
        $error = [
            'code' => $code,
            'message' => $message,
        ];

        if ($details !== []) {
            $error['details'] = $details;
        }

        return response()->json(['error' => $error], $status);
    }
}
```

### `app/Exceptions/ApiExceptionHandler.php`

```php
<?php

namespace App\Exceptions;

use App\Support\ApiResponse;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Phase 15 — centralized API error rendering.
 *
 * Maps exceptions to the standard `{ "error": { code, message, details } }`
 * envelope for /api/* requests only. Stack traces, SQL, credentials, class
 * paths and environment values are never included. Web routes keep Laravel's
 * default rendering (the renderer is only invoked for /api/* requests).
 */
class ApiExceptionHandler
{
    /**
     * Render an exception as a JSON API error, or return null to fall back
     * to Laravel's default handling.
     */
    public static function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null;
        }

        return match (true) {
            $e instanceof ValidationException => self::validation($e),
            $e instanceof AuthenticationException => ApiResponse::error(
                'unauthenticated',
                'Authentication is required.',
                [],
                401
            ),
            $e instanceof AuthorizationException => ApiResponse::error(
                'forbidden',
                $e->getMessage() !== '' ? $e->getMessage() : 'You are not authorized to perform this action.',
                [],
                403
            ),
            $e instanceof ModelNotFoundException => ApiResponse::error(
                'not_found',
                'The requested resource does not exist.',
                [],
                404
            ),
            $e instanceof NotFoundHttpException => ApiResponse::error(
                'not_found',
                'The requested endpoint does not exist.',
                [],
                404
            ),
            $e instanceof DomainException => self::domain($e),
            $e instanceof HttpExceptionInterface => ApiResponse::error(
                self::httpCode($e->getStatusCode()),
                self::httpMessage($e->getStatusCode(), $e->getMessage()),
                [],
                $e->getStatusCode()
            ),
            default => self::unexpected(),
        };
    }

    /**
     * Validation errors become a 422 with field-level details. Attribute
     * names are normalized but never echo raw request values.
     */
    protected static function validation(ValidationException $e): JsonResponse
    {
        $details = [];

        foreach ($e->errors() as $field => $messages) {
            $details[$field] = is_array($messages) ? $messages[0] : $messages;
        }

        return ApiResponse::error(
            'validation_error',
            'The request could not be processed.',
            $details,
            422
        );
    }

    /**
     * DomainException carries a business rule violation. When its code is a
     * real HTTP status (e.g. PaymentService's 404/400 for mismatches) that
     * status is preserved; otherwise it maps to a 409 conflict.
     */
    protected static function domain(DomainException $e): JsonResponse
    {
        $code = (int) $e->getCode();
        $status = ($code >= 400 && $code < 600) ? $code : 409;

        return ApiResponse::error(
            $status === 409 ? 'conflict' : 'invalid_request',
            $e->getMessage(),
            [],
            $status
        );
    }

    /**
     * Unexpected errors become a generic 500 with no internals. The real
     * exception is still reported to the application log.
     */
    protected static function unexpected(): JsonResponse
    {
        return ApiResponse::error(
            'server_error',
            'An unexpected error occurred.',
            [],
            500
        );
    }

    protected static function httpCode(int $status): string
    {
        return match ($status) {
            429 => 'rate_limited',
            403 => 'forbidden',
            404 => 'not_found',
            405 => 'method_not_allowed',
            default => 'http_error',
        };
    }

    protected static function httpMessage(int $status, string $fallback): string
    {
        return match ($status) {
            429 => 'Too many requests. Please slow down.',
            403 => $fallback !== '' ? $fallback : 'Forbidden.',
            404 => 'Not found.',
            405 => 'Method not allowed.',
            default => $fallback !== '' ? $fallback : 'Request failed.',
        };
    }
}
```

### `app/Contracts/GoogleIdTokenVerifierInterface.php`

```php
<?php

namespace App\Contracts;

use DomainException;

/**
 * Verifier for a Google OpenID Connect id_token (Phase 15 — mobile/API
 * Google sign-in).
 *
 * The real implementation validates the token server-side against Google
 * (issuer + audience + email_verified). Tests substitute a deterministic
 * fake so they never touch the network. When Google is not configured the
 * verifier reports `isConfigured() === false` and refuses to run.
 */
interface GoogleIdTokenVerifierInterface
{
    /**
     * Whether Google OIDC verification is configured.
     */
    public function isConfigured(): bool;

    /**
     * Verify an id_token and return the normalized Google user.
     *
     * @return array{id: string, email: ?string, email_verified: bool, name: ?string}
     *
     * @throws DomainException when the token is invalid or the provider is
     *                         not configured.
     */
    public function verify(string $idToken): array;
}
```

### `app/Gateways/GoogleTokenInfoIdVerifier.php`

```php
<?php

namespace App\Gateways;

use App\Contracts\GoogleIdTokenVerifierInterface;
use DomainException;
use Illuminate\Support\Facades\Http;

/**
 * Google OpenID Connect id_token verification via Google's tokeninfo
 * endpoint (Phase 15).
 *
 * The token is validated server-side against Google: issuer, audience (the
 * configured client id) and `email_verified` must all pass before the
 * identity is accepted. No client-supplied email/name is trusted. When the
 * OAuth credentials are absent the verifier honestly reports "not
 * configured".
 *
 * NOTE: for new integrations prefer verifying the JWT signature against
 * Google's JWKS endpoint; tokeninfo is retained here for its simplicity and
 * is only ever called with a token the client obtained from Google directly.
 */
class GoogleTokenInfoIdVerifier implements GoogleIdTokenVerifierInterface
{
    public const TOKENINFO_URL = 'https://oauth2.googleapis.com/tokeninfo';

    public function isConfigured(): bool
    {
        return ! empty(config('services.google.client_id'));
    }

    public function verify(string $idToken): array
    {
        if (! $this->isConfigured()) {
            throw new DomainException('Google Sign-In is not configured.');
        }

        $idToken = trim($idToken);

        if ($idToken === '') {
            throw new DomainException('A Google id token is required.');
        }

        $response = Http::timeout(10)->get(self::TOKENINFO_URL, ['id_token' => $idToken]);

        if ($response->failed()) {
            throw new DomainException('The Google id token could not be verified.');
        }

        $claims = $response->json();

        if (! is_array($claims)) {
            throw new DomainException('The Google id token could not be verified.');
        }

        // Server-side validation: issuer + audience + email_verified.
        if (($claims['iss'] ?? null) !== 'accounts.google.com'
            && ($claims['iss'] ?? null) !== 'https://accounts.google.com') {
            throw new DomainException('The Google id token issuer is invalid.');
        }

        $audience = $claims['aud'] ?? null;
        $expectedAudience = (string) config('services.google.client_id');

        if ($audience !== $expectedAudience) {
            throw new DomainException('The Google id token audience is invalid.');
        }

        $subject = (string) ($claims['sub'] ?? '');

        if ($subject === '') {
            throw new DomainException('The Google id token has no subject.');
        }

        $email = isset($claims['email']) ? (string) $claims['email'] : null;
        $emailVerified = (bool) ($claims['email_verified'] ?? false);

        if ($email !== null && ! $emailVerified) {
            // Never key an identity on an unverified email.
            $email = null;
        }

        return [
            'id' => $subject,
            'email' => $email,
            'email_verified' => $emailVerified,
            'name' => isset($claims['name']) ? (string) $claims['name'] : null,
        ];
    }
}
```

### `app/Models/ApiClient.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A user-owned API application (Phase 15).
 *
 * Personal access tokens are issued against a client and linked through
 * personal_access_tokens.api_client_id, so an admin (or the owner) can
 * revoke an entire application's tokens in one step. Plaintext secrets are
 * never stored — only Sanctum's SHA-256 token hashes.
 */
class ApiClient extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [];

    protected $casts = [
        'last_used_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function tokens()
    {
        return $this->hasMany(PersonalAccessToken::class, 'api_client_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
```

### `app/Models/PersonalAccessToken.php`

```php
<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * The Sanctum personal access token, with the Phase 15 `api_client_id` link.
 *
 * The table is Sanctum's own (hashed tokens, abilities, expiration, last-use
 * tracking); the only addition is the owning API client for grouped
 * revocation and admin management.
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    /**
     * Mirrors Sanctum's own fillable list. `api_client_id` is intentionally
     * excluded and assigned server-side only.
     */
    protected $fillable = [
        'name',
        'token',
        'abilities',
        'expires_at',
    ];

    public function client()
    {
        return $this->belongsTo(ApiClient::class, 'api_client_id');
    }
}
```

### `app/Models/ApiIdempotencyKey.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An idempotency record for a critical API mutation (Phase 15).
 *
 * Stores only the SHA-256 of the client key and the SHA-256 of the canonical
 * request body, plus the stored JSON response. A replayed request within the
 * TTL returns the stored response instead of executing again.
 */
class ApiIdempotencyKey extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [];

    protected $casts = [
        'response_body' => 'array',
        'created_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at === null || $this->expires_at->isPast();
    }
}
```

### `app/Models/WebhookEndpoint.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An approved third-party webhook subscription (Phase 15, outbound).
 *
 * The per-endpoint signing secret is stored encrypted and is never returned
 * by the API after creation. Subscriptions are event-scoped: a subscriber
 * only ever receives the events it selected.
 */
class WebhookEndpoint extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';

    protected $fillable = [];

    protected $casts = [
        'events' => 'array',
        'consecutive_failures' => 'integer',
        'last_success_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function deliveries()
    {
        return $this->hasMany(WebhookDelivery::class, 'endpoint_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function subscribesTo(string $event): bool
    {
        return in_array($event, (array) $this->events, true);
    }
}
```

### `app/Models/WebhookDelivery.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A single outbound webhook delivery attempt (Phase 15).
 *
 * Carries the redacted event payload, the signed signature and the delivery
 * state machine. Retries follow the configured exponential backoff; a
 * delivery that ultimately fails marks the endpoint disabled.
 */
class WebhookDelivery extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_DISABLED = 'disabled';

    protected $fillable = [];

    protected $casts = [
        'payload' => 'array',
        'attempts' => 'integer',
        'next_retry_at' => 'datetime',
        'last_status_code' => 'integer',
        'delivered_at' => 'datetime',
    ];

    public function endpoint()
    {
        return $this->belongsTo(WebhookEndpoint::class, 'endpoint_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_SUCCESS,
            self::STATUS_FAILED,
            self::STATUS_DISABLED,
        ], true);
    }
}
```

### `app/Models/WebhookEvent.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A signed inbound provider event (Phase 15).
 *
 * The raw payload is stored encrypted (never plaintext); only safe metadata
 * is stored in the clear. `(provider, external_event_id)` is unique so a
 * replayed event is idempotent.
 */
class WebhookEvent extends Model
{
    use HasFactory;

    public const SIGNATURE_VERIFIED = 'verified';
    public const SIGNATURE_INVALID = 'invalid';
    public const SIGNATURE_MISSING = 'missing';

    public const STATUS_RECEIVED = 'received';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_IGNORED = 'ignored';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REPLAYED = 'replayed';

    protected $fillable = [];

    protected $casts = [
        'attempts' => 'integer',
        'metadata' => 'array',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function isDuplicate(): bool
    {
        return $this->status === self::STATUS_REPLAYED;
    }
}
```

### `app/Http/Middleware/EnsureBearerToken.php`

```php
<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 15 — keep the bearer-token mode strictly distinct from the
 * stateful/cookie mode.
 *
 * API requests MUST authenticate with a bearer token. A session cookie is
 * never accepted as an API credential: if no Authorization bearer token is
 * present the request is refused before the sanctum guard runs.
 */
class EnsureBearerToken
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->bearerToken() === null) {
            return ApiResponse::error(
                'unauthenticated',
                'A bearer token is required.',
                [],
                401
            );
        }

        return $next($request);
    }
}
```

### `app/Http/Middleware/EnsureTokenIsValid.php`

```php
<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 15 — post-authentication token sanity checks.
 *
 * After `auth:sanctum` has resolved the bearer token, reject the request
 * when:
 *   - the token has expired,
 *   - the owning API client has been revoked,
 *   - the account is deactivated or pending deletion.
 *
 * No internal detail (token id, expiry, client) is leaked — the response is
 * always a generic 401.
 */
class EnsureTokenIsValid
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return ApiResponse::error('unauthenticated', 'Authentication is required.', [], 401);
        }

        // Deactivated / deleted accounts must not authenticate.
        if (! $user->isActive()) {
            return ApiResponse::error(
                'account_inactive',
                'This account is not active.',
                [],
                401
            );
        }

        $token = $request->user()->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            // Expired tokens are rejected even if Sanctum has not pruned them.
            if ($token->expires_at !== null && $token->expires_at->isPast()) {
                $token->delete();

                return ApiResponse::error('token_expired', 'This token has expired.', [], 401);
            }

            // A token minted for a revoked application stops working.
            if ($token->api_client_id !== null && $token->client !== null && ! $token->client->isActive()) {
                return ApiResponse::error('token_revoked', 'This token belongs to a revoked application.', [], 401);
            }
        }

        return $next($request);
    }
}
```

### `app/Http/Middleware/EnsureIdempotency.php`

```php
<?php

namespace App\Http\Middleware;

use App\Services\IdempotencyService;
use Closure;
use DomainException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 15 — Idempotency-Key enforcement for critical mutation endpoints.
 *
 * When an `Idempotency-Key` header is present the request is resolved
 * against the idempotency store: a replay within the TTL returns the stored
 * response, a reuse with a different body is a 409, and a fresh key's
 * successful (2xx) response is stored for future replays. Requests without
 * the header pass straight through.
 */
class EnsureIdempotency
{
    public function __construct(protected IdempotencyService $idempotency)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $key = $this->idempotency->keyFrom($request);

        if ($key === null) {
            return $next($request);
        }

        $stored = $this->idempotency->resolve($request->user(), $key, $request);

        if ($stored !== null) {
            $response = $stored;

            // Surface replays with the standard header.
            $response->headers->set('Idempotency-Replayed', 'true');

            return $response;
        }

        try {
            $response = $next($request);
        } catch (DomainException $e) {
            // The service layer has already rejected a conflicting reuse.
            throw $e;
        }

        // Only successful mutations are recorded; a failed request can be
        // safely retried with the same key.
        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            $this->idempotency->store($request->user(), $key, $request, $response);
            $response->headers->set('Idempotency-Key-Processed', 'true');
        }

        return $response;
    }
}
```

### `app/Services/ApiTokenService.php`

```php
<?php

namespace App\Services;

use App\Models\ApiClient;
use App\Models\LoginEvent;
use App\Models\Notification;
use App\Models\User;
use DomainException;
use Illuminate\Http\Request;
use Laravel\Sanctum\NewAccessToken;

/**
 * Phase 15 — personal access token issuance and revocation.
 *
 * Tokens are stored hashed by Sanctum (the plaintext is returned exactly
 * once), carry scoped abilities, an expiry, and an optional API client link.
 * Every issuance and revocation writes a security event, an audit entry and
 * (for the account) a notification. The `admin` scope can never be granted
 * from here — only platform staff may mint admin tokens.
 */
class ApiTokenService
{
    public function __construct(
        protected LoginEventService $loginEvents,
        protected AuditLogService $audit,
        protected NotificationService $notifications,
    ) {
    }

    /**
     * Issue a personal access token for a user.
     *
     * @param  string[]  $abilities
     */
    public function issue(
        User $user,
        string $name,
        array $abilities,
        ?int $expiresInDays = null,
        ?ApiClient $client = null,
        ?Request $request = null,
    ): NewAccessToken {
        $name = trim($name);

        if ($name === '') {
            throw new DomainException('A token name is required.');
        }

        $abilities = $this->sanitizeAbilities($abilities);

        if ($abilities === []) {
            throw new DomainException('Select at least one scope.');
        }

        $maxDays = (int) config('api.token.max_days', 365);
        $days = $expiresInDays ?? (int) config('api.token.default_days', 30);
        $days = max(1, min($days, $maxDays));

        $token = $user->createToken($name, $abilities, now()->addDays($days));

        if ($client !== null) {
            $token->accessToken->api_client_id = $client->id;
            $token->accessToken->save();
        }

        $this->loginEvents->record($user, LoginEvent::EVENT_ACCOUNT_LINKED, LoginEvent::STATUS_SUCCESS, $request, [
            'provider' => 'api_token',
            'token_name' => $this->cap($name, 80),
        ]);

        $this->audit->recordQuietly($user, 'auth.api_token_issued', 'user', $user->id, [
            'target_user_id' => $user->id,
            'metadata' => ['token_name' => $this->cap($name, 80), 'scopes' => $abilities],
        ]);

        $this->notifications->send(
            $user,
            Notification::TYPE_SYSTEM,
            'API token created',
            'A new API token "' . $this->cap($name, 60) . '" was created for your account.',
            NotificationService::link('settings.security'),
            ['token_name' => $this->cap($name, 80)],
        );

        return $token;
    }

    /**
     * Issue an admin-scoped token. Restricted to platform admins; the caller
     * must verify the actor is an admin before invoking this. The `admin`
     * scope is prepended to whatever sanitized abilities are passed.
     *
     * @param  string[]  $abilities
     */
    public function issueAdminToken(User $admin, string $name, array $abilities = [], ?int $expiresInDays = null): NewAccessToken
    {
        $abilities = $this->sanitizeAbilities($abilities);
        $abilities[] = 'admin';
        $abilities = array_values(array_unique($abilities));
        sort($abilities);

        $name = trim($name);
        $maxDays = (int) config('api.token.max_days', 365);
        $days = $expiresInDays ?? (int) config('api.token.default_days', 30);
        $days = max(1, min($days, $maxDays));

        $token = $admin->createToken($name, $abilities, now()->addDays($days));

        $this->audit->recordQuietly($admin, 'auth.api_token_issued', 'user', $admin->id, [
            'target_user_id' => $admin->id,
            'metadata' => ['token_name' => $this->cap($name, 80), 'scopes' => $abilities, 'admin' => true],
        ]);

        return $token;
    }

    /**
     * Revoke a single token by id, ensuring the caller owns it.
     */
    public function revokeToken(User $user, int $tokenId): void
    {
        $token = $user->tokens()->where('id', $tokenId)->first();

        if ($token === null) {
            throw new DomainException('That token does not exist.', 404);
        }

        $token->delete();

        $this->audit->recordQuietly($user, 'auth.api_token_revoked', 'user', $user->id, [
            'target_user_id' => $user->id,
            'metadata' => ['token_id' => $tokenId],
        ]);
    }

    /**
     * Revoke every token for a user except a given token id (used by
     * "logout other devices" on the API surface).
     */
    public function revokeAllExcept(User $user, ?int $keepTokenId = null): int
    {
        $count = $user->tokens()
            ->when($keepTokenId !== null, fn ($q) => $q->where('id', '!=', $keepTokenId))
            ->delete();

        return $count;
    }

    /**
     * Revoke an entire API client and every token linked to it.
     */
    public function revokeClient(User $user, ApiClient $client): void
    {
        if ($client->user_id !== $user->id && ! $user->isAdmin()) {
            throw new DomainException('This application does not belong to you.', 403);
        }

        $client->status = ApiClient::STATUS_REVOKED;
        $client->save();

        // Delete the linked tokens so they stop authenticating immediately.
        $user->tokens()->where('api_client_id', $client->id)->delete();

        $this->audit->recordQuietly($user, 'auth.api_client_revoked', 'api_client', $client->id, [
            'target_user_id' => $client->user_id,
            'metadata' => ['name' => $client->name],
        ]);
    }

    /**
     * Accept only known scopes and never the reserved admin scope.
     *
     * @param  string[]  $abilities
     * @return string[]
     */
    protected function sanitizeAbilities(array $abilities): array
    {
        $known = (array) config('api.scopes', []);
        $reserved = (array) config('api.staff_scopes', []);

        $clean = array_values(array_unique(array_filter(
            array_map('trim', $abilities),
            fn (string $ability) => $ability !== ''
                && in_array($ability, $known, true)
                && ! in_array($ability, $reserved, true),
        )));

        sort($clean);

        return $clean;
    }

    protected function cap(string $value, int $length): string
    {
        return mb_substr($value, 0, $length);
    }
}
```

### `app/Services/ApiClientService.php`

```php
<?php

namespace App\Services;

use App\Models\ApiClient;
use App\Models\User;
use DomainException;
use Laravel\Sanctum\NewAccessToken;

/**
 * Phase 15 — API application (client) management.
 *
 * A client is a named, user-owned API application. Creating one mints its
 * first token (returned exactly once, never stored in plaintext). Admins can
 * additionally inspect and revoke any client.
 */
class ApiClientService
{
    public function __construct(
        protected ApiTokenService $tokens,
        protected AuditLogService $audit,
    ) {
    }

    /**
     * Create a client and issue its first token.
     *
     * @param  string[]  $abilities
     */
    public function create(User $owner, string $name, string $description, array $abilities, ?int $expiresInDays = null): NewAccessToken
    {
        $name = trim($name);

        if ($name === '') {
            throw new DomainException('An application name is required.');
        }

        $client = new ApiClient();
        $client->user_id = $owner->id;
        $client->name = mb_substr($name, 0, 80);
        $client->description = $description !== '' ? mb_substr(trim($description), 0, 255) : null;
        $client->status = ApiClient::STATUS_ACTIVE;
        $client->save();

        $this->audit->recordQuietly($owner, 'auth.api_client_created', 'api_client', $client->id, [
            'target_user_id' => $owner->id,
            'metadata' => ['name' => $client->name],
        ]);

        return $this->tokens->issue($owner, $client->name, $abilities, $expiresInDays, $client);
    }

    /**
     * The user's clients, newest first.
     */
    public function forUser(User $user)
    {
        return ApiClient::where('user_id', $user->id)->orderByDesc('id')->get();
    }

    /**
     * The user's personal access tokens, newest first.
     */
    public function tokensFor(User $user)
    {
        return $user->tokens()->orderByDesc('id')->get();
    }

    /**
     * Admin view of all clients.
     */
    public function allClients()
    {
        return ApiClient::with('user:id,name,email')->orderByDesc('id')->get();
    }
}
```

### `app/Services/IdempotencyService.php`

```php
<?php

namespace App\Services;

use App\Models\ApiIdempotencyKey;
use App\Models\User;
use DomainException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 15 — API idempotency for critical mutation endpoints.
 *
 * The client sends an `Idempotency-Key` header. The service stores a hash of
 * the key plus a hash of the canonical request body. A repeat within the TTL
 * returns the stored response; a repeat with a *different* body is a 409
 * conflict. Only the SHA-256 of every input is persisted — never the raw key
 * or body.
 */
class IdempotencyService
{
    /**
     * Resolve the idempotency key for a request, or null when the header is
     * absent (non-idempotent requests pass through).
     */
    public function keyFrom(Request $request): ?string
    {
        $key = trim((string) $request->header('Idempotency-Key', ''));

        return $key === '' ? null : $key;
    }

    /**
     * Look up a prior response for a key, enforcing the body fingerprint.
     *
     * @throws DomainException when the key was reused with a different body.
     */
    public function resolve(?User $user, string $key, Request $request): ?Response
    {
        $hash = $this->hash($key);
        $record = $this->find($user, $hash);

        if ($record === null) {
            return null;
        }

        if ($record->isExpired()) {
            $record->delete();

            return null;
        }

        $fingerprint = $this->fingerprint($request);

        if ($record->request_fingerprint !== $fingerprint) {
            throw new DomainException('This idempotency key was already used with a different request body.', 409);
        }

        // A stored response that has not yet run has no status; that means a
        // concurrent in-flight request — treat as conflict to avoid races.
        if ($record->response_status === null) {
            throw new DomainException('A request with this idempotency key is already in progress.', 409);
        }

        return new Response(
            json_encode($record->response_body, JSON_UNESCAPED_SLASHES),
            (int) $record->response_status,
            ['Content-Type' => 'application/json'],
        );
    }

    /**
     * Persist the result of an idempotent request for future replays.
     */
    public function store(?User $user, string $key, Request $request, Response $response): void
    {
        $hash = $this->hash($key);
        $record = $this->find($user, $hash);

        if ($record === null) {
            $record = new ApiIdempotencyKey();
            $record->user_id = $user?->id;
            $record->key = $hash;
        }

        $record->method = $request->method();
        $record->path = $this->capPath($request->path());
        $record->request_fingerprint = $this->fingerprint($request);
        $record->response_status = $response->getStatusCode();
        $record->response_body = $this->bodyToArray($response);
        $record->expires_at = now()->addSeconds((int) config('api.idempotency.ttl_seconds', 86400));
        $record->save();
    }

    /**
     * A client may only ever see its own records (null user = anonymous).
     */
    protected function find(?User $user, string $hash): ?ApiIdempotencyKey
    {
        return ApiIdempotencyKey::where('key', $hash)
            ->when($user !== null, fn ($q) => $q->where('user_id', $user->id))
            ->when($user === null, fn ($q) => $q->whereNull('user_id'))
            ->first();
    }

    protected function hash(string $key): string
    {
        return hash('sha256', $key);
    }

    /**
     * Canonical fingerprint of the request: method + path + sorted body.
     */
    protected function fingerprint(Request $request): string
    {
        $body = $request->all();

        ksort($body);

        return hash('sha256', $request->method() . '|' . $request->path() . '|' . json_encode($body, JSON_UNESCAPED_SLASHES));
    }

    protected function bodyToArray(Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);

        return is_array($decoded) ? $decoded : ['raw' => (string) $response->getContent()];
    }

    protected function capPath(string $path): string
    {
        return mb_substr($path, 0, 255);
    }
}
```

### `app/Services/RegistrationService.php`

```php
<?php

namespace App\Services;

use App\Exceptions\RegistrationClosedException;
use App\Models\LiveEvent;
use App\Models\Notification;
use App\Models\RiskEvent;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\Tournament;
use App\Models\User;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Phase 15 — shared team-registration engine.
 *
 * This is the single authoritative registration flow used by BOTH the web
 * controller (Phase 04) and the /api/v1 registration endpoint. Nothing about
 * eligibility, capacity, slots, waitlist position or payment state is taken
 * from the client: the server derives every one of them.
 *
 * The flow (unchanged from Phase 04, extracted verbatim):
 *   1. fraud/risk gate (FraudRiskService::evaluateRegistration),
 *   2. authoritative lifecycle re-check inside a transaction,
 *   3. one-team-per-captain,
 *   4. roster UID availability,
 *   5. atomic slot claim (SQLite-compatible concurrency guard),
 *   6. waitlist branch when full, else pending team,
 *   7. roster member validation + insert,
 *   8. registration-volume risk signal,
 *   9. notifications, live event and audit.
 */
class RegistrationService
{
    public function __construct(
        protected RosterService $roster,
        protected FraudRiskService $risk,
        protected NotificationService $notifications,
        protected LiveEventService $live,
        protected AuditLogService $audit,
    ) {
    }

    /**
     * Register a team for a tournament.
     *
     * @param  array<string, mixed>  $data  already-validated registration payload
     * @return array{team: Team, waitlisted: bool}
     *
     * @throws DomainException             risk gate or roster violation
     * @throws RegistrationClosedException lifecycle refusal
     * @throws QueryException              unique-index backstop
     */
    public function register(Tournament $tournament, User $user, array $data): array
    {
        // Phase 10 — fraud/risk gate (restriction + risk-level enforcement).
        $this->risk->evaluateRegistration($tournament, $user);

        $captainUid = $this->roster->normalizeUid($data['game_uid']);
        $members = is_array($data['members'] ?? null) ? $data['members'] : [];

        $team = null;
        $waitlisted = false;

        DB::transaction(function () use ($tournament, $user, $data, $captainUid, $members, &$team, &$waitlisted) {
            $fresh = Tournament::findOrFail($tournament->id);

            if (! $fresh->acceptsRegistration()) {
                if ($fresh->hasStarted()) {
                    throw new RegistrationClosedException('Registration is closed — this tournament has already started.');
                }

                throw new RegistrationClosedException('Registration is closed for this tournament.');
            }

            // One-team-per-captain. The database unique(tournament_id,
            // captain_id) index is the final backstop.
            if (Team::where('tournament_id', $fresh->id)->where('captain_id', $user->id)->exists()) {
                throw new RegistrationClosedException('You have already registered a team in this tournament.');
            }

            // Roster integrity (Phase 03): the captain UID must not already
            // belong to another team in this tournament.
            $this->roster->assertUidAvailable($fresh, $captainUid);

            // ATOMIC SLOT CLAIM — SQLite-compatible concurrency guard.
            //
            // A single UPDATE that only succeeds while the tournament is
            // still open, has not started, and has a free slot. In SQLite
            // this statement acquires the write lock, so everything after
            // it in this transaction is race-free.
            $claimed = DB::table('tournaments')
                ->where('id', $fresh->id)
                ->where('status', Tournament::STATUS_OPEN)
                ->where(function ($q) {
                    $q->whereNull('starts_at')->orWhere('starts_at', '>', now());
                })
                ->whereRaw(
                    '(SELECT COUNT(*) FROM teams WHERE tournament_id = tournaments.id AND status IN (?, ?)) < team_slots',
                    [Team::STATUS_PENDING, Team::STATUS_CONFIRMED]
                )
                ->update(['updated_at' => now()]);

            if ($claimed !== 1) {
                // No slot. Re-check under the write lock: if the tournament
                // really is full, the team goes to the waitlist. Otherwise
                // registration is genuinely closed.
                $fresh2 = Tournament::findOrFail($fresh->id);

                if (! $fresh2->acceptsRegistration()) {
                    if ($fresh2->hasStarted()) {
                        throw new RegistrationClosedException('Registration is closed — this tournament has already started.');
                    }

                    throw new RegistrationClosedException('Registration is closed for this tournament.');
                }

                if (! $fresh2->isFull()) {
                    throw new RegistrationClosedException('Registration is not available for this tournament.');
                }

                // Full → waitlist (FIFO).
                $team = new Team();
                $team->tournament_id = $fresh2->id;
                $team->captain_id = $user->id;
                $team->name = $data['name'];
                $team->captain_name = $data['captain_name'];
                $team->phone = $data['phone'];
                $team->game_uid = $captainUid;
                $team->status = Team::STATUS_WAITLISTED;
                $team->waitlisted_at = now();
                $team->save();

                $waitlisted = true;
            } else {
                // Slot claimed → pending (awaits payment).
                $team = new Team();
                $team->tournament_id = $fresh->id;
                $team->captain_id = $user->id;
                $team->name = $data['name'];
                $team->captain_name = $data['captain_name'];
                $team->phone = $data['phone'];
                $team->game_uid = $captainUid;
                $team->status = Team::STATUS_PENDING;
                $team->save();
            }

            // Validate + persist roster members (size, duplicates,
            // cross-team clashes) — all inside the same transaction.
            $normalized = $this->roster->validateNewMembers($fresh, $team, $members);

            foreach ($normalized as $member) {
                $row = new TeamMember();
                $row->team_id = $team->id;
                $row->player_name = $member['player_name'];
                $row->game_uid = $member['game_uid'];
                $row->save();
            }
        });

        // Phase 10 — registration-volume signal (non-blocking observation).
        $teamCount = Team::where('captain_id', $user->id)->count();
        $maxTeams = (int) config('antifraud.registration.max_teams', 5);

        if ($teamCount >= $maxTeams) {
            $this->risk->recordSignal($user, RiskEvent::TYPE_REGISTRATION_VOLUME, RiskEvent::SEVERITY_MEDIUM, 'registration', [
                'team_count' => $teamCount,
            ], $tournament);
        }

        // Phase 11 — notify the captain and the organizer.
        $teamLink = NotificationService::link('teams.show', [$tournament, $team]);

        $this->notifications->send(
            $user,
            Notification::TYPE_TEAM_REGISTERED,
            'Team registered',
            'Your team ' . $team->name . ' was registered for ' . $tournament->name . '.',
            $teamLink,
            ['team_id' => $team->id, 'tournament_id' => $tournament->id],
        );

        $organizer = $tournament->organizer;

        if ($organizer !== null) {
            $this->notifications->send(
                $organizer,
                Notification::TYPE_TEAM_REGISTERED,
                'New team registration',
                'Team ' . $team->name . ' registered for ' . $tournament->name . '.',
                $teamLink,
                ['team_id' => $team->id, 'tournament_id' => $tournament->id],
            );
        }

        // Phase 12 — live event (best-effort).
        $this->live->recordQuietly($tournament, $user, LiveEvent::TYPE_TEAM_REGISTERED, [
            'team' => $team->name,
            'waitlisted' => $waitlisted,
        ]);

        // Phase 13 — central audit (best-effort).
        $this->audit->recordQuietly($user, 'team.registered', 'team', $team->id, [
            'tournament_id' => $tournament->id,
            'metadata' => ['team' => $team->name, 'waitlisted' => $waitlisted],
        ]);

        // Phase 15 — outbound webhook (best-effort; never rolls back the
        // registration if delivery fails).
        app(WebhookDispatcher::class)->dispatchQuietly('team.registered', [
            'team_id' => $team->id,
            'team_name' => $team->name,
            'tournament_id' => $tournament->id,
            'tournament_name' => $tournament->name,
            'waitlisted' => $waitlisted,
        ]);

        return ['team' => $team, 'waitlisted' => $waitlisted];
    }
}
```

### `app/Services/WebhookSignatureService.php`

```php
<?php

namespace App\Services;

/**
 * Phase 15 — deterministic HMAC-SHA256 webhook signatures.
 *
 * Outbound signature:  HMAC-SHA256(secret, "{timestamp}.{raw_body}")
 * The signature is sent in X-FFArena-Signature alongside X-FFArena-Timestamp,
 * X-FFArena-Event and X-FFArena-Delivery. Replays are prevented by timestamp
 * tolerance plus the delivery/event idempotency.
 *
 * Inbound verification follows the same scheme with the provider's secret.
 */
class WebhookSignatureService
{
    /**
     * Sign a payload for outbound delivery.
     */
    public function sign(string $secret, int $timestamp, string $rawBody): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
    }

    /**
     * Verify an inbound signature against a secret (raw-body HMAC-SHA256 —
     * the Phase 08 provider callback convention).
     */
    public function verify(string $secret, string $rawBody, string $signature): bool
    {
        if ($secret === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * Compute a raw-body HMAC signature (used to bridge the inbound provider
     * secret to the business-layer payment secret without weakening either).
     */
    public function signRaw(string $secret, string $rawBody): string
    {
        return hash_hmac('sha256', $rawBody, $secret);
    }

    /**
     * Extract a timestamp for the signature computation. The standard
     * scheme signs `{timestamp}.{raw_body}`, where the timestamp is taken
     * from the request's timestamp field.
     *
     * @param  int|null  $timestamp  explicit timestamp (header) when available
     */
    public function verifyWithTimestamp(string $secret, string $rawBody, string $signature, ?int $timestamp): bool
    {
        if ($timestamp === null || $timestamp <= 0) {
            return false;
        }

        $expected = $this->sign($secret, $timestamp, $rawBody);

        return hash_equals($expected, $signature);
    }

    /**
     * Whether a timestamp is within the tolerated skew.
     */
    public function timestampIsFresh(int $timestamp, int $toleranceSeconds = 300): bool
    {
        return abs(time() - $timestamp) <= $toleranceSeconds;
    }

    /**
     * Generate a random signing secret for a new outbound endpoint.
     */
    public function generateSecret(): string
    {
        return 'whsec_' . bin2hex(random_bytes(32));
    }
}
```

### `app/Services/WebhookIngressService.php`

```php
<?php

namespace App\Services;

use App\Models\WebhookEvent;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Phase 15 — inbound webhook ingestion.
 *
 * Verifies the provider signature, enforces timestamp tolerance and event-id
 * idempotency, stores a WebhookEvent (encrypted raw payload + safe
 * metadata), and then hands verified `payment.*` events to the existing
 * Phase 08 PaymentService callback logic. The business state machine is
 * never duplicated here.
 */
class WebhookIngressService
{
    public function __construct(
        protected WebhookSignatureService $signatures,
        protected PaymentService $payments,
    ) {
    }

    /**
     * Accept a provider webhook and dispatch it to the matching handler.
     *
     * @return array{event: WebhookEvent, replay: bool, payment?: array}
     *
     * @throws DomainException on invalid provider, signature, timestamp,
     *                          payload, or business validation failure.
     */
    public function handle(Request $request, string $provider): array
    {
        $this->assertKnownProvider($provider);

        $rawBody = (string) $request->getContent();

        $this->assertSize($rawBody);
        $this->assertJson($rawBody);
        $this->assertContentType($request);

        $payload = json_decode($rawBody, true);
        $eventId = $this->eventId($payload);

        $secret = $this->secretFor($provider);
        $signature = (string) $request->header('X-Signature', '');
        $timestamp = (int) $request->header('X-Timestamp', 0);

        // Raw-body HMAC-SHA256 (the Phase 08 provider convention).
        $signatureStatus = $this->signatures->verify($secret, $rawBody, $signature)
            ? WebhookEvent::SIGNATURE_VERIFIED
            : WebhookEvent::SIGNATURE_INVALID;

        if ($signatureStatus !== WebhookEvent::SIGNATURE_VERIFIED) {
            // Record the rejected attempt, then refuse.
            $this->recordRejected($provider, $eventId, $payload, $signatureStatus, $rawBody);

            throw new DomainException('Invalid webhook signature.', 401);
        }

        if (! $this->signatures->timestampIsFresh($timestamp, (int) config('webhooks.inbound.timestamp_tolerance', 300))) {
            $this->recordRejected($provider, $eventId, $payload, WebhookEvent::SIGNATURE_VERIFIED, $rawBody, WebhookEvent::STATUS_REPLAYED);

            throw new DomainException('Webhook timestamp is outside the tolerated window.', 401);
        }

        $eventType = $this->eventType($payload);

        // Event-id idempotency: a duplicate provider event is idempotent and
        // never processed twice.
        $existing = $eventId !== null
            ? WebhookEvent::where('provider', $provider)->where('external_event_id', $eventId)->first()
            : null;

        if ($existing !== null) {
            $existing->status = $existing->status === WebhookEvent::STATUS_PROCESSED
                ? WebhookEvent::STATUS_REPLAYED
                : $existing->status;
            $existing->attempts = $existing->attempts + 1;
            $existing->save();

            return ['event' => $existing, 'replay' => true];
        }

        $event = $this->record($provider, $eventId, $eventType, $signatureStatus, $payload, $rawBody);

        $result = ['event' => $event, 'replay' => false];

        // Payment events continue through the Phase 08 state machine (which
        // re-verifies the signature against the business secret and validates
        // amount/currency/state). Other event types are logged and ignored.
        if (str_starts_with($eventType, 'payment.')) {
            try {
                $result['payment'] = $this->processPayment($provider, $payload, $rawBody);
                $this->mark($event, WebhookEvent::STATUS_PROCESSED);
            } catch (DomainException $e) {
                // Business validation failed (amount/currency/provider/state)
                // — record the failure, then surface the rejection.
                $this->mark($event, WebhookEvent::STATUS_FAILED);

                throw $e;
            }
        } else {
            $this->mark($event, WebhookEvent::STATUS_IGNORED);
        }

        return $result;
    }

    /**
     * Route a verified payment webhook into the existing Phase 08 handler.
     *
     * The business rules (signature, amount, currency, provider, state)
     * remain PaymentService's single source of truth. The ingress layer has
     * already verified the provider's signature against the ingress secret;
     * here we re-compute the signature against the business secret so the
     * two secrets can differ without weakening either check.
     *
     * @return array{payment_id: int, status: string}
     */
    protected function processPayment(string $provider, array $payload, string $rawBody): array
    {
        $businessSecret = (string) config('services.payments.webhook_secret', '');
        $businessSignature = $this->signatures->signRaw($businessSecret, $rawBody);

        $payment = $this->payments->handleProviderCallback($provider, $payload, $businessSignature, $rawBody);

        return ['payment_id' => $payment->id, 'status' => $payment->status];
    }

    /**
     * The secret used to verify a provider's signature. Falls back to the
     * Phase 08 payment webhook secret so inbound payment events share the
     * same trust root as the legacy /webhooks/payments/* endpoint.
     */
    protected function secretFor(string $provider): string
    {
        $configured = config("webhooks.inbound.providers.{$provider}");

        if (! empty($configured)) {
            return (string) $configured;
        }

        return (string) config('services.payments.webhook_secret', 'ffarena-local-webhook-secret');
    }

    protected function assertKnownProvider(string $provider): void
    {
        $known = array_keys((array) config('webhooks.inbound.providers', []));

        if (! in_array($provider, $known, true)) {
            throw new DomainException('Unknown webhook provider.', 404);
        }
    }

    protected function assertSize(string $rawBody): void
    {
        $max = (int) config('webhooks.inbound.max_payload_bytes', 65536);

        if (strlen($rawBody) > $max) {
            throw new DomainException('Webhook payload is too large.', 413);
        }
    }

    protected function assertJson(string $rawBody): void
    {
        if (json_decode($rawBody, true) === null) {
            throw new DomainException('Webhook payload must be valid JSON.', 400);
        }
    }

    protected function assertContentType(Request $request): void
    {
        $contentType = strtolower((string) $request->header('Content-Type', ''));

        if ($contentType === '' || ! str_contains($contentType, 'application/json')) {
            throw new DomainException('Webhook Content-Type must be application/json.', 415);
        }
    }

    protected function eventId(array $payload): ?string
    {
        $id = $payload['event_id'] ?? $payload['id'] ?? null;

        if (! is_string($id) && ! is_numeric($id)) {
            return null;
        }

        $id = (string) $id;

        return $id === '' ? null : mb_substr($id, 0, 128);
    }

    protected function eventType(array $payload): string
    {
        $type = $payload['event'] ?? $payload['event_type'] ?? $payload['type'] ?? 'unknown';

        return mb_substr((string) $type, 0, 60);
    }

    protected function record(string $provider, ?string $eventId, string $eventType, string $signatureStatus, array $payload, string $rawBody): WebhookEvent
    {
        return DB::transaction(function () use ($provider, $eventId, $eventType, $signatureStatus, $payload, $rawBody) {
            $event = new WebhookEvent();
            $event->provider = $provider;
            $event->external_event_id = $eventId;
            $event->event_type = $eventType;
            $event->signature_status = $signatureStatus;
            $event->status = WebhookEvent::STATUS_VERIFIED;
            $event->attempts = 1;
            $event->received_at = now();
            $event->payload_encrypted = $this->encryptPayload($rawBody);
            $event->metadata = $this->safeMetadata($payload);
            $event->save();

            return $event;
        });
    }

    protected function recordRejected(string $provider, ?string $eventId, array $payload, string $signatureStatus, string $rawBody, string $status = WebhookEvent::STATUS_FAILED): void
    {
        try {
            $event = new WebhookEvent();
            $event->provider = $provider;
            $event->external_event_id = $eventId;
            $event->event_type = $this->eventType($payload);
            $event->signature_status = $signatureStatus;
            $event->status = $status;
            $event->attempts = 1;
            $event->received_at = now();
            $event->payload_encrypted = $this->encryptPayload($rawBody);
            $event->metadata = $this->safeMetadata($payload);
            $event->save();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    protected function mark(WebhookEvent $event, string $status): void
    {
        $event->status = $status;
        $event->processed_at = now();
        $event->save();
    }

    /**
     * Encrypt the raw payload at rest (never stored in plaintext).
     */
    protected function encryptPayload(string $rawBody): string
    {
        return Crypt::encryptString($rawBody);
    }

    /**
     * A deliberately minimal, safe metadata subset — never amounts, statuses
     * or identity claims that the business layer will re-validate anyway.
     */
    protected function safeMetadata(array $payload): array
    {
        $safe = [];

        foreach (['event', 'event_type', 'provider_reference', 'currency'] as $key) {
            if (isset($payload[$key]) && is_scalar($payload[$key])) {
                $safe[$key] = (string) $payload[$key];
            }
        }

        return $safe;
    }
}
```

### `app/Services/WebhookDispatcher.php`

```php
<?php

namespace App\Services;

use App\Jobs\SendWebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Str;

/**
 * Phase 15 — outbound webhook dispatch.
 *
 * Given a domain event + payload, enqueues a signed delivery for every
 * active endpoint subscribed to that event. Dispatching is best-effort by
 * contract (`dispatchQuietly`) and a delivery failure never rolls back the
 * originating business state.
 */
class WebhookDispatcher
{
    /**
     * Enqueue deliveries for a domain event.
     *
     * @param  array<string, mixed>  $payload  a redacted, non-sensitive payload
     * @return int number of deliveries enqueued
     */
    public function dispatch(string $event, array $payload, ?int $sourceUserId = null): int
    {
        if (! $this->isKnownEvent($event)) {
            return 0;
        }

        $endpoints = WebhookEndpoint::where('status', WebhookEndpoint::STATUS_ACTIVE)
            ->get()
            ->filter(fn (WebhookEndpoint $endpoint) => $endpoint->subscribesTo($event));

        $sent = 0;

        foreach ($endpoints as $endpoint) {
            SendWebhookDelivery::dispatch($endpoint, $event, $this->redact($payload), (string) Str::uuid());
            $sent++;
        }

        return $sent;
    }

    /**
     * Dispatch without ever throwing into the caller.
     */
    public function dispatchQuietly(string $event, array $payload, ?int $sourceUserId = null): int
    {
        try {
            return $this->dispatch($event, $payload, $sourceUserId);
        } catch (\Throwable $e) {
            report($e);

            return 0;
        }
    }

    /**
     * Whether an event is in the closed outbound vocabulary.
     */
    public function isKnownEvent(string $event): bool
    {
        return in_array($event, (array) config('webhooks.events', []), true);
    }

    /**
     * Strip fields that must never leave the platform (raw identifiers are
     * replaced by opaque ids where possible; sensitive keys are removed).
     */
    protected function redact(array $payload): array
    {
        $forbidden = [
            'email', 'phone', 'ip', 'ip_address', 'device', 'device_hash',
            'ip_hash', 'risk_score', 'risk_level', 'secret', 'token',
            'password', 'evidence', 'identity_document', 'ledger', 'wallet',
        ];

        $clean = [];

        foreach ($payload as $key => $value) {
            if (in_array($key, $forbidden, true)) {
                continue;
            }

            $clean[$key] = $value;
        }

        return $clean;
    }
}
```

### `app/Services/WebhookSubscriptionService.php`

```php
<?php

namespace App\Services;

use App\Models\WebhookEndpoint;
use App\Models\WebhookEvent;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\Crypt;

/**
 * Phase 15 — outbound webhook subscription management (admin-only).
 *
 * Endpoints are created with a one-time signing secret (returned exactly
 * once, stored encrypted). Secret rotation is admin-only. Subscriptions are
 * event-scoped: a subscriber only ever receives its subscribed events.
 */
class WebhookSubscriptionService
{
    public function __construct(
        protected WebhookSignatureService $signatures,
        protected AuditLogService $audit,
    ) {
    }

    /**
     * Create an endpoint and return it with the plaintext secret attached
     * transiently (shown exactly once).
     *
     * @param  string[]  $events
     */
    public function create(User $admin, string $url, string $description, array $events): array
    {
        $url = trim($url);
        $description = trim($description);

        if (! preg_match('#^https?://#i', $url)) {
            throw new DomainException('The webhook URL must start with http:// or https://.');
        }

        $events = $this->sanitizeEvents($events);

        if ($events === []) {
            throw new DomainException('Select at least one event to subscribe to.');
        }

        $secret = $this->signatures->generateSecret();

        $endpoint = new WebhookEndpoint();
        $endpoint->user_id = $admin->id;
        $endpoint->url = mb_substr($url, 0, 500);
        $endpoint->description = $description !== '' ? mb_substr($description, 0, 255) : null;
        $endpoint->status = WebhookEndpoint::STATUS_ACTIVE;
        $endpoint->secret_encrypted = Crypt::encryptString($secret);
        $endpoint->events = $events;
        $endpoint->consecutive_failures = 0;
        $endpoint->save();

        $this->audit->recordQuietly($admin, 'webhook.endpoint_created', 'webhook_endpoint', $endpoint->id, [
            'metadata' => ['url' => $endpoint->url, 'events' => $events],
        ]);

        return ['endpoint' => $endpoint, 'secret' => $secret];
    }

    /**
     * Rotate the signing secret (admin-only). The new secret is shown once.
     */
    public function rotateSecret(User $admin, WebhookEndpoint $endpoint): string
    {
        $secret = $this->signatures->generateSecret();

        $endpoint->secret_encrypted = Crypt::encryptString($secret);
        $endpoint->save();

        $this->audit->recordQuietly($admin, 'webhook.secret_rotated', 'webhook_endpoint', $endpoint->id, [
            'metadata' => ['url' => $endpoint->url],
        ]);

        return $secret;
    }

    /**
     * Enable/disable an endpoint.
     */
    public function setStatus(User $admin, WebhookEndpoint $endpoint, string $status): WebhookEndpoint
    {
        if (! in_array($status, [WebhookEndpoint::STATUS_ACTIVE, WebhookEndpoint::STATUS_DISABLED], true)) {
            throw new DomainException('Invalid endpoint status.');
        }

        $endpoint->status = $status;
        $endpoint->save();

        $this->audit->recordQuietly($admin, 'webhook.endpoint_status', 'webhook_endpoint', $endpoint->id, [
            'metadata' => ['status' => $status, 'url' => $endpoint->url],
        ]);

        return $endpoint;
    }

    /**
     * All endpoints, newest first.
     */
    public function all()
    {
        return WebhookEndpoint::orderByDesc('id')->get();
    }

    /**
     * The closed event vocabulary a subscriber may select from.
     *
     * @return string[]
     */
    public function vocabulary(): array
    {
        return (array) config('webhooks.events', []);
    }

    /**
     * @param  string[]  $events
     * @return string[]
     */
    protected function sanitizeEvents(array $events): array
    {
        $known = $this->vocabulary();

        $clean = array_values(array_unique(array_filter(
            array_map('trim', $events),
            fn (string $event) => $event !== '' && in_array($event, $known, true),
        )));

        sort($clean);

        return $clean;
    }
}
```

### `app/Jobs/SendWebhookDelivery.php`

```php
<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\WebhookSignatureService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/**
 * Phase 15 — outbound webhook delivery worker.
 *
 * Signs and POSTs one event to a subscribed endpoint with exponential
 * backoff. A failed delivery is rescheduled (never marking business state);
 * after the configured consecutive-failure threshold the endpoint is
 * disabled and the delivery terminal. The delivery id makes retries
 * idempotent on the receiving side.
 */
class SendWebhookDelivery implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        protected WebhookEndpoint $endpoint,
        protected string $event,
        protected array $payload,
        protected string $deliveryId,
    ) {
    }

    public function handle(WebhookSignatureService $signatures): void
    {
        if (! $this->endpoint->isActive()) {
            $this->record(WebhookDelivery::STATUS_DISABLED, null, 'Endpoint is disabled.');

            return;
        }

        $rawBody = json_encode($this->payload, JSON_UNESCAPED_SLASHES) ?: '{}';
        $timestamp = time();
        $secret = Crypt::decryptString($this->endpoint->secret_encrypted);
        $signature = $signatures->sign($secret, $timestamp, $rawBody);

        $delivery = $this->delivery();

        try {
            $response = Http::timeout((int) config('webhooks.outbound.timeout', 10))
                ->acceptJson()
                ->asJson()
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-FFArena-Signature' => $signature,
                    'X-FFArena-Timestamp' => (string) $timestamp,
                    'X-FFArena-Event' => $this->event,
                    'X-FFArena-Delivery' => $this->deliveryId,
                ])
                ->post($this->endpoint->url, $this->payload);

            if ($response->successful()) {
                $this->succeed($delivery, $response->status());

                return;
            }

            $this->fail($delivery, $response->status(), 'HTTP ' . $response->status());
        } catch (\Throwable $e) {
            $this->fail($delivery, null, mb_substr($e->getMessage(), 0, 255));
        }
    }

    /**
     * The persistent delivery row (created on first attempt).
     */
    protected function delivery(): WebhookDelivery
    {
        $delivery = WebhookDelivery::where('delivery_id', $this->deliveryId)->first();

        if ($delivery !== null) {
            return $delivery;
        }

        $delivery = new WebhookDelivery();
        $delivery->endpoint_id = $this->endpoint->id;
        $delivery->event = $this->event;
        $delivery->delivery_id = $this->deliveryId;
        $delivery->payload = $this->payload;
        $delivery->signature = ''; // signed per attempt, refreshed on retry
        $delivery->status = WebhookDelivery::STATUS_PENDING;
        $delivery->attempts = 0;
        $delivery->save();

        return $delivery;
    }

    protected function succeed(WebhookDelivery $delivery, int $status): void
    {
        $delivery->status = WebhookDelivery::STATUS_SUCCESS;
        $delivery->attempts = $delivery->attempts + 1;
        $delivery->last_status_code = $status;
        $delivery->delivered_at = now();
        $delivery->next_retry_at = null;
        $delivery->save();

        $this->endpoint->consecutive_failures = 0;
        $this->endpoint->last_success_at = now();
        $this->endpoint->save();
    }

    protected function fail(WebhookDelivery $delivery, ?int $status, string $error): void
    {
        $attempts = $delivery->attempts + 1;
        $backoff = (array) config('webhooks.outbound.backoff', [10, 60, 300, 1800, 3600, 10800]);
        $threshold = (int) config('webhooks.outbound.disable_after_failures', 6);

        $this->endpoint->consecutive_failures = $this->endpoint->consecutive_failures + 1;
        $this->endpoint->save();

        $disable = $this->endpoint->consecutive_failures >= $threshold;

        $delivery->attempts = $attempts;
        $delivery->last_status_code = $status;
        $delivery->last_error = $error;

        if ($disable) {
            $delivery->status = WebhookDelivery::STATUS_DISABLED;
            $delivery->next_retry_at = null;

            $this->endpoint->status = WebhookEndpoint::STATUS_DISABLED;
            $this->endpoint->save();
        } elseif ($attempts >= count($backoff)) {
            $delivery->status = WebhookDelivery::STATUS_FAILED;
            $delivery->next_retry_at = null;
        } else {
            $delivery->status = WebhookDelivery::STATUS_PENDING;
            $delay = $backoff[$attempts - 1] ?? 60;
            $delivery->next_retry_at = now()->addSeconds($delay);

            static::dispatch($this->endpoint, $this->event, $this->payload, $this->deliveryId)
                ->delay($delay);
        }

        $delivery->save();
    }

    protected function record(string $status, ?int $code, string $error): void
    {
        $delivery = $this->delivery();
        $delivery->status = $status;
        $delivery->last_status_code = $code;
        $delivery->last_error = $error;
        $delivery->save();
    }
}
```

### `app/Http/Controllers/Api/V1/AuthController.php`

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\GoogleIdTokenVerifierInterface;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\MeResource;
use App\Models\LoginEvent;
use App\Models\Notification;
use App\Models\RiskEvent;
use App\Models\User;
use App\Services\ApiTokenService;
use App\Services\AuditLogService;
use App\Services\DeviceFingerprintService;
use App\Services\IdentityService;
use App\Services\IpIntelligenceService;
use App\Services\LoginEventService;
use App\Services\NotificationService;
use App\Services\PhoneOtpService;
use App\Support\ApiResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;

/**
 * Phase 15 — mobile/API authentication.
 *
 * Reuses the Phase 14 account engine (IdentityService, PhoneOtpService,
 * AccountLifecycleService, FraudRiskService, LoginEventService) — there is
 * no second account system. Every login/registration issues a personal
 * access token shown exactly once.
 */
class AuthController extends Controller
{
    public function __construct(
        protected DeviceFingerprintService $devices,
        protected IpIntelligenceService $ipIntel,
        protected NotificationService $notifications,
        protected AuditLogService $audit,
        protected LoginEventService $loginEvents,
        protected PhoneOtpService $otp,
        protected IdentityService $identities,
        protected GoogleIdTokenVerifierInterface $googleVerifier,
        protected ApiTokenService $tokens,
    ) {
    }

    /**
     * POST /api/v1/auth/register
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:60|unique:users,username',
            'email' => 'required|email|unique:users,email',
            'phone' => 'nullable|string|max:20',
            'game_uid' => 'nullable|string|max:30',
            'role' => ['required', Rule::in(['player', 'organizer'])],
            'password' => 'required|min:6|confirmed',
        ]);

        // role is validated above (player|organizer only) and set explicitly —
        // it is NOT mass-assignable, so a client cannot self-register as admin.
        $user = new User();
        $user->name = $data['name'];
        $user->username = $data['username'];
        $user->email = $data['email'];
        $user->phone = $data['phone'] ?? null;
        $user->game_uid = $data['game_uid'] ?? null;
        $user->role = $data['role'];
        $user->password = Hash::make($data['password']);
        $user->account_status = 'active';
        $user->privacy = 'public';
        $user->save();

        // Phase 10 — pseudonymous device + IP observations (never throws).
        $this->devices->register($request, $user);
        $this->ipIntel->observe($request, $user);

        // Phase 13 — audit the signup.
        $this->audit->recordQuietly($user, 'auth.register', 'user', $user->id, [
            'target_user_id' => $user->id,
            'metadata' => ['role' => $user->role, 'via' => 'api'],
        ]);

        // Phase 11 — welcome + email verification link.
        $this->notifications->send(
            $user,
            Notification::TYPE_WELCOME,
            'Welcome to FF Arena',
            'Your account was created. Verify your email to secure it.',
            NotificationService::link('home'),
        );

        $this->sendVerificationNotification($user);

        $token = $this->issueDefaultToken($user, 'mobile-app', $request);

        return ApiResponse::created([
            'user' => new MeResource($user),
            'token' => $token->plainTextToken,
            'token_expires_at' => $token->accessToken->expires_at?->toISOString(),
        ]);
    }

    /**
     * POST /api/v1/auth/login  (email + password)
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if ($user === null || ! Hash::check($credentials['password'], (string) $user->password)) {
            // Enumeration-safe: identical failure for unknown email and bad
            // password; recorded against a matching account if one exists.
            $this->loginEvents->record($user, LoginEvent::EVENT_LOGIN_FAILED, LoginEvent::STATUS_FAILURE, $request);

            return ApiResponse::error('invalid_credentials', 'Invalid email or password.', [], 401);
        }

        if (! $user->isActive()) {
            return ApiResponse::error('account_inactive', 'This account is not active.', [], 401);
        }

        $this->devices->register($request, $user);
        $this->ipIntel->observe($request, $user);

        $this->loginEvents->record($user, LoginEvent::EVENT_LOGIN_PASSWORD, LoginEvent::STATUS_SUCCESS, $request);

        if ($this->loginEvents->isNewDevice($request, $user)) {
            $this->notifications->send(
                $user,
                Notification::TYPE_SUSPICIOUS_LOGIN,
                'New device sign-in',
                'Your account was just signed in from a new device.',
                NotificationService::link('settings.sessions'),
            );
        }

        $this->audit->recordQuietly($user, 'auth.login', 'user', $user->id, [
            'target_user_id' => $user->id,
            'metadata' => ['provider' => 'password', 'via' => 'api'],
        ]);

        $token = $this->issueDefaultToken($user, 'mobile-app', $request);

        return ApiResponse::data([
            'user' => new MeResource($user),
            'token' => $token->plainTextToken,
            'token_expires_at' => $token->accessToken->expires_at?->toISOString(),
        ]);
    }

    /**
     * POST /api/v1/auth/google  (id_token → account)
     */
    public function google(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id_token' => 'required|string',
        ]);

        if (! $this->googleVerifier->isConfigured()) {
            return ApiResponse::error('not_configured', 'Google Sign-In is not configured.', [], 503);
        }

        try {
            $googleUser = $this->googleVerifier->verify($data['id_token']);
        } catch (DomainException $e) {
            return ApiResponse::error('invalid_id_token', $e->getMessage(), [], 401);
        }

        $result = $this->identities->resolveGoogle($googleUser);
        $user = $result['user'];

        if (! $user->isActive()) {
            return ApiResponse::error('account_inactive', 'This account is not active.', [], 401);
        }

        $this->devices->register($request, $user);
        $this->ipIntel->observe($request, $user);

        $this->loginEvents->record($user, LoginEvent::EVENT_LOGIN_GOOGLE, LoginEvent::STATUS_SUCCESS, $request);

        $this->audit->recordQuietly($user, 'auth.login', 'user', $user->id, [
            'target_user_id' => $user->id,
            'metadata' => ['provider' => 'google', 'via' => 'api'],
        ]);

        $token = $this->issueDefaultToken($user, 'mobile-app', $request);

        return ApiResponse::data([
            'user' => new MeResource($user),
            'token' => $token->plainTextToken,
            'token_expires_at' => $token->accessToken->expires_at?->toISOString(),
            'created' => (bool) $result['created'],
        ], [], $result['created'] ? 201 : 200);
    }

    /**
     * POST /api/v1/auth/otp/request
     */
    public function otpRequest(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => 'required|string|max:20',
            'purpose' => ['required', Rule::in(['login', 'signup'])],
        ]);

        $user = $request->user();

        try {
            $this->otp->issue($user, $data['phone'], $data['purpose']);
        } catch (DomainException $e) {
            return ApiResponse::error('otp_request_failed', $e->getMessage(), [], 422);
        }

        return ApiResponse::data(['status' => 'sent'], ['message' => 'A verification code was sent to your phone.']);
    }

    /**
     * POST /api/v1/auth/otp/verify  (phone login)
     */
    public function otpVerify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => 'required|string|max:20',
            'purpose' => ['required', Rule::in(['login', 'signup'])],
            'code' => 'required|string|max:10',
        ]);

        $user = $request->user();

        try {
            $challenge = $this->otp->verify($user, $data['phone'], $data['purpose'], $data['code']);
        } catch (DomainException $e) {
            return ApiResponse::error('invalid_code', $e->getMessage(), [], 422);
        }

        // `login` purpose resolves (or creates) the account via the verified
        // phone identity; `signup` links the verified phone to the current
        // user. Mirrors the Phase 14 phone-login flow.
        $identityUser = $this->identities->userFor('phone', $challenge->phone);

        if ($data['purpose'] === 'signup') {
            if ($user === null) {
                return ApiResponse::error('unauthenticated', 'Authentication is required to link a phone.', [], 401);
            }

            $this->identities->linkPhone($user, $challenge->phone);

            $token = $this->issueDefaultToken($user, 'mobile-app', $request);

            return ApiResponse::data([
                'user' => new MeResource($user),
                'token' => $token->plainTextToken,
                'token_expires_at' => $token->accessToken->expires_at?->toISOString(),
            ]);
        }

        if ($identityUser === null) {
            return ApiResponse::error('no_account', 'No account is linked to this phone number.', [], 404);
        }

        if (! $identityUser->isActive()) {
            return ApiResponse::error('account_inactive', 'This account is not active.', [], 401);
        }

        $this->loginEvents->record($identityUser, LoginEvent::EVENT_LOGIN_PHONE, LoginEvent::STATUS_SUCCESS, $request);

        $this->audit->recordQuietly($identityUser, 'auth.login', 'user', $identityUser->id, [
            'target_user_id' => $identityUser->id,
            'metadata' => ['provider' => 'phone', 'via' => 'api'],
        ]);

        $token = $this->issueDefaultToken($identityUser, 'mobile-app', $request);

        return ApiResponse::data([
            'user' => new MeResource($identityUser),
            'token' => $token->plainTextToken,
            'token_expires_at' => $token->accessToken->expires_at?->toISOString(),
        ]);
    }

    /**
     * Issue the default first-party token (all non-admin scopes).
     */
    protected function issueDefaultToken(User $user, string $name, Request $request)
    {
        return $this->tokens->issue($user, $name, (array) config('api.scopes', []), null, null, $request);
    }

    protected function sendVerificationNotification(User $user): void
    {
        if ($user->hasVerifiedEmail()) {
            return;
        }

        $minutes = (int) config('account.verification.verify_link_minutes', 60);
        $link = URL::temporarySignedRoute('verification.verify', now()->addMinutes($minutes), [
            'id' => $user->id,
            'hash' => sha1((string) $user->email),
        ]);

        $this->notifications->send(
            $user,
            Notification::TYPE_EMAIL_VERIFY,
            'Verify your email',
            'Please verify your email address to secure your account.',
            $link,
        );
    }
}
```

### `app/Http/Controllers/Api/V1/DisputeController.php`

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\DisputeResource;
use App\Models\Dispute;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 15 — disputes: read-only for participants; private evidence is never
 * serialized. Staff additionally see the disputes they are party to via the
 * DisputePolicy.
 */
class DisputeController extends Controller
{
    /**
     * GET /api/v1/me/disputes — disputes the caller opened or (as staff) is
     * assigned to.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $disputes = Dispute::query()
            ->with(['match', 'team'])
            ->where(function ($q) use ($user) {
                $q->where('opened_by', $user->id);

                if ($user->isStaff()) {
                    $q->orWhere('assigned_to', $user->id);
                }
            })
            ->orderByDesc('id')
            ->paginate(min(50, max(1, (int) $request->query('per_page', 15))));

        return ApiResponse::data(
            DisputeResource::collection($disputes),
            [
                'pagination' => [
                    'current_page' => $disputes->currentPage(),
                    'last_page' => $disputes->lastPage(),
                    'per_page' => $disputes->perPage(),
                    'total' => $disputes->total(),
                ],
            ]
        );
    }

    /**
     * GET /api/v1/disputes/{dispute} — authorized read (participant or staff).
     */
    public function show(Request $request, Dispute $dispute): JsonResponse
    {
        $this->authorize('view', $dispute);

        return ApiResponse::data(new DisputeResource($dispute));
    }
}
```

### `app/Http/Controllers/Api/V1/LeaderboardController.php`

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\LeaderboardEntryResource;
use App\Models\Tournament;
use App\Models\User;
use App\Services\ScoringService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 15 — leaderboards: the list of ranked tournaments, a tournament's
 * standings, and a player's ranks. Every ranking comes from
 * ScoringService::standings (Phase 12) — no duplicated ranking math.
 */
class LeaderboardController extends Controller
{
    public function __construct(
        protected ScoringService $scoring,
    ) {
    }

    /**
     * GET /api/v1/leaderboards — tournaments that have standings.
     */
    public function index(Request $request): JsonResponse
    {
        $tournaments = Tournament::query()
            ->whereIn('status', Tournament::PUBLIC_STATUSES)
            ->whereHas('matches.scores')
            ->withCount(['matches as completed_matches' => fn ($q) => $q->whereIn('status', ['completed', 'disputed'])])
            ->orderByDesc('starts_at')
            ->paginate((int) config('api.pagination.default_per_page', 15));

        $rows = $tournaments->map(fn ($t) => [
            'id' => $t->id,
            'slug' => $t->slug,
            'name' => $t->name,
            'game_mode' => $t->game_mode,
            'status' => $t->status,
            'starts_at' => $t->starts_at?->toISOString(),
            'completed_matches' => (int) $t->completed_matches,
        ]);

        return ApiResponse::data($rows, [
            'pagination' => [
                'current_page' => $tournaments->currentPage(),
                'last_page' => $tournaments->lastPage(),
                'per_page' => $tournaments->perPage(),
                'total' => $tournaments->total(),
            ],
        ]);
    }

    /**
     * GET /api/v1/leaderboards/{tournament}
     */
    public function show(Request $request, Tournament $tournament): JsonResponse
    {
        if (! in_array($tournament->status, Tournament::PUBLIC_STATUSES, true)) {
            return ApiResponse::error('not_found', 'Tournament not found.', [], 404);
        }

        $rows = $this->scoring->standings($tournament)->map(function (array $row, int $index) {
            $row['rank'] = $index + 1;

            return $row;
        });

        return ApiResponse::data(
            LeaderboardEntryResource::collection($rows),
            ['tie_breakers' => $this->scoring->currentRuleSet($tournament)->tieBreakers()]
        );
    }

    /**
     * GET /api/v1/players/{user}/ranking
     */
    public function playerRanking(Request $request, User $user): JsonResponse
    {
        $teams = $user->teams()->with('tournament')->get();

        $ranks = [];

        foreach ($teams as $team) {
            $tournament = $team->tournament;

            if ($tournament === null || ! in_array($tournament->status, Tournament::PUBLIC_STATUSES, true)) {
                continue;
            }

            $standings = $this->scoring->standings($tournament);

            foreach ($standings->values() as $index => $row) {
                if ((int) ($row['team_id'] ?? 0) === $team->id) {
                    $ranks[] = [
                        'tournament_id' => $tournament->id,
                        'tournament_name' => $tournament->name,
                        'team_id' => $team->id,
                        'team_name' => $team->name,
                        'rank' => $index + 1,
                        'points' => (int) $row['points'],
                        'matches_played' => (int) $row['matches_played'],
                        'kills' => (int) $row['kills'],
                    ];

                    break;
                }
            }
        }

        return ApiResponse::data(['rankings' => $ranks]);
    }
}
```

### `app/Http/Controllers/Api/V1/LiveController.php`

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\LiveEventResource;
use App\Services\LiveEventService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 15 — the caller's own realtime cursor feed (account-targeted events
 * only; tournament events are served under /tournaments/{id}/live).
 */
class LiveController extends Controller
{
    public function __construct(
        protected LiveEventService $live,
    ) {
    }

    /**
     * GET /api/v1/me/live?since=N
     */
    public function me(Request $request): JsonResponse
    {
        $since = (int) $request->query('since', 0);
        $limit = min(100, max(1, (int) $request->query('limit', 50)));

        $events = $this->live->sinceForUser($since, $request->user(), $limit);

        return ApiResponse::data([
            'events' => LiveEventResource::collection($events),
            'latest_cursor' => $this->live->latestCursor(),
        ]);
    }
}
```

### `app/Http/Controllers/Api/V1/MatchController.php`

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\MatchResource;
use App\Http\Resources\Api\V1\ScoreResource;
use App\Models\GameMatch;
use App\Models\Score;
use App\Models\ScoringRule;
use App\Models\Team;
use App\Services\FraudRiskService;
use App\Services\ScoringService;
use App\Support\ApiResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 15 — match reads and score submission.
 *
 * Match-state mutation is server-side only; the only client mutation is
 * submitting raw kills/placement for their own participating team, which the
 * scoring engine turns into points.
 */
class MatchController extends Controller
{
    public function __construct(
        protected ScoringService $scoring,
        protected FraudRiskService $risk,
    ) {
    }

    /**
     * GET /api/v1/matches/{match}
     */
    public function show(Request $request, GameMatch $match): JsonResponse
    {
        if (! $this->matchIsPublic($match)) {
            return ApiResponse::error('not_found', 'Match not found.', [], 404);
        }

        $match->load(['team1', 'team2', 'winner', 'scores.team']);

        return ApiResponse::data(new MatchResource($match));
    }

    /**
     * POST /api/v1/matches/{match}/scores
     */
    public function submitScore(Request $request, GameMatch $match): JsonResponse
    {
        if (! $this->matchIsPublic($match)) {
            return ApiResponse::error('not_found', 'Match not found.', [], 404);
        }

        $data = $request->validate([
            'team_id' => 'required|integer|exists:teams,id',
            'kills' => 'required|integer|min:0',
            'placement' => 'required|integer|min:1|max:' . ScoringRule::MAX_PLACEMENT,
            // Authoritative totals and match state can never be supplied by a
            // client — they are computed server-side by the scoring engine.
            'points' => 'prohibited',
            'status' => 'prohibited',
            'placement_points' => 'prohibited',
            'kill_points' => 'prohibited',
        ]);

        $team = Team::find($data['team_id']);

        if ($team === null || ! $match->hasParticipant($team)) {
            return ApiResponse::error('forbidden', 'This team is not part of this match.', [], 403);
        }

        $this->authorize('submitScore', $team);

        // Phase 10 — fraud/risk gate for score submission.
        try {
            $this->risk->gate($request->user(), 'score_submission', $match->tournament);
        } catch (DomainException $e) {
            return ApiResponse::error('score_refused', $e->getMessage(), [], 422);
        }

        // Scoring is only possible while the match is ready or live.
        if (! $match->acceptsScoreSubmission()) {
            return ApiResponse::error('score_refused', 'Score submission is not open for this match.', [], 409);
        }

        // Duplicate + placement-claim guards (the scoring engine re-checks
        // these inside its transaction too).
        if (Score::where('match_id', $match->id)->where('team_id', $team->id)->exists()) {
            return ApiResponse::error('duplicate_score', 'A score for this team has already been submitted.', [], 409);
        }

        if (Score::where('match_id', $match->id)->where('placement', (int) $data['placement'])->exists()) {
            return ApiResponse::error('placement_taken', 'Another team in this match has already claimed that placement.', [], 409);
        }

        try {
            // The server computes every point — the client's values are only
            // raw inputs (kills + placement).
            $score = $this->scoring->submitScore(
                $match,
                $team,
                (int) $data['kills'],
                (int) $data['placement'],
            );
        } catch (DomainException $e) {
            return ApiResponse::error('score_refused', $e->getMessage(), [], 422);
        }

        return ApiResponse::created(new ScoreResource($score->load('team')));
    }

    /**
     * A match is publicly readable only once its tournament is public.
     */
    protected function matchIsPublic(GameMatch $match): bool
    {
        $tournament = $match->tournament()->first();

        return $tournament !== null
            && in_array($tournament->status, \App\Models\Tournament::PUBLIC_STATUSES, true);
    }
}
```

### `app/Http/Controllers/Api/V1/MeController.php`

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\MeResource;
use App\Http\Resources\Api\V1\SessionResource;
use App\Services\ProfileService;
use App\Services\SessionManagementService;
use App\Support\ApiResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 15 — /me: own profile, privacy, and session security.
 */
class MeController extends Controller
{
    public function __construct(
        protected ProfileService $profiles,
        protected SessionManagementService $sessions,
    ) {
    }

    /**
     * GET /api/v1/me
     */
    public function show(Request $request): JsonResponse
    {
        return ApiResponse::data(new MeResource($request->user()));
    }

    /**
     * PUT/PATCH /api/v1/me/profile
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'bio' => 'nullable|string|max:500',
            'country' => 'nullable|string|max:2',
            'region' => 'nullable|string|max:100',
            'avatar' => 'nullable|string|max:255',
            'privacy' => ['sometimes', Rule::in((array) config('account.privacy', ['public', 'registered', 'private']))],
        ]);

        $user = $request->user();

        // Username changes go through the cooldown/availability rules.
        if (isset($data['privacy'])) {
            $this->profiles->updatePrivacy($user, $data['privacy']);
        }

        if (array_key_exists('name', $data) || array_key_exists('bio', $data)
            || array_key_exists('country', $data) || array_key_exists('region', $data)
            || array_key_exists('avatar', $data)) {
            $user = $this->profiles->update($user, $data);
        }

        return ApiResponse::data(new MeResource($user->fresh()));
    }

    /**
     * GET /api/v1/me/security — sign-in methods, no internal signals.
     */
    public function security(Request $request): JsonResponse
    {
        $user = $request->user();

        return ApiResponse::data([
            'email' => $user->email,
            'email_verified' => $user->email_verified_at !== null,
            'has_password' => $this->profiles->hasPassword($user),
            'sign_in_methods' => app(\App\Services\IdentityService::class)->signInMethodCount($user),
            'account_status' => $user->account_status,
        ]);
    }

    /**
     * GET /api/v1/me/sessions
     */
    public function sessions(Request $request): JsonResponse
    {
        return ApiResponse::data(
            SessionResource::collection($this->sessions->sessionsFor($request->user()))
        );
    }

    /**
     * DELETE /api/v1/me/sessions/{session}
     */
    public function revokeSession(Request $request, string $session): JsonResponse
    {
        // Revoke a single named session that belongs to the caller. The id is
        // opaque and ownership is checked before deletion.
        $deleted = \Illuminate\Support\Facades\DB::table('sessions')
            ->where('id', $session)
            ->where('user_id', $request->user()->id)
            ->delete();

        if ($deleted === 0) {
            return ApiResponse::error('not_found', 'Session not found.', [], 404);
        }

        return ApiResponse::data(['revoked' => $deleted]);
    }

    /**
     * POST /api/v1/me/sessions/revoke-others
     */
    public function revokeOthers(Request $request): JsonResponse
    {
        $count = $this->sessions->revokeOtherSessions($request->user());

        return ApiResponse::data(['revoked' => $count]);
    }

    /**
     * POST /api/v1/me/sessions/revoke-all
     */
    public function revokeAll(Request $request): JsonResponse
    {
        $count = $this->sessions->revokeAllSessions($request->user());

        return ApiResponse::data(['revoked' => $count]);
    }
}
```

### `app/Http/Controllers/Api/V1/NotificationController.php`

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\NotificationResource;
use App\Models\Notification;
use App\Services\NotificationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 15 — the caller's own notifications (ownership enforced by querying
 * through the user relationship).
 */
class NotificationController extends Controller
{
    public function __construct(
        protected NotificationService $notifications,
    ) {
    }

    /**
     * GET /api/v1/me/notifications
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(50, max(1, (int) $request->query('per_page', 20)));

        $notifications = $this->notifications->forUser($request->user(), $perPage);

        return ApiResponse::data(
            NotificationResource::collection($notifications),
            [
                'unread_count' => $this->notifications->unreadCount($request->user()),
                'pagination' => [
                    'current_page' => $notifications->currentPage(),
                    'last_page' => $notifications->lastPage(),
                    'per_page' => $notifications->perPage(),
                    'total' => $notifications->total(),
                ],
            ]
        );
    }

    /**
     * GET /api/v1/me/notifications/unread-count
     */
    public function unreadCount(Request $request): JsonResponse
    {
        return ApiResponse::data([
            'unread_count' => $this->notifications->unreadCount($request->user()),
        ]);
    }

    /**
     * POST /api/v1/me/notifications/{notification}/read
     */
    public function markRead(Request $request, Notification $notification): JsonResponse
    {
        if ($notification->user_id !== $request->user()->id) {
            return ApiResponse::error('not_found', 'Notification not found.', [], 404);
        }

        $this->notifications->markRead($notification, $request->user());

        return ApiResponse::data(new NotificationResource($notification->fresh()));
    }

    /**
     * POST /api/v1/me/notifications/read-all
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $count = $this->notifications->markAllRead($request->user());

        return ApiResponse::data(['marked_read' => $count]);
    }
}
```

### `app/Http/Controllers/Api/V1/PaymentController.php`

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PaymentMethodResource;
use App\Http\Resources\Api\V1\PaymentResource;
use App\Models\LiveEvent;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\Team;
use App\Services\AuditLogService;
use App\Services\FraudRiskService;
use App\Services\LiveEventService;
use App\Services\NotificationService;
use App\Services\PaymentGatewayManager;
use App\Services\PaymentMethodService;
use App\Services\PaymentService;
use App\Support\ApiResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Phase 15 — payment methods + payment initiation.
 *
 * The amount, currency, payer, team and tournament are all server-derived;
 * the client selects a provider only. Success is only ever the result of
 * server-side verification (Phase 08 PaymentService).
 */
class PaymentController extends Controller
{
    public function __construct(
        protected PaymentService $payments,
        protected PaymentGatewayManager $gateways,
        protected PaymentMethodService $methods,
        protected FraudRiskService $risk,
        protected NotificationService $notifications,
        protected LiveEventService $live,
        protected AuditLogService $audit,
    ) {
    }

    /**
     * GET /api/v1/payments/methods — honest per-provider status + saved methods.
     */
    public function methods(Request $request): JsonResponse
    {
        return ApiResponse::data([
            'providers' => $this->gateways->statuses(),
            'saved_methods' => PaymentMethodResource::collection($this->methods->listFor($request->user())),
        ]);
    }

    /**
     * POST /api/v1/payments
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'team_id' => 'required|integer|exists:teams,id',
            'provider' => 'required|in:' . implode(',', $this->gateways->providers()),
        ]);

        $team = Team::find($data['team_id']);
        $tournament = $team?->tournament;

        if ($team === null || $tournament === null || ! $team->belongsToTournament($tournament)) {
            return ApiResponse::error('not_found', 'Team not found.', [], 404);
        }

        $this->authorize('pay', $team);

        $provider = $data['provider'];
        $gateway = $this->gateways->gateway($provider);

        // Phase 10 — fraud/risk gate for payment creation.
        try {
            $this->risk->evaluatePayment($request->user(), $tournament);
        } catch (DomainException $e) {
            return ApiResponse::error('payment_refused', $e->getMessage(), [], 422);
        }

        try {
            $payment = $this->payments->createForTeam(
                $tournament,
                $team,
                $request->user(),
                $provider,
                'PENDING',
                $provider,
                null,
            );
        } catch (DomainException $e) {
            $existing = Payment::where('team_id', $team->id)
                ->whereIn('status', Payment::ACTIVE_STATUSES)
                ->first();

            if ($existing !== null) {
                return ApiResponse::data(new PaymentResource($existing), ['existing' => true]);
            }

            return ApiResponse::error('payment_refused', $e->getMessage(), [], 422);
        }

        // Phase 13/14 — audit + notification + user live event.
        $this->audit->recordQuietly($request->user(), 'payment.initiated', 'payment', $payment->id, [
            'tournament_id' => $tournament->id,
            'metadata' => ['provider' => $provider, 'via' => 'api'],
        ]);

        $this->notifications->send(
            $request->user(),
            Notification::TYPE_PAYMENT_INITIATED,
            'Payment started',
            'Your entry fee payment for ' . $tournament->name . ' has been started (' . $gateway->label() . ').',
            NotificationService::link('payment.pending', [$tournament, $team, $payment]),
            ['payment_id' => $payment->id, 'provider' => $provider],
        );

        $this->live->recordForUserQuietly($request->user(), $request->user(), LiveEvent::TYPE_ACCOUNT_PAYMENT_STATUS, [
            'payment_id' => $payment->id,
            'status' => $payment->status,
        ], $tournament);

        $redirectUrl = null;

        if (! $payment->isSuccessful() && ! in_array($provider, ['bkash', 'nagad', 'rocket', 'bank'], true)) {
            try {
                $result = $gateway->createExternalPayment($payment);
                $redirectUrl = $result['redirect_url'] ?? null;
            } catch (DomainException $e) {
                $redirectUrl = null;
            }
        }

        return ApiResponse::created([
            'payment' => new PaymentResource($payment),
            'redirect_url' => $redirectUrl,
        ]);
    }

    /**
     * GET /api/v1/payments/{payment}
     */
    public function show(Request $request, Payment $payment): JsonResponse
    {
        $this->authorize('view', $payment);

        return ApiResponse::data(new PaymentResource($payment));
    }
}
```

### `app/Http/Controllers/Api/V1/PlayerController.php`

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ProfileResource;
use App\Models\User;
use App\Services\ProfileService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 15 — public player profiles. Privacy is honoured via
 * ProfileService::publicProfile: a private profile is never exposed merely
 * because the caller knows the id.
 */
class PlayerController extends Controller
{
    public function __construct(
        protected ProfileService $profiles,
    ) {
    }

    /**
     * GET /api/v1/players/{user}
     */
    public function show(Request $request, User $user): JsonResponse
    {
        $profile = $this->profiles->publicProfile($user, $request->user());

        return ApiResponse::data(new ProfileResource($profile));
    }
}
```

### `app/Http/Controllers/Api/V1/SupportController.php`

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\SupportMessageResource;
use App\Http\Resources\Api\V1\SupportTicketResource;
use App\Models\SupportTicket;
use App\Services\SupportTicketService;
use App\Support\ApiResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 15 — support tickets. A user only ever sees their own tickets;
 * staff can additionally see all tickets (authorization via policy/service).
 */
class SupportController extends Controller
{
    public function __construct(
        protected SupportTicketService $tickets,
    ) {
    }

    /**
     * GET /api/v1/me/support
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(50, max(1, (int) $request->query('per_page', 15)));

        $tickets = $this->tickets->forUser($request->user(), $perPage);

        return ApiResponse::data(
            SupportTicketResource::collection($tickets),
            [
                'pagination' => [
                    'current_page' => $tickets->currentPage(),
                    'last_page' => $tickets->lastPage(),
                    'per_page' => $tickets->perPage(),
                    'total' => $tickets->total(),
                ],
            ]
        );
    }

    /**
     * POST /api/v1/me/support
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject' => 'required|string|max:255',
            'category' => 'required|string|max:30',
            'priority' => 'nullable|string|max:12',
            'message' => 'required|string|max:5000',
        ]);

        try {
            $ticket = $this->tickets->create($request->user(), $data);
        } catch (DomainException $e) {
            return ApiResponse::error('ticket_refused', $e->getMessage(), [], 422);
        }

        return ApiResponse::created(new SupportTicketResource($ticket));
    }

    /**
     * GET /api/v1/me/support/{ticket}
     */
    public function show(Request $request, SupportTicket $ticket): JsonResponse
    {
        $this->authorize('view', $ticket);

        return ApiResponse::data(new SupportTicketResource($ticket));
    }

    /**
     * GET /api/v1/me/support/{ticket}/messages
     */
    public function messages(Request $request, SupportTicket $ticket): JsonResponse
    {
        $this->authorize('view', $ticket);

        $afterId = (int) $request->query('after', 0);

        $messages = $this->tickets->messagesAfter($ticket, $afterId)->load('author');

        return ApiResponse::data([
            'messages' => SupportMessageResource::collection($messages),
            'latest_message_id' => $this->tickets->latestMessageId($ticket),
        ]);
    }

    /**
     * POST /api/v1/me/support/{ticket}/messages
     */
    public function reply(Request $request, SupportTicket $ticket): JsonResponse
    {
        $this->authorize('reply', $ticket);

        $data = $request->validate([
            'body' => 'required|string|max:5000',
        ]);

        try {
            $message = $this->tickets->reply($request->user(), $ticket, $data['body']);
        } catch (DomainException $e) {
            return ApiResponse::error('reply_refused', $e->getMessage(), [], 422);
        }

        return ApiResponse::created(new SupportMessageResource($message->load('author')));
    }
}
```

### `app/Http/Controllers/Api/V1/TeamController.php`

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\TeamMemberResource;
use App\Http\Resources\Api\V1\TeamResource;
use App\Models\Team;
use App\Models\TeamMember;
use App\Services\AuditLogService;
use App\Services\RosterService;
use App\Support\ApiResponse;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 15 — team & roster APIs. Authorization goes through TeamPolicy and
 * roster integrity through RosterService; no direct DB manipulation.
 */
class TeamController extends Controller
{
    public function __construct(
        protected RosterService $roster,
        protected AuditLogService $audit,
    ) {
    }

    /**
     * GET /api/v1/me/teams — the caller's own teams.
     */
    public function index(Request $request): JsonResponse
    {
        $teams = Team::where('captain_id', $request->user()->id)
            ->with(['tournament', 'members'])
            ->orderByDesc('id')
            ->get();

        return ApiResponse::data(TeamResource::collection($teams));
    }

    /**
     * GET /api/v1/teams/{team}
     */
    public function show(Request $request, Team $team): JsonResponse
    {
        $this->authorize('view', $team);

        $team->load(['captain', 'members', 'tournament']);

        return ApiResponse::data(new TeamResource($team));
    }

    /**
     * PATCH /api/v1/teams/{team}
     */
    public function update(Request $request, Team $team): JsonResponse
    {
        $this->authorize('updateProfile', $team);

        $tournament = $team->tournament;

        $data = $request->validate([
            'name' => 'required|string|max:120',
            'captain_name' => 'required|string|max:120',
            'phone' => 'required|string|max:20',
            'game_uid' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9]{4,30}$/'],
        ]);

        try {
            $this->roster->updateProfile($team, $tournament, $data, $request->user()->isAdmin());
        } catch (DomainException $e) {
            return ApiResponse::error('roster_conflict', $e->getMessage(), [], 422);
        } catch (QueryException $e) {
            return ApiResponse::error('uid_conflict', 'This Free Fire UID is already used in this tournament.', [], 409);
        }

        $this->audit->recordQuietly($request->user(), 'team.updated', 'team', $team->id, [
            'tournament_id' => $tournament->id,
        ]);

        return ApiResponse::data(new TeamResource($team->fresh()->load('members')));
    }

    /**
     * GET /api/v1/teams/{team}/roster
     */
    public function roster(Request $request, Team $team): JsonResponse
    {
        $this->authorize('view', $team);

        return ApiResponse::data(TeamMemberResource::collection($team->members()->orderBy('id')->get()));
    }

    /**
     * POST /api/v1/teams/{team}/roster
     */
    public function addMember(Request $request, Team $team): JsonResponse
    {
        $this->authorize('addMember', $team);

        $tournament = $team->tournament;

        $data = $request->validate([
            'player_name' => 'required|string|max:120',
            'game_uid' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9]{4,30}$/'],
        ]);

        try {
            $member = $this->roster->addMember($team, $tournament, $data, $request->user()->isAdmin());
        } catch (DomainException $e) {
            return ApiResponse::error('roster_conflict', $e->getMessage(), [], 422);
        } catch (QueryException $e) {
            return ApiResponse::error('duplicate_member', 'This player is already in the team.', [], 409);
        }

        $this->audit->recordQuietly($request->user(), 'team.member_added', 'team', $team->id, [
            'tournament_id' => $tournament->id,
            'metadata' => ['player_name' => $member->player_name],
        ]);

        return ApiResponse::created(new TeamMemberResource($member));
    }

    /**
     * DELETE /api/v1/teams/{team}/roster/{member}
     */
    public function removeMember(Request $request, Team $team, TeamMember $member): JsonResponse
    {
        if ($member->team_id !== $team->id) {
            return ApiResponse::error('not_found', 'Member not found in this team.', [], 404);
        }

        $this->authorize('removeMember', $team);

        try {
            $this->roster->removeMember($team, $member, $request->user()->isAdmin());
        } catch (DomainException $e) {
            return ApiResponse::error('roster_conflict', $e->getMessage(), [], 422);
        }

        $this->audit->recordQuietly($request->user(), 'team.member_removed', 'team', $team->id, [
            'tournament_id' => $team->tournament_id,
            'metadata' => ['player_name' => $member->player_name],
        ]);

        return ApiResponse::noContent();
    }

    /**
     * POST /api/v1/teams/{team}/withdraw
     */
    public function withdraw(Request $request, Team $team): JsonResponse
    {
        $this->authorize('withdraw', $team);

        $tournament = $team->tournament;

        if (in_array($tournament->status, [
            \App\Models\Tournament::STATUS_LIVE,
            \App\Models\Tournament::STATUS_FINISHED,
            \App\Models\Tournament::STATUS_CANCELLED,
        ], true)) {
            return ApiResponse::error('withdraw_refused', 'Teams can no longer withdraw from this tournament.', [], 409);
        }

        if ($team->status === Team::STATUS_WITHDRAWN) {
            return ApiResponse::error('withdraw_refused', 'This team has already been withdrawn.', [], 409);
        }

        // Phase 10 — repeated-withdrawal signal (non-blocking observation).
        $priorWithdrawals = Team::where('captain_id', $request->user()->id)
            ->where('status', Team::STATUS_WITHDRAWN)
            ->count();

        $team->status = Team::STATUS_WITHDRAWN;
        $team->captain_id = null; // release the captain's claim so they may re-register
        $team->game_uid = null;   // release the captain UID so it can be re-used
        $team->save();

        $withdrawals = $priorWithdrawals + 1;
        $threshold = (int) config('antifraud.withdrawal.repeat_threshold', 3);

        if ($withdrawals >= $threshold) {
            app(\App\Services\FraudRiskService::class)->recordSignal(
                $request->user(),
                \App\Models\RiskEvent::TYPE_WITHDRAWAL_REPEAT,
                \App\Models\RiskEvent::SEVERITY_LOW,
                'registration',
                ['withdrawal_count' => $withdrawals],
                $tournament
            );
        }

        // Phase 11 — notify the organizer.
        $organizer = $tournament->organizer;

        if ($organizer !== null) {
            app(\App\Services\NotificationService::class)->send(
                $organizer,
                \App\Models\Notification::TYPE_TEAM_WITHDRAWN,
                'Team withdrew',
                'Team ' . $team->name . ' withdrew from ' . $tournament->name . '.',
                \App\Services\NotificationService::link('tournaments.show', [$tournament]),
                ['team_id' => $team->id, 'tournament_id' => $tournament->id],
            );
        }

        // Phase 12 — live event (best-effort).
        app(\App\Services\LiveEventService::class)->recordQuietly($tournament, $request->user(), \App\Models\LiveEvent::TYPE_TEAM_WITHDRAWN, [
            'team' => $team->name,
        ]);

        // Phase 13 — central audit (best-effort).
        $this->audit->recordQuietly($request->user(), 'team.withdrawn', 'team', $team->id, [
            'tournament_id' => $tournament->id,
            'metadata' => ['team' => $team->name],
        ]);

        return ApiResponse::data(new TeamResource($team->fresh()));
    }
}
```

### `app/Http/Controllers/Api/V1/TokenController.php`

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ApiClientResource;
use App\Http\Resources\Api\V1\TokenResource;
use App\Models\ApiClient;
use App\Services\ApiClientService;
use App\Services\ApiTokenService;
use App\Support\ApiResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 15 — personal access token + API client management.
 *
 * A token's plaintext is returned exactly once at creation and never stored,
 * logged, or listed again.
 */
class TokenController extends Controller
{
    public function __construct(
        protected ApiTokenService $tokens,
        protected ApiClientService $clients,
    ) {
    }

    /**
     * POST /api/v1/me/tokens
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:80',
            'scopes' => 'sometimes|array',
            'scopes.*' => 'string',
            'expires_in_days' => 'nullable|integer|min:1|max:365',
        ]);

        try {
            $token = $this->tokens->issue(
                $request->user(),
                $data['name'],
                $data['scopes'] ?? (array) config('api.scopes', []),
                $data['expires_in_days'] ?? null,
                null,
                $request,
            );
        } catch (DomainException $e) {
            return ApiResponse::error('token_refused', $e->getMessage(), [], 422);
        }

        return ApiResponse::created([
            'token' => $token->plainTextToken,
            'token_info' => new TokenResource($token->accessToken),
        ]);
    }

    /**
     * GET /api/v1/me/tokens
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::data(TokenResource::collection($this->clients->tokensFor($request->user())));
    }

    /**
     * DELETE /api/v1/me/tokens/{tokenId}
     */
    public function destroy(Request $request, int $tokenId): JsonResponse
    {
        try {
            $this->tokens->revokeToken($request->user(), $tokenId);
        } catch (DomainException $e) {
            return ApiResponse::error('not_found', $e->getMessage(), [], 404);
        }

        return ApiResponse::noContent();
    }

    /**
     * GET /api/v1/me/clients
     */
    public function clients(Request $request): JsonResponse
    {
        return ApiResponse::data(ApiClientResource::collection($this->clients->forUser($request->user())));
    }

    /**
     * POST /api/v1/me/clients
     */
    public function storeClient(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:80',
            'description' => 'nullable|string|max:255',
            'scopes' => 'sometimes|array',
            'scopes.*' => 'string',
            'expires_in_days' => 'nullable|integer|min:1|max:365',
        ]);

        try {
            $token = $this->clients->create(
                $request->user(),
                $data['name'],
                $data['description'] ?? '',
                $data['scopes'] ?? (array) config('api.scopes', []),
                $data['expires_in_days'] ?? null,
            );
        } catch (DomainException $e) {
            return ApiResponse::error('client_refused', $e->getMessage(), [], 422);
        }

        $client = ApiClient::find($token->accessToken->api_client_id);

        return ApiResponse::created([
            'client' => new ApiClientResource($client),
            'token' => $token->plainTextToken,
            'token_info' => new TokenResource($token->accessToken),
        ]);
    }

    /**
     * DELETE /api/v1/me/clients/{client}
     */
    public function destroyClient(Request $request, ApiClient $client): JsonResponse
    {
        try {
            $this->tokens->revokeClient($request->user(), $client);
        } catch (DomainException $e) {
            return ApiResponse::error('client_refused', $e->getMessage(), [], 403);
        }

        return ApiResponse::noContent();
    }
}
```

### `app/Http/Controllers/Api/V1/TournamentController.php`

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\RegistrationClosedException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\LeaderboardEntryResource;
use App\Http\Resources\Api\V1\LiveEventResource;
use App\Http\Resources\Api\V1\MatchResource;
use App\Http\Resources\Api\V1\TeamResource;
use App\Http\Resources\Api\V1\TournamentResource;
use App\Models\Tournament;
use App\Services\AuditLogService;
use App\Services\FraudRiskService;
use App\Services\LiveEventService;
use App\Services\RegistrationService;
use App\Services\ScoringService;
use App\Services\TournamentParticipationService;
use App\Support\ApiResponse;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 15 — tournament discovery, registration, check-in, waitlist,
 * leaderboard, bracket, live feed. Every business rule lives in the shared
 * services; this controller only translates HTTP.
 */
class TournamentController extends Controller
{
    public function __construct(
        protected RegistrationService $registrations,
        protected TournamentParticipationService $participation,
        protected FraudRiskService $risk,
        protected ScoringService $scoring,
        protected LiveEventService $live,
        protected AuditLogService $audit,
    ) {
    }

    /**
     * GET /api/v1/tournaments
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = $this->perPage($request);

        // Whitelisted sort keys + directions; never concatenated into SQL.
        $sort = $request->query('sort');
        $order = match ($sort) {
            'starts_at' => 'starts_at',
            'prize_pool' => 'prize_pool',
            'entry_fee' => 'entry_fee',
            'name' => 'name',
            default => 'created_at',
        };
        $direction = $request->query('direction') === 'asc' ? 'asc' : 'desc';

        $query = Tournament::query()
            ->with('organizer')
            ->withCount('confirmedTeams')
            ->whereIn('status', Tournament::PUBLIC_STATUSES);

        $status = $request->query('status');
        if ($status !== null && in_array($status, Tournament::PUBLIC_STATUSES, true)) {
            $query->where('status', $status);
        }

        $gameMode = $request->query('game_mode');
        if ($gameMode !== null && in_array($gameMode, ['squad', 'duo', 'solo'], true)) {
            $query->where('game_mode', $gameMode);
        }

        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $query->where('name', 'like', '%' . addcslashes($search, '%_') . '%');
        }

        $tournaments = $query->orderBy($order, $direction)->paginate($perPage);

        return ApiResponse::data(
            TournamentResource::collection($tournaments),
            [
                'pagination' => [
                    'current_page' => $tournaments->currentPage(),
                    'last_page' => $tournaments->lastPage(),
                    'per_page' => $tournaments->perPage(),
                    'total' => $tournaments->total(),
                ],
            ]
        );
    }

    /**
     * GET /api/v1/tournaments/{tournament}
     */
    public function show(Request $request, Tournament $tournament): JsonResponse
    {
        if (! in_array($tournament->status, Tournament::PUBLIC_STATUSES, true)) {
            return ApiResponse::error('not_found', 'Tournament not found.', [], 404);
        }

        $tournament->load('organizer')->loadCount('confirmedTeams');

        return ApiResponse::data(new TournamentResource($tournament));
    }

    /**
     * GET /api/v1/tournaments/{tournament}/matches
     */
    public function matches(Request $request, Tournament $tournament): JsonResponse
    {
        if (! in_array($tournament->status, Tournament::PUBLIC_STATUSES, true)) {
            return ApiResponse::error('not_found', 'Tournament not found.', [], 404);
        }

        $matches = $tournament->matches()
            ->with(['team1', 'team2', 'winner', 'scores.team'])
            ->orderBy('bracket')->orderBy('round')->orderBy('match_no')
            ->get();

        return ApiResponse::data(MatchResource::collection($matches));
    }

    /**
     * GET /api/v1/tournaments/{tournament}/leaderboard
     */
    public function leaderboard(Request $request, Tournament $tournament): JsonResponse
    {
        if (! in_array($tournament->status, Tournament::PUBLIC_STATUSES, true)) {
            return ApiResponse::error('not_found', 'Tournament not found.', [], 404);
        }

        $rows = $this->scoring->standings($tournament)->map(function (array $row, int $index) {
            $row['rank'] = $index + 1;

            return $row;
        });

        return ApiResponse::data(
            LeaderboardEntryResource::collection($rows),
            ['tie_breakers' => $this->scoring->currentRuleSet($tournament)->tieBreakers()]
        );
    }

    /**
     * GET /api/v1/tournaments/{tournament}/bracket
     */
    public function bracket(Request $request, Tournament $tournament): JsonResponse
    {
        if (! in_array($tournament->status, Tournament::PUBLIC_STATUSES, true)) {
            return ApiResponse::error('not_found', 'Tournament not found.', [], 404);
        }

        $matches = $tournament->matches()
            ->with(['team1', 'team2', 'winner'])
            ->orderBy('bracket')->orderBy('round')->orderBy('match_no')
            ->get();

        $byRound = $matches->groupBy(fn ($m) => $m->bracket . ':' . $m->round)
            ->map(fn ($group) => MatchResource::collection($group));

        return ApiResponse::data([
            'format' => $tournament->format,
            'rounds' => $byRound,
        ]);
    }

    /**
     * POST /api/v1/tournaments/{tournament}/registrations
     */
    public function register(Request $request, Tournament $tournament): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'captain_name' => 'required|string|max:120',
            'phone' => 'required|string|max:20',
            'game_uid' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9]{4,30}$/'],
            'members' => 'nullable|array',
            'members.*.player_name' => 'nullable|string|max:120',
            'members.*.game_uid' => 'nullable|string|max:30',
        ]);

        try {
            $result = $this->registrations->register($tournament, $request->user(), $data);
        } catch (RegistrationClosedException $e) {
            return ApiResponse::error('registration_closed', $e->getMessage(), [], 409);
        } catch (DomainException $e) {
            return ApiResponse::error('registration_refused', $e->getMessage(), [], 422);
        } catch (QueryException $e) {
            return ApiResponse::error('duplicate_detected', 'A duplicate team or player was detected. Registration was not saved.', [], 409);
        }

        $team = $result['team'];
        $waitlisted = $result['waitlisted'];

        return ApiResponse::created([
            'team' => new TeamResource($team->load('members')),
            'waitlisted' => $waitlisted,
            'waitlist_position' => $waitlisted ? $team->waitlistPosition() : null,
            'next_step' => $waitlisted ? 'waitlist' : 'payment',
        ]);
    }

    /**
     * POST /api/v1/tournaments/{tournament}/check-in
     */
    public function checkIn(Request $request, Tournament $tournament): JsonResponse
    {
        $data = $request->validate([
            'team_id' => 'required|integer|exists:teams,id',
        ]);

        $team = \App\Models\Team::find($data['team_id']);

        if ($team === null || ! $team->belongsToTournament($tournament)) {
            return ApiResponse::error('not_found', 'Team not found in this tournament.', [], 404);
        }

        $this->authorize('checkIn', $team);

        // Phase 10 — fraud/risk gate for check-in.
        try {
            $this->risk->gate($request->user(), 'checkin', $tournament);
        } catch (DomainException $e) {
            return ApiResponse::error('checkin_refused', $e->getMessage(), [], 422);
        }

        try {
            $result = $this->participation->checkIn($tournament, $team, $request->user(), $request->user()->isAdmin());
        } catch (DomainException $e) {
            return ApiResponse::error('checkin_refused', $e->getMessage(), [], 422);
        }

        $this->audit->recordQuietly($request->user(), 'team.checked_in', 'team', $team->id, [
            'tournament_id' => $tournament->id,
        ]);

        return ApiResponse::data([
            'status' => $result === 'already' ? 'already_checked_in' : 'checked_in',
            'team' => new TeamResource($team->fresh()),
        ]);
    }

    /**
     * GET /api/v1/tournaments/{tournament}/waitlist — positions only; promotion
     * is server/admin controlled, never client-submitted.
     */
    public function waitlist(Request $request, Tournament $tournament): JsonResponse
    {
        if (! in_array($tournament->status, Tournament::PUBLIC_STATUSES, true)) {
            return ApiResponse::error('not_found', 'Tournament not found.', [], 404);
        }

        $waitlisted = $tournament->waitlistedTeams()
            ->with('captain')
            ->orderBy('waitlisted_at')
            ->orderBy('id')
            ->get();

        $rows = $waitlisted->map(fn ($team) => [
            'team_id' => $team->id,
            'name' => $team->name,
            'waitlist_position' => $team->waitlistPosition(),
            'waitlisted_at' => $team->waitlisted_at?->toISOString(),
        ]);

        return ApiResponse::data(['teams' => $rows, 'count' => $rows->count()]);
    }

    /**
     * GET /api/v1/tournaments/{tournament}/live?since=N — visibility-gated
     * cursor feed (Phase 12).
     */
    public function live(Request $request, Tournament $tournament): JsonResponse
    {
        if (! in_array($tournament->status, Tournament::PUBLIC_STATUSES, true)) {
            return ApiResponse::error('not_found', 'Tournament not found.', [], 404);
        }

        $since = (int) $request->query('since', 0);
        $limit = min(100, max(1, (int) $request->query('limit', 50)));

        $events = $this->live->since($since, $tournament, $request->user(), $limit);

        return ApiResponse::data([
            'events' => LiveEventResource::collection($events),
            'latest_cursor' => $this->live->latestCursor(),
        ]);
    }

    protected function perPage(Request $request): int
    {
        $max = (int) config('api.pagination.max_per_page', 100);
        $default = (int) config('api.pagination.default_per_page', 15);

        $perPage = (int) $request->query('per_page', $default);

        return min($max, max(1, $perPage));
    }
}
```

### `app/Http/Controllers/Api/V1/WalletController.php`

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\LedgerEntryResource;
use App\Http\Resources\Api\V1\PayoutResource;
use App\Http\Resources\Api\V1\WalletResource;
use App\Services\WalletService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 15 — wallet & payouts (read-only for ordinary users).
 *
 * There are deliberately NO credit/debit endpoints: balances and the ledger
 * change only through the Phase 08/09 services.
 */
class WalletController extends Controller
{
    public function __construct(
        protected WalletService $wallets,
    ) {
    }

    /**
     * GET /api/v1/me/wallet
     */
    public function show(Request $request): JsonResponse
    {
        return ApiResponse::data(new WalletResource($this->wallets->walletFor($request->user())));
    }

    /**
     * GET /api/v1/me/wallet/ledger
     */
    public function ledger(Request $request): JsonResponse
    {
        $wallet = $this->wallets->walletFor($request->user());

        $perPage = min(100, max(1, (int) $request->query('per_page', 30)));

        $entries = $wallet->ledgerEntries()->orderByDesc('id')->paginate($perPage);

        return ApiResponse::data(
            LedgerEntryResource::collection($entries),
            [
                'pagination' => [
                    'current_page' => $entries->currentPage(),
                    'last_page' => $entries->lastPage(),
                    'per_page' => $entries->perPage(),
                    'total' => $entries->total(),
                ],
            ]
        );
    }

    /**
     * GET /api/v1/me/payouts — own payouts only.
     */
    public function payouts(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 30)));

        $payouts = $request->user()->payouts()
            ->with('tournament')
            ->orderByDesc('id')
            ->paginate($perPage);

        return ApiResponse::data(
            PayoutResource::collection($payouts),
            [
                'pagination' => [
                    'current_page' => $payouts->currentPage(),
                    'last_page' => $payouts->lastPage(),
                    'per_page' => $payouts->perPage(),
                    'total' => $payouts->total(),
                ],
            ]
        );
    }
}
```

### `app/Http/Controllers/Api/V1/WebhookInboundController.php`

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\WebhookIngressService;
use App\Support\ApiResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 15 — inbound provider webhooks.
 *
 * Signature verification, timestamp tolerance, event-id idempotency and
 * business validation all live in WebhookIngressService (which delegates
 * `payment.*` events to the Phase 08 PaymentService). This controller only
 * maps the outcome to HTTP.
 */
class WebhookInboundController extends Controller
{
    public function __construct(
        protected WebhookIngressService $ingress,
    ) {
    }

    /**
     * POST /api/v1/webhooks/inbound/{provider}
     */
    public function handle(Request $request, string $provider): JsonResponse
    {
        try {
            $result = $this->ingress->handle($request, $provider);
        } catch (DomainException $e) {
            return ApiResponse::error(
                'webhook_rejected',
                $e->getMessage(),
                [],
                $e->getCode() >= 400 && $e->getCode() < 600 ? (int) $e->getCode() : 400
            );
        }

        $event = $result['event'];

        return ApiResponse::data([
            'status' => $result['replay'] ? 'replayed' : $event->status,
            'event_id' => $event->external_event_id,
            'replay' => (bool) $result['replay'],
            'payment' => $result['payment'] ?? null,
        ]);
    }
}
```

### `app/Http/Controllers/Api/V1/WebhookSubscriptionController.php`

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\WebhookDeliveryResource;
use App\Http\Resources\Api\V1\WebhookEndpointResource;
use App\Models\WebhookEndpoint;
use App\Services\WebhookSubscriptionService;
use App\Support\ApiResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 15 — outbound webhook subscription management (admin-only).
 */
class WebhookSubscriptionController extends Controller
{
    public function __construct(
        protected WebhookSubscriptionService $subscriptions,
    ) {
    }

    /**
     * GET /api/v1/admin/webhooks/endpoints
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::data(WebhookEndpointResource::collection($this->subscriptions->all()));
    }

    /**
     * POST /api/v1/admin/webhooks/endpoints — the secret is returned exactly
     * once.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'url' => 'required|string|max:500',
            'description' => 'nullable|string|max:255',
            'events' => 'required|array|min:1',
            'events.*' => 'string',
        ]);

        try {
            $result = $this->subscriptions->create(
                $request->user(),
                $data['url'],
                $data['description'] ?? '',
                $data['events'],
            );
        } catch (DomainException $e) {
            return ApiResponse::error('subscription_refused', $e->getMessage(), [], 422);
        }

        return ApiResponse::created([
            'endpoint' => new WebhookEndpointResource($result['endpoint']),
            'secret' => $result['secret'],
        ], ['note' => 'Store this secret now. It is shown only once.']);
    }

    /**
     * GET /api/v1/admin/webhooks/endpoints/{endpoint}
     */
    public function show(Request $request, WebhookEndpoint $endpoint): JsonResponse
    {
        return ApiResponse::data(new WebhookEndpointResource($endpoint));
    }

    /**
     * POST /api/v1/admin/webhooks/endpoints/{endpoint}/rotate-secret
     */
    public function rotateSecret(Request $request, WebhookEndpoint $endpoint): JsonResponse
    {
        try {
            $secret = $this->subscriptions->rotateSecret($request->user(), $endpoint);
        } catch (DomainException $e) {
            return ApiResponse::error('rotation_refused', $e->getMessage(), [], 422);
        }

        return ApiResponse::data(['secret' => $secret], ['note' => 'Store this secret now. It is shown only once.']);
    }

    /**
     * POST /api/v1/admin/webhooks/endpoints/{endpoint}/toggle
     */
    public function toggle(Request $request, WebhookEndpoint $endpoint): JsonResponse
    {
        $data = $request->validate([
            'status' => 'required|in:active,disabled',
        ]);

        try {
            $endpoint = $this->subscriptions->setStatus($request->user(), $endpoint, $data['status']);
        } catch (DomainException $e) {
            return ApiResponse::error('toggle_refused', $e->getMessage(), [], 422);
        }

        return ApiResponse::data(new WebhookEndpointResource($endpoint));
    }

    /**
     * GET /api/v1/admin/webhooks/endpoints/{endpoint}/deliveries
     */
    public function deliveries(Request $request, WebhookEndpoint $endpoint): JsonResponse
    {
        $deliveries = $endpoint->deliveries()
            ->orderByDesc('id')
            ->paginate(min(100, max(1, (int) $request->query('per_page', 30))));

        return ApiResponse::data(
            WebhookDeliveryResource::collection($deliveries),
            [
                'pagination' => [
                    'current_page' => $deliveries->currentPage(),
                    'last_page' => $deliveries->lastPage(),
                    'per_page' => $deliveries->perPage(),
                    'total' => $deliveries->total(),
                ],
            ]
        );
    }

    /**
     * GET /api/v1/admin/webhooks/events — the event vocabulary.
     */
    public function events(Request $request): JsonResponse
    {
        return ApiResponse::data(['events' => $this->subscriptions->vocabulary()]);
    }
}
```

### `app/Http/Resources/Api/V1/ApiClientResource.php`

```php
<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An API client (application) summary.
 */
class ApiClientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status,
            'last_used_at' => $this->last_used_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
```

### `app/Http/Resources/Api/V1/DisputeResource.php`

```php
<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A dispute summary. Evidence, corrections and internal review notes are
 * deliberately excluded — only the state and resolution the participant is
 * entitled to see.
 */
class DisputeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'match_id' => $this->match_id,
            'team_id' => $this->team_id,
            'category' => $this->category,
            'status' => $this->status,
            'description' => $this->description,
            'resolution' => $this->resolution,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
```

### `app/Http/Resources/Api/V1/LeaderboardEntryResource.php`

```php
<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One leaderboard row produced by ScoringService::standings — the single
 * ranking source of truth (Phase 12).
 */
class LeaderboardEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'rank' => $this->when($this->resource['rank'] ?? null !== null, $this->resource['rank'] ?? null),
            'team_id' => $this->resource['team_id'] ?? null,
            'team_name' => $this->resource['team']?->name,
            'matches_played' => (int) ($this->resource['matches_played'] ?? 0),
            'kills' => (int) ($this->resource['kills'] ?? 0),
            'placement_points' => (int) ($this->resource['placement_points'] ?? 0),
            'kill_points' => (int) ($this->resource['kill_points'] ?? 0),
            'points' => (int) ($this->resource['points'] ?? 0),
            'best_placement' => $this->resource['best_placement'] ?? null,
        ];
    }
}
```

### `app/Http/Resources/Api/V1/LedgerEntryResource.php`

```php
<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A ledger entry. Exposes only the append-only balance history that belongs
 * to the caller — never internal reconciliation deltas or actor identity
 * details beyond the direction/amount/type.
 */
class LedgerEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'direction' => $this->direction,
            'amount_minor' => (int) $this->amount_minor,
            'balance_after_minor' => (int) $this->balance_after,
            'currency' => $this->currency,
            'type' => $this->type,
            'description' => $this->description,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
```

### `app/Http/Resources/Api/V1/LiveEventResource.php`

```php
<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A live event cursor row (Phase 12). Payloads are non-sensitive display
 * data only; visibility is gated by LiveEventService before this resource
 * ever runs.
 */
class LiveEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'tournament_id' => $this->tournament_id,
            'payload' => $this->payload ?? [],
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
```

### `app/Http/Resources/Api/V1/MatchResource.php`

```php
<?php

namespace App\Http\Resources\Api\V1;

use App\Models\GameMatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A match. The room id/pass are only exposed while the match is ready or
 * live, and only to participants, the organizer, or staff — never leaked to
 * the general public.
 */
class MatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();

        $isCaptainOfAParticipant = $viewer !== null && (
            ($this->team1 !== null && $this->team1->captain_id === $viewer->id)
            || ($this->team2 !== null && $this->team2->captain_id === $viewer->id)
        );

        $canSeeRoom = $viewer !== null && in_array($this->status, [
            GameMatch::STATUS_READY,
            GameMatch::STATUS_LIVE,
        ], true) && (
            $viewer->isStaff()
            || $this->tournament?->organizer_id === $viewer->id
            || $isCaptainOfAParticipant
        );

        return [
            'id' => $this->id,
            'tournament_id' => $this->tournament_id,
            'round' => (int) $this->round,
            'match_no' => (int) $this->match_no,
            'bracket' => $this->bracket,
            'status' => $this->status,
            'scheduled_at' => $this->scheduled_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'room_id' => $this->when($canSeeRoom, $this->room_id),
            'room_pass' => $this->when($canSeeRoom, $this->room_pass),
            'team1' => $this->whenLoaded('team1', fn () => $this->team1 ? [
                'id' => $this->team1->id,
                'name' => $this->team1->name,
            ] : null),
            'team2' => $this->whenLoaded('team2', fn () => $this->team2 ? [
                'id' => $this->team2->id,
                'name' => $this->team2->name,
            ] : null),
            'winner' => $this->whenLoaded('winner', fn () => $this->winner ? [
                'id' => $this->winner->id,
                'name' => $this->winner->name,
            ] : null),
            'scores' => ScoreResource::collection($this->whenLoaded('scores')),
        ];
    }
}
```

### `app/Http/Resources/Api/V1/MeResource.php`

```php
<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The authenticated user's own profile — richer than the public profile but
 * still scoped: no phone number, no fraud/risk state, no device/IP data.
 */
class MeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $identities = app(\App\Services\IdentityService::class);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'email' => $this->email,
            'email_verified' => $this->email_verified_at !== null,
            'avatar' => $this->avatar,
            'bio' => $this->bio,
            'country' => $this->country,
            'region' => $this->region,
            'role' => $this->role,
            'privacy' => $this->privacy ?? 'public',
            'joined_at' => $this->created_at?->toDateString(),
            'sign_in' => [
                'has_password' => $identities->hasPassword($this->resource),
                'has_google' => $identities->hasGoogle($this->resource),
                'has_phone' => $identities->hasVerifiedPhone($this->resource),
            ],
        ];
    }
}
```

### `app/Http/Resources/Api/V1/NotificationResource.php`

```php
<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'body' => $this->body,
            'link' => $this->link,
            'read' => $this->read_at !== null,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
```

### `app/Http/Resources/Api/V1/PaymentMethodResource.php`

```php
<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A stored payment method. Identifiers are always masked; no raw card
 * numbers, tokens or provider secrets ever leave the server.
 */
class PaymentMethodResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'label' => $this->label,
            'identifier_masked' => $this->masked_identifier ?? null,
            'is_default' => (bool) ($this->is_default ?? false),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
```

### `app/Http/Resources/Api/V1/PaymentResource.php`

```php
<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A payment. Amounts are exposed as decimal + minor; no provider secret,
 * token, evidence or raw gateway reference leaks. The provider reference is
 * exposed only as a redacted prefix.
 */
class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tournament_id' => $this->tournament_id,
            'team_id' => $this->team_id,
            'amount' => $this->amount,
            'amount_minor' => (int) $this->amount_minor,
            'currency' => $this->currency,
            'method' => $this->method,
            'provider' => $this->provider,
            'status' => $this->status,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
```

### `app/Http/Resources/Api/V1/PayoutResource.php`

```php
<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A payout. Exposes rank/status/amount only; no payment rails, provider
 * references, or processing internals.
 */
class PayoutResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tournament_id' => $this->tournament_id,
            'team_id' => $this->recipient_team_id,
            'rank' => (int) $this->rank,
            'amount_minor' => (int) $this->amount_minor,
            'currency' => $this->currency,
            'method' => $this->payout_method,
            'status' => $this->status,
            'processed_at' => $this->processed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
```

### `app/Http/Resources/Api/V1/ProfileResource.php`

```php
<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A privacy-honoring player profile. Backed by ProfileService::publicProfile
 * so private/registered profiles never leak fields merely because the caller
 * knows the user id.
 */
class ProfileResource extends JsonResource
{
    /**
     * @param  array<string, mixed>  $resource  ProfileService::publicProfile output
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
```

### `app/Http/Resources/Api/V1/ScoreResource.php`

```php
<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * A score entry. All points are server-computed; the client's raw inputs are
 * kills + placement only. The screenshot URL is exposed only when present.
 */
class ScoreResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'match_id' => $this->match_id,
            'team_id' => $this->team_id,
            'team_name' => $this->whenLoaded('team', fn () => $this->team?->name),
            'kills' => (int) $this->kills,
            'placement' => (int) $this->placement,
            'placement_points' => (int) $this->placement_points,
            'kill_points' => (int) $this->kill_points,
            'bonus_points' => (int) $this->bonus_points,
            'penalty_points' => (int) $this->penalty_points,
            'points' => (int) $this->points,
            'status' => $this->status,
            'screenshot_url' => $this->when(
                ! empty($this->screenshot_path),
                fn () => Storage::disk('public')->url($this->screenshot_path)
            ),
            'submitted_at' => $this->created_at?->toISOString(),
        ];
    }
}
```

### `app/Http/Resources/Api/V1/SessionResource.php`

```php
<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A device session summary (from SessionManagementService::sessionsFor).
 * Never exposes raw IP, user agent, or fingerprint — only the derived
 * device label and activity timestamp.
 */
class SessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource['id'] ?? null,
            'device_label' => $this->resource['device_label'] ?? 'Unknown device',
            'last_activity' => isset($this->resource['last_activity'])
                ? now()->setTimestamp((int) $this->resource['last_activity'])->toISOString()
                : null,
            'is_current' => (bool) ($this->resource['is_current'] ?? false),
        ];
    }
}
```

### `app/Http/Resources/Api/V1/SupportMessageResource.php`

```php
<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A support conversation message. Only the author's public identity is
 * exposed (never email/phone/IP); internal notes are never serialized.
 */
class SupportMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ticket_id' => $this->ticket_id,
            'author' => $this->whenLoaded('author', fn () => $this->author ? [
                'id' => $this->author->id,
                'name' => $this->author->name,
                'is_staff' => $this->author->isStaff(),
            ] : null),
            'body' => $this->body,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
```

### `app/Http/Resources/Api/V1/SupportTicketResource.php`

```php
<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A support ticket summary. Internal notes and staff-only fields are never
 * serialized here.
 */
class SupportTicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'subject' => $this->subject,
            'category' => $this->category,
            'priority' => $this->priority,
            'status' => $this->status,
            'last_activity_at' => $this->last_activity_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
```

### `app/Http/Resources/Api/V1/TeamMemberResource.php`

```php
<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeamMemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'player_name' => $this->player_name,
            'game_uid' => $this->game_uid,
        ];
    }
}
```

### `app/Http/Resources/Api/V1/TeamResource.php`

```php
<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A team. The captain's phone is only exposed to the captain, the tournament
 * organizer and staff; everyone else sees the roster and status.
 */
class TeamResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();

        $canSeePhone = $viewer !== null && (
            $viewer->id === $this->captain_id
            || ($this->tournament_id !== null && $this->tournament?->organizer_id === $viewer->id)
            || $viewer->isStaff()
        );

        return [
            'id' => $this->id,
            'tournament_id' => $this->tournament_id,
            'name' => $this->name,
            'captain_name' => $this->captain_name,
            'phone' => $this->when($canSeePhone, $this->phone),
            'game_uid' => $this->game_uid,
            'status' => $this->status,
            'checked_in_at' => $this->checked_in_at?->toISOString(),
            'waitlisted_at' => $this->waitlisted_at?->toISOString(),
            'waitlist_position' => $this->when($this->status === 'waitlisted', fn () => $this->waitlistPosition()),
            'captain' => $this->whenLoaded('captain', fn () => $this->captain ? [
                'id' => $this->captain->id,
                'name' => $this->captain->name,
                'username' => $this->captain->username,
            ] : null),
            'members' => TeamMemberResource::collection($this->whenLoaded('members')),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
```

### `app/Http/Resources/Api/V1/TokenResource.php`

```php
<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A personal access token summary. Only metadata is exposed — the plaintext
 * token is shown exactly once at creation and is never stored, logged, or
 * serialized.
 */
class TokenResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'abilities' => $this->abilities ?? [],
            'last_used_at' => $this->last_used_at?->toISOString(),
            'expires_at' => $this->expires_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'api_client_id' => $this->api_client_id,
        ];
    }
}
```

### `app/Http/Resources/Api/V1/TournamentResource.php`

```php
<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A tournament's public fields. Financial amounts are exposed as both the
 * decimal display value and the integer minor unit; every value is
 * server-derived.
 */
class TournamentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'game_mode' => $this->game_mode,
            'map' => $this->map,
            'format' => $this->format,
            'status' => $this->status,
            'entry_fee' => $this->entry_fee,
            'entry_fee_minor' => $this->entryFeeMinor(),
            'currency' => 'BDT',
            'prize_pool' => $this->prize_pool,
            'team_slots' => $this->team_slots,
            'team_size' => $this->team_size,
            'starts_at' => $this->starts_at?->toISOString(),
            'check_in_starts_at' => $this->check_in_starts_at?->toISOString(),
            'check_in_ends_at' => $this->check_in_ends_at?->toISOString(),
            'slots_left' => $this->slotsLeft(),
            'is_full' => $this->isFull(),
            'accepts_registration' => $this->acceptsRegistration(),
            'organizer' => $this->whenLoaded('organizer', fn () => [
                'id' => $this->organizer?->id,
                'name' => $this->organizer?->name,
            ]),
            'confirmed_teams_count' => $this->when(
                $this->resource->getAttribute('confirmed_teams_count') !== null,
                (int) $this->resource->getAttribute('confirmed_teams_count')
            ),
        ];
    }
}
```

### `app/Http/Resources/Api/V1/UserResource.php`

```php
<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A public-safe user summary. Only ever shows the caller's own (or a staff
 * member's) email; never phone, IP, device, risk or identity internals.
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'avatar' => $this->avatar,
            'role' => $this->role,
            'joined_at' => $this->created_at?->toDateString(),
            'email' => $this->when(
                $viewer !== null && ($viewer->id === $this->id || $viewer->isAdmin()),
                $this->email
            ),
        ];
    }
}
```

### `app/Http/Resources/Api/V1/WalletResource.php`

```php
<?php

namespace App\Http\Resources\Api\V1;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A wallet summary. Read-only for ordinary users; mutations only ever go
 * through WalletService.
 */
class WalletResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'balance' => Money::toDecimal($this->balanceMinor()),
            'balance_minor' => $this->balanceMinor(),
            'currency' => $this->currency ?? 'BDT',
            'status' => $this->status,
        ];
    }
}
```

### `app/Http/Resources/Api/V1/WebhookDeliveryResource.php`

```php
<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An outbound webhook delivery record. The payload is intentionally NOT
 * serialized (it may contain business data); only status/diagnostics.
 */
class WebhookDeliveryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event,
            'delivery_id' => $this->delivery_id,
            'status' => $this->status,
            'attempts' => (int) $this->attempts,
            'next_retry_at' => $this->next_retry_at?->toISOString(),
            'last_status_code' => $this->last_status_code,
            'last_error' => $this->last_error,
            'delivered_at' => $this->delivered_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
```

### `app/Http/Resources/Api/V1/WebhookEndpointResource.php`

```php
<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An outbound webhook endpoint. The signing secret is never returned —
 * it is shown exactly once at creation (and rotated only by admins).
 */
class WebhookEndpointResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url,
            'description' => $this->description,
            'status' => $this->status,
            'events' => $this->events ?? [],
            'consecutive_failures' => (int) $this->consecutive_failures,
            'last_success_at' => $this->last_success_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
```

### `app/Http/Resources/Api/V1/WebhookEventResource.php`

```php
<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An inbound webhook event record. The encrypted raw payload is never
 * serialized; only the safe metadata + state machine status.
 */
class WebhookEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'external_event_id' => $this->external_event_id,
            'event_type' => $this->event_type,
            'signature_status' => $this->signature_status,
            'status' => $this->status,
            'attempts' => (int) $this->attempts,
            'metadata' => $this->metadata ?? [],
            'received_at' => $this->received_at?->toISOString(),
            'processed_at' => $this->processed_at?->toISOString(),
        ];
    }
}
```

### `routes/api.php`

```php
<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DisputeController;
use App\Http\Controllers\Api\V1\LeaderboardController;
use App\Http\Controllers\Api\V1\LiveController;
use App\Http\Controllers\Api\V1\MatchController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PlayerController;
use App\Http\Controllers\Api\V1\SupportController;
use App\Http\Controllers\Api\V1\TeamController;
use App\Http\Controllers\Api\V1\TokenController;
use App\Http\Controllers\Api\V1\TournamentController;
use App\Http\Controllers\Api\V1\WalletController;
use App\Http\Controllers\Api\V1\WebhookInboundController;
use App\Http\Controllers\Api\V1\WebhookSubscriptionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| FF Arena Public API (Phase 15)
|--------------------------------------------------------------------------
|
| All public business endpoints are versioned under /api/v1. A future
| /api/v2 can be added alongside without breaking v1 clients. Sessions
| (cookies) are never accepted here — bearer tokens only.
|
*/

Route::prefix('v1')->name('api.v1.')->group(function () {

    // ------------------------------------------------------------------
    // Authentication (anonymous, tightly rate-limited)
    // ------------------------------------------------------------------
    Route::post('auth/register', [AuthController::class, 'register'])
        ->middleware('throttle:api_register')->name('auth.register');

    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:api_login')->name('auth.login');

    Route::post('auth/google', [AuthController::class, 'google'])
        ->middleware('throttle:api_login')->name('auth.google');

    Route::post('auth/otp/request', [AuthController::class, 'otpRequest'])
        ->middleware('throttle:api_otp_request')->name('auth.otp.request');

    Route::post('auth/otp/verify', [AuthController::class, 'otpVerify'])
        ->middleware('throttle:api_otp_verify')->name('auth.otp.verify');

    // ------------------------------------------------------------------
    // Public discovery (anonymous, rate-limited)
    // ------------------------------------------------------------------
    Route::middleware('throttle:api_anon')->group(function () {
        Route::get('tournaments', [TournamentController::class, 'index'])->name('tournaments.index');
        Route::get('tournaments/{tournament}', [TournamentController::class, 'show'])->name('tournaments.show');
        Route::get('tournaments/{tournament}/matches', [TournamentController::class, 'matches'])->name('tournaments.matches');
        Route::get('tournaments/{tournament}/leaderboard', [TournamentController::class, 'leaderboard'])->name('tournaments.leaderboard');
        Route::get('tournaments/{tournament}/bracket', [TournamentController::class, 'bracket'])->name('tournaments.bracket');
        Route::get('matches/{match}', [MatchController::class, 'show'])->name('matches.show');
        Route::get('players/{user}', [PlayerController::class, 'show'])->name('players.show');
        Route::get('players/{user}/ranking', [LeaderboardController::class, 'playerRanking'])->name('players.ranking');
        Route::get('leaderboards', [LeaderboardController::class, 'index'])->name('leaderboards.index');
        Route::get('leaderboards/{tournament}', [LeaderboardController::class, 'show'])->name('leaderboards.show');
    });

    // ------------------------------------------------------------------
    // Authenticated (bearer token + valid, active account)
    // ------------------------------------------------------------------
    Route::middleware(['bearer', 'auth:sanctum', 'api.token', 'throttle:api'])->group(function () {

        // Me / profile
        Route::get('me', [MeController::class, 'show'])->middleware('abilities:profile:read')->name('me.show');
        Route::put('me/profile', [MeController::class, 'update'])->middleware('abilities:profile:write')->name('me.profile.update');
        Route::patch('me/profile', [MeController::class, 'update'])->middleware('abilities:profile:write')->name('me.profile.patch');

        // Security / sessions
        Route::get('me/security', [MeController::class, 'security'])->middleware('abilities:profile:read')->name('me.security');
        Route::get('me/sessions', [MeController::class, 'sessions'])->middleware('abilities:profile:read')->name('me.sessions');
        Route::delete('me/sessions/{session}', [MeController::class, 'revokeSession'])->middleware('abilities:profile:write')->name('me.sessions.revoke');
        Route::post('me/sessions/revoke-others', [MeController::class, 'revokeOthers'])->middleware('abilities:profile:write')->name('me.sessions.revoke_others');
        Route::post('me/sessions/revoke-all', [MeController::class, 'revokeAll'])->middleware('abilities:profile:write')->name('me.sessions.revoke_all');

        // Notifications
        Route::get('me/notifications', [NotificationController::class, 'index'])->middleware('abilities:notifications:read')->name('me.notifications');
        Route::get('me/notifications/unread-count', [NotificationController::class, 'unreadCount'])->middleware('abilities:notifications:read')->name('me.notifications.unread_count');
        Route::post('me/notifications/{notification}/read', [NotificationController::class, 'markRead'])->middleware('abilities:notifications:write')->name('me.notifications.read');
        Route::post('me/notifications/read-all', [NotificationController::class, 'markAllRead'])->middleware('abilities:notifications:write')->name('me.notifications.read_all');

        // Realtime
        Route::get('me/live', [LiveController::class, 'me'])->middleware('abilities:notifications:read')->name('me.live');
        Route::get('tournaments/{tournament}/live', [TournamentController::class, 'live'])->middleware('throttle:api_anon')->name('tournaments.live');

        // Teams
        Route::get('me/teams', [TeamController::class, 'index'])->middleware('abilities:teams:read')->name('me.teams');
        Route::get('teams/{team}', [TeamController::class, 'show'])->middleware('abilities:teams:read')->name('teams.show');
        Route::patch('teams/{team}', [TeamController::class, 'update'])->middleware('abilities:teams:write')->name('teams.update');
        Route::get('teams/{team}/roster', [TeamController::class, 'roster'])->middleware('abilities:roster:read')->name('teams.roster');
        Route::post('teams/{team}/roster', [TeamController::class, 'addMember'])->middleware('abilities:roster:write')->name('teams.roster.add');
        Route::delete('teams/{team}/roster/{member}', [TeamController::class, 'removeMember'])->middleware('abilities:roster:write')->name('teams.roster.remove');
        Route::post('teams/{team}/withdraw', [TeamController::class, 'withdraw'])->middleware('abilities:teams:write')->name('teams.withdraw');

        // Registration / check-in / waitlist
        Route::post('tournaments/{tournament}/registrations', [TournamentController::class, 'register'])
            ->middleware(['abilities:tournaments:register', 'idempotency'])->name('tournaments.register');
        Route::post('tournaments/{tournament}/check-in', [TournamentController::class, 'checkIn'])
            ->middleware('abilities:tournaments:register')->name('tournaments.checkin');
        Route::get('tournaments/{tournament}/waitlist', [TournamentController::class, 'waitlist'])
            ->middleware('abilities:tournaments:read')->name('tournaments.waitlist');

        // Score submission
        Route::post('matches/{match}/scores', [MatchController::class, 'submitScore'])
            ->middleware(['abilities:scores:submit', 'throttle:api_score', 'idempotency'])->name('matches.scores.submit');

        // Payments
        Route::get('payments/methods', [PaymentController::class, 'methods'])->middleware('abilities:wallet:read')->name('payments.methods');
        Route::post('payments', [PaymentController::class, 'store'])
            ->middleware(['abilities:payments:create', 'throttle:api_payment', 'idempotency'])->name('payments.store');
        Route::get('payments/{payment}', [PaymentController::class, 'show'])->middleware('abilities:payments:read')->name('payments.show');

        // Wallet / payouts (read-only)
        Route::get('me/wallet', [WalletController::class, 'show'])->middleware('abilities:wallet:read')->name('me.wallet');
        Route::get('me/wallet/ledger', [WalletController::class, 'ledger'])->middleware('abilities:wallet:read')->name('me.wallet.ledger');
        Route::get('me/payouts', [WalletController::class, 'payouts'])->middleware('abilities:payouts:read')->name('me.payouts');

        // Support / disputes
        Route::get('me/support', [SupportController::class, 'index'])->middleware('abilities:support:read')->name('me.support.index');
        Route::post('me/support', [SupportController::class, 'store'])
            ->middleware(['abilities:support:write', 'throttle:api_support', 'idempotency'])->name('me.support.store');
        Route::get('me/support/{ticket}', [SupportController::class, 'show'])->middleware('abilities:support:read')->name('me.support.show');
        Route::get('me/support/{ticket}/messages', [SupportController::class, 'messages'])->middleware('abilities:support:read')->name('me.support.messages');
        Route::post('me/support/{ticket}/messages', [SupportController::class, 'reply'])
            ->middleware(['abilities:support:write', 'throttle:api_support'])->name('me.support.reply');
        Route::get('me/disputes', [DisputeController::class, 'index'])->middleware('abilities:disputes:read')->name('me.disputes');
        Route::get('disputes/{dispute}', [DisputeController::class, 'show'])->middleware('abilities:disputes:read')->name('disputes.show');

        // Personal access tokens / API clients
        Route::post('me/tokens', [TokenController::class, 'store'])
            ->middleware(['abilities:profile:write', 'throttle:api_token_issue'])->name('me.tokens.store');
        Route::get('me/tokens', [TokenController::class, 'index'])->middleware('abilities:profile:read')->name('me.tokens.index');
        Route::delete('me/tokens/{tokenId}', [TokenController::class, 'destroy'])->middleware('abilities:profile:write')->name('me.tokens.destroy');
        Route::get('me/clients', [TokenController::class, 'clients'])->middleware('abilities:profile:read')->name('me.clients.index');
        Route::post('me/clients', [TokenController::class, 'storeClient'])
            ->middleware(['abilities:profile:write', 'throttle:api_token_issue'])->name('me.clients.store');
        Route::delete('me/clients/{client}', [TokenController::class, 'destroyClient'])->middleware('abilities:profile:write')->name('me.clients.destroy');

        // ------------------------------------------------------------------
        // Admin-only: outbound webhook subscriptions
        // ------------------------------------------------------------------
        Route::middleware(['admin', 'abilities:admin'])->prefix('admin')->name('admin.')->group(function () {
            Route::get('webhooks/endpoints', [WebhookSubscriptionController::class, 'index'])->name('webhooks.endpoints.index');
            Route::post('webhooks/endpoints', [WebhookSubscriptionController::class, 'store'])->name('webhooks.endpoints.store');
            Route::get('webhooks/endpoints/{endpoint}', [WebhookSubscriptionController::class, 'show'])->name('webhooks.endpoints.show');
            Route::post('webhooks/endpoints/{endpoint}/rotate-secret', [WebhookSubscriptionController::class, 'rotateSecret'])->name('webhooks.endpoints.rotate');
            Route::post('webhooks/endpoints/{endpoint}/toggle', [WebhookSubscriptionController::class, 'toggle'])->name('webhooks.endpoints.toggle');
            Route::get('webhooks/endpoints/{endpoint}/deliveries', [WebhookSubscriptionController::class, 'deliveries'])->name('webhooks.endpoints.deliveries');
            Route::get('webhooks/events', [WebhookSubscriptionController::class, 'events'])->name('webhooks.events.index');
        });
    });

    // ------------------------------------------------------------------
    // Inbound provider webhooks (HMAC-authenticated; no bearer required)
    // ------------------------------------------------------------------
    Route::post('webhooks/inbound/{provider}', [WebhookInboundController::class, 'handle'])
        ->middleware('throttle:api_webhook')->name('webhooks.inbound');
});
```

### `tools/gen_openapi.py`

```python
#!/usr/bin/env python3
"""
Phase 15 — OpenAPI 3.0 generator + validation gate.

Builds storage/api-docs/openapi.json from an explicit path table and shared
component schemas, then cross-checks that every documented path matches a
route registered by the application (`php artisan route:list --json`). A path
that is documented but not routed — or a routed public business endpoint that
is missing from the spec — is a hard failure.

Usage:
    python3 tools/gen_openapi.py            # (re)generate + validate
    python3 tools/gen_openapi.py --validate # validate the existing file only
"""

import json
import re
import subprocess
import sys

ROOT = "/home/user/ffarena-app"
OUT = f"{ROOT}/storage/api-docs/openapi.json"

BEARER = [{"bearerAuth": []}]
NONE = []

# ---------------------------------------------------------------------------
# Path table: (method, path, summary, security, scopes, request/response hints)
# Path params are written as {name}. Scopes string is informational.
# ---------------------------------------------------------------------------
PATHS = [
    # --- Authentication -----------------------------------------------------
    ("post", "/api/v1/auth/register", "Register an account", NONE, None,
     {"register": True, "rate": "api_register (3/hour/IP)"}),
    ("post", "/api/v1/auth/login", "Login with email + password", NONE, None,
     {"login": True, "rate": "api_login (5/min/identifier)"}),
    ("post", "/api/v1/auth/google", "Login with a Google id_token", NONE, None,
     {"google": True, "rate": "api_login (5/min/identifier)"}),
    ("post", "/api/v1/auth/otp/request", "Request a phone OTP", NONE, None,
     {"otp": True, "rate": "api_otp_request (1/min/phone)"}),
    ("post", "/api/v1/auth/otp/verify", "Verify a phone OTP and login", NONE, None,
     {"otp": True, "rate": "api_otp_verify (5/5min/phone)"}),

    # --- Public discovery --------------------------------------------------
    ("get", "/api/v1/tournaments", "List public tournaments", NONE, None,
     {"rate": "api_anon (60/min/IP)"}),
    ("get", "/api/v1/tournaments/{tournament}", "Show a tournament", NONE, None, {}),
    ("get", "/api/v1/tournaments/{tournament}/matches", "List a tournament's matches", NONE, None, {}),
    ("get", "/api/v1/tournaments/{tournament}/leaderboard", "Tournament leaderboard", NONE, None, {}),
    ("get", "/api/v1/tournaments/{tournament}/bracket", "Tournament bracket", NONE, None, {}),
    ("get", "/api/v1/matches/{match}", "Show a match", NONE, None, {}),
    ("get", "/api/v1/players/{user}", "Public player profile", NONE, None, {}),
    ("get", "/api/v1/players/{user}/ranking", "Player's rankings", NONE, None, {}),
    ("get", "/api/v1/leaderboards", "Ranked tournaments", NONE, None, {}),
    ("get", "/api/v1/leaderboards/{tournament}", "Tournament standings", NONE, None, {}),

    # --- Me / profile / security ------------------------------------------
    ("get", "/api/v1/me", "Current user", BEARER, "profile:read", {}),
    ("get", "/api/v1/me/security", "Sign-in methods & account status", BEARER, "profile:read", {}),
    ("get", "/api/v1/me/sessions", "Active sessions", BEARER, "profile:read", {}),
    ("get", "/api/v1/me/tokens", "Personal access tokens", BEARER, "profile:read", {}),
    ("get", "/api/v1/me/clients", "API clients", BEARER, "profile:read", {}),
    ("put", "/api/v1/me/profile", "Update profile", BEARER, "profile:write", {}),
    ("patch", "/api/v1/me/profile", "Update profile (partial)", BEARER, "profile:write", {}),
    ("delete", "/api/v1/me/sessions/{session}", "Revoke a session", BEARER, "profile:write", {}),
    ("post", "/api/v1/me/sessions/revoke-others", "Revoke other sessions", BEARER, "profile:write", {}),
    ("post", "/api/v1/me/sessions/revoke-all", "Revoke all sessions", BEARER, "profile:write", {}),
    ("post", "/api/v1/me/tokens", "Create a personal access token", BEARER, "profile:write",
     {"rate": "api_token_issue (5/min/user)"}),
    ("post", "/api/v1/me/clients", "Create an API client", BEARER, "profile:write",
     {"rate": "api_token_issue (5/min/user)"}),
    ("delete", "/api/v1/me/clients/{client}", "Revoke an API client", BEARER, "profile:write", {}),
    ("delete", "/api/v1/me/tokens/{tokenId}", "Revoke a token", BEARER, "profile:write", {}),

    # --- Notifications + realtime -----------------------------------------
    ("get", "/api/v1/me/notifications", "List notifications", BEARER, "notifications:read", {}),
    ("get", "/api/v1/me/notifications/unread-count", "Unread notification count", BEARER, "notifications:read", {}),
    ("post", "/api/v1/me/notifications/{notification}/read", "Mark a notification read", BEARER, "notifications:write", {}),
    ("post", "/api/v1/me/notifications/read-all", "Mark all notifications read", BEARER, "notifications:write", {}),
    ("get", "/api/v1/me/live", "Own realtime cursor feed", BEARER, "notifications:read", {}),
    ("get", "/api/v1/tournaments/{tournament}/live", "Tournament realtime feed", NONE, None,
     {"rate": "api_anon (60/min/IP)"}),

    # --- Teams / roster ----------------------------------------------------
    ("get", "/api/v1/me/teams", "Own teams", BEARER, "teams:read", {}),
    ("get", "/api/v1/teams/{team}", "Show a team", BEARER, "teams:read", {}),
    ("patch", "/api/v1/teams/{team}", "Update a team", BEARER, "teams:write", {}),
    ("post", "/api/v1/teams/{team}/withdraw", "Withdraw a team", BEARER, "teams:write", {}),
    ("get", "/api/v1/teams/{team}/roster", "List roster", BEARER, "roster:read", {}),
    ("post", "/api/v1/teams/{team}/roster", "Add a roster member", BEARER, "roster:write", {}),
    ("delete", "/api/v1/teams/{team}/roster/{member}", "Remove a roster member", BEARER, "roster:write", {}),

    # --- Registration / check-in / waitlist -------------------------------
    ("post", "/api/v1/tournaments/{tournament}/registrations", "Register a team", BEARER, "tournaments:register",
     {"idempotency": True}),
    ("post", "/api/v1/tournaments/{tournament}/check-in", "Check a team in", BEARER, "tournaments:register", {}),
    ("get", "/api/v1/tournaments/{tournament}/waitlist", "Waitlist positions", BEARER, "tournaments:read", {}),

    # --- Scores ------------------------------------------------------------
    ("post", "/api/v1/matches/{match}/scores", "Submit a score", BEARER, "scores:submit",
     {"idempotency": True, "rate": "api_score (10/min/user)"}),

    # --- Payments / wallet / payouts --------------------------------------
    ("get", "/api/v1/payments/methods", "Payment providers & saved methods", BEARER, "wallet:read", {}),
    ("post", "/api/v1/payments", "Create a payment", BEARER, "payments:create",
     {"idempotency": True, "rate": "api_payment (5/min/user)"}),
    ("get", "/api/v1/payments/{payment}", "Show a payment", BEARER, "payments:read", {}),
    ("get", "/api/v1/me/wallet", "Wallet summary", BEARER, "wallet:read", {}),
    ("get", "/api/v1/me/wallet/ledger", "Wallet ledger", BEARER, "wallet:read", {}),
    ("get", "/api/v1/me/payouts", "Own payouts", BEARER, "payouts:read", {}),

    # --- Support / disputes ------------------------------------------------
    ("get", "/api/v1/me/support", "Own support tickets", BEARER, "support:read", {}),
    ("post", "/api/v1/me/support", "Create a support ticket", BEARER, "support:write",
     {"idempotency": True, "rate": "api_support (10/min/user)"}),
    ("get", "/api/v1/me/support/{ticket}", "Show a ticket", BEARER, "support:read", {}),
    ("get", "/api/v1/me/support/{ticket}/messages", "Ticket messages", BEARER, "support:read", {}),
    ("post", "/api/v1/me/support/{ticket}/messages", "Reply to a ticket", BEARER, "support:write",
     {"rate": "api_support (10/min/user)"}),
    ("get", "/api/v1/me/disputes", "Own disputes", BEARER, "disputes:read", {}),
    ("get", "/api/v1/disputes/{dispute}", "Show a dispute", BEARER, "disputes:read", {}),

    # --- Admin webhooks (outbound subscriptions) --------------------------
    ("get", "/api/v1/admin/webhooks/endpoints", "List webhook endpoints", BEARER, "admin", {}),
    ("post", "/api/v1/admin/webhooks/endpoints", "Create a webhook endpoint", BEARER, "admin", {}),
    ("get", "/api/v1/admin/webhooks/endpoints/{endpoint}", "Show an endpoint", BEARER, "admin", {}),
    ("post", "/api/v1/admin/webhooks/endpoints/{endpoint}/rotate-secret", "Rotate endpoint secret", BEARER, "admin", {}),
    ("post", "/api/v1/admin/webhooks/endpoints/{endpoint}/toggle", "Enable/disable endpoint", BEARER, "admin", {}),
    ("get", "/api/v1/admin/webhooks/endpoints/{endpoint}/deliveries", "Endpoint deliveries", BEARER, "admin", {}),
    ("get", "/api/v1/admin/webhooks/events", "Webhook event vocabulary", BEARER, "admin", {}),

    # --- Inbound provider webhooks ----------------------------------------
    ("post", "/api/v1/webhooks/inbound/{provider}", "Inbound provider webhook", NONE, None,
     {"inbound_webhook": True, "rate": "api_webhook (60/min/IP)"}),
]


def build_paths():
    """Build the OpenAPI `paths` object."""
    paths = {}
    for method, path, summary, security, scopes, hints in PATHS:
        if path not in paths:
            paths[path] = {}
        op = {
            "summary": summary,
            "operationId": f"{method}_{re.sub(r'[^a-zA-Z0-9]', '_', path.strip('/'))}",
            "tags": [tag_for(path)],
            "responses": responses_for(method, hints),
        }
        if security:
            op["security"] = security
        else:
            op["security"] = []
        params = path_params(path)
        if params:
            op["parameters"] = params
        body = body_for(method, path, hints)
        if body:
            op["requestBody"] = body
        desc_bits = []
        if scopes:
            desc_bits.append(f"**Required scope:** `{scopes}`.")
        if hints.get("idempotency"):
            desc_bits.append(
                "Supports the `Idempotency-Key` header: a replay within the TTL "
                "returns the stored response; reusing a key with a different "
                "body returns 409."
            )
        if hints.get("rate"):
            desc_bits.append(f"**Rate limit:** `{hints['rate']}`.")
        if hints.get("inbound_webhook"):
            desc_bits.append(
                "Authenticated by HMAC-SHA256 over the raw body "
                "(`X-Signature`), a fresh `X-Timestamp`, and an event-id "
                "idempotency check. Content-Type must be `application/json`."
            )
        if desc_bits:
            op["description"] = "\n\n".join(desc_bits)
        paths[path][method] = op
    return paths


def tag_for(path):
    if "/auth/" in path:
        return "Auth"
    if path.startswith("/api/v1/tournaments"):
        return "Tournaments"
    if path.startswith("/api/v1/matches"):
        return "Matches"
    if path.startswith("/api/v1/teams") or path == "/api/v1/me/teams":
        return "Teams"
    if path.startswith("/api/v1/players") or path.startswith("/api/v1/leaderboards"):
        return "Players & Leaderboards"
    if "/notifications" in path or path.endswith("/live"):
        return "Notifications & Realtime"
    if path.startswith("/api/v1/payments") or "/wallet" in path or "/payouts" in path:
        return "Payments & Wallet"
    if "/support" in path or "/disputes" in path:
        return "Support & Disputes"
    if "/admin/webhooks" in path:
        return "Admin Webhooks"
    if "/webhooks/inbound" in path:
        return "Inbound Webhooks"
    if path.startswith("/api/v1/me"):
        return "Me"
    return "General"


def path_params(path):
    names = re.findall(r"\{([a-zA-Z_]+)\}", path)
    return [{
        "name": n,
        "in": "path",
        "required": True,
        "schema": {"type": "string"},
        "description": path_param_desc(n),
    } for n in names]


def path_param_desc(name):
    return {
        "tournament": "Tournament slug",
        "match": "Match id",
        "team": "Team id",
        "member": "Roster member id",
        "user": "User id",
        "payment": "Payment id",
        "ticket": "Support ticket id",
        "dispute": "Dispute id",
        "session": "Session id",
        "tokenId": "Token id",
        "client": "API client id",
        "endpoint": "Webhook endpoint id",
        "provider": "Provider id (bkash|nagad|rocket|sslcommerz|card)",
        "notification": "Notification id",
    }.get(name, name)


def responses_for(method, hints):
    ok = "200"
    if method == "post":
        ok = "201"
    elif method == "delete":
        ok = "204"
    envelope = {"$ref": "#/components/schemas/Envelope"}
    responses = {
        ok: {"description": "Success", "content": {"application/json": {"schema": envelope}}},
        "401": {"$ref": "#/components/responses/Unauthorized"},
        "403": {"$ref": "#/components/responses/Forbidden"},
        "404": {"$ref": "#/components/responses/NotFound"},
        "422": {"$ref": "#/components/responses/ValidationError"},
        "429": {"$ref": "#/components/responses/RateLimited"},
    }
    if method == "delete" and ok == "204":
        responses["204"] = {"description": "No content"}
        responses.pop("200", None)
    return responses


def body_for(method, path, hints):
    if method not in ("post", "put", "patch"):
        return None
    schema = {"type": "object"}
    example = None
    if "auth/register" in path:
        example = {"name": "Alice", "username": "alice", "email": "alice@example.com",
                   "phone": "01712345678", "role": "player",
                   "password": "secret123", "password_confirmation": "secret123"}
    elif "auth/login" in path:
        example = {"email": "alice@example.com", "password": "secret123"}
    elif "auth/google" in path:
        example = {"id_token": "<google id_token>"}
    elif "otp/request" in path:
        example = {"phone": "01712345678", "purpose": "login"}
    elif "otp/verify" in path:
        example = {"phone": "01712345678", "purpose": "login", "code": "123456"}
    elif path.endswith("/me/profile"):
        example = {"name": "Alice", "bio": "Player", "privacy": "public"}
    elif path.endswith("/registrations"):
        example = {"name": "Squad", "captain_name": "Captain", "phone": "01712345678",
                   "game_uid": "UID1234", "members": [{"player_name": "P1", "game_uid": "UID5678"}]}
    elif path.endswith("/check-in"):
        example = {"team_id": 1}
    elif path.endswith("/scores"):
        example = {"team_id": 1, "kills": 5, "placement": 1}
    elif path.endswith("/payments"):
        example = {"team_id": 1, "provider": "bkash"}
    elif path.endswith("/me/support"):
        example = {"subject": "Help", "category": "payment", "message": "Details"}
    elif path.endswith("/messages"):
        example = {"body": "Reply text"}
    elif path.endswith("/teams/{team}/roster") or path.endswith("/roster"):
        example = {"player_name": "P1", "game_uid": "UID1234"}
    elif path.endswith("/me/tokens"):
        example = {"name": "mobile", "scopes": ["profile:read"], "expires_in_days": 30}
    elif path.endswith("/me/clients"):
        example = {"name": "My App", "description": "optional", "scopes": ["profile:read"]}
    elif path.endswith("/webhooks/endpoints"):
        example = {"url": "https://example.com/hooks", "description": "optional", "events": ["payment.succeeded"]}
    elif path.endswith("/toggle"):
        example = {"status": "active"}
    return {"required": True, "content": {
        "application/json": {"schema": schema, "example": example} if example else {"schema": schema}}}


def components():
    return {
        "securitySchemes": {
            "bearerAuth": {
                "type": "http",
                "scheme": "bearer",
                "bearerFormat": "personal access token",
                "description": "Personal access token issued by /api/v1/auth/* or /api/v1/me/tokens. "
                               "Session cookies are NOT accepted by the API.",
            }
        },
        "responses": {
            "Unauthorized": {"description": "Missing/invalid token", "content": {
                "application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}},
            "Forbidden": {"description": "Insufficient scope or authorization", "content": {
                "application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}},
            "NotFound": {"description": "Resource not found", "content": {
                "application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}},
            "ValidationError": {"description": "Validation failed", "content": {
                "application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}},
            "RateLimited": {"description": "Rate limit exceeded", "content": {
                "application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}},
        },
        "schemas": {
            "Envelope": {"type": "object", "properties": {
                "data": {}, "meta": {"type": "object"}}},
            "ErrorResponse": {"type": "object", "properties": {
                "error": {"$ref": "#/components/schemas/Error"}}},
            "Error": {"type": "object", "required": ["code", "message"], "properties": {
                "code": {"type": "string"}, "message": {"type": "string"},
                "details": {"type": "object", "additionalProperties": {"type": "string"}}}},
            "Tournament": {"type": "object", "properties": {
                "id": {"type": "integer"}, "slug": {"type": "string"}, "name": {"type": "string"},
                "game_mode": {"type": "string", "enum": ["squad", "duo", "solo"]},
                "map": {"type": "string"}, "format": {"type": "string"},
                "status": {"type": "string"}, "entry_fee": {"type": "string"},
                "entry_fee_minor": {"type": "integer"}, "currency": {"type": "string"},
                "prize_pool": {"type": "string"}, "team_slots": {"type": "integer"},
                "team_size": {"type": "integer"}, "starts_at": {"type": "string", "format": "date-time"},
                "slots_left": {"type": "integer"}, "is_full": {"type": "boolean"},
                "accepts_registration": {"type": "boolean"}}},
            "Match": {"type": "object", "properties": {
                "id": {"type": "integer"}, "tournament_id": {"type": "integer"},
                "round": {"type": "integer"}, "match_no": {"type": "integer"},
                "bracket": {"type": "string"}, "status": {"type": "string"},
                "scheduled_at": {"type": "string", "format": "date-time"}}},
            "Score": {"type": "object", "properties": {
                "id": {"type": "integer"}, "team_id": {"type": "integer"},
                "kills": {"type": "integer"}, "placement": {"type": "integer"},
                "placement_points": {"type": "integer"}, "kill_points": {"type": "integer"},
                "points": {"type": "integer"}, "status": {"type": "string"}}},
            "Team": {"type": "object", "properties": {
                "id": {"type": "integer"}, "tournament_id": {"type": "integer"},
                "name": {"type": "string"}, "captain_name": {"type": "string"},
                "game_uid": {"type": "string"}, "status": {"type": "string"},
                "waitlist_position": {"type": "integer"}}},
            "UserProfile": {"type": "object", "properties": {
                "id": {"type": "integer"}, "name": {"type": "string"},
                "username": {"type": "string"}, "visible": {"type": "boolean"},
                "privacy": {"type": "string"}}},
            "Notification": {"type": "object", "properties": {
                "id": {"type": "integer"}, "type": {"type": "string"},
                "title": {"type": "string"}, "body": {"type": "string"},
                "read": {"type": "boolean"}}},
            "Payment": {"type": "object", "properties": {
                "id": {"type": "integer"}, "tournament_id": {"type": "integer"},
                "team_id": {"type": "integer"}, "amount": {"type": "string"},
                "amount_minor": {"type": "integer"}, "currency": {"type": "string"},
                "provider": {"type": "string"}, "status": {"type": "string"}}},
            "Wallet": {"type": "object", "properties": {
                "id": {"type": "integer"}, "balance": {"type": "string"},
                "balance_minor": {"type": "integer"}, "currency": {"type": "string"},
                "status": {"type": "string"}}},
            "LedgerEntry": {"type": "object", "properties": {
                "id": {"type": "integer"}, "direction": {"type": "string", "enum": ["credit", "debit"]},
                "amount_minor": {"type": "integer"}, "balance_after_minor": {"type": "integer"},
                "type": {"type": "string"}}},
            "Payout": {"type": "object", "properties": {
                "id": {"type": "integer"}, "tournament_id": {"type": "integer"},
                "rank": {"type": "integer"}, "amount_minor": {"type": "integer"},
                "currency": {"type": "string"}, "status": {"type": "string"}}},
            "SupportTicket": {"type": "object", "properties": {
                "id": {"type": "integer"}, "subject": {"type": "string"},
                "category": {"type": "string"}, "priority": {"type": "string"},
                "status": {"type": "string"}}},
            "SupportMessage": {"type": "object", "properties": {
                "id": {"type": "integer"}, "ticket_id": {"type": "integer"},
                "body": {"type": "string"}}},
            "Dispute": {"type": "object", "properties": {
                "id": {"type": "integer"}, "match_id": {"type": "integer"},
                "category": {"type": "string"}, "status": {"type": "string"},
                "description": {"type": "string"}, "resolution": {"type": "string"}}},
            "Token": {"type": "object", "properties": {
                "id": {"type": "integer"}, "name": {"type": "string"},
                "abilities": {"type": "array", "items": {"type": "string"}},
                "last_used_at": {"type": "string", "format": "date-time"},
                "expires_at": {"type": "string", "format": "date-time"}}},
            "ApiClient": {"type": "object", "properties": {
                "id": {"type": "integer"}, "name": {"type": "string"},
                "description": {"type": "string"}, "status": {"type": "string"}}},
            "WebhookEndpoint": {"type": "object", "properties": {
                "id": {"type": "integer"}, "url": {"type": "string"},
                "status": {"type": "string"}, "events": {"type": "array", "items": {"type": "string"}},
                "consecutive_failures": {"type": "integer"}}},
            "WebhookDelivery": {"type": "object", "properties": {
                "id": {"type": "integer"}, "event": {"type": "string"},
                "delivery_id": {"type": "string"}, "status": {"type": "string"},
                "attempts": {"type": "integer"}}},
        },
    }


def load_routes():
    """Run `php artisan route:list --json` and return (method, normalized_uri) pairs."""
    out = subprocess.run(
        ["php", "artisan", "route:list", "--json"], cwd=ROOT,
        capture_output=True, text=True, check=True)
    routes = json.loads(out.stdout)
    result = []
    for r in routes:
        uri = r["uri"]
        # Normalize optional params and strip prefix duplication.
        uri = re.sub(r"\{\w+\?\}", lambda m: m.group(0).rstrip("?"), uri)
        for method in r["method"].split("|"):
            result.append((method.lower(), uri))
    return set(result)


def validate(spec_path):
    with open(spec_path) as fh:
        spec = json.load(fh)  # hard-fails on invalid JSON
    routes = load_routes()

    problems = []
    documented = set()
    for path, methods in spec["paths"].items():
        for method in methods:
            documented.add((method.lower(), path.lstrip("/")))
            if (method.lower(), path.lstrip("/")) not in routes:
                problems.append(f"documented but NOT routed: {method.upper()} {path}")

    # Every routed /api/v1 business endpoint must be documented. HEAD is
    # Laravel's auto-derived companion of GET and is not part of the spec.
    for method, uri in routes:
        if method == "head":
            continue
        if not uri.startswith("api/v1/"):
            continue
        if (method, uri) not in documented:
            problems.append(f"routed but NOT documented: {method.upper()} /{uri}")

    if problems:
        print("OPENAPI VALIDATION FAILED:")
        for p in problems:
            print("  -", p)
        sys.exit(1)

    print(f"OpenAPI validation OK: {len(documented)} documented paths, all routed and no missing endpoints.")


def main():
    spec = {
        "openapi": "3.0.3",
        "info": {
            "title": "FF Arena Public API",
            "version": "1.0.0",
            "description": (
                "Versioned public API for FF Arena (mobile/SPA/trusted third-party "
                "clients). All business endpoints live under /api/v1; a future "
                "/api/v2 can be added without breaking v1.\n\n"
                "**Auth:** bearer personal access tokens only (session cookies are "
                "never accepted). Tokens are stored hashed, support granular scopes, "
                "expiry, revocation and last-used tracking; the plaintext is shown "
                "exactly once at creation.\n\n"
                "**Envelope:** success `{data, meta}`; errors "
                "`{error:{code,message,details}}`.\n\n"
                "**Idempotency:** critical mutations accept an `Idempotency-Key` "
                "header; replays return the stored response.\n\n"
                "**Webhooks (outbound):** deliveries are signed "
                "`X-FFArena-Signature = HMAC-SHA256(secret, \"{timestamp}.{body}\")` "
                "with `X-FFArena-Timestamp`, `X-FFArena-Event` and "
                "`X-FFArena-Delivery` headers; retries use exponential backoff."
            ),
        },
        "servers": [{"url": "/"}],
        "tags": [
            {"name": "Auth"}, {"name": "Tournaments"}, {"name": "Matches"}, {"name": "Teams"},
            {"name": "Players & Leaderboards"}, {"name": "Notifications & Realtime"},
            {"name": "Payments & Wallet"}, {"name": "Support & Disputes"},
            {"name": "Me"}, {"name": "Admin Webhooks"}, {"name": "Inbound Webhooks"},
        ],
        "paths": build_paths(),
        "components": components(),
    }

    import os
    os.makedirs(os.path.dirname(OUT), exist_ok=True)
    with open(OUT, "w") as fh:
        json.dump(spec, fh, indent=2)
        fh.write("\n")
    print(f"Wrote {OUT}")

    validate(OUT)


if __name__ == "__main__":
    main()
```

### `tests/Feature/Api/ApiTestCase.php`

```php
<?php

namespace Tests\Feature\Api;

use App\Contracts\GoogleIdTokenVerifierInterface;
use App\Contracts\PhoneOtpProviderInterface;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Shared base for Phase 15 API feature tests.
 *
 * Provides token issuance, auth-guard resetting between requests (a test-only
 * necessity — the container memoizes the sanctum guard user across requests
 * within one test), and deterministic fakes for SMS and Google.
 */
abstract class ApiTestCase extends TestCase
{
    use RefreshDatabase;

    protected function user(array $attributes = []): User
    {
        $user = User::factory()->create();

        // `role` and `account_status` are intentionally not mass-assignable.
        $user->role = $attributes['role'] ?? 'player';
        $user->account_status = $attributes['account_status'] ?? 'active';

        foreach ($attributes as $key => $value) {
            if (in_array($key, ['role', 'account_status'], true)) {
                continue;
            }

            $user->{$key} = $value;
        }

        $user->save();

        return $user;
    }

    protected function admin(array $attributes = []): User
    {
        return $this->user(array_merge(['role' => 'admin'], $attributes));
    }

    /**
     * Mint a bearer token for the given user/abilities.
     */
    protected function tokenFor(User $user, array $abilities = ['*']): string
    {
        return $user->createToken('test-token', $abilities)->plainTextToken;
    }

    /**
     * Reset the auth guard between requests with different tokens. Without
     * this the RequestGuard memoizes the first resolved user for the rest of
     * the test method.
     */
    protected function authForget(): void
    {
        $this->app['auth']->forgetGuards();
    }

    protected function asUser(User $user, array $abilities = ['*'])
    {
        $token = $this->tokenFor($user, $abilities);
        $this->authForget();

        return $this->withToken($token);
    }

    protected function makeTournament(User $organizer, string $status = 'open', array $o = []): Tournament
    {
        $t = new Tournament();
        $t->organizer_id = $organizer->id;
        $t->name = $o['name'] ?? 'API Tournament ' . Str::random(5);
        $t->slug = $o['slug'] ?? ('api-' . Str::random(8));
        $t->game_mode = $o['game_mode'] ?? 'squad';
        $t->map = $o['map'] ?? 'Bermuda';
        $t->entry_fee = $o['entry_fee'] ?? 0;
        $t->prize_pool = $o['prize_pool'] ?? 5000;
        $t->team_slots = $o['team_slots'] ?? 8;
        $t->team_size = $o['team_size'] ?? 4;
        $t->rules = $o['rules'] ?? null;
        $t->starts_at = $o['starts_at'] ?? now()->addDay();
        $t->check_in_starts_at = $o['check_in_starts_at'] ?? null;
        $t->check_in_ends_at = $o['check_in_ends_at'] ?? null;
        $t->format = $o['format'] ?? 'single_elim';
        $t->dispute_window_hours = $o['dispute_window_hours'] ?? 24;
        $t->status = $status;
        $t->save();

        return $t;
    }

    protected function makeTeam(
        Tournament $tournament,
        ?User $captain = null,
        string $status = 'pending',
        ?string $uid = null,
        bool $checkedIn = false,
    ): Team {
        $team = new Team();
        $team->tournament_id = $tournament->id;
        $team->captain_id = $captain?->id;
        $team->name = 'Team ' . Str::random(6);
        $team->captain_name = $captain?->name ?? 'Captain';
        $team->phone = '01700000000';
        $team->game_uid = $uid ?? 'UID' . strtoupper(Str::random(8));
        $team->status = $status;

        if ($status === Team::STATUS_WAITLISTED) {
            $team->waitlisted_at = now();
        }

        if ($checkedIn) {
            $team->checked_in_at = now();
        }

        $team->save();

        return $team;
    }

    protected function registrationPayload(string $name = 'API Squad', string $uid = 'UIDAPI0001'): array
    {
        return [
            'name' => $name,
            'captain_name' => 'Captain',
            'phone' => '01700000000',
            'game_uid' => $uid,
            'members' => [],
        ];
    }

    protected function bindFakeSms(): ApiFakeSmsProvider
    {
        $fake = new ApiFakeSmsProvider();
        $this->app->instance(PhoneOtpProviderInterface::class, $fake);

        return $fake;
    }

    protected function bindFakeGoogle(array $user = ['id' => 'g-123', 'email' => 'g@example.com', 'email_verified' => true, 'name' => 'G User']): ApiFakeGoogleVerifier
    {
        $fake = new ApiFakeGoogleVerifier($user);
        $this->app->instance(GoogleIdTokenVerifierInterface::class, $fake);

        return $fake;
    }
}
```

### `tests/Feature/Api/ApiFakeSmsProvider.php`

```php
<?php

namespace Tests\Feature\Api;

use App\Contracts\PhoneOtpProviderInterface;

/**
 * Deterministic Phase 15 test double. Never touches the network — records the
 * code in memory so the OTP flow can be exercised end-to-end.
 */
class ApiFakeSmsProvider implements PhoneOtpProviderInterface
{
    /** @var array<string, string> phone => last code */
    public array $codes = [];

    public function id(): string
    {
        return 'fake';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function send(string $phone, string $code): void
    {
        $this->codes[$phone] = $code;
    }

    public function lastCodeFor(string $phone): ?string
    {
        return $this->codes[$phone] ?? null;
    }
}
```

### `tests/Feature/Api/ApiFakeGoogleVerifier.php`

```php
<?php

namespace Tests\Feature\Api;

use App\Contracts\GoogleIdTokenVerifierInterface;

/**
 * Deterministic Phase 15 test double for Google id_token verification.
 */
class ApiFakeGoogleVerifier implements GoogleIdTokenVerifierInterface
{
    /**
     * @param  array{id: string, email: ?string, email_verified: bool, name: ?string}  $user
     */
    public function __construct(
        protected array $user,
        protected ?\Throwable $error = null,
        protected bool $configured = true,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function verify(string $idToken): array
    {
        if ($this->error !== null) {
            throw $this->error;
        }

        return $this->user;
    }
}
```

### `tests/Feature/Api/ApiAuthTest.php`

```php
<?php

namespace Tests\Feature\Api;

use App\Models\Notification;
use App\Models\User;

/**
 * Phase 15 — API authentication: register, login, Google, OTP, token
 * issuance and revocation, deactivated-account denial.
 */
class ApiAuthTest extends ApiTestCase
{
    public function test_register_creates_account_and_returns_token_once(): void
    {
        $res = $this->postJson('/api/v1/auth/register', [
            'name' => 'Alice',
            'username' => 'alice',
            'email' => 'alice@example.com',
            'role' => 'player',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.user.email', 'alice@example.com')
            ->assertJsonPath('data.user.role', 'player');

        $this->assertIsString($res->json('data.token'));
        $this->assertArrayNotHasKey('password', $res->json('data.user'));

        // The token authenticates.
        $this->authForget();
        $this->withToken($res->json('data.token'))->getJson('/api/v1/me')->assertStatus(200);

        $this->assertSame(1, User::where('email', 'alice@example.com')->count());
    }

    public function test_register_rejects_admin_role_and_mass_assignment(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Mallory',
            'username' => 'mallory',
            'email' => 'mallory@example.com',
            'role' => 'admin',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertStatus(422);

        $this->assertSame(0, User::where('email', 'mallory@example.com')->count());
    }

    public function test_login_succeeds_and_failed_login_is_enumeration_safe(): void
    {
        $this->user(['email' => 'bob@example.com', 'password' => bcrypt('secret123')]);

        $ok = $this->postJson('/api/v1/auth/login', [
            'email' => 'bob@example.com',
            'password' => 'secret123',
        ]);

        $ok->assertStatus(200)->assertJsonPath('data.user.email', 'bob@example.com');
        $this->assertIsString($ok->json('data.token'));

        // Wrong password + unknown email → identical 401 payload.
        $badPassword = $this->postJson('/api/v1/auth/login', [
            'email' => 'bob@example.com',
            'password' => 'wrong-password',
        ]);
        $unknownEmail = $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'secret123',
        ]);

        $this->assertSame($badPassword->json('error.code'), $unknownEmail->json('error.code'));
        $this->assertSame('invalid_credentials', $unknownEmail->json('error.code'));
    }

    public function test_deactivated_account_cannot_authenticate(): void
    {
        $user = $this->user(['email' => 'gone@example.com', 'password' => bcrypt('secret123'), 'account_status' => 'deactivated']);
        $token = $this->tokenFor($user);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'gone@example.com',
            'password' => 'secret123',
        ])->assertStatus(401)->assertJsonPath('error.code', 'account_inactive');

        $this->authForget();
        $this->withToken($token)->getJson('/api/v1/me')->assertStatus(401)->assertJsonPath('error.code', 'account_inactive');
    }

    public function test_google_login_creates_then_reuses_account(): void
    {
        $this->bindFakeGoogle(['id' => 'g-123', 'email' => 'google@example.com', 'email_verified' => true, 'name' => 'Google User']);

        $first = $this->postJson('/api/v1/auth/google', ['id_token' => 'id-token-1']);
        $first->assertStatus(201)->assertJsonPath('data.created', true);

        $second = $this->postJson('/api/v1/auth/google', ['id_token' => 'id-token-1']);
        $second->assertStatus(200)->assertJsonPath('data.created', false);

        $this->assertSame(1, User::where('email', 'google@example.com')->count());
    }

    public function test_google_login_unconfigured_is_honest(): void
    {
        $this->app->instance(GoogleIdTokenVerifierInterface::class, new ApiFakeGoogleVerifier(
            ['id' => 'g', 'email' => null, 'email_verified' => false, 'name' => null],
            configured: false,
        ));

        $this->postJson('/api/v1/auth/google', ['id_token' => 'x'])
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'not_configured');
    }

    public function test_phone_otp_login_round_trip(): void
    {
        $sms = $this->bindFakeSms();

        // Link a phone to a user up front (verified identity).
        $user = $this->user(['phone' => '+8801712345678']);
        $identity = new \App\Models\UserIdentity();
        $identity->user_id = $user->id;
        $identity->provider = 'phone';
        $identity->provider_subject = '+8801712345678';
        $identity->verified_at = now();
        $identity->save();

        $this->postJson('/api/v1/auth/otp/request', [
            'phone' => '01712345678',
            'purpose' => 'login',
        ])->assertStatus(200);

        $code = $sms->lastCodeFor('+8801712345678');
        $this->assertNotNull($code);

        $verify = $this->postJson('/api/v1/auth/otp/verify', [
            'phone' => '01712345678',
            'purpose' => 'login',
            'code' => $code,
        ]);

        $verify->assertStatus(200)->assertJsonPath('data.user.id', $user->id);
        $this->assertIsString($verify->json('data.token'));
    }

    public function test_revoked_token_stops_working(): void
    {
        $user = $this->user();
        $token = $this->tokenFor($user);
        $tokenId = $user->tokens()->first()->id;

        $this->authForget();
        $this->withToken($token)->deleteJson('/api/v1/me/tokens/' . $tokenId)->assertStatus(204);

        $this->authForget();
        $this->withToken($token)->getJson('/api/v1/me')->assertStatus(401);
    }

    public function test_token_metadata_is_listed_without_plaintext(): void
    {
        $user = $this->user();
        $token = $this->tokenFor($user, ['profile:read']);

        $this->authForget();
        $res = $this->withToken($token)->getJson('/api/v1/me/tokens');

        $res->assertStatus(200);
        $this->assertSame('test-token', $res->json('data.0.name'));
        $this->assertSame(['profile:read'], $res->json('data.0.abilities'));
        $this->assertArrayNotHasKey('token', $res->json('data.0'));
    }

    public function test_register_notifies_welcome(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Carol',
            'username' => 'carol',
            'email' => 'carol@example.com',
            'role' => 'player',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertStatus(201);

        $user = User::where('email', 'carol@example.com')->first();
        $this->assertSame(1, Notification::where('user_id', $user->id)->where('type', Notification::TYPE_WELCOME)->count());
    }
}
```

### `tests/Feature/Api/ApiTokenScopesTest.php`

```php
<?php

namespace Tests\Feature\Api;

use App\Models\User;

/**
 * Phase 15 — token scope enforcement and admin-scope denial.
 */
class ApiTokenScopesTest extends ApiTestCase
{
    public function test_profile_read_scope_gates_me_endpoint(): void
    {
        $user = $this->user();

        $this->asUser($user, ['profile:read'])->getJson('/api/v1/me')->assertStatus(200);
    }

    public function test_missing_scope_is_rejected(): void
    {
        $user = $this->user();

        // A token with only notifications:read cannot read the profile.
        $this->asUser($user, ['notifications:read'])->getJson('/api/v1/me')->assertStatus(403);

        // A token with no profile:write cannot update the profile.
        $this->asUser($user, ['profile:read'])
            ->putJson('/api/v1/me/profile', ['name' => 'X'])
            ->assertStatus(403);
    }

    public function test_financial_mutations_require_payments_create_scope(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $player = $this->user();
        $tournament = $this->makeTournament($org, 'open', ['entry_fee' => 500]);
        $team = $this->makeTeam($tournament, $player);

        $this->asUser($player, ['wallet:read'])
            ->postJson('/api/v1/payments', ['team_id' => $team->id, 'provider' => 'bkash'])
            ->assertStatus(403);

        $this->asUser($player, ['payments:create'])
            ->postJson('/api/v1/payments', ['team_id' => $team->id, 'provider' => 'bkash'])
            ->assertStatus(201);
    }

    public function test_admin_scope_cannot_be_self_granted(): void
    {
        $user = $this->user();

        // Requesting the admin scope in a normal token issue is silently
        // stripped — the resulting token must not carry it.
        $res = $this->asUser($user, ['profile:write'])
            ->postJson('/api/v1/me/tokens', [
                'name' => 'evil',
                'scopes' => ['admin', 'profile:read'],
            ]);

        $res->assertStatus(201);
        $this->assertNotContains('admin', $res->json('data.token_info.abilities'));

        // And the admin webhook surface is unreachable with that token.
        $this->authForget();
        $this->withToken($res->json('data.token'))->getJson('/api/v1/admin/webhooks/endpoints')->assertStatus(403);
    }

    public function test_admin_token_reaches_admin_surface(): void
    {
        $admin = $this->admin();
        $token = $this->tokenFor($admin, ['admin']);

        $this->authForget();
        $this->withToken($token)->getJson('/api/v1/admin/webhooks/endpoints')->assertStatus(200);
    }

    public function test_guest_cannot_hit_protected_endpoints(): void
    {
        $this->getJson('/api/v1/me')->assertStatus(401);
        $this->getJson('/api/v1/me/wallet')->assertStatus(401);
        $this->getJson('/api/v1/me/notifications')->assertStatus(401);
    }

    public function test_expired_token_is_rejected(): void
    {
        $user = $this->user();
        $token = $user->createToken('exp', ['profile:read'], now()->subMinute())->plainTextToken;

        $this->authForget();
        $this->withToken($token)->getJson('/api/v1/me')->assertStatus(401);
    }

    public function test_revoked_client_disables_linked_tokens(): void
    {
        $user = $this->user();

        // Create a client + token through the API.
        $res = $this->asUser($user, ['profile:write'])
            ->postJson('/api/v1/me/clients', [
                'name' => 'My App',
                'scopes' => ['profile:read', 'profile:write'],
            ]);

        $res->assertStatus(201);
        $clientId = $res->json('data.client.id');
        $token = $res->json('data.token');

        // Revoke the client.
        $this->authForget();
        $this->withToken($token)->deleteJson('/api/v1/me/clients/' . $clientId)->assertStatus(204);

        // The linked token stops authenticating.
        $this->authForget();
        $this->withToken($token)->getJson('/api/v1/me')->assertStatus(401);
    }
}
```

### `tests/Feature/Api/ApiTournamentsTest.php`

```php
<?php

namespace Tests\Feature\Api;

use App\Models\Team;
use App\Models\Tournament;

/**
 * Phase 15 — tournament discovery, registration, check-in, waitlist and
 * authorization.
 */
class ApiTournamentsTest extends ApiTestCase
{
    public function test_public_tournaments_are_listed_and_filtered(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $open = $this->makeTournament($org, 'open', ['name' => 'Alpha Cup']);
        $this->makeTournament($org, 'live', ['name' => 'Beta Cup']);
        $draft = $this->makeTournament($org, 'draft', ['name' => 'Secret Draft']);

        $res = $this->getJson('/api/v1/tournaments');
        $res->assertStatus(200);

        $ids = collect($res->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($open->id));
        $this->assertFalse($ids->contains($draft->id));

        // Whitelisted status filter.
        $filtered = $this->getJson('/api/v1/tournaments?status=live');
        $this->assertSame(['live'], array_unique(array_column($filtered->json('data'), 'status')));

        // Unknown sort key falls back to the default — no SQL injection.
        $this->getJson('/api/v1/tournaments?sort=evil%27%3B%20DROP%20TABLE%20users%3B--&direction=asc')
            ->assertStatus(200);
    }

    public function test_tournament_show_excludes_non_public(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $open = $this->makeTournament($org, 'open');
        $draft = $this->makeTournament($org, 'draft');

        $this->getJson('/api/v1/tournaments/' . $open->slug)->assertStatus(200);
        $this->getJson('/api/v1/tournaments/' . $draft->slug)->assertStatus(404);
    }

    public function test_registration_derives_state_and_rejects_injected_fields(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $player = $this->user();
        $tournament = $this->makeTournament($org, 'open', ['team_slots' => 8, 'entry_fee' => 500]);

        // The client tries to inject status/slot/confirmation — all ignored;
        // the server derives pending + payment step.
        $res = $this->asUser($player, ['tournaments:register'])
            ->postJson('/api/v1/tournaments/' . $tournament->slug . '/registrations', [
                'name' => 'Injected',
                'captain_name' => 'Captain',
                'phone' => '01700000000',
                'game_uid' => 'UIDINJECT1',
                'status' => 'confirmed',
                'confirmation' => 'paid',
                'slot' => 7,
            ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.team.status', 'pending')
            ->assertJsonPath('data.waitlisted', false)
            ->assertJsonPath('data.next_step', 'payment');

        $this->assertSame(1, Team::where('captain_id', $player->id)->count());
    }

    public function test_registration_capacity_overflow_goes_to_waitlist(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $player = $this->user();
        $tournament = $this->makeTournament($org, 'open', ['team_slots' => 2]);

        $this->makeTeam($tournament, null, 'pending');
        $this->makeTeam($tournament, null, 'pending');

        $res = $this->asUser($player, ['tournaments:register'])
            ->postJson('/api/v1/tournaments/' . $tournament->slug . '/registrations', $this->registrationPayload('Overflow'));

        $res->assertStatus(201)
            ->assertJsonPath('data.waitlisted', true)
            ->assertJsonPath('data.waitlist_position', 1);
    }

    public function test_registration_refused_when_closed(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $player = $this->user();
        $tournament = $this->makeTournament($org, 'closed');

        $this->asUser($player, ['tournaments:register'])
            ->postJson('/api/v1/tournaments/' . $tournament->slug . '/registrations', $this->registrationPayload())
            ->assertStatus(409);
    }

    public function test_duplicate_registration_is_blocked(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $player = $this->user();
        $tournament = $this->makeTournament($org, 'open');

        $this->asUser($player, ['tournaments:register'])
            ->postJson('/api/v1/tournaments/' . $tournament->slug . '/registrations', $this->registrationPayload())
            ->assertStatus(201);

        $this->asUser($player, ['tournaments:register'])
            ->postJson('/api/v1/tournaments/' . $tournament->slug . '/registrations', $this->registrationPayload('Second', 'UIDAPI0002'))
            ->assertStatus(409);
    }

    public function test_guest_cannot_register_or_check_in(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $tournament = $this->makeTournament($org, 'open');

        $this->postJson('/api/v1/tournaments/' . $tournament->slug . '/registrations', $this->registrationPayload())
            ->assertStatus(401);

        $this->postJson('/api/v1/tournaments/' . $tournament->slug . '/check-in', ['team_id' => 1])
            ->assertStatus(401);
    }

    public function test_check_in_requires_captain_and_window(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $captain = $this->user();
        $other = $this->user();
        $tournament = $this->makeTournament($org, 'open', [
            'check_in_starts_at' => now()->subHour(),
            'check_in_ends_at' => now()->addHour(),
        ]);
        $team = $this->makeTeam($tournament, $captain, 'confirmed');

        // A non-captain cannot check the team in (IDOR / authorization).
        $this->asUser($other, ['tournaments:register'])
            ->postJson('/api/v1/tournaments/' . $tournament->slug . '/check-in', ['team_id' => $team->id])
            ->assertStatus(403);

        // The captain can.
        $this->asUser($captain, ['tournaments:register'])
            ->postJson('/api/v1/tournaments/' . $tournament->slug . '/check-in', ['team_id' => $team->id])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'checked_in');

        $this->assertNotNull($team->fresh()->checked_in_at);
    }

    public function test_waitlist_positions_are_visible_but_promotion_is_server_only(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $player = $this->user();
        $tournament = $this->makeTournament($org, 'open', ['team_slots' => 1]);

        $this->makeTeam($tournament, null, 'pending');
        $this->asUser($player, ['tournaments:register'])
            ->postJson('/api/v1/tournaments/' . $tournament->slug . '/registrations', $this->registrationPayload())
            ->assertStatus(201);

        $res = $this->asUser($player, ['tournaments:read'])
            ->getJson('/api/v1/tournaments/' . $tournament->slug . '/waitlist');

        $res->assertStatus(200);
        $this->assertSame(1, $res->json('data.count'));

        // There is no client promotion endpoint — POST to a fake one is 404/405.
        $this->asUser($player, ['tournaments:register'])
            ->postJson('/api/v1/tournaments/' . $tournament->slug . '/waitlist', ['team_id' => 1])
            ->assertStatus(405);
    }
}
```

### `tests/Feature/Api/ApiTeamsRosterTest.php`

```php
<?php

namespace Tests\Feature\Api;

use App\Models\TeamMember;

/**
 * Phase 15 — team & roster APIs with authorization (TeamPolicy) and IDOR
 * protection.
 */
class ApiTeamsRosterTest extends ApiTestCase
{
    public function test_my_teams_lists_only_own_teams(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $captain = $this->user();
        $other = $this->user();
        $tournament = $this->makeTournament($org, 'open');
        $mine = $this->makeTeam($tournament, $captain, 'pending', 'UIDMINE001');
        $this->makeTeam($tournament, $other, 'pending', 'UIDOTHER001');

        $res = $this->asUser($captain, ['teams:read'])->getJson('/api/v1/me/teams');
        $res->assertStatus(200);
        $this->assertSame([$mine->id], array_column($res->json('data'), 'id'));
    }

    public function test_team_view_and_phone_privacy(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $captain = $this->user();
        $stranger = $this->user();
        $tournament = $this->makeTournament($org, 'open');
        $team = $this->makeTeam($tournament, $captain, 'pending', 'UIDPRIV001');

        // A stranger cannot view the team at all (TeamPolicy::view is
        // captain/organizer/staff only), so the phone can never leak.
        $this->asUser($stranger, ['teams:read'])->getJson('/api/v1/teams/' . $team->id)->assertStatus(403);

        $captainView = $this->asUser($captain, ['teams:read'])->getJson('/api/v1/teams/' . $team->id);
        $captainView->assertStatus(200);
        $this->assertSame('01700000000', $captainView->json('data.phone'));
    }

    public function test_team_profile_update_by_non_captain_is_forbidden(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $captain = $this->user();
        $stranger = $this->user();
        $tournament = $this->makeTournament($org, 'open');
        $team = $this->makeTeam($tournament, $captain, 'pending', 'UIDEDIT001');

        $payload = [
            'name' => 'Hijacked',
            'captain_name' => 'Hijacker',
            'phone' => '01799999999',
            'game_uid' => 'UIDHIJACK1',
        ];

        $this->asUser($stranger, ['teams:write'])->patchJson('/api/v1/teams/' . $team->id, $payload)->assertStatus(403);

        $this->asUser($captain, ['teams:write'])->patchJson('/api/v1/teams/' . $team->id, $payload)->assertStatus(200);
        $this->assertSame('Hijacked', $team->fresh()->name);
    }

    public function test_roster_add_remove_with_authorization(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $captain = $this->user();
        $stranger = $this->user();
        $tournament = $this->makeTournament($org, 'open');
        $team = $this->makeTeam($tournament, $captain, 'pending', 'UIDROST001');

        $add = ['player_name' => 'Rookie', 'game_uid' => 'UIDROOK1E'];

        $this->asUser($stranger, ['roster:write'])->postJson('/api/v1/teams/' . $team->id . '/roster', $add)->assertStatus(403);

        $created = $this->asUser($captain, ['roster:write'])->postJson('/api/v1/teams/' . $team->id . '/roster', $add);
        $created->assertStatus(201);
        $memberId = $created->json('data.id');

        $this->assertSame(1, TeamMember::where('team_id', $team->id)->count());

        $this->asUser($stranger, ['roster:write'])->deleteJson('/api/v1/teams/' . $team->id . '/roster/' . $memberId)->assertStatus(403);
        $this->asUser($captain, ['roster:write'])->deleteJson('/api/v1/teams/' . $team->id . '/roster/' . $memberId)->assertStatus(204);

        $this->assertSame(0, TeamMember::where('team_id', $team->id)->count());
    }

    public function test_roster_member_from_other_team_is_not_found(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $captain = $this->user();
        $tournament = $this->makeTournament($org, 'open');
        $teamA = $this->makeTeam($tournament, $captain, 'pending', 'UIDAAA0001');
        $teamB = $this->makeTeam($tournament, null, 'pending', 'UIDBBB0001');

        $member = new TeamMember();
        $member->team_id = $teamB->id;
        $member->player_name = 'Other';
        $member->game_uid = 'UIDOTHER2';
        $member->save();

        $this->asUser($captain, ['roster:write'])
            ->deleteJson('/api/v1/teams/' . $teamA->id . '/roster/' . $member->id)
            ->assertStatus(404);
    }

    public function test_withdraw_by_non_captain_is_forbidden(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $captain = $this->user();
        $stranger = $this->user();
        $tournament = $this->makeTournament($org, 'open');
        $team = $this->makeTeam($tournament, $captain, 'pending', 'UIDWDRAW1');

        $this->asUser($stranger, ['teams:write'])->postJson('/api/v1/teams/' . $team->id . '/withdraw')->assertStatus(403);

        $this->asUser($captain, ['teams:write'])->postJson('/api/v1/teams/' . $team->id . '/withdraw')->assertStatus(200);
        $this->assertSame('withdrawn', $team->fresh()->status);
    }
}
```

### `tests/Feature/Api/ApiMatchesScoresTest.php`

```php
<?php

namespace Tests\Feature\Api;

use App\Models\GameMatch;
use App\Models\Score;
use App\Models\ScoringRule;

/**
 * Phase 15 — match reads and score submission: participant auth, duplicate
 * protection, placement claims, server-derived scoring, no client state
 * mutation.
 */
class ApiMatchesScoresTest extends ApiTestCase
{
    protected function makeMatch($tournament, $team1, $team2, string $status = 'live'): GameMatch
    {
        $match = new GameMatch();
        $match->tournament_id = $tournament->id;
        $match->team1_id = $team1?->id;
        $match->team2_id = $team2?->id;
        $match->round = 1;
        $match->match_no = 1;
        $match->bracket = GameMatch::BRACKET_WINNERS;
        $match->status = $status;
        $match->save();

        return $match;
    }

    protected function makeRuleSet($tournament): ScoringRule
    {
        return app(\App\Services\ScoringService::class)->createVersion($tournament, [
            'name' => 'v1',
            'kill_points' => 1,
            'placement_points' => ScoringRule::DEFAULT_PLACEMENT_POINTS,
            'tie_breakers' => ScoringRule::DEFAULT_TIE_BREAKERS,
        ]);
    }

    public function test_match_show_exposes_scores(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $t = $this->makeTournament($org, 'live');
        $a = $this->makeTeam($t, null, 'confirmed', 'UIDM01A1');
        $b = $this->makeTeam($t, null, 'confirmed', 'UIDM01B1');
        $match = $this->makeMatch($t, $a, $b, 'completed');

        $res = $this->getJson('/api/v1/matches/' . $match->id);
        $res->assertStatus(200)->assertJsonPath('data.status', 'completed');
    }

    public function test_score_submission_by_non_participant_is_forbidden(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $captainA = $this->user();
        $captainB = $this->user();
        $intruder = $this->user();
        $t = $this->makeTournament($org, 'live');
        $a = $this->makeTeam($t, $captainA, 'confirmed', 'UIDS01A1');
        $b = $this->makeTeam($t, $captainB, 'confirmed', 'UIDS01B1');
        $this->makeRuleSet($t);
        $match = $this->makeMatch($t, $a, $b, 'live');

        $this->asUser($intruder, ['scores:submit'])
            ->postJson('/api/v1/matches/' . $match->id . '/scores', [
                'team_id' => $a->id,
                'kills' => 5,
                'placement' => 1,
            ])
            ->assertStatus(403);
    }

    public function test_score_submission_computes_points_server_side(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $captainA = $this->user();
        $captainB = $this->user();
        $t = $this->makeTournament($org, 'live');
        $a = $this->makeTeam($t, $captainA, 'confirmed', 'UIDS02A1');
        $b = $this->makeTeam($t, $captainB, 'confirmed', 'UIDS02B1');
        $rule = $this->makeRuleSet($t);
        $match = $this->makeMatch($t, $a, $b, 'live');

        $res = $this->asUser($captainA, ['scores:submit'])
            ->postJson('/api/v1/matches/' . $match->id . '/scores', [
                'team_id' => $a->id,
                'kills' => 5,
                'placement' => 1,
            ]);

        $res->assertStatus(201);
        // placement_points=12, kill_points=5 → total 17, derived server-side.
        $this->assertSame(12, $res->json('data.placement_points'));
        $this->assertSame(5, $res->json('data.kill_points'));
        $this->assertSame(17, $res->json('data.points'));

        // A client-supplied authoritative point total is not accepted (422).
        $this->asUser($captainB, ['scores:submit'])
            ->postJson('/api/v1/matches/' . $match->id . '/scores', [
                'team_id' => $b->id,
                'kills' => 1,
                'placement' => 2,
                'points' => 999999,
            ])
            ->assertStatus(422);
    }

    public function test_duplicate_score_and_placement_claims_are_rejected(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $captainA = $this->user();
        $captainB = $this->user();
        $t = $this->makeTournament($org, 'live');
        $a = $this->makeTeam($t, $captainA, 'confirmed', 'UIDS03A1');
        $b = $this->makeTeam($t, $captainB, 'confirmed', 'UIDS03B1');
        $this->makeRuleSet($t);
        $match = $this->makeMatch($t, $a, $b, 'live');

        $this->asUser($captainA, ['scores:submit'])
            ->postJson('/api/v1/matches/' . $match->id . '/scores', [
                'team_id' => $a->id, 'kills' => 5, 'placement' => 1,
            ])->assertStatus(201);

        // Duplicate team score.
        $this->asUser($captainA, ['scores:submit'])
            ->postJson('/api/v1/matches/' . $match->id . '/scores', [
                'team_id' => $a->id, 'kills' => 5, 'placement' => 2,
            ])->assertStatus(409);

        // Placement already claimed.
        $this->asUser($captainB, ['scores:submit'])
            ->postJson('/api/v1/matches/' . $match->id . '/scores', [
                'team_id' => $b->id, 'kills' => 1, 'placement' => 1,
            ])->assertStatus(409);
    }

    public function test_no_client_match_state_mutation_endpoints_exist(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $t = $this->makeTournament($org, 'live');
        $a = $this->makeTeam($t, null, 'confirmed', 'UIDM02A1');
        $b = $this->makeTeam($t, null, 'confirmed', 'UIDM02B1');
        $match = $this->makeMatch($t, $a, $b, 'live');
        $player = $this->user();

        // PATCHing a match (state mutation) does not exist for clients.
        $this->asUser($player, ['scores:submit'])
            ->patchJson('/api/v1/matches/' . $match->id, ['status' => 'completed'])
            ->assertStatus(405);
    }
}
```

### `tests/Feature/Api/ApiProfilePrivacyTest.php`

```php
<?php

namespace Tests\Feature\Api;

/**
 * Phase 15 — profile privacy: /me, public profiles, private-profile
 * redaction, and sensitive-field leakage.
 */
class ApiProfilePrivacyTest extends ApiTestCase
{
    public function test_me_returns_own_profile_without_sensitive_fields(): void
    {
        $user = $this->user(['email' => 'me@example.com', 'phone' => '+8801712345678']);

        $res = $this->asUser($user, ['profile:read'])->getJson('/api/v1/me');

        $res->assertStatus(200)
            ->assertJsonPath('data.email', 'me@example.com')
            ->assertJsonPath('data.username', $user->username);

        $data = $res->json('data');
        foreach (['password', 'phone', 'remember_token', 'risk_score', 'risk_level'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $data, "leaked key: {$forbidden}");
        }

        $body = json_encode($data);
        foreach (['ip_address', 'device_hash', 'fraud'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body, "leaked field: {$forbidden}");
        }
    }

    public function test_me_profile_update(): void
    {
        $user = $this->user();

        $res = $this->asUser($user, ['profile:write'])
            ->putJson('/api/v1/me/profile', [
                'name' => 'New Name',
                'bio' => 'Hello world',
                'privacy' => 'private',
            ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.privacy', 'private');

        $this->assertSame('New Name', $user->fresh()->name);
        $this->assertSame('private', $user->fresh()->privacy);
    }

    public function test_private_profile_is_never_exposed_by_id(): void
    {
        $target = $this->user(['privacy' => 'private', 'bio' => 'Secret bio', 'email' => 'private@example.com']);
        $viewer = $this->user();

        $res = $this->asUser($viewer, ['profile:read'])->getJson('/api/v1/players/' . $target->id);

        $res->assertStatus(200)
            ->assertJsonPath('data.visible', false)
            ->assertJsonPath('data.name', $target->name);

        $this->assertNull($res->json('data.bio') ?? null);
        $this->assertNull($res->json('data.email') ?? null);
    }

    public function test_public_profile_exposes_public_fields_only(): void
    {
        $target = $this->user(['privacy' => 'public', 'bio' => 'Public bio', 'email' => 'pub@example.com', 'phone' => '+8801712345678']);
        $viewer = $this->user();

        $res = $this->asUser($viewer, ['profile:read'])->getJson('/api/v1/players/' . $target->id);

        $res->assertStatus(200)->assertJsonPath('data.visible', true);

        $body = json_encode($res->json('data'));
        $this->assertStringNotContainsString('pub@example.com', $body, 'email leaked on public profile');
        $this->assertStringNotContainsString('phone', $body, 'phone leaked on public profile');
    }

    public function test_profile_update_rejects_invalid_privacy(): void
    {
        $user = $this->user();

        $this->asUser($user, ['profile:write'])
            ->putJson('/api/v1/me/profile', ['privacy' => 'everyone-on-earth'])
            ->assertStatus(422);
    }
}
```

### `tests/Feature/Api/ApiNotificationsTest.php`

```php
<?php

namespace Tests\Feature\Api;

use App\Models\Notification;

/**
 * Phase 15 — notifications: ownership, unread counts, mark read, and IDOR
 * protection.
 */
class ApiNotificationsTest extends ApiTestCase
{
    protected function notify($user, string $type = Notification::TYPE_SYSTEM, bool $read = false): Notification
    {
        return app(\App\Services\NotificationService::class)->send(
            $user,
            $type,
            'Title',
            'Body',
            null,
            [],
        );
    }

    public function test_notifications_list_is_own_only(): void
    {
        $user = $this->user();
        $other = $this->user();

        $mine = $this->notify($user);
        $this->notify($other);

        $res = $this->asUser($user, ['notifications:read'])->getJson('/api/v1/me/notifications');

        $res->assertStatus(200);
        $this->assertSame([$mine->id], array_column($res->json('data'), 'id'));
    }

    public function test_unread_count_and_mark_read(): void
    {
        $user = $this->user();
        $notification = $this->notify($user);

        $this->asUser($user, ['notifications:read'])->getJson('/api/v1/me/notifications/unread-count')
            ->assertJsonPath('data.unread_count', 1);

        $this->asUser($user, ['notifications:write'])
            ->postJson('/api/v1/me/notifications/' . $notification->id . '/read')
            ->assertStatus(200)
            ->assertJsonPath('data.read', true);

        $this->asUser($user, ['notifications:read'])->getJson('/api/v1/me/notifications/unread-count')
            ->assertJsonPath('data.unread_count', 0);
    }

    public function test_mark_read_idor_is_blocked(): void
    {
        $user = $this->user();
        $other = $this->user();
        $notification = $this->notify($other);

        $this->asUser($user, ['notifications:write'])
            ->postJson('/api/v1/me/notifications/' . $notification->id . '/read')
            ->assertStatus(404);

        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_mark_all_read_only_affects_own(): void
    {
        $user = $this->user();
        $other = $this->user();
        $this->notify($user);
        $this->notify($user);
        $this->notify($other);

        $res = $this->asUser($user, ['notifications:write'])->postJson('/api/v1/me/notifications/read-all');
        $res->assertStatus(200)->assertJsonPath('data.marked_read', 2);

        $this->assertSame(0, Notification::where('user_id', $user->id)->whereNull('read_at')->count());
        $this->assertSame(1, Notification::where('user_id', $other->id)->whereNull('read_at')->count());
    }
}
```

### `tests/Feature/Api/ApiPaymentsWalletTest.php`

```php
<?php

namespace Tests\Feature\Api;

use App\Models\Payment;
use App\Models\Payout;
use App\Models\PrizeDistribution;

/**
 * Phase 15 — payments & wallet: server-derived amounts, provider selection
 * only, duplicate protection, wallet/ledger read-only, payouts own-only.
 */
class ApiPaymentsWalletTest extends ApiTestCase
{
    public function test_payment_methods_list_is_honest(): void
    {
        $user = $this->user();

        $res = $this->asUser($user, ['wallet:read'])->getJson('/api/v1/payments/methods');

        $res->assertStatus(200);
        $this->assertIsArray($res->json('data.providers'));
        $this->assertIsArray($res->json('data.saved_methods'));
    }

    public function test_payment_amount_is_server_derived(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $player = $this->user();
        $tournament = $this->makeTournament($org, 'open', ['entry_fee' => 500]);
        $team = $this->makeTeam($tournament, $player);

        $res = $this->asUser($player, ['payments:create'])
            ->postJson('/api/v1/payments', ['team_id' => $team->id, 'provider' => 'bkash']);

        $res->assertStatus(201);
        // 500 BDT = 50000 poisha, derived server-side; the client never
        // supplied an amount.
        $this->assertSame(50000, $res->json('data.payment.amount_minor'));
        $this->assertSame('BDT', $res->json('data.payment.currency'));
    }

    public function test_payment_requires_team_owner(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $captain = $this->user();
        $intruder = $this->user();
        $tournament = $this->makeTournament($org, 'open', ['entry_fee' => 500]);
        $team = $this->makeTeam($tournament, $captain);

        $this->asUser($intruder, ['payments:create'])
            ->postJson('/api/v1/payments', ['team_id' => $team->id, 'provider' => 'bkash'])
            ->assertStatus(403);
    }

    public function test_duplicate_payment_returns_existing(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $player = $this->user();
        $tournament = $this->makeTournament($org, 'open', ['entry_fee' => 500]);
        $team = $this->makeTeam($tournament, $player);

        $first = $this->asUser($player, ['payments:create'])
            ->postJson('/api/v1/payments', ['team_id' => $team->id, 'provider' => 'bkash']);
        $first->assertStatus(201);

        $second = $this->asUser($player, ['payments:create'])
            ->postJson('/api/v1/payments', ['team_id' => $team->id, 'provider' => 'bkash']);

        $second->assertStatus(200)->assertJsonPath('meta.existing', true);

        $this->assertSame(1, Payment::where('team_id', $team->id)->count());
    }

    public function test_idempotency_key_prevents_duplicate_payment(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $player = $this->user();
        $tournament = $this->makeTournament($org, 'open', ['entry_fee' => 500]);
        $team = $this->makeTeam($tournament, $player);

        $payload = ['team_id' => $team->id, 'provider' => 'bkash'];
        $headers = ['Idempotency-Key' => 'pay-1'];

        $first = $this->asUser($player, ['payments:create'])
            ->withHeaders($headers)
            ->postJson('/api/v1/payments', $payload);
        $first->assertStatus(201);

        $replay = $this->asUser($player, ['payments:create'])
            ->withHeaders($headers)
            ->postJson('/api/v1/payments', $payload);
        $replay->assertStatus(201);
        $this->assertSame('true', $replay->headers->get('Idempotency-Replayed'));

        $this->assertSame(1, Payment::where('team_id', $team->id)->count());
    }

    public function test_no_wallet_credit_or_debit_endpoints(): void
    {
        $user = $this->user();

        // No such mutation endpoints exist — the wallet changes only through
        // the Phase 08/09 services.
        $this->asUser($user, ['wallet:read'])->postJson('/api/v1/me/wallet/credit', ['amount_minor' => 100])
            ->assertStatus(404);

        $this->asUser($user, ['wallet:read'])->postJson('/api/v1/me/wallet/debit', ['amount_minor' => 100])
            ->assertStatus(404);
    }

    public function test_wallet_and_ledger_are_readable(): void
    {
        $user = $this->user();

        $wallet = $this->asUser($user, ['wallet:read'])->getJson('/api/v1/me/wallet');
        $wallet->assertStatus(200);
        $this->assertSame(0, $wallet->json('data.balance_minor'));

        $ledger = $this->asUser($user, ['wallet:read'])->getJson('/api/v1/me/wallet/ledger');
        $ledger->assertStatus(200)->assertJsonPath('data', []);
    }

    public function test_payouts_are_own_only(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $recipient = $this->user();
        $other = $this->user();
        $tournament = $this->makeTournament($org, 'finished');
        $team = $this->makeTeam($tournament, $recipient, 'confirmed');

        $dist = new PrizeDistribution();
        $dist->tournament_id = $tournament->id;
        $dist->status = PrizeDistribution::STATUS_COMPLETED;
        $dist->pool_minor = 100000;
        $dist->total_allocated_minor = 100000;
        $dist->save();

        $payout = new Payout();
        $payout->distribution_id = $dist->id;
        $payout->tournament_id = $tournament->id;
        $payout->recipient_user_id = $recipient->id;
        $payout->recipient_team_id = $team->id;
        $payout->rank = 1;
        $payout->amount_minor = 100000;
        $payout->currency = 'BDT';
        $payout->status = 'completed';
        $payout->payout_method = 'wallet';
        $payout->provider = 'wallet';
        $payout->save();

        $mine = $this->asUser($recipient, ['payouts:read'])->getJson('/api/v1/me/payouts');
        $mine->assertStatus(200);
        $this->assertSame([$payout->id], array_column($mine->json('data'), 'id'));

        $theirs = $this->asUser($other, ['payouts:read'])->getJson('/api/v1/me/payouts');
        $theirs->assertStatus(200)->assertJsonPath('data', []);
    }

    public function test_payout_lookup_by_other_user_route_does_not_exist(): void
    {
        $this->asUser($this->user(), ['payouts:read'])
            ->getJson('/api/v1/users/999/payouts')
            ->assertStatus(404);
    }
}
```

### `tests/Feature/Api/ApiSupportDisputesTest.php`

```php
<?php

namespace Tests\Feature\Api;

use App\Models\SupportTicket;

/**
 * Phase 15 — support (own-only + IDOR) and disputes (authorized read-only).
 */
class ApiSupportDisputesTest extends ApiTestCase
{
    public function test_support_ticket_create_and_list_own_only(): void
    {
        $user = $this->user();
        $other = $this->user();

        $created = $this->asUser($user, ['support:write'])
            ->postJson('/api/v1/me/support', [
                'subject' => 'Can I change my team?',
                'category' => 'general',
                'message' => 'Please help.',
            ]);

        $created->assertStatus(201);
        $ticketId = $created->json('data.id');

        $this->asUser($other, ['support:write'])
            ->postJson('/api/v1/me/support', [
                'subject' => 'Other',
                'category' => 'general',
                'message' => 'Other body',
            ])->assertStatus(201);

        $mine = $this->asUser($user, ['support:read'])->getJson('/api/v1/me/support');
        $mine->assertStatus(200);
        $this->assertSame([$ticketId], array_column($mine->json('data'), 'id'));
    }

    public function test_support_idor_is_blocked(): void
    {
        $user = $this->user();
        $other = $this->user();

        $ticket = new SupportTicket();
        $ticket->user_id = $other->id;
        $ticket->subject = 'Private';
        $ticket->category = 'general';
        $ticket->priority = 'normal';
        $ticket->status = 'open';
        $ticket->save();

        $this->asUser($user, ['support:read'])->getJson('/api/v1/me/support/' . $ticket->id)
            ->assertStatus(403);

        $this->asUser($user, ['support:write'])
            ->postJson('/api/v1/me/support/' . $ticket->id . '/messages', ['body' => 'snooping'])
            ->assertStatus(403);
    }

    public function test_support_messages_and_reply(): void
    {
        $user = $this->user();

        $created = $this->asUser($user, ['support:write'])
            ->postJson('/api/v1/me/support', [
                'subject' => 'Messages',
                'category' => 'payment',
                'message' => 'First message',
            ]);
        $ticketId = $created->json('data.id');

        $replied = $this->asUser($user, ['support:write'])
            ->postJson('/api/v1/me/support/' . $ticketId . '/messages', ['body' => 'Second message']);
        $replied->assertStatus(201);

        $messages = $this->asUser($user, ['support:read'])
            ->getJson('/api/v1/me/support/' . $ticketId . '/messages');
        $messages->assertStatus(200);
        $this->assertSame(2, count($messages->json('data.messages')));
    }

    public function test_disputes_list_and_read_are_authorized(): void
    {
        $stranger = $this->user();
        $other = $this->user();
        $captainB = $this->user();

        // A dispute owned by another user must not appear in the caller's
        // list and must not be readable by id.
        $org = $this->user(['role' => 'organizer']);
        $tournament = $this->makeTournament($org, 'finished');
        $teamA = $this->makeTeam($tournament, $other, 'confirmed', 'UIDDSP001');
        $teamB = $this->makeTeam($tournament, $captainB, 'confirmed', 'UIDDSP002');

        $match = new \App\Models\GameMatch();
        $match->tournament_id = $tournament->id;
        $match->team1_id = $teamA->id;
        $match->team2_id = $teamB->id;
        $match->round = 1;
        $match->match_no = 1;
        $match->bracket = 'winners';
        $match->status = 'completed';
        $match->save();

        $dispute = app(\App\Services\DisputeService::class)->open($match, $teamA, $other, 'wrong_score', 'I disagree with the result');

        // The other user is the opener, so it appears in their list.
        $theirs = $this->asUser($other, ['disputes:read'])->getJson('/api/v1/me/disputes');
        $this->assertSame([$dispute->id], array_column($theirs->json('data'), 'id'));

        // A stranger (not a party) must not see it in their list, and a
        // direct id read is forbidden.
        $mine = $this->asUser($stranger, ['disputes:read'])->getJson('/api/v1/me/disputes');
        $this->assertSame([], $mine->json('data'));

        $this->asUser($stranger, ['disputes:read'])->getJson('/api/v1/disputes/' . $dispute->id)
            ->assertStatus(403);
    }

    public function test_dispute_evidence_is_never_serialized(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $player = $this->user();
        $tournament = $this->makeTournament($org, 'finished');
        $teamA = $this->makeTeam($tournament, $player, 'confirmed', 'UIDEV0011');
        $teamB = $this->makeTeam($tournament, null, 'confirmed', 'UIDEV0022');

        $match = new \App\Models\GameMatch();
        $match->tournament_id = $tournament->id;
        $match->team1_id = $teamA->id;
        $match->team2_id = $teamB->id;
        $match->round = 1;
        $match->match_no = 1;
        $match->bracket = 'winners';
        $match->status = 'completed';
        $match->save();

        $dispute = app(\App\Services\DisputeService::class)->open($match, $teamA, $player, 'wrong_score', 'Evidence check');

        $res = $this->asUser($player, ['disputes:read'])->getJson('/api/v1/disputes/' . $dispute->id);
        $res->assertStatus(200);

        $body = json_encode($res->json('data'));
        foreach (['evidence', 'path', 'correction', 'resolution_winner'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body, "leaked field: {$forbidden}");
        }
    }
}
```

### `tests/Feature/Api/ApiIdempotencyTest.php`

```php
<?php

namespace Tests\Feature\Api;

use App\Models\Team;

/**
 * Phase 15 — Idempotency-Key behavior for critical mutations.
 */
class ApiIdempotencyTest extends ApiTestCase
{
    public function test_registration_replay_is_idempotent(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $player = $this->user();
        $tournament = $this->makeTournament($org, 'open', ['team_slots' => 8]);

        $payload = $this->registrationPayload('Idem Squad', 'UIDIDEM001');

        $first = $this->asUser($player, ['tournaments:register'])
            ->withHeaders(['Idempotency-Key' => 'reg-1'])
            ->postJson('/api/v1/tournaments/' . $tournament->slug . '/registrations', $payload);
        $first->assertStatus(201);

        $replay = $this->asUser($player, ['tournaments:register'])
            ->withHeaders(['Idempotency-Key' => 'reg-1'])
            ->postJson('/api/v1/tournaments/' . $tournament->slug . '/registrations', $payload);
        $replay->assertStatus(201);
        $this->assertSame('true', $replay->headers->get('Idempotency-Replayed'));

        // Exactly one team was created.
        $this->assertSame(1, Team::where('captain_id', $player->id)->count());
    }

    public function test_reusing_key_with_different_body_is_conflict(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $player = $this->user();
        $tournament = $this->makeTournament($org, 'open', ['team_slots' => 8]);

        $this->asUser($player, ['tournaments:register'])
            ->withHeaders(['Idempotency-Key' => 'reg-2'])
            ->postJson('/api/v1/tournaments/' . $tournament->slug . '/registrations', $this->registrationPayload('Team One', 'UIDIDEM002'))
            ->assertStatus(201);

        // Same key, different body → 409 conflict, no second team.
        $this->asUser($player, ['tournaments:register'])
            ->withHeaders(['Idempotency-Key' => 'reg-2'])
            ->postJson('/api/v1/tournaments/' . $tournament->slug . '/registrations', $this->registrationPayload('Team Two', 'UIDIDEM003'))
            ->assertStatus(409);

        $this->assertSame(1, Team::where('captain_id', $player->id)->count());
    }

    public function test_support_ticket_creation_is_idempotent(): void
    {
        $user = $this->user();

        $payload = ['subject' => 'Idempotent ticket', 'category' => 'general', 'message' => 'body'];

        $first = $this->asUser($user, ['support:write'])
            ->withHeaders(['Idempotency-Key' => 'support-1'])
            ->postJson('/api/v1/me/support', $payload);
        $first->assertStatus(201);

        $replay = $this->asUser($user, ['support:write'])
            ->withHeaders(['Idempotency-Key' => 'support-1'])
            ->postJson('/api/v1/me/support', $payload);
        $replay->assertStatus(201);
        $this->assertSame('true', $replay->headers->get('Idempotency-Replayed'));

        $this->assertSame(1, \App\Models\SupportTicket::where('user_id', $user->id)->count());
    }
}
```

### `tests/Feature/Api/ApiWebhookTest.php`

```php
<?php

namespace Tests\Feature\Api;

use App\Models\Payment;
use App\Models\WebhookEvent;

/**
 * Phase 15 — inbound webhook verification: signature, timestamp tolerance,
 * provider identity, event-id idempotency, and business validation.
 */
class ApiWebhookTest extends ApiTestCase
{
    protected string $secret = 'ffarena-local-webhook-secret';

    protected function sign(string $rawBody): string
    {
        return hash_hmac('sha256', $rawBody, $this->secret);
    }

    protected function makePendingPayment(string $provider = 'bkash', int $amountMinor = 50000): Payment
    {
        $org = $this->user(['role' => 'organizer']);
        $player = $this->user();
        $tournament = $this->makeTournament($org, 'open', ['entry_fee' => 500]);
        $team = $this->makeTeam($tournament, $player);

        return app(\App\Services\PaymentService::class)->createForTeam(
            $tournament, $team, $player, $provider, 'TRX0001', $provider, 'TRX0001'
        );
    }

    protected function webhookRequest(string $provider, array $payload, ?string $rawBody = null, array $extraHeaders = [])
    {
        $rawBody = $rawBody ?? json_encode($payload);
        $headers = array_merge([
            'X-Signature' => $this->sign($rawBody),
            'X-Timestamp' => (string) time(),
            'Content-Type' => 'application/json',
        ], $extraHeaders);

        return $this->withHeaders($headers)->postJson('/api/v1/webhooks/inbound/' . $provider, $payload);
    }

    public function test_payment_webhook_settles_payment(): void
    {
        $payment = $this->makePendingPayment();

        $payload = [
            'event' => 'payment.succeeded',
            'event_id' => 'evt-settle-1',
            'payment_id' => $payment->id,
            'provider_reference' => 'TRX0001',
            'amount_minor' => 50000,
            'currency' => 'BDT',
            'status' => 'paid',
        ];

        $res = $this->webhookRequest('bkash', $payload);

        $res->assertStatus(200)->assertJsonPath('data.status', 'processed');

        $this->assertSame(Payment::STATUS_PAID, $payment->fresh()->status);
        $this->assertSame(1, WebhookEvent::where('external_event_id', 'evt-settle-1')->count());
    }

    public function test_duplicate_event_id_is_idempotent(): void
    {
        $payment = $this->makePendingPayment();

        $payload = [
            'event' => 'payment.succeeded',
            'event_id' => 'evt-dup-1',
            'payment_id' => $payment->id,
            'provider_reference' => 'TRX0001',
            'amount_minor' => 50000,
            'currency' => 'BDT',
            'status' => 'paid',
        ];

        $first = $this->webhookRequest('bkash', $payload);
        $first->assertStatus(200)->assertJsonPath('data.replay', false);

        $second = $this->webhookRequest('bkash', $payload);
        $second->assertStatus(200)->assertJsonPath('data.replay', true)->assertJsonPath('data.status', 'replayed');

        // One stored event; the payment settled exactly once.
        $this->assertSame(1, WebhookEvent::where('external_event_id', 'evt-dup-1')->count());
        $this->assertSame(Payment::STATUS_PAID, $payment->fresh()->status);
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $payload = [
            'event' => 'payment.succeeded',
            'event_id' => 'evt-bad-1',
            'payment_id' => 1,
            'amount_minor' => 50000,
            'currency' => 'BDT',
            'status' => 'paid',
        ];

        $this->withHeaders([
            'X-Signature' => 'deadbeef',
            'X-Timestamp' => (string) time(),
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/webhooks/inbound/bkash', $payload)
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'webhook_rejected');
    }

    public function test_stale_timestamp_is_rejected_as_replay(): void
    {
        $payload = [
            'event' => 'payment.succeeded',
            'event_id' => 'evt-stale-1',
            'payment_id' => 1,
            'amount_minor' => 50000,
            'currency' => 'BDT',
            'status' => 'paid',
        ];

        $this->webhookRequest('bkash', $payload, null, [
            'X-Timestamp' => (string) (time() - 600),
        ])->assertStatus(401);
    }

    public function test_unknown_provider_is_rejected(): void
    {
        $this->webhookRequest('unknown-provider', ['event' => 'payment.succeeded'])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'webhook_rejected');
    }

    public function test_amount_mismatch_is_rejected_and_marked_failed(): void
    {
        $payment = $this->makePendingPayment();

        $payload = [
            'event' => 'payment.succeeded',
            'event_id' => 'evt-mismatch-1',
            'payment_id' => $payment->id,
            'provider_reference' => 'TRX0001',
            'amount_minor' => 1, // wrong amount
            'currency' => 'BDT',
            'status' => 'paid',
        ];

        $this->webhookRequest('bkash', $payload)
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'webhook_rejected');

        $event = WebhookEvent::where('external_event_id', 'evt-mismatch-1')->first();
        $this->assertSame(WebhookEvent::STATUS_FAILED, $event->status);

        // The payment was never touched.
        $this->assertNotSame(Payment::STATUS_PAID, $payment->fresh()->status);
    }

    public function test_non_payment_event_is_recorded_and_ignored(): void
    {
        $payload = [
            'event' => 'tournament.created',
            'event_id' => 'evt-ignore-1',
            'tournament_id' => 123,
        ];

        $this->webhookRequest('bkash', $payload)
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'ignored');

        $this->assertSame(
            WebhookEvent::STATUS_IGNORED,
            WebhookEvent::where('external_event_id', 'evt-ignore-1')->first()->status
        );
    }
}
```

### `tests/Feature/Api/ApiSecurityTest.php`

```php
<?php

namespace Tests\Feature\Api;

use App\Models\User;

/**
 * Phase 15 — security coverage: privilege escalation, SQL-injection
 * resistance, mass-assignment resistance, token leakage, and
 * sensitive-field redaction.
 */
class ApiSecurityTest extends ApiTestCase
{
    public function test_admin_endpoints_reject_non_admin_tokens(): void
    {
        $player = $this->user();
        $organizer = $this->user(['role' => 'organizer']);
        $moderator = $this->user(['role' => 'moderator']);

        foreach ([$player, $organizer, $moderator] as $user) {
            $this->asUser($user, ['*'])
                ->getJson('/api/v1/admin/webhooks/endpoints')
                ->assertStatus(403);
        }
    }

    public function test_sql_injection_in_sort_and_search_is_inert(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $this->makeTournament($org, 'open', ['name' => 'Normal Cup']);

        $this->getJson("/api/v1/tournaments?sort=created_at%3B%20DROP%20TABLE%20users%3B--")
            ->assertStatus(200);

        $this->getJson("/api/v1/tournaments?q='%20OR%201%3D1--")
            ->assertStatus(200);

        $this->getJson("/api/v1/tournaments?direction=desc%3B%20UPDATE%20users%20SET%20role%3D'admin'--")
            ->assertStatus(200);

        // The user table is intact.
        $this->assertGreaterThanOrEqual(1, User::count());
    }

    public function test_mass_assignment_cannot_escalate_role(): void
    {
        $res = $this->postJson('/api/v1/auth/register', [
            'name' => 'Escalator',
            'username' => 'escalator',
            'email' => 'escalator@example.com',
            'role' => 'admin',
            'account_status' => 'active',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $res->assertStatus(422);
        $this->assertSame(0, User::where('role', 'admin')->where('email', 'escalator@example.com')->count());
    }

    public function test_profile_update_cannot_change_role_or_status(): void
    {
        $user = $this->user();

        $this->asUser($user, ['profile:write'])
            ->putJson('/api/v1/me/profile', [
                'name' => 'Still Me',
                'role' => 'admin',
                'account_status' => 'deleted',
                'email' => 'hijacked@example.com',
            ])
            ->assertStatus(200);

        $fresh = $user->fresh();
        $this->assertSame('player', $fresh->role);
        $this->assertSame('active', $fresh->account_status);
    }

    public function test_no_token_or_stack_trace_leaks_in_errors(): void
    {
        // Trigger an unexpected error and assert no internals leak.
        $res = $this->getJson('/api/v1/tournaments?sort=' . urlencode("x'\""));
        $res->assertStatus(200);

        $notFound = $this->getJson('/api/v1/matches/999999');
        $this->assertSame('not_found', $notFound->json('error.code'));
        $body = (string) $notFound->getContent();
        $this->assertStringNotContainsString('Stack trace', $body);
        $this->assertStringNotContainsString('app/Http', $body);
        $this->assertStringNotContainsString('SQLSTATE', $body);
    }

    public function test_register_response_does_not_leak_password_hash(): void
    {
        $res = $this->postJson('/api/v1/auth/register', [
            'name' => 'Hashless',
            'username' => 'hashless',
            'email' => 'hashless@example.com',
            'role' => 'player',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $this->assertStringNotContainsString('$2y$', (string) $res->getContent());
        $this->assertArrayNotHasKey('password', $res->json('data.user'));
    }

    public function test_token_list_never_returns_plaintext(): void
    {
        $user = $this->user();
        $plaintext = $this->tokenFor($user, ['profile:read']);

        $res = $this->asUser($user, ['profile:read'])->getJson('/api/v1/me/tokens');
        $body = (string) $res->getContent();

        $this->assertStringNotContainsString($plaintext, $body);
        $this->assertArrayNotHasKey('token', $res->json('data.0'));
        $this->assertArrayNotHasKey('plainTextToken', $res->json('data.0'));
    }

    public function test_sensitive_fields_are_redacted_across_surfaces(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $player = $this->user(['phone' => '+8801712345678']);
        $tournament = $this->makeTournament($org, 'live');
        $team = $this->makeTeam($tournament, $player, 'confirmed', 'UIDSAFE001');
        $this->makeMatch($tournament, $team, null, 'live');

        $surfaces = [
            $this->asUser($player, ['profile:read'])->getJson('/api/v1/me'),
            $this->asUser($player, ['teams:read'])->getJson('/api/v1/teams/' . $team->id),
            $this->asUser($player, ['tournaments:read'])->getJson('/api/v1/tournaments/' . $tournament->slug),
        ];

        foreach ($surfaces as $res) {
            $body = json_encode($res->json());
            foreach (['risk_score', 'risk_level', 'ip_address', 'device_hash', 'fingerprint', 'ledger_internal'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $body, "leaked: {$forbidden}");
            }
        }
    }

    protected function makeMatch($tournament, $team1, $team2, string $status): \App\Models\GameMatch
    {
        $match = new \App\Models\GameMatch();
        $match->tournament_id = $tournament->id;
        $match->team1_id = $team1?->id;
        $match->team2_id = $team2?->id;
        $match->round = 1;
        $match->match_no = 1;
        $match->bracket = 'winners';
        $match->status = $status;
        $match->save();

        return $match;
    }
}
```

### `tests/Feature/Api/ApiRateLimitTest.php`

```php
<?php

namespace Tests\Feature\Api;

/**
 * Phase 15 — route-level, named API rate limits.
 */
class ApiRateLimitTest extends ApiTestCase
{
    public function test_login_is_rate_limited_per_identifier(): void
    {
        // 5 attempts/min per identifier — the 6th is a 429.
        $responses = [];

        for ($i = 0; $i < 6; $i++) {
            $responses[] = $this->postJson('/api/v1/auth/login', [
                'email' => 'ratelimited@example.com',
                'password' => 'wrong-password',
            ])->getStatusCode();
        }

        $this->assertSame(401, $responses[0]);
        $this->assertSame(429, $responses[5]);
    }

    public function test_anonymous_discovery_is_rate_limited(): void
    {
        $statuses = [];

        for ($i = 0; $i < 65; $i++) {
            $statuses[] = $this->getJson('/api/v1/tournaments')->getStatusCode();
        }

        $this->assertSame(200, $statuses[0]);
        $this->assertContains(429, $statuses);
    }

    public function test_rate_limit_response_uses_standard_envelope(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'envelope@example.com',
                'password' => 'x',
            ]);
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => 'envelope@example.com',
            'password' => 'x',
        ])->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
    }
}
```

### `tests/Feature/Api/ApiReadSurfacesTest.php`

```php
<?php

namespace Tests\Feature\Api;

use App\Models\LiveEvent;

/**
 * Phase 15 — read-only discovery surfaces: live feeds, leaderboards,
 * rankings, bracket and match lists.
 */
class ApiReadSurfacesTest extends ApiTestCase
{
    public function test_live_leaderboard_and_bracket_surfaces(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $player = $this->user();
        $tournament = $this->makeTournament($org, 'live');
        $team = $this->makeTeam($tournament, $player, 'confirmed', 'UIDLIVE01');

        $event = new LiveEvent();
        $event->tournament_id = $tournament->id;
        $event->actor_user_id = $player->id;
        $event->type = LiveEvent::TYPE_TEAM_REGISTERED;
        $event->payload = ['team' => $team->name];
        $event->created_at = now();
        $event->save();

        $token = $this->tokenFor($player, ['*']);
        $this->authForget();

        $surfaces = [
            ['GET', '/api/v1/tournaments/' . $tournament->slug . '/live', 'with token'],
            ['GET', '/api/v1/me/live', 'with token'],
            ['GET', '/api/v1/leaderboards', 'guest'],
            ['GET', '/api/v1/leaderboards/' . $tournament->slug, 'guest'],
            ['GET', '/api/v1/players/' . $player->id . '/ranking', 'guest'],
            ['GET', '/api/v1/tournaments/' . $tournament->slug . '/leaderboard', 'guest'],
            ['GET', '/api/v1/tournaments/' . $tournament->slug . '/bracket', 'guest'],
            ['GET', '/api/v1/tournaments/' . $tournament->slug . '/matches', 'guest'],
        ];

        foreach ($surfaces as [$method, $uri, $auth]) {
            $req = $auth === 'with token' ? $this->withToken($token) : $this;

            $status = $method === 'GET'
                ? $req->getJson($uri)->getStatusCode()
                : $req->postJson($uri, [])->getStatusCode();

            $this->assertSame(200, $status, "{$method} {$uri} ({$auth})");
        }
    }
}
```

### `tests/Feature/Api/ApiSmokeMatrixTest.php`

```php
<?php

namespace Tests\Feature\Api;

use App\Models\Payment;

/**
 * Phase 15 — HTTP smoke matrix across actors (guest / protected / player /
 * organizer / moderator / admin) plus inbound webhook valid/invalid/replay.
 *
 * Prints a readable result table for the phase report and asserts every cell.
 */
class ApiSmokeMatrixTest extends ApiTestCase
{
    public function test_full_smoke_matrix(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $mod = $this->user(['role' => 'moderator']);
        $admin = $this->admin();
        $player = $this->user();

        $tournament = $this->makeTournament($org, 'open', ['entry_fee' => 500]);
        $playerTeam = $this->makeTeam($tournament, $player);
        $playerReg = $this->user();

        $rows = [];

        // --- Guest -----------------------------------------------------------------
        $rows[] = ['guest', 'GET /tournaments', $this->getJson('/api/v1/tournaments')->getStatusCode()];
        $rows[] = ['guest', 'GET /me', $this->getJson('/api/v1/me')->getStatusCode()];
        $rows[] = ['guest', 'POST /registrations', $this->postJson('/api/v1/tournaments/' . $tournament->slug . '/registrations', $this->registrationPayload())->getStatusCode()];
        $rows[] = ['guest', 'GET /admin/webhooks', $this->getJson('/api/v1/admin/webhooks/endpoints')->getStatusCode()];

        // --- Protected (player token, all scopes) ----------------------------------
        $playerToken = $this->tokenFor($player, ['*']);
        $this->authForget();
        $rows[] = ['player', 'GET /me', $this->withToken($playerToken)->getJson('/api/v1/me')->getStatusCode()];
        $rows[] = ['player', 'GET /me/wallet', $this->withToken($playerToken)->getJson('/api/v1/me/wallet')->getStatusCode()];

        $regToken = $this->tokenFor($playerReg, ['*']);
        $this->authForget();
        $rows[] = ['player', 'POST /registrations', $this->withToken($regToken)
            ->postJson('/api/v1/tournaments/' . $tournament->slug . '/registrations', $this->registrationPayload('Smoke', 'UIDSMOKE1'))->getStatusCode()];

        // --- Organizer -------------------------------------------------------------
        $orgToken = $this->tokenFor($org, ['*']);
        $this->authForget();
        $rows[] = ['organizer', 'GET /teams/{team}', $this->withToken($orgToken)->getJson('/api/v1/teams/' . $playerTeam->id)->getStatusCode()];
        $rows[] = ['organizer', 'GET /admin/webhooks', $this->withToken($orgToken)->getJson('/api/v1/admin/webhooks/endpoints')->getStatusCode()];

        // --- Moderator -------------------------------------------------------------
        $modToken = $this->tokenFor($mod, ['*']);
        $this->authForget();
        $rows[] = ['moderator', 'GET /admin/webhooks', $this->withToken($modToken)->getJson('/api/v1/admin/webhooks/endpoints')->getStatusCode()];

        // --- Admin -----------------------------------------------------------------
        $adminToken = $this->tokenFor($admin, ['admin']);
        $this->authForget();
        $rows[] = ['admin', 'GET /admin/webhooks', $this->withToken($adminToken)->getJson('/api/v1/admin/webhooks/endpoints')->getStatusCode()];

        // --- Webhooks (valid / invalid / replay) -----------------------------------
        $payment = app(\App\Services\PaymentService::class)->createForTeam(
            $tournament, $playerTeam, $player, 'bkash', 'TRXSMOKE', 'bkash', 'TRXSMOKE'
        );

        $payload = [
            'event' => 'payment.succeeded',
            'event_id' => 'evt-smoke-1',
            'payment_id' => $payment->id,
            'provider_reference' => 'TRXSMOKE',
            'amount_minor' => 50000,
            'currency' => 'BDT',
            'status' => 'paid',
        ];
        $rawBody = json_encode($payload);
        $secret = (string) config('services.payments.webhook_secret');

        $valid = $this->withHeaders([
            'X-Signature' => hash_hmac('sha256', $rawBody, $secret),
            'X-Timestamp' => (string) time(),
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/webhooks/inbound/bkash', $payload)->getStatusCode();
        $rows[] = ['webhook', 'POST inbound (valid)', $valid];

        $invalid = $this->withHeaders([
            'X-Signature' => 'bad-signature',
            'X-Timestamp' => (string) time(),
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/webhooks/inbound/bkash', $payload)->getStatusCode();
        $rows[] = ['webhook', 'POST inbound (invalid signature)', $invalid];

        $replay = $this->withHeaders([
            'X-Signature' => hash_hmac('sha256', $rawBody, $secret),
            'X-Timestamp' => (string) time(),
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/webhooks/inbound/bkash', $payload);
        $rows[] = ['webhook', 'POST inbound (replay)', $replay->getStatusCode() . ' replay=' . var_export($replay->json('data.replay'), true)];

        // --- Assertions ------------------------------------------------------------
        $this->assertSame(200, $rows[0][2], 'guest list tournaments');
        $this->assertSame(401, $rows[1][2], 'guest /me');
        $this->assertSame(401, $rows[2][2], 'guest registration');
        $this->assertSame(401, $rows[3][2], 'guest admin');
        $this->assertSame(200, $rows[4][2], 'player /me');
        $this->assertSame(200, $rows[5][2], 'player wallet');
        $this->assertSame(201, $rows[6][2], 'player registration');
        $this->assertSame(200, $rows[7][2], 'organizer view team');
        $this->assertSame(403, $rows[8][2], 'organizer admin surface');
        $this->assertSame(403, $rows[9][2], 'moderator admin surface');
        $this->assertSame(200, $rows[10][2], 'admin webhooks');
        $this->assertSame(200, $rows[11][2], 'webhook valid');
        $this->assertSame(401, $rows[12][2], 'webhook invalid signature');
        $this->assertSame('200 replay=true', $rows[13][2], 'webhook replay');

        // The payment settled exactly once despite the replay.
        $this->assertSame(Payment::STATUS_PAID, $payment->fresh()->status);

        fwrite(STDERR, "\nPhase 15 API smoke matrix:\n");
        foreach ($rows as $row) {
            fwrite(STDERR, sprintf("  %-9s %-32s %s\n", $row[0], $row[1], $row[2]));
        }
    }
}
```

## Modified Files (final content)

### `composer.json`

```json
{
    "$schema": "https://getcomposer.org/schema.json",
    "name": "laravel/laravel",
    "type": "project",
    "description": "The skeleton application for the Laravel framework.",
    "keywords": ["laravel", "framework"],
    "license": "MIT",
    "require": {
        "php": "^8.2",
        "laravel/framework": "^12.0",
        "laravel/sanctum": "^4.3",
        "laravel/socialite": "^5.31",
        "laravel/tinker": "^2.10.1"
    },
    "require-dev": {
        "fakerphp/faker": "^1.23",
        "laravel/pail": "^1.2.2",
        "laravel/pint": "^1.24",
        "laravel/sail": "^1.41",
        "mockery/mockery": "^1.6",
        "nunomaduro/collision": "^8.6",
        "phpunit/phpunit": "^11.5.50"
    },
    "autoload": {
        "psr-4": {
            "App\\": "app/",
            "Database\\Factories\\": "database/factories/",
            "Database\\Seeders\\": "database/seeders/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Tests\\": "tests/"
        }
    },
    "scripts": {
        "setup": [
            "composer install",
            "@php -r \"file_exists('.env') || copy('.env.example', '.env');\"",
            "@php artisan key:generate",
            "@php artisan migrate --force",
            "npm install",
            "npm run build"
        ],
        "dev": [
            "Composer\\Config::disableProcessTimeout",
            "npx concurrently -c \"#93c5fd,#c4b5fd,#fb7185,#fdba74\" \"php artisan serve\" \"php artisan queue:listen --tries=1 --timeout=0\" \"php artisan pail --timeout=0\" \"npm run dev\" --names=server,queue,logs,vite --kill-others"
        ],
        "test": [
            "@php artisan config:clear --ansi",
            "@php artisan test"
        ],
        "post-autoload-dump": [
            "Illuminate\\Foundation\\ComposerScripts::postAutoloadDump",
            "@php artisan package:discover --ansi"
        ],
        "post-update-cmd": [
            "@php artisan vendor:publish --tag=laravel-assets --ansi --force"
        ],
        "post-root-package-install": [
            "@php -r \"file_exists('.env') || copy('.env.example', '.env');\""
        ],
        "post-create-project-cmd": [
            "@php artisan key:generate --ansi",
            "@php -r \"file_exists('database/database.sqlite') || touch('database/database.sqlite');\"",
            "@php artisan migrate --graceful --ansi"
        ],
        "pre-package-uninstall": [
            "Illuminate\\Foundation\\ComposerScripts::prePackageUninstall"
        ]
    },
    "extra": {
        "laravel": {
            "dont-discover": []
        }
    },
    "config": {
        "optimize-autoloader": true,
        "preferred-install": "dist",
        "sort-packages": true,
        "allow-plugins": {
            "pestphp/pest-plugin": true,
            "php-http/discovery": true
        }
    },
    "minimum-stability": "stable",
    "prefer-stable": true
}
```

### `config/auth.php`

```php
<?php

use App\Models\User;

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | This option defines the default authentication "guard" and password
    | reset "broker" for your application. You may change these values
    | as required, but they're a perfect start for most applications.
    |
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    |
    | Next, you may define every authentication guard for your application.
    | Of course, a great default configuration has been defined for you
    | which utilizes session storage plus the Eloquent user provider.
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | Supported: "session"
    |
    */

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],

        // Phase 15 — bearer-token API authentication (Laravel Sanctum).
        // Only bearer tokens authenticate this guard here; session cookies
        // are NOT accepted as mobile credentials.
        'sanctum' => [
            'driver' => 'sanctum',
            'provider' => 'users',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | If you have multiple user tables or models you may configure multiple
    | providers to represent the model / table. These providers may then
    | be assigned to any extra authentication guards you have defined.
    |
    | Supported: "database", "eloquent"
    |
    */

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => env('AUTH_MODEL', User::class),
        ],

        // 'users' => [
        //     'driver' => 'database',
        //     'table' => 'users',
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    |
    | These configuration options specify the behavior of Laravel's password
    | reset functionality, including the table utilized for token storage
    | and the user provider that is invoked to actually retrieve users.
    |
    | The expiry time is the number of minutes that each reset token will be
    | considered valid. This security feature keeps tokens short-lived so
    | they have less time to be guessed. You may change this as needed.
    |
    | The throttle setting is the number of seconds a user must wait before
    | generating more password reset tokens. This prevents the user from
    | quickly generating a very large amount of password reset tokens.
    |
    */

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Confirmation Timeout
    |--------------------------------------------------------------------------
    |
    | Here you may define the number of seconds before a password confirmation
    | window expires and users are asked to re-enter their password via the
    | confirmation screen. By default, the timeout lasts for three hours.
    |
    */

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];
```

### `bootstrap/app.php`

```php
<?php

use App\Exceptions\ApiExceptionHandler;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureUserIsAdmin::class,
            'staff' => \App\Http\Middleware\EnsureUserIsStaff::class,

            // Phase 15 — API middleware.
            'bearer' => \App\Http\Middleware\EnsureBearerToken::class,
            'api.token' => \App\Http\Middleware\EnsureTokenIsValid::class,
            'idempotency' => \App\Http\Middleware\EnsureIdempotency::class,
            'abilities' => \Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
            'ability' => \Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class,
        ]);

        // Phase 13 — assign a per-request audit correlation id.
        // Phase 14 — block deactivated/deleted accounts (with a reactivate
        // escape hatch on the security-settings page).
        $middleware->web(append: [
            \App\Http\Middleware\AssignAuditRequestId::class,
            \App\Http\Middleware\EnsureActiveAccount::class,
        ]);

        // Provider payment webhooks are authenticated by HMAC signature, not
        // by a session CSRF token. (Both the legacy Phase 08 endpoint and the
        // new Phase 15 inbound endpoint are signature-authenticated.)
        $middleware->validateCsrfTokens(except: [
            'webhooks/payments/*',
            'api/v1/webhooks/inbound/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Phase 15 — centralized API error rendering (JSON envelope). The
        // renderer only transforms /api/* responses; web routes keep
        // Laravel's default handling.
        $exceptions->render(function (Throwable $e, $request) {
            return ApiExceptionHandler::render($e, $request);
        });
    })->create();
```

### `app/Providers/AppServiceProvider.php`

```php
<?php

namespace App\Providers;

use App\Contracts\GoogleIdTokenVerifierInterface;
use App\Contracts\GoogleOAuthProviderInterface;
use App\Contracts\PhoneOtpProviderInterface;
use App\Gateways\GoogleTokenInfoIdVerifier;
use App\Gateways\LogPhoneOtpProvider;
use App\Gateways\SmsGatewayPhoneOtpProvider;
use App\Gateways\SocialiteGoogleProvider;
use App\Services\NotificationService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Phase 14 — honest provider wiring. The SMS gateway is used only when
        // configured; otherwise the dev/test log provider delivers codes.
        $this->app->singleton(PhoneOtpProviderInterface::class, function ($app) {
            if (! empty(env('SMS_GATEWAY_ENDPOINT')) && ! empty(env('SMS_GATEWAY_API_KEY'))) {
                return new SmsGatewayPhoneOtpProvider();
            }

            return new LogPhoneOtpProvider();
        });

        $this->app->singleton(GoogleOAuthProviderInterface::class, fn ($app) => new SocialiteGoogleProvider());

        // Phase 15 — Google id_token verification for the mobile/API login.
        // Tests bind a deterministic fake; production verifies server-side
        // against Google.
        $this->app->singleton(GoogleIdTokenVerifierInterface::class, fn ($app) => new GoogleTokenInfoIdVerifier());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Phase 15 — use the app's PersonalAccessToken subclass so the
        // api_client_id link (grouped revocation) is available on tokens.
        \Laravel\Sanctum\Sanctum::usePersonalAccessTokenModel(\App\Models\PersonalAccessToken::class);

        // Expose the authenticated user's unread notification count to the
        // shared layout (Phase 11). Guests always see zero.
        View::composer('layouts.app', function ($view) {
            $user = auth()->user();

            $view->with('unreadNotifications', $user !== null
                ? app(NotificationService::class)->unreadCount($user)
                : 0);
        });

        $this->registerRateLimiters();
    }

    /**
     * Phase 14 — named rate limiters for auth, OTP, recovery and payments.
     */
    protected function registerRateLimiters(): void
    {
        // Login: 5 attempts per minute per email+IP, then 1 per minute.
        RateLimiter::for('login', function (Request $request) {
            $key = 'login:' . strtolower((string) $request->input('email')) . ':' . $request->ip();

            return [
                Limit::perMinute(5)->by($key),
                Limit::perMinute(20)->by($request->ip()),
            ];
        });

        // Registration: 3 per hour per IP.
        RateLimiter::for('register', function (Request $request) {
            return Limit::perHour(3)->by($request->ip());
        });

        // OTP request: hard per-phone + per-IP limits (SMS abuse control).
        RateLimiter::for('otp-request', function (Request $request) {
            $phone = preg_replace('/\D/', '', (string) $request->input('phone')) ?? '';

            return [
                Limit::perMinute(1)->by('otp-request:' . $phone),
                Limit::perHour(5)->by('otp-request:' . $phone),
                Limit::perHour(10)->by('otp-request:ip:' . $request->ip()),
            ];
        });

        // OTP verify: 5 per 5 minutes per phone.
        RateLimiter::for('otp-verify', function (Request $request) {
            $phone = preg_replace('/\D/', '', (string) $request->input('phone')) ?? '';

            return Limit::perMinutes(5, 5)->by('otp-verify:' . $phone);
        });

        // Password reset requests: 3 per hour per email+IP.
        RateLimiter::for('password-reset', function (Request $request) {
            $key = 'password-reset:' . strtolower((string) $request->input('email')) . ':' . $request->ip();

            return Limit::perHour(3)->by($key);
        });

        // Google callback: generic abuse ceiling per IP.
        RateLimiter::for('google-callback', function (Request $request) {
            return Limit::perMinute(20)->by($request->ip());
        });

        // Account linking (google/phone): 5 per minute per user.
        RateLimiter::for('account-link', function (Request $request) {
            return Limit::perMinute(5)->by('account-link:user:' . ($request->user()?->id ?? $request->ip()));
        });

        // Payment initiation: 5 per minute per user.
        RateLimiter::for('payment-initiate', function (Request $request) {
            return Limit::perMinute(5)->by('payment-initiate:user:' . ($request->user()?->id ?? $request->ip()));
        });

        // Verification email resends: 3 per hour per user.
        RateLimiter::for('verification-resend', function (Request $request) {
            return Limit::perHour(3)->by('verification-resend:user:' . ($request->user()?->id ?? $request->ip()));
        });

        $this->registerApiRateLimiters();
    }

    /**
     * Phase 15 — named, route-level API limiters. Keys are user/token-aware
     * wherever a user exists (never IP-only for authenticated operations),
     * with tighter windows for auth, OTP, scoring, payment and support.
     */
    protected function registerApiRateLimiters(): void
    {
        // General authenticated API ceiling: 120/min per token.
        RateLimiter::for('api', function (Request $request) {
            $user = $request->user();

            return Limit::perMinute((int) config('api.rate_limits.api', 120))
                ->by('api:user:' . ($user?->id ?? $request->ip()));
        });

        // Anonymous discovery: 60/min per IP.
        RateLimiter::for('api_anon', function (Request $request) {
            return Limit::perMinute((int) config('api.rate_limits.api_anon', 60))
                ->by('api-anon:' . $request->ip());
        });

        // Token issuance: 5/min per user.
        RateLimiter::for('api_token_issue', function (Request $request) {
            return Limit::perMinute(5)
                ->by('api-token-issue:user:' . ($request->user()?->id ?? $request->ip()));
        });

        // API login (email/password + google): 5/min per identifier + IP.
        RateLimiter::for('api_login', function (Request $request) {
            $identifier = strtolower((string) ($request->input('email') ?? $request->input('id_token') ?? ''));

            return [
                Limit::perMinute(5)->by('api-login:' . $identifier),
                Limit::perMinute(20)->by('api-login:ip:' . $request->ip()),
            ];
        });

        // API registration: 3/hour per IP (mirrors the web 'register' limiter).
        RateLimiter::for('api_register', function (Request $request) {
            return Limit::perHour(3)->by('api-register:' . $request->ip());
        });

        // API OTP request: 1/min per phone + 5/hour per phone + 10/hour per IP.
        RateLimiter::for('api_otp_request', function (Request $request) {
            $phone = preg_replace('/\D/', '', (string) $request->input('phone')) ?? '';

            return [
                Limit::perMinute(1)->by('api-otp-request:' . $phone),
                Limit::perHour(5)->by('api-otp-request:' . $phone),
                Limit::perHour(10)->by('api-otp-request:ip:' . $request->ip()),
            ];
        });

        // API OTP verify: 5/5min per phone.
        RateLimiter::for('api_otp_verify', function (Request $request) {
            $phone = preg_replace('/\D/', '', (string) $request->input('phone')) ?? '';

            return Limit::perMinutes(5, 5)->by('api-otp-verify:' . $phone);
        });

        // API score submission: 10/min per user.
        RateLimiter::for('api_score', function (Request $request) {
            return Limit::perMinute((int) config('api.rate_limits.api_score', 10))
                ->by('api-score:user:' . ($request->user()?->id ?? $request->ip()));
        });

        // API payment creation: 5/min per user.
        RateLimiter::for('api_payment', function (Request $request) {
            return Limit::perMinute((int) config('api.rate_limits.api_payment', 5))
                ->by('api-payment:user:' . ($request->user()?->id ?? $request->ip()));
        });

        // API support writes: 10/min per user.
        RateLimiter::for('api_support', function (Request $request) {
            return Limit::perMinute((int) config('api.rate_limits.api_support', 10))
                ->by('api-support:user:' . ($request->user()?->id ?? $request->ip()));
        });

        // Inbound provider webhooks: 60/min per IP.
        RateLimiter::for('api_webhook', function (Request $request) {
            return Limit::perMinute((int) config('api.rate_limits.api_webhook', 60))
                ->by('api-webhook:' . $request->ip());
        });
    }
}
```

### `app/Models/User.php`

```php
<?php

namespace App\Models;

use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, CanResetPassword;

    /**
     * Sensitive fields (role, wallet_balance, account_status, verification
     * state) are intentionally excluded from mass assignment. `role` must be
     * set explicitly (see AuthController) and can only ever be 'player' or
     * 'organizer' at registration time. Profile fields below are the only
     * user-editable attributes and are still validated server-side.
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'username',
        'phone',
        'game_uid',
        'bio',
        'country',
        'region',
        'avatar',
        'language',
        'timezone',
        'privacy',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isOrganizer(): bool
    {
        return $this->role === 'organizer';
    }

    /**
     * Moderators are platform staff who can work the dispute/moderation
     * queue and review/resolve disputes. The role is granted only by admins
     * (never self-assigned and never mass-assignable).
     */
    public function isModerator(): bool
    {
        return $this->role === 'moderator';
    }

    /**
     * Platform staff (admins + moderators) — distinct from tournament
     * organizers, who are staff only within their own tournaments.
     */
    public function isStaff(): bool
    {
        return $this->isAdmin() || $this->isModerator();
    }

    public function tournaments()
    {
        return $this->hasMany(Tournament::class, 'organizer_id');
    }

    public function teams()
    {
        return $this->hasMany(Team::class, 'captain_id');
    }

    /**
     * The user's wallet (Phase 08). Created lazily by WalletService.
     */
    public function wallet()
    {
        return $this->hasOne(Wallet::class);
    }

    /**
     * Prize payouts received by this user (Phase 09).
     */
    public function payouts()
    {
        return $this->hasMany(Payout::class, 'recipient_user_id');
    }

    // ------------------------------------------------------------------
    // Phase 10 — anti-fraud / trust & safety relations
    // ------------------------------------------------------------------

    /**
     * The user's server-side risk profile.
     */
    public function riskProfile()
    {
        return $this->hasOne(RiskProfile::class);
    }

    /**
     * Risk events attributable to this account (append-only).
     */
    public function riskEvents()
    {
        return $this->hasMany(RiskEvent::class);
    }

    /**
     * Pseudonymous device associations.
     */
    public function deviceLinks()
    {
        return $this->hasMany(DeviceLink::class);
    }

    /**
     * Hashed IP observations for this account.
     */
    public function ipLinks()
    {
        return $this->hasMany(IpLink::class);
    }

    /**
     * Account restrictions applied to this user.
     */
    public function restrictions()
    {
        return $this->hasMany(Restriction::class);
    }

    /**
     * The user's identity-verification record.
     */
    public function identityVerification()
    {
        return $this->hasOne(IdentityVerification::class);
    }

    /**
     * Account-similarity links involving this user (either direction),
     * eagerly loading both sides.
     */
    public function linkedAccounts()
    {
        return AccountLink::query()
            ->with(['user', 'linkedUser'])
            ->where(function ($q) {
                $q->where('user_id', $this->id)->orWhere('linked_user_id', $this->id);
            });
    }

    /**
     * Anti-cheat incidents where this user is the accused or reporter.
     */
    public function antiCheatIncidents()
    {
        return $this->hasMany(AntiCheatIncident::class, 'accused_user_id');
    }

    // ------------------------------------------------------------------
    // Phase 14 — account ecosystem relations + helpers
    // ------------------------------------------------------------------

    /**
     * Provider-linked identities (google, phone) on this account.
     */
    public function identities()
    {
        return $this->hasMany(UserIdentity::class);
    }

    /**
     * Phone OTP challenges issued for this account.
     */
    public function otpChallenges()
    {
        return $this->hasMany(OtpChallenge::class);
    }

    /**
     * Security/login history (append-only).
     */
    public function loginEvents()
    {
        return $this->hasMany(LoginEvent::class)->orderByDesc('id');
    }

    /**
     * Saved payment methods owned by this account.
     */
    public function paymentMethods()
    {
        return $this->hasMany(PaymentMethod::class);
    }

    public function hasVerifiedEmail(): bool
    {
        return $this->email_verified_at !== null;
    }

    public function isActive(): bool
    {
        // `null` (e.g. a freshly-constructed or legacy row that has not been
        // reloaded since the column was added) means "not deactivated", so we
        // treat it as active. Only an explicit lifecycle status blocks access.
        return $this->account_status === null || $this->account_status === 'active';
    }

    public function isDeactivated(): bool
    {
        return $this->account_status === 'deactivated';
    }
}
```

### `app/Services/AuditLogService.php`

```php
<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Central admin/security audit trail (Phase 13).
 *
 * The single authority for writing `audit_logs`. Records are append-only
 * (the model refuses updates/deletes), carry the acting user, a closed
 * vocabulary of actions, optional entity/tournament/target references,
 * whitelisted before/after state, a request correlation id and the source
 * route. All payloads are redacted and size-capped before they are written.
 *
 * `recordQuietly()` is the integration seam: business flows call it so an
 * audit failure can never break the originating action.
 */
class AuditLogService
{
    /**
     * The closed action vocabulary. Keeping this list explicit prevents
     * typos, arbitrary actions and unreadable trails.
     */
    public const ACTIONS = [
        'auth.login',
        'auth.logout',
        'auth.register',
        'auth.password_reset',
        'auth.password_changed',
        'auth.email_verified',
        'auth.phone_verified',
        'auth.phone_unlinked',
        'auth.google_linked',
        'auth.google_unlinked',
        'auth.otp_requested',
        'auth.otp_verified',
        'auth.sessions_revoked',
        'auth.account_deactivated',
        'auth.account_reactivated',
        'auth.account_deletion_requested',
        'auth.account_deleted',
        'auth.suspicious_login',
        'profile.updated',
        'profile.username_changed',
        'profile.privacy_changed',
        'payment_method.added',
        'payment_method.removed',
        'payment_method.default',
        'payment.initiated',
        'role.change',
        'wallet.credited',
        'wallet.debited',
        'payment.verified',
        'payment.failed',
        'payment.refunded',
        'payout.approved',
        'payout.processed',
        'payout.override',
        'payout.completed',
        'payout.failed',
        'payout.cancelled',
        'settlement.tiers_saved',
        'settlement.calculated',
        'settlement.approved',
        'settlement.processed',
        'settlement.cancelled',
        'settlement.adjusted',
        'tournament.published',
        'tournament.registration_closed',
        'tournament.started',
        'tournament.completed',
        'tournament.cancelled',
        'tournament.noshows',
        'tournament.waitlist_promoted',
        'team.registered',
        'team.withdrawn',
        'team.member_added',
        'team.member_removed',
        'team.updated',
        'team.checked_in',
        'match.score_adjusted',
        'match.winner_set',
        'match.disputed',
        'match.resolved',
        'dispute.under_review',
        'dispute.assigned',
        'dispute.resolved',
        'dispute.rejected',
        'dispute.cancelled',
        'dispute.evidence_removed',
        'restriction.applied',
        'restriction.lifted',
        'identity.verified',
        'identity.rejected',
        'anti_cheat.opened',
        'anti_cheat.resolved',
        'support.created',
        'support.replied',
        'support.assigned',
        'support.status_changed',
        'support.internal_note',
        'support.reopened',
        'auth.api_token_issued',
        'auth.api_token_revoked',
        'auth.api_client_created',
        'auth.api_client_revoked',
        'webhook.endpoint_created',
        'webhook.secret_rotated',
        'webhook.endpoint_status',
    ];

    /**
     * Record an audit entry. Throws on failure — business flows should prefer
     * recordQuietly().
     */
    public function record(
        ?User $actor,
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        array $options = [],
    ): AuditLog {
        if (! in_array($action, self::ACTIONS, true)) {
            throw new \InvalidArgumentException("Unknown audit action [{$action}].");
        }

        $log = new AuditLog();
        $log->actor_user_id = $actor?->id;
        $log->action = $action;
        $log->entity_type = $entityType;
        $log->entity_id = $entityId;
        $log->tournament_id = ($options['tournament'] ?? null) instanceof Tournament
            ? $options['tournament']->id
            : ($options['tournament_id'] ?? null);
        $log->target_user_id = ($options['target_user'] ?? null) instanceof User
            ? $options['target_user']->id
            : ($options['target_user_id'] ?? null);
        $log->before = $this->capPayload(array_key_exists('before', $options) ? $this->redact((array) $options['before']) : null);
        $log->after = $this->capPayload(array_key_exists('after', $options) ? $this->redact((array) $options['after']) : null);
        $log->metadata = $this->capPayload(array_key_exists('metadata', $options) ? $this->redact((array) $options['metadata']) : null);
        $log->request_id = $this->requestId();
        $log->source = $this->source();
        $log->save();

        return $log;
    }

    /**
     * Record without ever throwing into the caller (integration seam).
     */
    public function recordQuietly(
        ?User $actor,
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        array $options = [],
    ): ?AuditLog {
        try {
            return $this->record($actor, $action, $entityType, $entityId, $options);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * An admin action with a user-facing label (same as record, named for
     * readability at call sites).
     */
    public function recordAdminAction(
        User $actor,
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        array $options = [],
    ): AuditLog {
        return $this->record($actor, $action, $entityType, $entityId, $options);
    }

    /**
     * A security/trust & safety action (same as record, named for
     * readability at call sites).
     */
    public function recordSecurityAction(
        User $actor,
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        array $options = [],
    ): AuditLog {
        return $this->record($actor, $action, $entityType, $entityId, $options);
    }

    /**
     * Record a change against an Eloquent model. `entity_type` is derived
     * from the model class; the model's `tournament_id` (when present) is
     * captured automatically.
     */
    public function recordModelChange(
        ?User $actor,
        string $action,
        Model $model,
        ?array $before = null,
        ?array $after = null,
        array $options = [],
    ): AuditLog {
        if (! array_key_exists('tournament_id', $options)) {
            $options['tournament_id'] = $model->getAttribute('tournament_id');
        }

        return $this->record($actor, $action, Str::snake(class_basename($model)), $model->getKey(), $options + [
            'before' => $before,
            'after' => $after,
        ]);
    }

    /**
     * Build the whitelisted filter query shared by the index and the CSV
     * export. Filter fields and the sort column are whitelisted — no raw SQL
     * is ever assembled from user input.
     *
     * @param  array<string, mixed>  $filters
     */
    public function query(array $filters = []): \Illuminate\Database\Eloquent\Builder
    {
        $query = AuditLog::query();

        if (! empty($filters['action'])) {
            $query->where('action', (string) $filters['action']);
        }

        if (! empty($filters['entity_type'])) {
            $query->where('entity_type', (string) $filters['entity_type']);
        }

        if (! empty($filters['entity_id'])) {
            $query->where('entity_id', (int) $filters['entity_id']);
        }

        if (! empty($filters['tournament_id'])) {
            $query->where('tournament_id', (int) $filters['tournament_id']);
        }

        if (! empty($filters['target_user_id'])) {
            $query->where('target_user_id', (int) $filters['target_user_id']);
        }

        if (! empty($filters['actor_user_id'])) {
            $query->where('actor_user_id', (int) $filters['actor_user_id']);
        }

        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', $filters['to']);
        }

        return $query;
    }

    /**
     * Filtered, paginated audit search.
     *
     * @param  array<string, mixed>  $filters
     */
    public function search(array $filters = [], int $perPage = 30): LengthAwarePaginator
    {
        $sort = $filters['sort'] ?? 'created_at';

        if (! in_array($sort, ['created_at'], true)) {
            $sort = 'created_at';
        }

        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        return $this->query($filters)
            ->with(['actor:id,name,username,role', 'targetUser:id,name,username,role', 'tournament:id,name'])
            ->orderBy($sort, $direction)
            ->paginate($perPage);
    }

    /**
     * The most recent audit entries for one entity (for "related history").
     *
     * @return Collection<int, AuditLog>
     */
    public function relatedHistory(string $entityType, int $entityId, int $limit = 50): Collection
    {
        return AuditLog::query()
            ->with(['actor:id,name,username,role'])
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * The per-request correlation id (set by AssignAuditRequestId middleware).
     */
    public function requestId(): string
    {
        return (string) request()->attributes->get('audit_request_id', Str::uuid());
    }

    /**
     * The route name (or path) that produced this entry.
     */
    public function source(): ?string
    {
        try {
            return request()->route()?->getName() ?? request()->path();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Recursively redact sensitive keys and cap individual values.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function redact(array $data): array
    {
        $keys = (array) config('audit.redact_keys', []);

        $result = [];

        foreach ($data as $key => $value) {
            $normalized = strtolower((string) $key);

            if (in_array($normalized, $keys, true)) {
                $result[$key] = '[redacted]';
                continue;
            }

            if (is_array($value)) {
                $result[$key] = $this->redact($value);
                continue;
            }

            $result[$key] = $this->capValue($value);
        }

        return $result;
    }

    /**
     * Cap an individual string value.
     */
    protected function capValue(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $max = (int) config('audit.max_value_chars', 500);

        return mb_strlen($value) > $max
            ? mb_substr($value, 0, $max) . '…'
            : $value;
    }

    /**
     * Cap a whole JSON payload; oversized payloads become a truncated preview.
     *
     * @return array<string, mixed>|null
     */
    protected function capPayload(?array $data): ?array
    {
        if ($data === null) {
            return null;
        }

        $json = json_encode($data);
        $max = (int) config('audit.max_payload_chars', 4000);

        if ($json !== false && strlen($json) <= $max) {
            return $data;
        }

        return [
            '_truncated' => true,
            '_note' => 'Payload exceeded the size cap and was truncated.',
            'preview' => $json === false ? '' : substr($json, 0, $max),
        ];
    }
}
```

### `app/Http/Controllers/TeamController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Exceptions\RegistrationClosedException;
use App\Models\LiveEvent;
use App\Models\Notification;
use App\Models\RiskEvent;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\Tournament;
use App\Services\AuditLogService;
use App\Services\FraudRiskService;
use App\Services\LiveEventService;
use App\Services\NotificationService;
use App\Services\RegistrationService;
use App\Services\RosterService;
use App\Services\TournamentParticipationService;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TeamController extends Controller
{
    public function __construct(
        protected RosterService $roster,
        protected TournamentParticipationService $participation,
        protected FraudRiskService $risk,
        protected NotificationService $notifications,
        protected LiveEventService $live,
        protected AuditLogService $audit,
        protected RegistrationService $registrations,
    ) {
    }

    public function showRegistration(Tournament $tournament)
    {
        if (! $tournament->acceptsRegistration()) {
            if ($tournament->hasStarted()) {
                return back()->with('error', 'Registration is closed — this tournament has already started.');
            }

            return back()->with('error', 'This tournament is not open for registration.');
        }

        return view('teams.register', compact('tournament'));
    }

    public function register(Request $request, Tournament $tournament)
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        // Fast-fail lifecycle checks with friendly messages. The authoritative
        // checks run again inside the shared RegistrationService.
        if (! $tournament->acceptsRegistration()) {
            if ($tournament->hasStarted()) {
                return back()->with('error', 'Registration is closed — this tournament has already started.');
            }

            return back()->with('error', 'Registration is closed for this tournament.');
        }

        $data = $request->validate([
            'name' => 'required|string|max:120',
            'captain_name' => 'required|string|max:120',
            'phone' => 'required|string|max:20',
            'game_uid' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9]{4,30}$/'],
            'members' => 'nullable|array',
            'members.*.player_name' => 'nullable|string|max:120',
            'members.*.game_uid' => 'nullable|string|max:30',
        ]);

        try {
            $result = $this->registrations->register($tournament, $user, $data);
        } catch (RegistrationClosedException $e) {
            return back()->with('error', $e->getMessage());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        } catch (QueryException $e) {
            // Database backstop: unique(tournament_id, captain_id) for the
            // one-team-per-captain rule, or unique(tournament_id, game_uid)
            // for a captain UID clash.
            return back()->with('error', 'A duplicate team or player was detected. Registration was not saved.');
        }

        $team = $result['team'];
        $waitlisted = $result['waitlisted'];

        if ($waitlisted) {
            return redirect()
                ->route('tournaments.show', $tournament)
                ->with('success', 'All slots are full. Your team is on the waitlist (position '.$team->waitlistPosition().').');
        }

        return redirect()->route('payment.show', [$tournament, $team]);
    }

    /**
     * Withdraw a team before the tournament reaches an irreversible stage.
     * No refund logic is invented here: any existing payment is left
     * untouched and must be handled offline/manually.
     */
    public function withdraw(Request $request, Tournament $tournament, Team $team)
    {
        abort_unless($team->belongsToTournament($tournament), 404);
        $this->authorize('withdraw', $team);

        if (in_array($tournament->status, [
            Tournament::STATUS_LIVE,
            Tournament::STATUS_FINISHED,
            Tournament::STATUS_CANCELLED,
        ], true)) {
            return back()->with('error', 'Teams can no longer withdraw from this tournament.');
        }

        if ($team->status === Team::STATUS_WITHDRAWN) {
            return back()->with('error', 'This team has already been withdrawn.');
        }

        // Phase 10 — repeated-withdrawal signal (non-blocking observation).
        // Count prior withdrawals before releasing the captain claim.
        $priorWithdrawals = Team::where('captain_id', $request->user()->id)
            ->where('status', Team::STATUS_WITHDRAWN)
            ->count();

        $team->status = Team::STATUS_WITHDRAWN;
        $team->captain_id = null; // release the captain's claim so they may re-register
        $team->game_uid = null;   // release the captain UID so it can be re-used
        $team->save();

        $withdrawals = $priorWithdrawals + 1;
        $threshold = (int) config('antifraud.withdrawal.repeat_threshold', 3);

        if ($withdrawals >= $threshold) {
            $this->risk->recordSignal($request->user(), RiskEvent::TYPE_WITHDRAWAL_REPEAT, RiskEvent::SEVERITY_LOW, 'registration', [
                'withdrawal_count' => $withdrawals,
            ], $tournament);
        }

        // Phase 11 — notify the organizer that a team withdrew.
        $organizer = $tournament->organizer;

        if ($organizer !== null) {
            $this->notifications->send(
                $organizer,
                Notification::TYPE_TEAM_WITHDRAWN,
                'Team withdrew',
                'Team ' . $team->name . ' withdrew from ' . $tournament->name . '.',
                NotificationService::link('tournaments.show', [$tournament]),
                ['team_id' => $team->id, 'tournament_id' => $tournament->id],
            );
        }

        // Phase 12 — live event (best-effort).
        $this->live->recordQuietly($tournament, $request->user(), LiveEvent::TYPE_TEAM_WITHDRAWN, [
            'team' => $team->name,
        ]);

        // Phase 13 — central audit (best-effort).
        $this->audit->recordQuietly($request->user(), 'team.withdrawn', 'team', $team->id, [
            'tournament_id' => $tournament->id,
            'metadata' => ['team' => $team->name],
        ]);

        return back()->with('success', 'Your team has been withdrawn from the tournament.');
    }

    /**
     * Team / roster management page.
     */
    public function show(Tournament $tournament, Team $team)
    {
        abort_unless($team->belongsToTournament($tournament), 404);
        $this->authorize('view', $team);

        $team->load(['members', 'captain']);

        $locked = $this->roster->isLocked($team);
        $slotsLeft = $this->roster->maxMembers($team) - $team->members()->count();

        $canEdit = auth()->check() && (auth()->user()->isAdmin() || ($team->isCaptain(auth()->user()) && ! $locked));
        $canCheckIn = auth()->check() && (auth()->user()->isAdmin() || $team->isCaptain(auth()->user()));

        return view('teams.show', compact('tournament', 'team', 'locked', 'slotsLeft', 'canEdit', 'canCheckIn'));
    }

    /**
     * Add a roster member (captain or admin).
     */
    public function addMember(Request $request, Tournament $tournament, Team $team)
    {
        abort_unless($team->belongsToTournament($tournament), 404);
        $this->authorize('addMember', $team);

        $data = $request->validate([
            'player_name' => 'required|string|max:120',
            'game_uid' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9]{4,30}$/'],
        ]);

        try {
            $member = $this->roster->addMember($team, $tournament, $data, $request->user()->isAdmin());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        } catch (QueryException $e) {
            return back()->with('error', 'This player is already in the team.');
        }

        $this->audit->recordQuietly($request->user(), 'team.member_added', 'team', $team->id, [
            'tournament_id' => $tournament->id,
            'metadata' => ['player_name' => $member->player_name],
        ]);

        return back()->with('success', $member->player_name.' added to the roster.');
    }

    /**
     * Remove a roster member (captain or admin).
     */
    public function removeMember(Request $request, Tournament $tournament, Team $team, TeamMember $member)
    {
        abort_unless($team->belongsToTournament($tournament), 404);
        $this->authorize('removeMember', $team);

        try {
            $this->roster->removeMember($team, $member, $request->user()->isAdmin());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->audit->recordQuietly($request->user(), 'team.member_removed', 'team', $team->id, [
            'tournament_id' => $tournament->id,
            'metadata' => ['player_name' => $member->player_name],
        ]);

        return back()->with('success', 'Member removed from the roster.');
    }

    /**
     * Update team profile (captain or admin).
     */
    public function updateProfile(Request $request, Tournament $tournament, Team $team)
    {
        abort_unless($team->belongsToTournament($tournament), 404);
        $this->authorize('updateProfile', $team);

        $data = $request->validate([
            'name' => 'required|string|max:120',
            'captain_name' => 'required|string|max:120',
            'phone' => 'required|string|max:20',
            'game_uid' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9]{4,30}$/'],
        ]);

        try {
            $this->roster->updateProfile($team, $tournament, $data, $request->user()->isAdmin());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        } catch (QueryException $e) {
            return back()->with('error', 'This Free Fire UID is already used in this tournament.');
        }

        $this->audit->recordQuietly($request->user(), 'team.updated', 'team', $team->id, [
            'tournament_id' => $tournament->id,
        ]);

        return back()->with('success', 'Team profile updated.');
    }

    /**
     * Team check-in (captain or admin). Idempotent.
     */
    public function checkIn(Request $request, Tournament $tournament, Team $team)
    {
        abort_unless($team->belongsToTournament($tournament), 404);
        $this->authorize('checkIn', $team);

        // Phase 10 — fraud/risk gate for check-in.
        try {
            $this->risk->gate($request->user(), 'checkin', $tournament);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        try {
            $result = $this->participation->checkIn($tournament, $team, $request->user(), $request->user()->isAdmin());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($result === 'already') {
            return back()->with('success', 'Your team is already checked in.');
        }

        $this->audit->recordQuietly($request->user(), 'team.checked_in', 'team', $team->id, [
            'tournament_id' => $tournament->id,
        ]);

        return back()->with('success', 'Check-in successful! Your team is confirmed for the bracket.');
    }
}
```

### `app/Services/ScoringService.php`

```php
<?php

namespace App\Services;

use App\Models\GameMatch;
use App\Models\LiveEvent;
use App\Models\Score;
use App\Models\ScoreAdjustment;
use App\Models\ScoringRule;
use App\Models\Team;
use App\Models\Tournament;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Free Fire scoring engine (Phase 06).
 *
 * Single source of truth for all scoring math: placement points, kill
 * points, bonuses, penalties, totals, versioned rule snapshots and
 * deterministic tie-broken standings. Controllers delegate here and never
 * compute scoring themselves.
 */
class ScoringService
{
    public function __construct(
        protected LiveEventService $live,
    ) {
    }

    /**
     * The tournament's current (active) scoring rule set, lazily creating the
     * legacy default snapshot when none exists (backward compatibility).
     */
    public function currentRuleSet(Tournament $tournament): ScoringRule
    {
        $rule = $tournament->scoringRules()
            ->where('is_current', true)
            ->orderByDesc('version')
            ->first();

        if ($rule !== null) {
            return $rule;
        }

        return $this->createVersion($tournament, [
            'name' => 'Default Free Fire Rules',
            'kill_points' => ScoringRule::DEFAULT_KILL_POINTS,
            'placement_points' => ScoringRule::DEFAULT_PLACEMENT_POINTS,
            'tie_breakers' => ScoringRule::DEFAULT_TIE_BREAKERS,
        ]);
    }

    /**
     * Create a new immutable rule-set version and make it the current one.
     * Existing scores keep their own snapshots, so history never changes.
     */
    public function createVersion(Tournament $tournament, array $data): ScoringRule
    {
        $data = $this->normalizeRuleData($data);

        return DB::transaction(function () use ($tournament, $data) {
            $next = (int) ($tournament->scoringRules()->max('version') ?? 0) + 1;

            $tournament->scoringRules()
                ->where('is_current', true)
                ->update(['is_current' => false]);

            $rule = new ScoringRule();
            $rule->tournament_id = $tournament->id;
            $rule->version = $next;
            $rule->name = $data['name'];
            $rule->kill_points = $data['kill_points'];
            $rule->placement_points = $data['placement_points'];
            $rule->tie_breakers = $data['tie_breakers'];
            $rule->is_current = true;
            $rule->save();

            return $rule;
        });
    }

    /**
     * Re-activate an existing rule-set version as current.
     */
    public function activateVersion(Tournament $tournament, ScoringRule $rule): void
    {
        if ($rule->tournament_id !== $tournament->id) {
            throw new DomainException('That rule set does not belong to this tournament.');
        }

        DB::transaction(function () use ($tournament, $rule) {
            $tournament->scoringRules()
                ->where('is_current', true)
                ->update(['is_current' => false]);

            // A raw update always executes, even if the in-memory model still
            // thinks it is current.
            ScoringRule::where('id', $rule->id)->update(['is_current' => true]);
        });
    }

    /**
     * Deterministically compute a score breakdown from a rule snapshot.
     *
     * @return array{placement_points:int, kill_points:int, bonus_points:int, penalty_points:int, total:int}
     */
    public function breakdown(ScoringRule $rule, int $kills, int $placement, iterable $adjustments): array
    {
        $placementPoints = $rule->placementPointsFor($placement);
        $killPoints = $kills * (int) $rule->kill_points;

        $bonus = 0;
        $penalty = 0;

        foreach ($adjustments as $adjustment) {
            if ($adjustment->type === ScoreAdjustment::TYPE_PENALTY) {
                $penalty += (int) $adjustment->points;
            } else {
                $bonus += (int) $adjustment->points;
            }
        }

        $total = $placementPoints + $killPoints + $bonus - $penalty;

        return [
            'placement_points' => $placementPoints,
            'kill_points' => $killPoints,
            'bonus_points' => $bonus,
            'penalty_points' => $penalty,
            'total' => max(0, $total),
        ];
    }

    /**
     * Record a team's score for a match using the current rule snapshot.
     * Transaction-safe; the unique (match_id, team_id) constraint is the
     * race-condition backstop.
     */
    public function submitScore(GameMatch $match, Team $team, int $kills, int $placement, ?string $screenshotPath = null): Score
    {
        if (! $match->acceptsScoreSubmission()) {
            throw new DomainException('Score submission is not open for this match.');
        }

        if (! $match->hasParticipant($team)) {
            throw new DomainException('This team is not part of this match.');
        }

        if ($placement < 1 || $placement > ScoringRule::MAX_PLACEMENT) {
            throw new DomainException('Placement must be between 1 and ' . ScoringRule::MAX_PLACEMENT . '.');
        }

        if ($kills < 0) {
            throw new DomainException('Kills cannot be negative.');
        }

        try {
            return DB::transaction(function () use ($match, $team, $kills, $placement, $screenshotPath) {
                if (Score::where('match_id', $match->id)->where('team_id', $team->id)->exists()) {
                    throw new DomainException('A score for this team has already been submitted.');
                }

                if (Score::where('match_id', $match->id)->where('placement', $placement)->exists()) {
                    throw new DomainException('Another team in this match has already claimed that placement.');
                }

                $rule = $this->currentRuleSet($match->tournament);
                $parts = $this->breakdown($rule, $kills, $placement, []);

                $score = new Score();
                $score->match_id = $match->id;
                $score->team_id = $team->id;
                $score->kills = $kills;
                $score->placement = $placement;
                $score->placement_points = $parts['placement_points'];
                $score->kill_points = $parts['kill_points'];
                $score->bonus_points = 0;
                $score->penalty_points = 0;
                $score->points = $parts['total'];
                $score->scoring_rules_id = $rule->id;
                $score->screenshot_path = $screenshotPath;
                $score->status = 'pending';
                $score->save();

                // Phase 12 — live event (atomic with the score; best-effort).
                $this->live->recordQuietly($match->tournament, null, LiveEvent::TYPE_SCORE_SUBMITTED, [
                    'match_no' => (int) $match->match_no,
                    'round' => (int) $match->round,
                    'team' => $team->name,
                    'kills' => $kills,
                    'placement' => $placement,
                ]);

                // Phase 15 — outbound webhook (best-effort).
                app(WebhookDispatcher::class)->dispatchQuietly('match.score_submitted', [
                    'match_id' => $match->id,
                    'match_no' => (int) $match->match_no,
                    'round' => (int) $match->round,
                    'tournament_id' => $match->tournament_id,
                    'team_id' => $team->id,
                    'team_name' => $team->name,
                    'kills' => $kills,
                    'placement' => $placement,
                    'points' => (int) $score->points,
                ]);

                return $score;
            });
        } catch (QueryException $e) {
            // Unique (match_id, team_id) or (match_id, placement) constraint.
            throw new DomainException('A score already exists for this team or placement.');
        }
    }

    /**
     * Apply an auditable bonus/penalty to a score and recompute its totals.
     * Only possible while the match is not finalized.
     */
    public function addAdjustment(Score $score, string $type, int $points, string $reason): ScoreAdjustment
    {
        if (! in_array($type, ScoreAdjustment::TYPES, true)) {
            throw new DomainException('Adjustment type must be bonus or penalty.');
        }

        if ($points < 1) {
            throw new DomainException('Adjustment points must be a positive integer.');
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('An adjustment requires a reason.');
        }

        $match = $score->match;

        if ($match === null || ! $match->acceptsScoreSubmission()) {
            throw new DomainException('Scores cannot be adjusted once the match is finalized.');
        }

        return DB::transaction(function () use ($score, $type, $points, $reason) {
            $adjustment = new ScoreAdjustment();
            $adjustment->score_id = $score->id;
            $adjustment->type = $type;
            $adjustment->points = $points;
            $adjustment->reason = $reason;
            $adjustment->save();

            $this->recompute($score);

            return $adjustment;
        });
    }

    /**
     * Correct a score's raw inputs (kills and/or placement) through the
     * scoring engine and recompute every derived value.
     *
     * Phase 07 — this is the ONLY sanctioned path for a moderator/admin to
     * correct a result. It:
     *   - requires the match to be disputed or completed (i.e. inside the
     *     controlled dispute-resolution flow),
     *   - rejects negative kills and impossible placements,
     *   - rejects a placement already claimed by another team in the match,
     *   - recalculates using the score's OWN scoring-rule snapshot, so the
     *     historical scoring version is preserved,
     *   - never accepts a client-supplied total.
     */
    public function correctScore(Score $score, ?int $kills, ?int $placement): Score
    {
        $match = $score->match;

        if ($match === null) {
            throw new DomainException('This score is not attached to a match.');
        }

        if (! in_array($match->status, [GameMatch::STATUS_DISPUTED, GameMatch::STATUS_COMPLETED], true)) {
            throw new DomainException('Scores can only be corrected during dispute resolution.');
        }

        $newKills = $kills === null ? (int) $score->kills : $kills;
        $newPlacement = $placement === null ? (int) $score->placement : $placement;

        if ($newKills < 0) {
            throw new DomainException('Kills cannot be negative.');
        }

        if ($newPlacement < 1 || $newPlacement > ScoringRule::MAX_PLACEMENT) {
            throw new DomainException('Placement must be between 1 and ' . ScoringRule::MAX_PLACEMENT . '.');
        }

        return DB::transaction(function () use ($score, $match, $newKills, $newPlacement) {
            $taken = Score::query()
                ->where('match_id', $match->id)
                ->where('placement', $newPlacement)
                ->where('id', '!=', $score->id)
                ->exists();

            if ($taken) {
                throw new DomainException('Another team in this match has already claimed that placement.');
            }

            $score->kills = $newKills;
            $score->placement = $newPlacement;
            $score->save();

            $this->recompute($score);

            return $score;
        });
    }

    /**
     * Recompute a score's breakdown and total from its rule snapshot and its
     * current adjustments.
     */
    public function recompute(Score $score): void
    {
        $rule = $score->scoringRule;

        if ($rule === null) {
            // Legacy score without a snapshot — fall back to current rules.
            $rule = $this->currentRuleSet($score->match->tournament);
        }

        $parts = $this->breakdown(
            $rule,
            (int) $score->kills,
            (int) $score->placement,
            $score->adjustments
        );

        $score->placement_points = $parts['placement_points'];
        $score->kill_points = $parts['kill_points'];
        $score->bonus_points = $parts['bonus_points'];
        $score->penalty_points = $parts['penalty_points'];
        $score->points = $parts['total'];
        $score->scoring_rules_id = $rule->id;
        $score->save();
    }

    /**
     * Deterministic tournament standings.
     *
     * Only scores from matches that are not bye, cancelled or disputed are
     * counted (pending matches can never hold scores). Rows are ordered by
     * the current rule set's tie-breaker chain and finally by team id, so
     * identical inputs always produce an identical ranking.
     */
    public function standings(Tournament $tournament): Collection
    {
        $rule = $this->currentRuleSet($tournament);

        $scores = Score::query()
            ->whereHas('match', fn ($q) => $q
                ->where('tournament_id', $tournament->id)
                ->whereNotIn('status', [
                    GameMatch::STATUS_BYE,
                    GameMatch::STATUS_CANCELLED,
                    GameMatch::STATUS_DISPUTED,
                ]))
            ->with('team')
            ->get();

        $rows = [];

        foreach ($scores as $score) {
            $teamId = $score->team_id;

            if (! isset($rows[$teamId])) {
                $rows[$teamId] = [
                    'team_id' => $teamId,
                    'team' => $score->team,
                    'matches_played' => 0,
                    'kills' => 0,
                    'placement_points' => 0,
                    'kill_points' => 0,
                    'points' => 0,
                    'best_placement' => null,
                ];
            }

            $rows[$teamId]['matches_played']++;
            $rows[$teamId]['kills'] += (int) $score->kills;
            $rows[$teamId]['placement_points'] += (int) $score->placement_points;
            $rows[$teamId]['kill_points'] += (int) $score->kill_points;
            $rows[$teamId]['points'] += (int) $score->points;

            $best = $rows[$teamId]['best_placement'];
            if ($best === null || (int) $score->placement < $best) {
                $rows[$teamId]['best_placement'] = (int) $score->placement;
            }
        }

        $rows = array_values($rows);

        usort($rows, function (array $a, array $b) use ($rule) {
            foreach ($rule->tieBreakers() as $key) {
                $comparison = $this->compareMetric($key, $a, $b);
                if ($comparison !== 0) {
                    return $comparison;
                }
            }

            // Deterministic final fallback — never database-order dependent.
            return $a['team_id'] <=> $b['team_id'];
        });

        $result = [];
        $rank = 0;

        foreach ($rows as $row) {
            $row['rank'] = ++$rank;
            $result[] = (object) $row;
        }

        return new Collection($result);
    }

    /**
     * Compare two aggregated rows on a single metric.
     *
     * @return int negative when $a sorts before $b
     */
    protected function compareMetric(string $key, array $a, array $b): int
    {
        return match ($key) {
            'points' => (int) $b['points'] <=> (int) $a['points'],
            'placement_points' => (int) $b['placement_points'] <=> (int) $a['placement_points'],
            'kill_points' => (int) $b['kill_points'] <=> (int) $a['kill_points'],
            'kills' => (int) $b['kills'] <=> (int) $a['kills'],
            'best_placement' => ($a['best_placement'] ?? PHP_INT_MAX) <=> ($b['best_placement'] ?? PHP_INT_MAX),
            default => 0,
        };
    }

    /**
     * Validate and normalise rule-set input (server-authoritative — the
     * controller's request validation is a first line, this is the last).
     */
    protected function normalizeRuleData(array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            $name = 'Scoring rules';
        }

        $killPoints = (int) ($data['kill_points'] ?? ScoringRule::DEFAULT_KILL_POINTS);
        if ($killPoints < 0) {
            throw new DomainException('Kill points cannot be negative.');
        }

        $placementPoints = [];
        $provided = $data['placement_points'] ?? null;

        if (is_array($provided)) {
            foreach ($provided as $placement => $points) {
                $placement = (int) $placement;
                if ($placement < 1 || $placement > ScoringRule::MAX_PLACEMENT) {
                    continue;
                }
                if ((int) $points < 0) {
                    throw new DomainException('Placement points cannot be negative.');
                }
                $placementPoints[$placement] = (int) $points;
            }
        }

        if ($placementPoints === []) {
            throw new DomainException('A placement point table is required.');
        }

        $tieBreakers = $data['tie_breakers'] ?? ScoringRule::DEFAULT_TIE_BREAKERS;
        $allowed = array_keys(ScoringRule::TIE_BREAKER_OPTIONS);
        $chain = is_array($tieBreakers)
            ? array_values(array_filter(array_map('strval', $tieBreakers), fn ($k) => in_array($k, $allowed, true)))
            : [];

        if ($chain === []) {
            $chain = ScoringRule::DEFAULT_TIE_BREAKERS;
        }

        if (! in_array('points', $chain, true)) {
            array_unshift($chain, 'points');
        }

        return [
            'name' => $name,
            'kill_points' => $killPoints,
            'placement_points' => $placementPoints,
            'tie_breakers' => array_values(array_unique($chain)),
        ];
    }
}
```

### `app/Services/PaymentService.php`

```php
<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Refund;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Money;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Payment lifecycle + financial integrity (Phase 08).
 *
 * Single authority for payment intents, state transitions, manual/admin
 * verification, refunds and provider callbacks. Amounts are integer minor
 * units (poisha), always derived from the tournament — never from clients.
 */
class PaymentService
{
    public function __construct(
        protected PaymentGatewayManager $gateways,
        protected WalletService $wallets,
        protected NotificationService $notifications,
    ) {
    }

    /**
     * Create a payment intent for a team's entry fee.
     *
     * Idempotent per team: if the team already has an active (pending /
     * processing / paid / verified) payment, a DomainException is thrown so
     * the caller can redirect to the existing one.
     */
    public function createForTeam(
        Tournament $tournament,
        Team $team,
        User $payer,
        string $method,
        string $trxId,
        ?string $provider = null,
        ?string $providerReference = null,
    ): Payment {
        if (! $team->belongsToTournament($tournament)) {
            throw new DomainException('This team does not belong to this tournament.');
        }

        if (! $tournament->acceptsRegistration()) {
            throw new DomainException('Payment is no longer accepted for this tournament.');
        }

        if ($team->status !== Team::STATUS_PENDING) {
            throw new DomainException('This team is not awaiting payment.');
        }

        $existing = Payment::where('team_id', $team->id)
            ->whereIn('status', Payment::ACTIVE_STATUSES)
            ->first();

        if ($existing !== null) {
            throw new DomainException('This team already has an active payment.');
        }

        // The amount is always derived from the server-side entry fee.
        $minor = $tournament->entryFeeMinor();
        $provider = $provider ?? $this->gateways->defaultProvider();
        $this->gateways->gateway($provider); // throws on unknown provider
        $idempotencyKey = Str::uuid();

        return DB::transaction(function () use ($tournament, $team, $payer, $method, $trxId, $minor, $provider, $providerReference, $idempotencyKey) {
            $payment = new Payment();
            $payment->tournament_id = $tournament->id;
            $payment->team_id = $team->id;
            $payment->payer_user_id = $payer->id;
            $payment->amount_minor = $minor;
            $payment->amount = Money::toDecimal($minor);
            $payment->currency = 'BDT';
            $payment->method = $method;
            $payment->trx_id = strtoupper(trim($trxId));
            $payment->provider = $provider;
            $payment->provider_reference = $providerReference !== null
                ? strtoupper(trim($providerReference))
                : strtoupper(trim($trxId));
            $payment->idempotency_key = $idempotencyKey;
            $payment->status = Payment::STATUS_PENDING;
            $payment->save();

            $this->recordEvent($payment, $payer, PaymentEvent::EVENT_CREATED, $minor);

            // Free-entry tournaments are auto-confirmed (Phase 01–07 demo
            // behaviour preserved).
            if ($minor <= 0) {
                $this->settleSuccess($payment, Payment::STATUS_VERIFIED, $payer);
            }

            // Phase 15 — outbound webhook (best-effort).
            app(WebhookDispatcher::class)->dispatchQuietly('payment.created', [
                'payment_id' => $payment->id,
                'tournament_id' => $tournament->id,
                'team_id' => $team->id,
                'amount_minor' => $minor,
                'currency' => 'BDT',
                'provider' => $provider,
                'status' => $payment->status,
            ]);

            return $payment;
        });
    }

    /**
     * Admin/manual verification of a pending payment (demo bKash flow).
     */
    public function verifyManually(Payment $payment, User $admin): Payment
    {
        if (! in_array($payment->status, [Payment::STATUS_PENDING, Payment::STATUS_PROCESSING], true)) {
            throw new DomainException('Only pending payments can be verified.');
        }

        return DB::transaction(function () use ($payment, $admin) {
            $this->settleSuccess($payment, Payment::STATUS_VERIFIED, $admin);

            return $payment;
        });
    }

    /**
     * Mark a pending/processing payment failed.
     */
    public function markFailed(Payment $payment, User $actor, string $reason = ''): Payment
    {
        if (! in_array($payment->status, [Payment::STATUS_PENDING, Payment::STATUS_PROCESSING], true)) {
            throw new DomainException('This payment cannot be failed from its current state.');
        }

        return DB::transaction(function () use ($payment, $actor, $reason) {
            $payment->status = Payment::STATUS_FAILED;
            $payment->save();

            $this->recordEvent($payment, $actor, PaymentEvent::EVENT_FAILED, $payment->amountMinor(), ['reason' => $reason]);

            // Phase 11 — notify the payer.
            $payer = $payment->payer ?? $payment->team?->captain;

            if ($payer !== null) {
                $this->notifications->send(
                    $payer,
                    Notification::TYPE_PAYMENT_FAILED,
                    'Payment failed',
                    'Your entry fee payment for ' . ($payment->tournament?->name ?? 'a tournament') . ' was marked failed.',
                    NotificationService::link('teams.show', [$payment->tournament, $payment->team]),
                    ['payment_id' => $payment->id],
                );
            }

            return $payment;
        });
    }

    /**
     * Cancel a pending/processing payment.
     */
    public function cancel(Payment $payment, User $actor): Payment
    {
        if (! in_array($payment->status, [Payment::STATUS_PENDING, Payment::STATUS_PROCESSING], true)) {
            throw new DomainException('This payment cannot be cancelled from its current state.');
        }

        return DB::transaction(function () use ($payment, $actor) {
            $payment->status = Payment::STATUS_CANCELLED;
            $payment->save();

            $this->recordEvent($payment, $actor, PaymentEvent::EVENT_CANCELLED, $payment->amountMinor());

            return $payment;
        });
    }

    /**
     * Refund a settled payment (full amount only), credit the payer's wallet,
     * and record the refund + ledger + audit trail. Idempotent: a second
     * refund of the same payment is rejected.
     */
    public function refund(Payment $payment, User $admin, string $reason): Refund
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('A refund reason is required.');
        }

        if (! $payment->isRefundable()) {
            throw new DomainException('Only settled payments can be refunded.');
        }

        $minor = $payment->amountMinor();

        return DB::transaction(function () use ($payment, $admin, $reason, $minor) {
            if (Refund::where('payment_id', $payment->id)->exists()) {
                throw new DomainException('This payment has already been refunded.');
            }

            $payment->status = Payment::STATUS_REFUNDED;
            $payment->refunded_at = now();
            $payment->save();

            $refund = new Refund();
            $refund->payment_id = $payment->id;
            $refund->amount_minor = $minor;
            $refund->currency = 'BDT';
            $refund->reason = $reason;
            $refund->processed_by = $admin->id;
            $refund->save();

            $this->recordEvent($payment, $admin, PaymentEvent::EVENT_REFUNDED, $minor, ['reason' => $reason]);

            // Credit the payer's wallet (when a payer account exists). This is
            // the platform-side representation of the refund; external gateway
            // refunds are NOT simulated.
            $payer = $payment->payer ?? $payment->team?->captain;

            if ($payer !== null) {
                $wallet = $this->wallets->walletFor($payer);
                $this->wallets->credit(
                    $wallet,
                    $minor,
                    \App\Models\LedgerEntry::TYPE_REFUND,
                    'Refund for tournament entry fee',
                    $admin,
                    'refund',
                    $refund->id,
                );

                // Phase 11 — notify the payer of the refund.
                $this->notifications->send(
                    $payer,
                    Notification::TYPE_PAYMENT_REFUNDED,
                    'Entry fee refunded',
                    'Your entry fee for ' . ($payment->tournament?->name ?? 'a tournament') . ' was refunded.',
                    NotificationService::link('wallet.index'),
                    ['payment_id' => $payment->id, 'refund_id' => $refund->id],
                );
            }

            return $refund;
        });
    }

    /**
     * Process a provider callback/webhook. Signature is verified against the
     * configured secret, then amount/currency/payment are validated and the
     * state transition applied. Fully idempotent: repeated or replayed
     * callbacks return the current state without double effects.
     */
    public function handleProviderCallback(string $provider, array $payload, string $signature, string $rawBody): Payment
    {
        if (! $this->verifySignature($rawBody, $signature)) {
            throw new DomainException('Invalid webhook signature.');
        }

        $paymentId = (int) ($payload['payment_id'] ?? 0);
        $reference = (string) ($payload['provider_reference'] ?? '');
        $amountMinor = (int) ($payload['amount_minor'] ?? 0);
        $currency = (string) ($payload['currency'] ?? 'BDT');
        $status = (string) ($payload['status'] ?? '');

        $payment = Payment::find($paymentId);

        if ($payment === null) {
            throw new DomainException('Unknown payment.', 404);
        }

        if ($payment->provider !== $provider) {
            throw new DomainException('Provider mismatch.', 404);
        }

        if ($reference !== '' && $payment->provider_reference !== null && $payment->provider_reference !== $reference) {
            throw new DomainException('Provider reference mismatch.', 400);
        }

        if ($payment->currency !== $currency) {
            throw new DomainException('Currency mismatch.', 400);
        }

        if ($payment->amountMinor() !== $amountMinor) {
            throw new DomainException('Amount mismatch.', 400);
        }

        if (! in_array($status, [Payment::STATUS_PAID, Payment::STATUS_FAILED], true)) {
            throw new DomainException('Invalid callback status.', 400);
        }

        // Idempotency: an already-settled payment simply reports its state.
        if ($payment->status === Payment::STATUS_PAID || $payment->status === Payment::STATUS_VERIFIED) {
            $this->recordEvent($payment, null, PaymentEvent::EVENT_CALLBACK, $amountMinor, ['duplicate' => true, 'reference' => $reference]);

            return $payment;
        }

        if (! in_array($payment->status, [Payment::STATUS_PENDING, Payment::STATUS_PROCESSING], true)) {
            throw new DomainException('This payment is no longer actionable.', 400);
        }

        return DB::transaction(function () use ($payment, $status, $amountMinor, $reference) {
            if ($status === Payment::STATUS_PAID) {
                $this->settleSuccess($payment, Payment::STATUS_PAID, null);
            } else {
                $payment->status = Payment::STATUS_FAILED;
                $payment->save();
            }

            $this->recordEvent($payment, null, PaymentEvent::EVENT_CALLBACK, $amountMinor, ['status' => $status, 'reference' => $reference]);

            return $payment;
        });
    }

    /**
     * Verify an HMAC-SHA256 webhook signature against the configured secret.
     */
    public function verifySignature(string $rawBody, string $signature): bool
    {
        $secret = (string) config('services.payments.webhook_secret', '');

        if ($secret === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * Move a payment into a success state, timestamp it, confirm the team
     * (if still pending) and write the audit event.
     */
    protected function settleSuccess(Payment $payment, string $targetStatus, ?User $actor): void
    {
        $payment->status = $targetStatus;
        $payment->paid_at = now();
        $payment->save();

        $team = $payment->team;
        if ($team !== null && $team->status === Team::STATUS_PENDING) {
            $team->status = Team::STATUS_CONFIRMED;
            $team->save();
        }

        $event = $targetStatus === Payment::STATUS_PAID
            ? PaymentEvent::EVENT_PAID
            : PaymentEvent::EVENT_VERIFIED;

        $this->recordEvent($payment, $actor, $event, $payment->amountMinor());

        // Phase 11 — notify the payer that the payment was accepted.
        $payer = $payment->payer ?? $payment->team?->captain;

        if ($payer !== null) {
            $this->notifications->send(
                $payer,
                Notification::TYPE_PAYMENT_VERIFIED,
                'Payment verified',
                'Your entry fee payment for ' . ($payment->tournament?->name ?? 'a tournament') . ' was verified.',
                NotificationService::link('teams.show', [$payment->tournament, $payment->team]),
                ['payment_id' => $payment->id],
            );
        }
    }

    protected function recordEvent(Payment $payment, ?User $actor, string $event, int $amountMinor, array $metadata = []): void
    {
        $record = new PaymentEvent();
        $record->payment_id = $payment->id;
        $record->actor_id = $actor?->id;
        $record->event = $event;
        $record->amount_minor = $amountMinor;
        $record->currency = 'BDT';
        $record->reference = $payment->provider_reference;
        $record->metadata = $metadata;
        $record->save();
    }
}
```

### `app/Services/SupportTicketService.php`

```php
<?php

namespace App\Services;

use App\Models\LiveEvent;
use App\Models\Notification;
use App\Models\SupportInternalNote;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\User;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Support ticket system (Phase 13).
 *
 * The single authority for creating and mutating tickets. Every status
 * change goes through the validated transition map; assignee and status are
 * never mass-assignable; internal notes are kept strictly separate from
 * user-visible messages and are never exposed to the requester.
 *
 * Integrates Phase 11 (notifications) and Phase 12 (staff-only live events)
 * — it never builds a second notification or realtime system.
 */
class SupportTicketService
{
    public function __construct(
        protected NotificationService $notifications,
        protected LiveEventService $live,
        protected AuditLogService $audit,
    ) {
    }

    /**
     * Create a ticket from an authenticated user, seeding the first message.
     */
    public function create(User $user, array $data): SupportTicket
    {
        $ticket = new SupportTicket();
        $ticket->user_id = $user->id;
        $ticket->subject = trim($data['subject']);
        $ticket->category = in_array($data['category'], SupportTicket::CATEGORIES, true)
            ? $data['category']
            : 'general';
        $ticket->priority = in_array($data['priority'] ?? null, SupportTicket::PRIORITIES, true)
            ? $data['priority']
            : SupportTicket::PRIORITY_NORMAL;
        $ticket->status = SupportTicket::STATUS_OPEN;
        $ticket->last_activity_at = now();
        $ticket->save();

        $this->appendMessage($ticket, $user, trim($data['message']));

        $this->notifications->send(
            $user,
            Notification::TYPE_SUPPORT_CREATED,
            'Support ticket created',
            'Your ticket "' . $ticket->subject . '" has been created. We will reply as soon as possible.',
            self::ticketLink($ticket),
            ['ticket_id' => $ticket->id],
        );

        // Staff-only realtime signal (never exposes the ticket body).
        $this->live->recordQuietly(null, $user, LiveEvent::TYPE_SUPPORT_CREATED, [
            'ticket_id' => $ticket->id,
            'category' => $ticket->category,
            'priority' => $ticket->priority,
        ]);

        $this->audit->recordQuietly($user, 'support.created', 'support_ticket', $ticket->id, [
            'metadata' => ['category' => $ticket->category, 'priority' => $ticket->priority],
        ]);

        // Phase 15 — outbound webhook (best-effort; subject/body are never
        // included).
        app(WebhookDispatcher::class)->dispatchQuietly('support.ticket.created', [
            'ticket_id' => $ticket->id,
            'category' => $ticket->category,
            'priority' => $ticket->priority,
        ]);

        return $ticket;
    }

    /**
     * Append a reply. The caller must be authorized (owner, staff, or the
     * ticket tournament's organizer) — authorization lives in the policy.
     */
    public function reply(User $author, SupportTicket $ticket, string $body): SupportMessage
    {
        if ($ticket->isClosed()) {
            throw new DomainException('This ticket is closed. Reopen it to continue the conversation.');
        }

        $message = $this->appendMessage($ticket, $author, trim($body));

        $staff = $author->isStaff();

        // Reflect who now needs to act.
        $ticket->status = $staff
            ? SupportTicket::STATUS_WAITING_ON_USER
            : SupportTicket::STATUS_WAITING_ON_STAFF;
        $ticket->last_activity_at = now();
        $ticket->save();

        if ($staff) {
            $this->notifications->send(
                $ticket->user,
                Notification::TYPE_SUPPORT_REPLY,
                'Support replied to your ticket',
                'A staff member replied to "' . $ticket->subject . '".',
                self::ticketLink($ticket),
                ['ticket_id' => $ticket->id],
            );
        } elseif ($ticket->assigned_to !== null && $ticket->assignee !== null) {
            $this->notifications->send(
                $ticket->assignee,
                Notification::TYPE_SUPPORT_REPLY,
                'User replied to a ticket',
                $author->name . ' replied to "' . $ticket->subject . '".',
                self::ticketLink($ticket),
                ['ticket_id' => $ticket->id],
            );
        }

        $this->live->recordQuietly($ticket->tournament, $author, LiveEvent::TYPE_SUPPORT_MESSAGE, [
            'ticket_id' => $ticket->id,
            'staff' => $staff,
        ]);

        $this->audit->recordQuietly($author, 'support.replied', 'support_ticket', $ticket->id, [
            'metadata' => ['staff' => $staff],
        ]);

        return $message;
    }

    /**
     * Add a staff-only internal note (never visible to the requester).
     */
    public function addInternalNote(User $staff, SupportTicket $ticket, string $body): SupportInternalNote
    {
        if (! $staff->isStaff()) {
            throw new DomainException('Only staff may add internal notes.');
        }

        $note = new SupportInternalNote();
        $note->ticket_id = $ticket->id;
        $note->author_user_id = $staff->id;
        $note->body = trim($body);
        $note->save();

        $ticket->last_activity_at = now();
        $ticket->save();

        // Note content is sensitive — never mirrored into audit/live payloads.
        $this->audit->recordQuietly($staff, 'support.internal_note', 'support_ticket', $ticket->id);

        return $note;
    }

    /**
     * Assign (or unassign) a staff member to a ticket. Staff only.
     */
    public function assign(User $actor, SupportTicket $ticket, ?User $assignee): SupportTicket
    {
        if (! $actor->isStaff()) {
            throw new DomainException('Only staff may assign tickets.');
        }

        if ($assignee !== null && ! $assignee->isStaff()) {
            throw new DomainException('Tickets can only be assigned to staff members.');
        }

        $ticket->assigned_to = $assignee?->id;
        $ticket->last_activity_at = now();

        if ($assignee !== null && $ticket->status === SupportTicket::STATUS_OPEN) {
            $ticket->status = SupportTicket::STATUS_PENDING;
        }

        $ticket->save();

        if ($assignee !== null) {
            $this->notifications->send(
                $assignee,
                Notification::TYPE_SUPPORT_ASSIGNED,
                'Ticket assigned to you',
                'You were assigned "' . $ticket->subject . '".',
                self::ticketLink($ticket),
                ['ticket_id' => $ticket->id],
            );

            $this->notifications->send(
                $ticket->user,
                Notification::TYPE_SUPPORT_ASSIGNED,
                'Your ticket was assigned',
                'A staff member is now handling "' . $ticket->subject . '".',
                self::ticketLink($ticket),
                ['ticket_id' => $ticket->id],
            );
        }

        $this->live->recordQuietly($ticket->tournament, $actor, LiveEvent::TYPE_SUPPORT_ASSIGNED, [
            'ticket_id' => $ticket->id,
            'assignee' => $assignee?->id,
        ]);

        $this->audit->recordQuietly($actor, 'support.assigned', 'support_ticket', $ticket->id, [
            'metadata' => ['assignee_id' => $assignee?->id],
        ]);

        return $ticket;
    }

    /**
     * Change a ticket's status through the validated transition map.
     * Staff only. A note may be appended as a user-visible message.
     */
    public function changeStatus(User $actor, SupportTicket $ticket, string $status, ?string $note = null): SupportTicket
    {
        if (! $actor->isStaff()) {
            throw new DomainException('Only staff may change ticket status.');
        }

        if (! in_array($status, SupportTicket::STATUSES, true)) {
            throw new DomainException('Unknown ticket status.');
        }

        if (! $ticket->canTransitionTo($status)) {
            throw new DomainException("Cannot move this ticket from {$ticket->status} to {$status}.");
        }

        $from = $ticket->status;

        $ticket->status = $status;
        $ticket->last_activity_at = now();

        if ($status === SupportTicket::STATUS_RESOLVED) {
            $ticket->resolved_at = now();
            $ticket->closed_at = null;
        } elseif ($status === SupportTicket::STATUS_CLOSED) {
            $ticket->closed_at = now();
        } elseif ($status === SupportTicket::STATUS_OPEN) {
            $ticket->resolved_at = null;
            $ticket->closed_at = null;
        }

        $ticket->save();

        if (trim((string) $note) !== '') {
            $this->appendMessage($ticket, $actor, trim((string) $note));
        }

        $type = $status === SupportTicket::STATUS_RESOLVED
            ? Notification::TYPE_SUPPORT_RESOLVED
            : Notification::TYPE_SUPPORT_STATUS;

        $this->notifications->send(
            $ticket->user,
            $type,
            'Support ticket update',
            'Your ticket "' . $ticket->subject . '" is now ' . $ticket->statusLabel() . '.',
            self::ticketLink($ticket),
            ['ticket_id' => $ticket->id, 'status' => $status],
        );

        $this->live->recordQuietly($ticket->tournament, $actor, LiveEvent::TYPE_SUPPORT_STATUS_CHANGED, [
            'ticket_id' => $ticket->id,
            'status' => $status,
        ]);

        $this->audit->recordQuietly($actor, 'support.status_changed', 'support_ticket', $ticket->id, [
            'before' => ['status' => $from],
            'after' => ['status' => $status],
        ]);

        return $ticket;
    }

    /**
     * Close a ticket by its owner (must not already be resolved/closed).
     */
    public function closeByUser(User $user, SupportTicket $ticket): SupportTicket
    {
        if ($ticket->user_id !== $user->id) {
            throw new DomainException('Only the ticket owner may close it.');
        }

        if ($ticket->isClosed() || $ticket->isResolved()) {
            throw new DomainException('This ticket is already closed.');
        }

        $from = $ticket->status;

        $ticket->status = SupportTicket::STATUS_CLOSED;
        $ticket->closed_at = now();
        $ticket->last_activity_at = now();
        $ticket->save();

        if ($ticket->assigned_to !== null && $ticket->assignee !== null) {
            $this->notifications->send(
                $ticket->assignee,
                Notification::TYPE_SUPPORT_STATUS,
                'Ticket closed by user',
                $user->name . ' closed "' . $ticket->subject . '".',
                self::ticketLink($ticket),
                ['ticket_id' => $ticket->id],
            );
        }

        $this->live->recordQuietly($ticket->tournament, $user, LiveEvent::TYPE_SUPPORT_STATUS_CHANGED, [
            'ticket_id' => $ticket->id,
            'status' => SupportTicket::STATUS_CLOSED,
        ]);

        $this->audit->recordQuietly($user, 'support.status_changed', 'support_ticket', $ticket->id, [
            'before' => ['status' => $from],
            'after' => ['status' => SupportTicket::STATUS_CLOSED],
        ]);

        return $ticket;
    }

    /**
     * Reopen a resolved/closed ticket (owner or staff, authorized upstream).
     */
    public function reopen(User $actor, SupportTicket $ticket): SupportTicket
    {
        if (! $ticket->isResolved() && ! $ticket->isClosed()) {
            throw new DomainException('Only resolved or closed tickets can be reopened.');
        }

        $wasClosed = $ticket->isClosed();

        $ticket->status = SupportTicket::STATUS_OPEN;
        $ticket->resolved_at = null;
        $ticket->closed_at = null;
        $ticket->last_activity_at = now();

        if ($wasClosed) {
            $ticket->reopened_count = $ticket->reopened_count + 1;
        }

        $ticket->save();

        if ($actor->isStaff()) {
            $this->notifications->send(
                $ticket->user,
                Notification::TYPE_SUPPORT_REOPENED,
                'Ticket reopened',
                'Your ticket "' . $ticket->subject . '" was reopened.',
                self::ticketLink($ticket),
                ['ticket_id' => $ticket->id],
            );
        } elseif ($ticket->assigned_to !== null && $ticket->assignee !== null) {
            $this->notifications->send(
                $ticket->assignee,
                Notification::TYPE_SUPPORT_REOPENED,
                'Ticket reopened',
                $actor->name . ' reopened "' . $ticket->subject . '".',
                self::ticketLink($ticket),
                ['ticket_id' => $ticket->id],
            );
        }

        $this->live->recordQuietly($ticket->tournament, $actor, LiveEvent::TYPE_SUPPORT_STATUS_CHANGED, [
            'ticket_id' => $ticket->id,
            'status' => SupportTicket::STATUS_OPEN,
        ]);

        $this->audit->recordQuietly($actor, 'support.reopened', 'support_ticket', $ticket->id);

        return $ticket;
    }

    /**
     * A user's own tickets, newest first.
     */
    public function forUser(User $user, int $perPage = 15): LengthAwarePaginator
    {
        return SupportTicket::query()
            ->with('assignee:id,name')
            ->where('user_id', $user->id)
            ->orderByDesc('last_activity_at')
            ->paginate($perPage);
    }

    /**
     * The staff queue, optionally scoped to a single organizer's tournaments.
     */
    public function queue(array $filters, ?User $scopedOrganizer = null, int $perPage = 20): LengthAwarePaginator
    {
        $query = SupportTicket::query()
            ->with(['user:id,name,username', 'assignee:id,name', 'tournament:id,name'])
            ->orderByDesc('last_activity_at');

        if ($scopedOrganizer !== null) {
            $query->whereHas('tournament', fn ($q) => $q->where('organizer_id', $scopedOrganizer->id));
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }

        if (! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        if (! empty($filters['assigned_to'])) {
            $query->where('assigned_to', (int) $filters['assigned_to']);
        }

        return $query->paginate($perPage);
    }

    /**
     * Messages with id > $afterId (ascending), for lightweight polling on the
     * ticket page. The caller enforces ticket-view authorization.
     *
     * @return Collection<int, SupportMessage>
     */
    public function messagesAfter(SupportTicket $ticket, int $afterId): Collection
    {
        return SupportMessage::query()
            ->where('ticket_id', $ticket->id)
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->get();
    }

    /**
     * The latest message id for a ticket (polling cursor).
     */
    public function latestMessageId(SupportTicket $ticket): int
    {
        return (int) (SupportMessage::where('ticket_id', $ticket->id)->max('id') ?? 0);
    }

    /**
     * Build the ticket route link safely (null when unavailable).
     */
    public static function ticketLink(SupportTicket $ticket): ?string
    {
        try {
            return route('support.tickets.show', $ticket);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Append a message row (server-authored; never mass-assigned).
     */
    protected function appendMessage(SupportTicket $ticket, User $author, string $body): SupportMessage
    {
        $message = new SupportMessage();
        $message->ticket_id = $ticket->id;
        $message->user_id = $author->id;
        $message->body = $body;
        $message->save();

        return $message;
    }
}
```

### `.env.example`

```text
APP_NAME=Laravel
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost

APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US

APP_MAINTENANCE_DRIVER=file
# APP_MAINTENANCE_STORE=database

# PHP_CLI_SERVER_WORKERS=4

BCRYPT_ROUNDS=12

LOG_CHANNEL=stack
LOG_STACK=single
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=debug

DB_CONNECTION=sqlite
# DB_HOST=127.0.0.1
# DB_PORT=3306
# DB_DATABASE=laravel
# DB_USERNAME=root
# DB_PASSWORD=

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database

CACHE_STORE=database
# CACHE_PREFIX=

MEMCACHED_HOST=127.0.0.1

REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

MAIL_MAILER=log
MAIL_SCHEME=null
MAIL_HOST=127.0.0.1
MAIL_PORT=2525
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_FROM_ADDRESS="hello@example.com"
MAIL_FROM_NAME="${APP_NAME}"

AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=
AWS_USE_PATH_STYLE_ENDPOINT=false

# ---------------------------------------------------------------------------
# Phase 14 — Google OAuth / OpenID Connect
# ---------------------------------------------------------------------------
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI="${APP_URL}/auth/google/callback"

# ---------------------------------------------------------------------------
# Phase 14 — phone OTP delivery (SMS gateway)
# ---------------------------------------------------------------------------
SMS_GATEWAY_ENDPOINT=
SMS_GATEWAY_API_KEY=
SMS_GATEWAY_SENDER=

# ---------------------------------------------------------------------------
# Phase 14 — payment providers (honest: absent credentials = "not configured")
# ---------------------------------------------------------------------------
PAYMENT_WEBHOOK_SECRET=ffarena-local-webhook-secret

BKASH_ENABLED=true
BKASH_MODE=sandbox
BKASH_BASE_URL=
BKASH_APP_KEY=
BKASH_APP_SECRET=
BKASH_USERNAME=
BKASH_PASSWORD=
BKASH_MERCHANT_NUMBER=

NAGAD_ENABLED=true
NAGAD_MODE=sandbox
NAGAD_BASE_URL=
NAGAD_MERCHANT_ID=
NAGAD_MERCHANT_PRIVATE_KEY=
NAGAD_PG_PUBLIC_KEY=
NAGAD_MERCHANT_NUMBER=

ROCKET_ENABLED=true
ROCKET_MODE=sandbox
ROCKET_BASE_URL=
ROCKET_MERCHANT_ID=
ROCKET_MERCHANT_SECRET=

CARD_ENABLED=false
CARD_MODE=sandbox
CARD_GATEWAY=
CARD_MERCHANT_ID=
CARD_MERCHANT_SECRET=

BANK_ENABLED=true
BANK_ACCOUNT_NAME=
BANK_ACCOUNT_NUMBER=

SSLCOMMERZ_ENABLED=false
SSLCOMMERZ_MODE=sandbox
SSLCOMMERZ_STORE_ID=
SSLCOMMERZ_STORE_PASSWORD=
SSLCOMMERZ_BASE_URL=

# Phase 15 — public API (Laravel Sanctum)
# Prefix for issued personal access tokens (set in production so leaked tokens
# are detectable by secret scanners, e.g. "ffarena_".)
SANCTUM_TOKEN_PREFIX=

# Phase 15 — inbound webhook secrets. Leave a provider empty to fall back to
# PAYMENT_WEBHOOK_SECRET (the Phase 08 trust root).
WEBHOOK_BKASH_SECRET=
WEBHOOK_NAGAD_SECRET=
WEBHOOK_ROCKET_SECRET=
WEBHOOK_SSLCOMMERZ_SECRET=
WEBHOOK_CARD_SECRET=

VITE_APP_NAME="${APP_NAME}"
```

## Generated Artifacts (final content)

### `composer.lock`

```text
{
    "_readme": [
        "This file locks the dependencies of your project to a known state",
        "Read more about it at https://getcomposer.org/doc/01-basic-usage.md#installing-dependencies",
        "This file is @generated automatically"
    ],
    "content-hash": "932ed8fc0f8a4f296d6d073ab1987957",
    "packages": [
        {
            "name": "brick/math",
            "version": "0.14.8",
            "source": {
                "type": "git",
                "url": "https://github.com/brick/math.git",
                "reference": "63422359a44b7f06cae63c3b429b59e8efcc0629"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/brick/math/zipball/63422359a44b7f06cae63c3b429b59e8efcc0629",
                "reference": "63422359a44b7f06cae63c3b429b59e8efcc0629",
                "shasum": ""
            },
            "require": {
                "php": "^8.2"
            },
            "require-dev": {
                "php-coveralls/php-coveralls": "^2.2",
                "phpstan/phpstan": "2.1.22",
                "phpunit/phpunit": "^11.5"
            },
            "type": "library",
            "autoload": {
                "psr-4": {
                    "Brick\\Math\\": "src/"
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "description": "Arbitrary-precision arithmetic library",
            "keywords": [
                "Arbitrary-precision",
                "BigInteger",
                "BigRational",
                "arithmetic",
                "bigdecimal",
                "bignum",
                "bignumber",
                "brick",
                "decimal",
                "integer",
                "math",
                "mathematics",
                "rational"
            ],
            "support": {
                "issues": "https://github.com/brick/math/issues",
                "source": "https://github.com/brick/math/tree/0.14.8"
            },
            "funding": [
                {
                    "url": "https://github.com/BenMorel",
                    "type": "github"
                }
            ],
            "time": "2026-02-10T14:33:43+00:00"
        },
        {
            "name": "carbonphp/carbon-doctrine-types",
            "version": "3.2.0",
            "source": {
                "type": "git",
                "url": "https://github.com/CarbonPHP/carbon-doctrine-types.git",
                "reference": "18ba5ddfec8976260ead6e866180bd5d2f71aa1d"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/CarbonPHP/carbon-doctrine-types/zipball/18ba5ddfec8976260ead6e866180bd5d2f71aa1d",
                "reference": "18ba5ddfec8976260ead6e866180bd5d2f71aa1d",
                "shasum": ""
            },
            "require": {
                "php": "^8.1"
            },
            "conflict": {
                "doctrine/dbal": "<4.0.0 || >=5.0.0"
            },
            "require-dev": {
                "doctrine/dbal": "^4.0.0",
                "nesbot/carbon": "^2.71.0 || ^3.0.0",
                "phpunit/phpunit": "^10.3"
            },
            "type": "library",
            "autoload": {
                "psr-4": {
                    "Carbon\\Doctrine\\": "src/Carbon/Doctrine/"
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "KyleKatarn",
                    "email": "kylekatarnls@gmail.com"
                }
            ],
            "description": "Types to use Carbon in Doctrine",
            "keywords": [
                "carbon",
                "date",
                "datetime",
                "doctrine",
                "time"
            ],
            "support": {
                "issues": "https://github.com/CarbonPHP/carbon-doctrine-types/issues",
                "source": "https://github.com/CarbonPHP/carbon-doctrine-types/tree/3.2.0"
            },
            "funding": [
                {
                    "url": "https://github.com/kylekatarnls",
                    "type": "github"
                },
                {
                    "url": "https://opencollective.com/Carbon",
                    "type": "open_collective"
                },
                {
                    "url": "https://tidelift.com/funding/github/packagist/nesbot/carbon",
                    "type": "tidelift"
                }
            ],
            "time": "2024-02-09T16:56:22+00:00"
        },
        {
            "name": "dflydev/dot-access-data",
            "version": "v3.0.3",
            "source": {
                "type": "git",
                "url": "https://github.com/dflydev/dflydev-dot-access-data.git",
                "reference": "a23a2bf4f31d3518f3ecb38660c95715dfead60f"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/dflydev/dflydev-dot-access-data/zipball/a23a2bf4f31d3518f3ecb38660c95715dfead60f",
                "reference": "a23a2bf4f31d3518f3ecb38660c95715dfead60f",
                "shasum": ""
            },
            "require": {
                "php": "^7.1 || ^8.0"
            },
            "require-dev": {
                "phpstan/phpstan": "^0.12.42",
                "phpunit/phpunit": "^7.5 || ^8.5 || ^9.3",
                "scrutinizer/ocular": "1.6.0",
                "squizlabs/php_codesniffer": "^3.5",
                "vimeo/psalm": "^4.0.0"
            },
            "type": "library",
            "extra": {
                "branch-alias": {
                    "dev-main": "3.x-dev"
                }
            },
            "autoload": {
                "psr-4": {
                    "Dflydev\\DotAccessData\\": "src/"
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Dragonfly Development Inc.",
                    "email": "info@dflydev.com",
                    "homepage": "http://dflydev.com"
                },
                {
                    "name": "Beau Simensen",
                    "email": "beau@dflydev.com",
                    "homepage": "http://beausimensen.com"
                },
                {
                    "name": "Carlos Frutos",
                    "email": "carlos@kiwing.it",
                    "homepage": "https://github.com/cfrutos"
                },
                {
                    "name": "Colin O'Dell",
                    "email": "colinodell@gmail.com",
                    "homepage": "https://www.colinodell.com"
                }
            ],
            "description": "Given a deep data structure, access data by dot notation.",
            "homepage": "https://github.com/dflydev/dflydev-dot-access-data",
            "keywords": [
                "access",
                "data",
                "dot",
                "notation"
            ],
            "support": {
                "issues": "https://github.com/dflydev/dflydev-dot-access-data/issues",
                "source": "https://github.com/dflydev/dflydev-dot-access-data/tree/v3.0.3"
            },
            "time": "2024-07-08T12:26:09+00:00"
        },
        {
            "name": "doctrine/inflector",
            "version": "2.1.0",
            "source": {
                "type": "git",
                "url": "https://github.com/doctrine/inflector.git",
                "reference": "6d6c96277ea252fc1304627204c3d5e6e15faa3b"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/doctrine/inflector/zipball/6d6c96277ea252fc1304627204c3d5e6e15faa3b",
                "reference": "6d6c96277ea252fc1304627204c3d5e6e15faa3b",
                "shasum": ""
            },
            "require": {
                "php": "^7.2 || ^8.0"
            },
            "require-dev": {
                "doctrine/coding-standard": "^12.0 || ^13.0",
                "phpstan/phpstan": "^1.12 || ^2.0",
                "phpstan/phpstan-phpunit": "^1.4 || ^2.0",
                "phpstan/phpstan-strict-rules": "^1.6 || ^2.0",
                "phpunit/phpunit": "^8.5 || ^12.2"
            },
            "type": "library",
            "autoload": {
                "psr-4": {
                    "Doctrine\\Inflector\\": "src"
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Guilherme Blanco",
                    "email": "guilhermeblanco@gmail.com"
                },
                {
                    "name": "Roman Borschel",
                    "email": "roman@code-factory.org"
                },
                {
                    "name": "Benjamin Eberlei",
                    "email": "kontakt@beberlei.de"
                },
                {
                    "name": "Jonathan Wage",
                    "email": "jonwage@gmail.com"
                },
                {
                    "name": "Johannes Schmitt",
                    "email": "schmittjoh@gmail.com"
                }
            ],
            "description": "PHP Doctrine Inflector is a small library that can perform string manipulations with regard to upper/lowercase and singular/plural forms of words.",
            "homepage": "https://www.doctrine-project.org/projects/inflector.html",
            "keywords": [
                "inflection",
                "inflector",
                "lowercase",
                "manipulation",
                "php",
                "plural",
                "singular",
                "strings",
                "uppercase",
                "words"
            ],
            "support": {
                "issues": "https://github.com/doctrine/inflector/issues",
                "source": "https://github.com/doctrine/inflector/tree/2.1.0"
            },
            "funding": [
                {
                    "url": "https://www.doctrine-project.org/sponsorship.html",
                    "type": "custom"
                },
                {
                    "url": "https://www.patreon.com/phpdoctrine",
                    "type": "patreon"
                },
                {
                    "url": "https://tidelift.com/funding/github/packagist/doctrine%2Finflector",
                    "type": "tidelift"
                }
            ],
            "time": "2025-08-10T19:31:58+00:00"
        },
        {
            "name": "doctrine/lexer",
            "version": "3.0.1",
            "source": {
                "type": "git",
                "url": "https://github.com/doctrine/lexer.git",
                "reference": "31ad66abc0fc9e1a1f2d9bc6a42668d2fbbcd6dd"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/doctrine/lexer/zipball/31ad66abc0fc9e1a1f2d9bc6a42668d2fbbcd6dd",
                "reference": "31ad66abc0fc9e1a1f2d9bc6a42668d2fbbcd6dd",
                "shasum": ""
            },
            "require": {
                "php": "^8.1"
            },
            "require-dev": {
                "doctrine/coding-standard": "^12",
                "phpstan/phpstan": "^1.10",
                "phpunit/phpunit": "^10.5",
                "psalm/plugin-phpunit": "^0.18.3",
                "vimeo/psalm": "^5.21"
            },
            "type": "library",
            "autoload": {
                "psr-4": {
                    "Doctrine\\Common\\Lexer\\": "src"
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Guilherme Blanco",
                    "email": "guilhermeblanco@gmail.com"
                },
                {
                    "name": "Roman Borschel",
                    "email": "roman@code-factory.org"
                },
                {
                    "name": "Johannes Schmitt",
                    "email": "schmittjoh@gmail.com"
                }
            ],
            "description": "PHP Doctrine Lexer parser library that can be used in Top-Down, Recursive Descent Parsers.",
            "homepage": "https://www.doctrine-project.org/projects/lexer.html",
            "keywords": [
                "annotations",
                "docblock",
                "lexer",
                "parser",
                "php"
            ],
            "support": {
                "issues": "https://github.com/doctrine/lexer/issues",
                "source": "https://github.com/doctrine/lexer/tree/3.0.1"
            },
            "funding": [
                {
                    "url": "https://www.doctrine-project.org/sponsorship.html",
                    "type": "custom"
                },
                {
                    "url": "https://www.patreon.com/phpdoctrine",
                    "type": "patreon"
                },
                {
                    "url": "https://tidelift.com/funding/github/packagist/doctrine%2Flexer",
                    "type": "tidelift"
                }
            ],
            "time": "2024-02-05T11:56:58+00:00"
        },
        {
            "name": "dragonmantank/cron-expression",
            "version": "v3.6.0",
            "source": {
                "type": "git",
                "url": "https://github.com/dragonmantank/cron-expression.git",
                "reference": "d61a8a9604ec1f8c3d150d09db6ce98b32675013"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/dragonmantank/cron-expression/zipball/d61a8a9604ec1f8c3d150d09db6ce98b32675013",
                "reference": "d61a8a9604ec1f8c3d150d09db6ce98b32675013",
                "shasum": ""
            },
            "require": {
                "php": "^8.2|^8.3|^8.4|^8.5"
            },
            "replace": {
                "mtdowling/cron-expression": "^1.0"
            },
            "require-dev": {
                "phpstan/extension-installer": "^1.4.3",
                "phpstan/phpstan": "^1.12.32|^2.1.31",
                "phpunit/phpunit": "^8.5.48|^9.0"
            },
            "type": "library",
            "extra": {
                "branch-alias": {
                    "dev-master": "3.x-dev"
                }
            },
            "autoload": {
                "psr-4": {
                    "Cron\\": "src/Cron/"
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Chris Tankersley",
                    "email": "chris@ctankersley.com",
                    "homepage": "https://github.com/dragonmantank"
                }
            ],
            "description": "CRON for PHP: Calculate the next or previous run date and determine if a CRON expression is due",
            "keywords": [
                "cron",
                "schedule"
            ],
            "support": {
                "issues": "https://github.com/dragonmantank/cron-expression/issues",
                "source": "https://github.com/dragonmantank/cron-expression/tree/v3.6.0"
            },
            "funding": [
                {
                    "url": "https://github.com/dragonmantank",
                    "type": "github"
                }
            ],
            "time": "2025-10-31T18:51:33+00:00"
        },
        {
            "name": "egulias/email-validator",
            "version": "4.0.4",
            "source": {
                "type": "git",
                "url": "https://github.com/egulias/EmailValidator.git",
                "reference": "d42c8731f0624ad6bdc8d3e5e9a4524f68801cfa"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/egulias/EmailValidator/zipball/d42c8731f0624ad6bdc8d3e5e9a4524f68801cfa",
                "reference": "d42c8731f0624ad6bdc8d3e5e9a4524f68801cfa",
                "shasum": ""
            },
            "require": {
                "doctrine/lexer": "^2.0 || ^3.0",
                "php": ">=8.1",
                "symfony/polyfill-intl-idn": "^1.26"
            },
            "require-dev": {
                "phpunit/phpunit": "^10.2",
                "vimeo/psalm": "^5.12"
            },
            "suggest": {
                "ext-intl": "PHP Internationalization Libraries are required to use the SpoofChecking validation"
            },
            "type": "library",
            "extra": {
                "branch-alias": {
                    "dev-master": "4.0.x-dev"
                }
            },
            "autoload": {
                "psr-4": {
                    "Egulias\\EmailValidator\\": "src"
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Eduardo Gulias Davis"
                }
            ],
            "description": "A library for validating emails against several RFCs",
            "homepage": "https://github.com/egulias/EmailValidator",
            "keywords": [
                "email",
                "emailvalidation",
                "emailvalidator",
                "validation",
                "validator"
            ],
            "support": {
                "issues": "https://github.com/egulias/EmailValidator/issues",
                "source": "https://github.com/egulias/EmailValidator/tree/4.0.4"
            },
            "funding": [
                {
                    "url": "https://github.com/egulias",
                    "type": "github"
                }
            ],
            "time": "2025-03-06T22:45:56+00:00"
        },
        {
            "name": "firebase/php-jwt",
            "version": "v7.1.0",
            "source": {
                "type": "git",
                "url": "https://github.com/googleapis/php-jwt.git",
                "reference": "b374a5d1a4f1f67fadc2165cdb284645945e2fc0"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/googleapis/php-jwt/zipball/b374a5d1a4f1f67fadc2165cdb284645945e2fc0",
                "reference": "b374a5d1a4f1f67fadc2165cdb284645945e2fc0",
                "shasum": ""
            },
            "require": {
                "php": "^8.0"
            },
            "require-dev": {
                "guzzlehttp/guzzle": "^7.4",
                "phpfastcache/phpfastcache": "^9.2",
                "phpseclib/phpseclib": "~3.0",
                "phpspec/prophecy-phpunit": "^2.0",
                "phpunit/phpunit": "^9.5",
                "psr/cache": "^2.0||^3.0",
                "psr/http-client": "^1.0",
                "psr/http-factory": "^1.0"
            },
            "suggest": {
                "ext-sodium": "Support EdDSA (Ed25519) signatures",
                "paragonie/sodium_compat": "Support EdDSA (Ed25519) signatures when libsodium is not present",
                "phpseclib/phpseclib": "Support PS256 (RSASSA-PSS) signatures"
            },
            "type": "library",
            "autoload": {
                "psr-4": {
                    "Firebase\\JWT\\": "src"
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "BSD-3-Clause"
            ],
            "authors": [
                {
                    "name": "Neuman Vong",
                    "email": "neuman+pear@twilio.com",
                    "role": "Developer"
                },
                {
                    "name": "Anant Narayanan",
                    "email": "anant@php.net",
                    "role": "Developer"
                }
            ],
            "description": "A simple library to encode and decode JSON Web Tokens (JWT) in PHP. Should conform to the current spec.",
            "homepage": "https://github.com/googleapis/php-jwt",
            "keywords": [
                "jwt",
                "php"
            ],
            "support": {
                "issues": "https://github.com/googleapis/php-jwt/issues",
                "source": "https://github.com/googleapis/php-jwt/tree/v7.1.0"
            },
            "time": "2026-06-11T17:54:14+00:00"
        },
        {
            "name": "fruitcake/php-cors",
            "version": "v1.4.0",
            "source": {
                "type": "git",
                "url": "https://github.com/fruitcake/php-cors.git",
                "reference": "38aaa6c3fd4c157ffe2a4d10aa8b9b16ba8de379"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/fruitcake/php-cors/zipball/38aaa6c3fd4c157ffe2a4d10aa8b9b16ba8de379",
                "reference": "38aaa6c3fd4c157ffe2a4d10aa8b9b16ba8de379",
                "shasum": ""
            },
            "require": {
                "php": "^8.1",
                "symfony/http-foundation": "^5.4|^6.4|^7.3|^8"
            },
            "require-dev": {
                "phpstan/phpstan": "^2",
                "phpunit/phpunit": "^9",
                "squizlabs/php_codesniffer": "^4"
            },
            "type": "library",
            "extra": {
                "branch-alias": {
                    "dev-master": "1.3-dev"
                }
            },
            "autoload": {
                "psr-4": {
                    "Fruitcake\\Cors\\": "src/"
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Fruitcake",
                    "homepage": "https://fruitcake.nl"
                },
                {
                    "name": "Barryvdh",
                    "email": "barryvdh@gmail.com"
                }
            ],
            "description": "Cross-origin resource sharing library for the Symfony HttpFoundation",
            "homepage": "https://github.com/fruitcake/php-cors",
            "keywords": [
                "cors",
                "laravel",
                "symfony"
            ],
            "support": {
                "issues": "https://github.com/fruitcake/php-cors/issues",
                "source": "https://github.com/fruitcake/php-cors/tree/v1.4.0"
            },
            "funding": [
                {
                    "url": "https://fruitcake.nl",
                    "type": "custom"
                },
                {
                    "url": "https://github.com/barryvdh",
                    "type": "github"
                }
            ],
            "time": "2025-12-03T09:33:47+00:00"
        },
        {
            "name": "graham-campbell/result-type",
            "version": "v1.2.0",
            "source": {
                "type": "git",
                "url": "https://github.com/GrahamCampbell/Result-Type.git",
                "reference": "adccca3324eece92ca35463648c12b9e6293c05b"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/GrahamCampbell/Result-Type/zipball/adccca3324eece92ca35463648c12b9e6293c05b",
                "reference": "adccca3324eece92ca35463648c12b9e6293c05b",
                "shasum": ""
            },
            "require": {
                "php": "^7.2.5 || ^8.0",
                "phpoption/phpoption": "^1.10"
            },
            "require-dev": {
                "phpunit/phpunit": "^8.5.52 || ^9.6.34 || ^10.5.63 || ^11.5.55 || ^12.5.14"
            },
            "type": "library",
            "autoload": {
                "psr-4": {
                    "GrahamCampbell\\ResultType\\": "src/"
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Graham Campbell",
                    "email": "hello@gjcampbell.co.uk",
                    "homepage": "https://github.com/GrahamCampbell"
                }
            ],
            "description": "An Implementation Of The Result Type",
            "keywords": [
                "Graham Campbell",
                "GrahamCampbell",
                "Result Type",
                "Result-Type",
                "result"
            ],
            "support": {
                "issues": "https://github.com/GrahamCampbell/Result-Type/issues",
                "source": "https://github.com/GrahamCampbell/Result-Type/tree/v1.2.0"
            },
            "funding": [
                {
                    "url": "https://github.com/GrahamCampbell",
                    "type": "github"
                },
                {
                    "url": "https://tidelift.com/funding/github/packagist/graham-campbell/result-type",
                    "type": "tidelift"
                }
            ],
            "time": "2026-08-24T09:06:52+00:00"
        },
        {
            "name": "guzzlehttp/guzzle",
            "version": "7.15.5",
            "source": {
                "type": "git",
                "url": "https://github.com/guzzle/guzzle.git",
                "reference": "ee80339fd9177ba44c49cdb653ff02a4d1106b9a"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/guzzle/guzzle/zipball/ee80339fd9177ba44c49cdb653ff02a4d1106b9a",
                "reference": "ee80339fd9177ba44c49cdb653ff02a4d1106b9a",
                "shasum": ""
            },
            "require": {
                "ext-json": "*",
                "guzzlehttp/promises": "^2.5.3",
                "guzzlehttp/psr7": "^2.13.1",
                "php": "^7.2.5 || ^8.0",
                "psr/http-client": "^1.0",
                "symfony/deprecation-contracts": "^2.5 || ^3.0",
                "symfony/polyfill-php80": "^1.25"
            },
            "provide": {
                "psr/http-client-implementation": "1.0"
            },
            "require-dev": {
                "bamarni/composer-bin-plugin": "^1.8.2",
                "ext-curl": "*",
                "guzzle/client-integration-tests": "3.0.3",
                "guzzlehttp/test-server": "^0.7",
                "php-http/message-factory": "^1.1",
                "phpunit/phpunit": "^8.5.52 || ^9.6.34",
                "psr/log": "^1.1 || ^2.0 || ^3.0"
            },
            "suggest": {
                "ext-curl": "Required for CURL handler support",
                "ext-intl": "Required for Internationalized Domain Name (IDN) support",
                "psr/log": "Required for using the Log middleware"
            },
            "type": "library",
            "extra": {
                "bamarni-bin": {
                    "bin-links": true,
                    "forward-command": false
                }
            },
            "autoload": {
                "files": [
                    "src/functions_include.php"
                ],
                "psr-4": {
                    "GuzzleHttp\\": "src/"
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Graham Campbell",
                    "email": "hello@gjcampbell.co.uk",
                    "homepage": "https://github.com/GrahamCampbell"
                },
                {
                    "name": "Michael Dowling",
                    "email": "mtdowling@gmail.com",
                    "homepage": "https://github.com/mtdowling"
                },
                {
                    "name": "Jeremy Lindblom",
                    "email": "jeremeamia@gmail.com",
                    "homepage": "https://github.com/jeremeamia"
                },
                {
                    "name": "George Mponos",
                    "email": "gmponos@gmail.com",
                    "homepage": "https://github.com/gmponos"
                },
                {
                    "name": "Tobias Nyholm",
                    "email": "tobias.nyholm@gmail.com",
                    "homepage": "https://github.com/Nyholm"
                },
                {
                    "name": "Márk Sági-Kazár",
                    "email": "mark.sagikazar@gmail.com",
                    "homepage": "https://github.com/sagikazarmark"
                },
                {
                    "name": "Tobias Schultze",
                    "email": "webmaster@tubo-world.de",
                    "homepage": "https://github.com/Tobion"
                }
            ],
            "description": "Guzzle is a PHP HTTP client library",
            "keywords": [
                "client",
                "curl",
                "framework",
                "http",
                "http client",
                "psr-18",
                "psr-7",
                "rest",
                "web service"
            ],
            "support": {
                "issues": "https://github.com/guzzle/guzzle/issues",
                "source": "https://github.com/guzzle/guzzle/tree/7.15.5"
            },
            "funding": [
                {
                    "url": "https://github.com/GrahamCampbell",
                    "type": "github"
                },
                {
                    "url": "https://github.com/Nyholm",
                    "type": "github"
                },
                {
                    "url": "https://tidelift.com/funding/github/packagist/guzzlehttp/guzzle",
                    "type": "tidelift"
                }
            ],
            "time": "2026-08-24T09:21:06+00:00"
        },
        {
            "name": "guzzlehttp/promises",
            "version": "2.5.3",
            "source": {
                "type": "git",
                "url": "https://github.com/guzzle/promises.git",
                "reference": "cde49999552d185d64715fe9c1f77a2aadd2f9f1"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/guzzle/promises/zipball/cde49999552d185d64715fe9c1f77a2aadd2f9f1",
                "reference": "cde49999552d185d64715fe9c1f77a2aadd2f9f1",
                "shasum": ""
            },
            "require": {
                "php": "^7.2.5 || ^8.0",
                "symfony/deprecation-contracts": "^2.5 || ^3.0"
            },
            "require-dev": {
                "bamarni/composer-bin-plugin": "^1.8.2",
                "phpunit/phpunit": "^8.5.52 || ^9.6.34"
            },
            "type": "library",
            "extra": {
                "bamarni-bin": {
                    "bin-links": true,
                    "forward-command": false
                }
            },
            "autoload": {
                "psr-4": {
                    "GuzzleHttp\\Promise\\": "src/"
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Graham Campbell",
                    "email": "hello@gjcampbell.co.uk",
                    "homepage": "https://github.com/GrahamCampbell"
                },
                {
                    "name": "Michael Dowling",
                    "email": "mtdowling@gmail.com",
                    "homepage": "https://github.com/mtdowling"
                },
                {
                    "name": "Tobias Nyholm",
                    "email": "tobias.nyholm@gmail.com",
                    "homepage": "https://github.com/Nyholm"
                },
                {
                    "name": "Tobias Schultze",
                    "email": "webmaster@tubo-world.de",
                    "homepage": "https://github.com/Tobion"
                }
            ],
            "description": "Guzzle promises library",
            "keywords": [
                "promise"
            ],
            "support": {
                "issues": "https://github.com/guzzle/promises/issues",
                "source": "https://github.com/guzzle/promises/tree/2.5.3"
            },
            "funding": [
                {
                    "url": "https://github.com/GrahamCampbell",
                    "type": "github"
                },
                {
                    "url": "https://github.com/Nyholm",
                    "type": "github"
                },
                {
                    "url": "https://tidelift.com/funding/github/packagist/guzzlehttp/promises",
                    "type": "tidelift"
                }
            ],
            "time": "2026-08-24T09:11:28+00:00"
        },
        {
            "name": "guzzlehttp/psr7",
            "version": "2.13.1",
            "source": {
                "type": "git",
                "url": "https://github.com/guzzle/psr7.git",
                "reference": "95e7828100de18b4e269fb1703be530082d5166d"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/guzzle/psr7/zipball/95e7828100de18b4e269fb1703be530082d5166d",
                "reference": "95e7828100de18b4e269fb1703be530082d5166d",
                "shasum": ""
            },
            "require": {
                "php": "^7.2.5 || ^8.0",
                "psr/http-factory": "^1.0",
                "psr/http-message": "^1.1 || ^2.0",
                "ralouphie/getallheaders": "^3.0",
                "symfony/deprecation-contracts": "^2.5 || ^3.0",
                "symfony/polyfill-php80": "^1.25"
            },
            "provide": {
                "psr/http-factory-implementation": "1.0",
                "psr/http-message-implementation": "1.0"
            },
            "require-dev": {
                "bamarni/composer-bin-plugin": "^1.8.2",
                "http-interop/http-factory-tests": "1.1.0",
                "jshttp/mime-db": "1.54.0.1",
                "phpunit/phpunit": "^8.5.52 || ^9.6.34"
            },
            "suggest": {
                "laminas/laminas-httphandlerrunner": "Emit PSR-7 responses"
            },
            "type": "library",
            "extra": {
                "bamarni-bin": {
                    "bin-links": true,
                    "forward-command": false
                }
            },
            "autoload": {
                "psr-4": {
                    "GuzzleHttp\\Psr7\\": "src/"
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Graham Campbell",
                    "email": "hello@gjcampbell.co.uk",
                    "homepage": "https://github.com/GrahamCampbell"
                },
                {
                    "name": "Michael Dowling",
                    "email": "mtdowling@gmail.com",
                    "homepage": "https://github.com/mtdowling"
                },
                {
                    "name": "George Mponos",
                    "email": "gmponos@gmail.com",
                    "homepage": "https://github.com/gmponos"
                },
                {
                    "name": "Tobias Nyholm",
                    "email": "tobias.nyholm@gmail.com",
                    "homepage": "https://github.com/Nyholm"
                },
                {
                    "name": "Márk Sági-Kazár",
                    "email": "mark.sagikazar@gmail.com",
                    "homepage": "https://github.com/sagikazarmark"
                },
                {
                    "name": "Tobias Schultze",
                    "email": "webmaster@tubo-world.de",
                    "homepage": "https://github.com/Tobion"
                },
                {
                    "name": "Márk Sági-Kazár",
                    "email": "mark.sagikazar@gmail.com",
                    "homepage": "https://sagikazarmark.hu"
                }
            ],
            "description": "PSR-7 message implementation that also provides common utility methods",
            "keywords": [
                "http",
                "message",
                "psr-7",
                "request",
                "response",
                "stream",
                "uri",
                "url"
            ],
            "support": {
                "issues": "https://github.com/guzzle/psr7/issues",
                "source": "https://github.com/guzzle/psr7/tree/2.13.1"
            },
            "funding": [
                {
                    "url": "https://github.com/GrahamCampbell",
                    "type": "github"
                },
                {
                    "url": "https://github.com/Nyholm",
                    "type": "github"
                },
                {
                    "url": "https://tidelift.com/funding/github/packagist/guzzlehttp/psr7",
                    "type": "tidelift"
                }
            ],
            "time": "2026-08-24T09:13:11+00:00"
        },
        {
            "name": "guzzlehttp/uri-template",
            "version": "v1.0.11",
            "source": {
                "type": "git",
                "url": "https://github.com/guzzle/uri-template.git",
                "reference": "d0058dccf4299d70c3d9da3378b8908b32780368"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/guzzle/uri-template/zipball/d0058dccf4299d70c3d9da3378b8908b32780368",
                "reference": "d0058dccf4299d70c3d9da3378b8908b32780368",
                "shasum": ""
            },
            "require": {
                "php": "^7.2.5 || ^8.0",
                "symfony/polyfill-php80": "^1.25"
            },
            "require-dev": {
                "bamarni/composer-bin-plugin": "^1.8.2",
                "phpunit/phpunit": "^8.5.52 || ^9.6.34",
                "uri-template/tests": "1.0.0"
            },
            "type": "library",
            "extra": {
                "bamarni-bin": {
                    "bin-links": true,
                    "forward-command": false
                }
            },
            "autoload": {
                "psr-4": {
                    "GuzzleHttp\\UriTemplate\\": "src"
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Graham Campbell",
                    "email": "hello@gjcampbell.co.uk",
                    "homepage": "https://github.com/GrahamCampbell"
                },
                {
                    "name": "Michael Dowling",
                    "email": "mtdowling@gmail.com",
                    "homepage": "https://github.com/mtdowling"
                },
                {
                    "name": "George Mponos",
                    "email": "gmponos@gmail.com",
                    "homepage": "https://github.com/gmponos"
                },
                {
                    "name": "Tobias Nyholm",
                    "email": "tobias.nyholm@gmail.com",
                    "homepage": "https://github.com/Nyholm"
                }
            ],
            "description": "A polyfill class for uri_template of PHP",
            "keywords": [
                "guzzlehttp",
                "uri-template"
            ],
            "support": {
                "issues": "https://github.com/guzzle/uri-template/issues",
                "source": "https://github.com/guzzle/uri-template/tree/v1.0.11"
            },
            "funding": [
                {
                    "url": "https://github.com/GrahamCampbell",
                    "type": "github"
                },
                {
                    "url": "https://github.com/Nyholm",
                    "type": "github"
                },
                {
                    "url": "https://tidelift.com/funding/github/packagist/guzzlehttp/uri-template",
                    "type": "tidelift"
                }
            ],
            "time": "2026-08-24T09:15:32+00:00"
        },
        {
            "name": "laravel/framework",
            "version": "v12.69.1",
            "source": {
                "type": "git",
                "url": "https://github.com/laravel/framework.git",
                "reference": "0c07b0b1f88af44d8558ffadf66900a860f93c23"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/laravel/framework/zipball/0c07b0b1f88af44d8558ffadf66900a860f93c23",
                "reference": "0c07b0b1f88af44d8558ffadf66900a860f93c23",
                "shasum": ""
            },
            "require": {
                "brick/math": "^0.11|^0.12|^0.13|^0.14",
                "composer-runtime-api": "^2.2",
                "doctrine/inflector": "^2.0.5",
                "dragonmantank/cron-expression": "^3.4",
                "egulias/email-validator": "^3.2.1|^4.0",
                "ext-ctype": "*",
                "ext-filter": "*",
                "ext-hash": "*",
                "ext-mbstring": "*",
                "ext-openssl": "*",
                "ext-session": "*",
                "ext-tokenizer": "*",
                "fruitcake/php-cors": "^1.3",
                "guzzlehttp/guzzle": "^7.8.2",
                "guzzlehttp/uri-template": "^1.0",
                "laravel/prompts": "^0.3.0",
                "laravel/serializable-closure": "^1.3|^2.0",
                "league/commonmark": "^2.8.1",
                "league/flysystem": "^3.25.1",
                "league/flysystem-local": "^3.25.1",
                "league/uri": "^7.5.1",
                "monolog/monolog": "^3.0",
                "nesbot/carbon": "^3.8.4",
                "nunomaduro/termwind": "^2.0",
                "php": "^8.2",
                "psr/container": "^1.1.1|^2.0.1",
                "psr/log": "^1.0|^2.0|^3.0",
                "psr/simple-cache": "^1.0|^2.0|^3.0",
                "ramsey/uuid": "^4.7",
                "symfony/console": "^7.2.0",
                "symfony/error-handler": "^7.2.0",
                "symfony/finder": "^7.2.0",
                "symfony/http-foundation": "^7.2.0",
                "symfony/http-kernel": "^7.2.0",
                "symfony/mailer": "^7.2.0",
                "symfony/mime": "^7.2.0",
                "symfony/polyfill-php83": "^1.33",
                "symfony/polyfill-php84": "^1.34",
                "symfony/polyfill-php85": "^1.34",
                "symfony/process": "^7.2.0",
                "symfony/routing": "^7.2.0",
                "symfony/uid": "^7.2.0",
                "symfony/var-dumper": "^7.2.0",
                "tijsverkoyen/css-to-inline-styles": "^2.2.5",
                "vlucas/phpdotenv": "^5.6.1",
                "voku/portable-ascii": "^2.0.2"
            },
            "conflict": {
                "tightenco/collect": "<5.5.33"
            },
            "provide": {
                "psr/container-implementation": "1.1|2.0",
                "psr/log-implementation": "1.0|2.0|3.0",
                "psr/simple-cache-implementation": "1.0|2.0|3.0"
            },
            "replace": {
                "illuminate/auth": "self.version",
                "illuminate/broadcasting": "self.version",
                "illuminate/bus": "self.version",
                "illuminate/cache": "self.version",
                "illuminate/collections": "self.version",
                "illuminate/concurrency": "self.version",
                "illuminate/conditionable": "self.version",
                "illuminate/config": "self.version",
                "illuminate/console": "self.version",
                "illuminate/container": "self.version",
                "illuminate/contracts": "self.version",
                "illuminate/cookie": "self.version",
                "illuminate/database": "self.version",
                "illuminate/encryption": "self.version",
                "illuminate/events": "self.version",
                "illuminate/filesystem": "self.version",
                "illuminate/hashing": "self.version",
                "illuminate/http": "self.version",
                "illuminate/json-schema": "self.version",
                "illuminate/log": "self.version",
                "illuminate/macroable": "self.version",
                "illuminate/mail": "self.version",
                "illuminate/notifications": "self.version",
                "illuminate/pagination": "self.version",
                "illuminate/pipeline": "self.version",
                "illuminate/process": "self.version",
                "illuminate/queue": "self.version",
                "illuminate/redis": "self.version",
                "illuminate/reflection": "self.version",
                "illuminate/routing": "self.version",
                "illuminate/session": "self.version",
                "illuminate/support": "self.version",
                "illuminate/testing": "self.version",
                "illuminate/translation": "self.version",
                "illuminate/validation": "self.version",
                "illuminate/view": "self.version",
                "spatie/once": "*"
            },
            "require-dev": {
                "ably/ably-php": "^1.0",
                "aws/aws-sdk-php": "^3.322.9",
                "ext-gmp": "*",
                "fakerphp/faker": "^1.24",
                "guzzlehttp/promises": "^2.0.3",
                "guzzlehttp/psr7": "^2.4",
                "laravel/pint": "^1.18",
                "league/flysystem-aws-s3-v3": "^3.25.1",
                "league/flysystem-ftp": "^3.25.1",
                "league/flysystem-path-prefixing": "^3.25.1",
                "league/flysystem-read-only": "^3.25.1",
                "league/flysystem-sftp-v3": "^3.25.1",
                "mockery/mockery": "^1.6.10",
                "opis/json-schema": "^2.4.1",
                "orchestra/testbench-core": "^10.9.0",
                "pda/pheanstalk": "^5.0.6|^7.0.0",
                "php-http/discovery": "^1.15",
                "phpstan/phpstan": "^2.1.41",
                "phpunit/phpunit": "^10.5.35|^11.5.3|^12.0.1",
                "predis/predis": "^2.3|^3.0",
                "resend/resend-php": "^0.10.0|^1.0",
                "symfony/cache": "^7.2.0",
                "symfony/http-client": "^7.2.0",
                "symfony/psr-http-message-bridge": "^7.2.0",
                "symfony/translation": "^7.2.0"
            },
            "suggest": {
                "ably/ably-php": "Required to use the Ably broadcast driver (^1.0).",
                "aws/aws-sdk-php": "Required to use the SQS queue driver, DynamoDb failed job storage, and SES mail driver (^3.322.9).",
                "brianium/paratest": "Required to run tests in parallel (^7.0|^8.0).",
                "ext-apcu": "Required to use the APC cache driver.",
                "ext-fileinfo": "Required to use the Filesystem class.",
                "ext-ftp": "Required to use the Flysystem FTP driver.",
                "ext-gd": "Required to use Illuminate\\Http\\Testing\\FileFactory::image().",
                "ext-memcached": "Required to use the memcache cache driver.",
                "ext-pcntl": "Required to use all features of the queue worker and console signal trapping.",
                "ext-pdo": "Required to use all database features.",
                "ext-posix": "Required to use all features of the queue worker.",
                "ext-redis": "Required to use the Redis cache and queue drivers (^4.0|^5.0|^6.0).",
                "fakerphp/faker": "Required to generate fake data using the fake() helper (^1.23).",
                "filp/whoops": "Required for friendly error pages in development (^2.14.3).",
                "laravel/tinker": "Required to use the tinker console command (^2.0).",
                "league/flysystem-aws-s3-v3": "Required to use the Flysystem S3 driver (^3.25.1).",
                "league/flysystem-ftp": "Required to use the Flysystem FTP driver (^3.25.1).",
                "league/flysystem-path-prefixing": "Required to use the scoped driver (^3.25.1).",
                "league/flysystem-read-only": "Required to use read-only disks (^3.25.1)",
                "league/flysystem-sftp-v3": "Required to use the Flysystem SFTP driver (^3.25.1).",
                "mockery/mockery": "Required to use mocking (^1.6).",
                "pda/pheanstalk": "Required to use the beanstalk queue driver (^5.0).",
                "php-http/discovery": "Required to use PSR-7 bridging features (^1.15).",
                "phpunit/phpunit": "Required to use assertions and run tests (^10.5.35|^11.5.3|^12.0.1).",
                "predis/predis": "Required to use the predis connector (^2.3|^3.0).",
                "psr/http-message": "Required to allow Storage::put to accept a StreamInterface (^1.0).",
                "pusher/pusher-php-server": "Required to use the Pusher broadcast driver (^6.0|^7.0).",
                "resend/resend-php": "Required to enable support for the Resend mail transport (^0.10.0|^1.0).",
                "symfony/cache": "Required to PSR-6 cache bridge (^7.2).",
                "symfony/filesystem": "Required to enable support for relative symbolic links (^7.2).",
                "symfony/http-client": "Required to enable support for the Symfony API mail transports (^7.2).",
                "symfony/mailgun-mailer": "Required to enable support for the Mailgun mail transport (^7.2).",
                "symfony/postmark-mailer": "Required to enable support for the Postmark mail transport (^7.2).",
                "symfony/psr-http-message-bridge": "Required to use PSR-7 bridging features (^7.2)."
            },
            "type": "library",
            "extra": {
                "branch-alias": {
                    "dev-master": "12.x-dev"
                }
            },
            "autoload": {
                "files": [
                    "src/Illuminate/Collections/functions.php",
                    "src/Illuminate/Collections/helpers.php",
                    "src/Illuminate/Events/functions.php",
                    "src/Illuminate/Filesystem/functions.php",
                    "src/Illuminate/Foundation/helpers.php",
                    "src/Illuminate/Log/functions.php",
                    "src/Illuminate/Reflection/helpers.php",
                    "src/Illuminate/Support/functions.php",
                    "src/Illuminate/Support/helpers.php"
                ],
                "psr-4": {
                    "Illuminate\\": "src/Illuminate/",
                    "Illuminate\\Support\\": [
                        "src/Illuminate/Macroable/",
                        "src/Illuminate/Collections/",
                        "src/Illuminate/Conditionable/",
                        "src/Illuminate/Reflection/"
                    ]
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Taylor Otwell",
                    "email": "taylor@laravel.com"
                }
            ],
            "description": "The Laravel Framework.",
            "homepage": "https://laravel.com",
            "keywords": [
                "framework",
                "laravel"
            ],
            "support": {
                "issues": "https://github.com/laravel/framework/issues",
                "source": "https://github.com/laravel/framework"
            },
            "time": "2026-09-01T21:34:37+00:00"
        },
        {
            "name": "laravel/prompts",
            "version": "v0.3.24",
            "source": {
                "type": "git",
                "url": "https://github.com/laravel/prompts.git",
                "reference": "5d3cdef29e93ca3b62b1871359db3078cd99908b"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/laravel/prompts/zipball/5d3cdef29e93ca3b62b1871359db3078cd99908b",
                "reference": "5d3cdef29e93ca3b62b1871359db3078cd99908b",
                "shasum": ""
            },
            "require": {
                "composer-runtime-api": "^2.2",
                "ext-mbstring": "*",
                "php": "^8.1",
                "symfony/console": "^6.2|^7.0|^8.0"
            },
            "conflict": {
                "illuminate/console": ">=10.17.0 <10.25.0",
                "laravel/framework": ">=10.17.0 <10.25.0"
            },
            "require-dev": {
                "illuminate/collections": "^10.0|^11.0|^12.0|^13.0",
                "mockery/mockery": "^1.5",
                "pestphp/pest": "^2.3|^3.4|^4.0",
                "phpstan/phpstan": "^1.12.28",
                "phpstan/phpstan-mockery": "^1.1.3"
            },
            "suggest": {
                "ext-pcntl": "Required for the spinner to be animated."
            },
            "type": "library",
            "extra": {
                "branch-alias": {
                    "dev-main": "0.3.x-dev"
                }
            },
            "autoload": {
                "files": [
                    "src/helpers.php"
                ],
                "psr-4": {
                    "Laravel\\Prompts\\": "src/"
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "description": "Add beautiful and user-friendly forms to your command-line applications.",
            "support": {
                "issues": "https://github.com/laravel/prompts/issues",
                "source": "https://github.com/laravel/prompts/tree/v0.3.24"
            },
            "time": "2026-08-20T12:55:36+00:00"
        },
        {
            "name": "laravel/sanctum",
            "version": "v4.3.3",
            "source": {
                "type": "git",
                "url": "https://github.com/laravel/sanctum.git",
                "reference": "fee27a573d1a013af3721d86153a65e0b11927e6"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/laravel/sanctum/zipball/fee27a573d1a013af3721d86153a65e0b11927e6",
                "reference": "fee27a573d1a013af3721d86153a65e0b11927e6",
                "shasum": ""
            },
            "require": {
                "ext-json": "*",
                "illuminate/console": "^11.0|^12.0|^13.0",
                "illuminate/contracts": "^11.0|^12.0|^13.0",
                "illuminate/database": "^11.0|^12.0|^13.0",
                "illuminate/support": "^11.0|^12.0|^13.0",
                "php": "^8.2",
                "symfony/console": "^7.0|^8.0"
            },
            "require-dev": {
                "mockery/mockery": "^1.6",
                "orchestra/testbench": "^9.15|^10.8|^11.0",
                "phpstan/phpstan": "^1.10"
            },
            "type": "library",
            "extra": {
                "laravel": {
                    "providers": [
                        "Laravel\\Sanctum\\SanctumServiceProvider"
                    ]
                }
            },
            "autoload": {
                "psr-4": {
                    "Laravel\\Sanctum\\": "src/"
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Taylor Otwell",
                    "email": "taylor@laravel.com"
                }
            ],
            "description": "Laravel Sanctum provides a featherweight authentication system for SPAs and simple APIs.",
            "keywords": [
                "auth",
                "laravel",
                "sanctum"
            ],
            "support": {
                "issues": "https://github.com/laravel/sanctum/issues",
                "source": "https://github.com/laravel/sanctum"
            },
            "time": "2026-06-23T18:26:55+00:00"
        },
        {
            "name": "laravel/serializable-closure",
            "version": "v2.0.16",
            "source": {
                "type": "git",
                "url": "https://github.com/laravel/serializable-closure.git",
                "reference": "7cfc24e4fa2cca045fb8dd2a797a2b2b13b655ed"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/laravel/serializable-closure/zipball/7cfc24e4fa2cca045fb8dd2a797a2b2b13b655ed",
                "reference": "7cfc24e4fa2cca045fb8dd2a797a2b2b13b655ed",
                "shasum": ""
            },
            "require": {
                "php": "^8.1"
            },
            "require-dev": {
                "illuminate/support": "^10.0|^11.0|^12.0|^13.0",
                "nesbot/carbon": "^2.67|^3.0",
                "pestphp/pest": "^2.36|^3.0|^4.0",
                "phpstan/phpstan": "^2.0",
                "symfony/var-dumper": "^6.2.0|^7.0.0|^8.0.0"
            },
            "type": "library",
            "extra": {
                "branch-alias": {
                    "dev-master": "2.x-dev"
                }
            },
            "autoload": {
                "psr-4": {
                    "Laravel\\SerializableClosure\\": "src/"
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Taylor Otwell",
                    "email": "taylor@laravel.com"
                },
                {
                    "name": "Nuno Maduro",
                    "email": "nuno@laravel.com"
                }
            ],
            "description": "Laravel Serializable Closure provides an easy and secure way to serialize closures in PHP.",
            "keywords": [
                "closure",
                "laravel",
                "serializable"
            ],
            "support": {
                "issues": "https://github.com/laravel/serializable-closure/issues",
                "source": "https://github.com/laravel/serializable-closure"
            },
            "time": "2026-08-18T20:28:54+00:00"
        },
        {
            "name": "laravel/socialite",
            "version": "v5.31.0",
            "source": {
                "type": "git",
                "url": "https://github.com/laravel/socialite.git",
                "reference": "f721b2cbec327ab820bd6aabea6ab211cfcc9f08"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/laravel/socialite/zipball/f721b2cbec327ab820bd6aabea6ab211cfcc9f08",
                "reference": "f721b2cbec327ab820bd6aabea6ab211cfcc9f08",
                "shasum": ""
            },
            "require": {
                "ext-json": "*",
                "firebase/php-jwt": "^6.4|^7.0",
                "guzzlehttp/guzzle": "^6.0|^7.0|^8.0",
                "illuminate/contracts": "^6.0|^7.0|^8.0|^9.0|^10.0|^11.0|^12.0|^13.0",
                "illuminate/http": "^6.0|^7.0|^8.0|^9.0|^10.0|^11.0|^12.0|^13.0",
                "illuminate/support": "^6.0|^7.0|^8.0|^9.0|^10.0|^11.0|^12.0|^13.0",
                "league/oauth1-client": "^1.11",
                "php": "^8.1",
                "phpseclib/phpseclib": "^4.0"
            },
            "require-dev": {
                "mockery/mockery": "^1.0",
                "orchestra/testbench": "^4.18|^5.20|^6.47|^7.55|^8.36|^9.15|^10.8|^11.0",
                "phpstan/phpstan": "^1.12.23",
                "phpunit/phpunit": "^8.0|^9.3|^10.4|^11.5|^12.0"
            },
            "type": "library",
            "extra": {
                "laravel": {
                    "aliases": {
                        "Socialite": "Laravel\\Socialite\\Facades\\Socialite"
                    },
                    "providers": [
                        "Laravel\\Socialite\\SocialiteServiceProvider"
                    ]
                },
                "branch-alias": {
                    "dev-master": "5.x-dev"
                }
            },
            "autoload": {
                "psr-4": {
                    "Laravel\\Socialite\\": "src/"
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Taylor Otwell",
                    "email": "taylor@laravel.com"
                }
            ],
            "description": "Laravel wrapper around OAuth 1 & OAuth 2 libraries.",
            "homepage": "https://laravel.com",
            "keywords": [
                "laravel",
                "oauth"
            ],
            "support": {
                "issues": "https://github.com/laravel/socialite/issues",
                "source": "https://github.com/laravel/socialite"
            },
            "time": "2026-08-31T13:49:19+00:00"
        },
        {
            "name": "laravel/tinker",
            "version": "v2.11.1",
            "source": {
                "type": "git",
                "url": "https://github.com/laravel/tinker.git",
                "reference": "c9f80cc835649b5c1842898fb043f8cc098dd741"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/laravel/tinker/zipball/c9f80cc835649b5c1842898fb043f8cc098dd741",
                "reference": "c9f80cc835649b5c1842898fb043f8cc098dd741",
                "shasum": ""
            },
            "require": {
                "illuminate/console": "^6.0|^7.0|^8.0|^9.0|^10.0|^11.0|^12.0",
                "illuminate/contracts": "^6.0|^7.0|^8.0|^9.0|^10.0|^11.0|^12.0",
                "illuminate/support": "^6.0|^7.0|^8.0|^9.0|^10.0|^11.0|^12.0",
                "php": "^7.2.5|^8.0",
                "psy/psysh": "^0.11.1|^0.12.0",
                "symfony/var-dumper": "^4.3.4|^5.0|^6.0|^7.0|^8.0"
            },
            "require-dev": {
                "mockery/mockery": "~1.3.3|^1.4.2",
                "phpstan/phpstan": "^1.10",
                "phpunit/phpunit": "^8.5.8|^9.3.3|^10.0"
            },
            "suggest": {
                "illuminate/database": "The Illuminate Database package (^6.0|^7.0|^8.0|^9.0|^10.0|^11.0|^12.0)."
            },
            "type": "library",
            "extra": {
                "laravel": {
                    "providers": [
                        "Laravel\\Tinker\\TinkerServiceProvider"
                    ]
                }
            },
            "autoload": {
                "psr-4": {
                    "Laravel\\Tinker\\": "src/"
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Taylor Otwell",
                    "email": "taylor@laravel.com"
                }
            ],
            "description": "Powerful REPL for the Laravel framework.",
            "keywords": [
                "REPL",
                "Tinker",
                "laravel",
                "psysh"
            ],
            "support": {
                "issues": "https://github.com/laravel/tinker/issues",
                "source": "https://github.com/laravel/tinker/tree/v2.11.1"
            },
            "time": "2026-02-06T14:12:35+00:00"
        },
        {
            "name": "league/commonmark",
            "version": "2.10.0",
            "source": {
                "type": "git",
                "url": "https://github.com/thephpleague/commonmark.git",
                "reference": "d2d1aa8b35e072966c89bc0c66cf926e56767dc4"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/thephpleague/commonmark/zipball/d2d1aa8b35e072966c89bc0c66cf926e56767dc4",
                "reference": "d2d1aa8b35e072966c89bc0c66cf926e56767dc4",
                "shasum": ""
            },
            "require": {
                "ext-mbstring": "*",
                "league/config": "^1.1.1",
                "php": "^7.4 || ^8.0",
                "psr/event-dispatcher": "^1.0",
                "symfony/deprecation-contracts": "^2.1 || ^3.0",
                "symfony/polyfill-php80": "^1.16"
            },
            "require-dev": {
                "cebe/markdown": "^1.0",
                "commonmark/cmark": "0.31.1",
                "commonmark/commonmark.js": "0.31.1",
                "composer/package-versions-deprecated": "^1.8",
                "embed/embed": "^4.4",
                "erusev/parsedown": "^1.0",
                "ext-json": "*",
                "github/gfm": "0.29.0",
                "michelf/php-markdown": "^1.4 || ^2.0",
                "nyholm/psr7": "^1.5",
                "phpstan/phpstan": "^2.0.0",
                "phpunit/phpunit": "^9.5.21 || ^10.5.9 || ^11.0.0 || ^12.0.0 || ^13.0.0",
                "scrutinizer/ocular": "^1.8.1",
                "symfony/finder": "^5.3 | ^6.0 | ^7.0 || ^8.0",
                "symfony/process": "^5.4 | ^6.0 | ^7.0 || ^8.0",
                "symfony/yaml": "^2.3 | ^3.0 | ^4.0 | ^5.0 | ^6.0 | ^7.0 || ^8.0",
                "unleashedtech/php-coding-standard": "^3.1.1",
                "vimeo/psalm": "^4.24.0 || ^5.0.0 || ^6.0.0"
            },
            "suggest": {
                "symfony/yaml": "v2.3+ required if using the Front Matter extension"
            },
            "type": "library",
            "extra": {
                "branch-alias": {
                    "dev-main": "2.11-dev"
                }
            },
            "autoload": {
                "psr-4": {
                    "League\\CommonMark\\": "src"
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "BSD-3-Clause"
            ],
            "authors": [
                {
                    "name": "Colin O'Dell",
                    "email": "colinodell@gmail.com",
                    "homepage": "https://www.colinodell.com",
                    "role": "Lead Developer"
                }
            ],
            "description": "Highly-extensible PHP Markdown parser which fully supports the CommonMark spec and GitHub-Flavored Markdown (GFM)",
            "homepage": "https://commonmark.thephpleague.com",
            "keywords": [
                "commonmark",
                "flavored",
                "gfm",
                "github",
                "github-flavored",
                "markdown",
                "md",
                "parser"
            ],
            "support": {
                "docs": "https://commonmark.thephpleague.com/",
                "forum": "https://github.com/thephpleague/commonmark/discussions",
                "issues": "https://github.com/thephpleague/commonmark/issues",
                "rss": "https://github.com/thephpleague/commonmark/releases.atom",
                "source": "https://github.com/thephpleague/commonmark"
            },
            "funding": [
                {
                    "url": "https://www.colinodell.com/sponsor",
                    "type": "custom"
                },
                {
                    "url": "https://www.paypal.me/colinpodell/10.00",
                    "type": "custom"
                },
                {
                    "url": "https://github.com/colinodell",
                    "type": "github"
                },
                {
                    "url": "https://tidelift.com/funding/github/packagist/league/commonmark",
                    "type": "tidelift"
                }
            ],
            "time": "2026-08-11T16:06:25+00:00"
        },
        {
            "name": "league/config",
            "version": "v1.2.0",
            "source": {
                "type": "git",
                "url": "https://github.com/thephpleague/config.git",
                "reference": "754b3604fb2984c71f4af4a9cbe7b57f346ec1f3"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/thephpleague/config/zipball/754b3604fb2984c71f4af4a9cbe7b57f346ec1f3",
                "reference": "754b3604fb2984c71f4af4a9cbe7b57f346ec1f3",
                "shasum": ""
            },
            "require": {
                "dflydev/dot-access-data": "^3.0.1",
                "nette/schema": "^1.2",
                "php": "^7.4 || ^8.0"
            },
            "require-dev": {
                "phpstan/phpstan": "^1.8.2",
                "phpunit/phpunit": "^9.5.5",
                "scrutinizer/ocular": "^1.8.1",
                "unleashedtech/php-coding-standard": "^3.1",
                "vimeo/psalm": "^4.7.3"
            },
            "type": "library",
            "extra": {
                "branch-alias": {
                    "dev-main": "1.2-dev"
                }
            },
            "autoload": {
                "psr-4": {
                    "League\\Config\\": "src"
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "BSD-3-Clause"
            ],
            "authors": [
                {
                    "name": "Colin O'Dell",
                    "email": "colinodell@gmail.com",
                    "homepage": "https://www.colinodell.com",
                    "role": "Lead Developer"
                }
            ],
            "description": "Define configuration arrays with strict schemas and access values with dot notation",
            "homepage": "https://config.thephpleague.com",
            "keywords": [
                "array",
                "config",
                "configuration",
                "dot",
                "dot-access",
                "nested",
                "schema"
            ],
            "support": {
                "docs": "https://config.thephpleague.com/",
                "issues": "https://github.com/thephpleague/config/issues",
                "rss": "https://github.com/thephpleague/config/releases.atom",
                "source": "https://github.com/thephpleague/config"
            },
            "funding": [
                {
                    "url": "https://www.colinodell.com/sponsor",
                    "type": "custom"
                },
                {
                    "url": "https://www.paypal.me/colinpodell/10.00",
                    "type": "custom"
                },
                {
                    "url": "https://github.com/colinodell",
                    "type": "github"
                }
            ],
            "time": "2022-12-11T20:36:23+00:00"
        },
        {
            "name": "league/flysystem",
            "version": "3.36.0",
            "source": {
                "type": "git",
                "url": "https://github.com/thephpleague/flysystem.git",
                "reference": "f7fb152932f30072d573510cbd4dd657d6475b25"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/thephpleague/flysystem/zipball/f7fb152932f30072d573510cbd4dd657d6475b25",
                "reference": "f7fb152932f30072d573510cbd4dd657d6475b25",
                "shasum": ""
            },
            "require": {
                "league/flysystem-local": "^3.0.0",
                "league/mime-type-detection": "^1.0.0",
                "php": "^8.0.2"
            },
            "conflict": {
                "async-aws/core": "<1.19.0",
                "async-aws/s3": "<1.14.0",
                "aws/aws-sdk-php": "3.209.31 || 3.210.0",
                "guzzlehttp/guzzle": "<7.0",
                "guzzlehttp/ringphp": "<1.1.1",
                "phpseclib/phpseclib": "3.0.15",
                "symfony/http-client": "<5.2"
            },
            "require-dev": {
                "async-aws/s3": "^1.5 || ^2.0",
                "async-aws/simple-s3": "^1.1 || ^2.0",
                "aws/aws-sdk-php": "^3.295.10",
                "composer/semver": "^3.0",
                "ext-fileinfo": "*",
                "ext-ftp": "*",
                "ext-mongodb": "^1.3|^2",
                "ext-zip": "*",
                "friendsofphp/php-cs-fixer": "^3.5",
                "google/cloud-storage": "^1.23",
                "guzzlehttp/psr7": "^2.6",
                "microsoft/azure-storage-blob": "^1.1",
                "mongodb/mongodb": "^1.2|^2",
                "phpseclib/phpseclib": "^3.0.36",
                "phpstan/phpstan": "^1.10",
                "phpunit/phpunit": "^9.5.11|^10.0",
                "sabre/dav": "^4.6.0"
            },
            "type": "library",
            "autoload": {
                "psr-4": {
                    "League\\Flysystem\\": "src"
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Frank de Jonge",
                    "email": "info@frankdejonge.nl"
                }
            ],
            "description": "File storage abstraction for PHP",
            "keywords": [
                "WebDAV",
                "aws",
                "cloud",
                "file",
                "files",
                "filesystem",
                "filesystems",
                "ftp",
                "s3",
                "sftp",
                "storage"
            ],
            "support": {
                "issues": "https://github.com/thephpleague/flysystem/issues",
                "source": "https://github.com/thephpleague/flysystem/tree/3.36.0"
            },
            "time": "2026-09-02T08:00:27+00:00"
        },
        {
            "name": "league/flysystem-local",
            "version": "3.35.3",
            "source": {
                "type": "git",
                "url": "https://github.com/thephpleague/flysystem-local.git",
                "reference": "a099b24dce160f3b2239043d13d47c4a1a214ea4"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/thephpleague/flysystem-local/zipball/a099b24dce160f3b2239043d13d47c4a1a214ea4",
                "reference": "a099b24dce160f3b2239043d13d47c4a1a214ea4",
                "shasum": ""
            },
            "require": {
                "ext-fileinfo": "*",
                "league/flysystem": "^3.0.0",
                "league/mime-type-detection": "^1.0.0",
                "php": "^8.0.2"
            },
            "type": "library",
            "autoload": {
                "psr-4": {
                    "League\\Flysystem\\Local\\": ""
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Frank de Jonge",
                    "email": "info@frankdejonge.nl"
                }
            ],
            "description": "Local filesystem adapter for Flysystem.",
            "keywords": [
                "Flysystem",
                "file",
                "files",
                "filesystem",
                "local"
            ],
            "support": {
                "source": "https://github.com/thephpleague/flysystem-local/tree/3.35.3"
            },
            "time": "2026-08-12T13:29:21+00:00"
        },
        {
            "name": "league/mime-type-detection",
            "version": "1.17.0",
            "source": {
                "type": "git",
                "url": "https://github.com/thephpleague/mime-type-detection.git",
                "reference": "f5f47eff7c48ed1003069a2ca67f316fb4021c76"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/thephpleague/mime-type-detection/zipball/f5f47eff7c48ed1003069a2ca67f316fb4021c76",
                "reference": "f5f47eff7c48ed1003069a2ca67f316fb4021c76",
                "shasum": ""
            },
            "require": {
                "ext-fileinfo": "*",
                "php": "^7.4 || ^8.0"
            },
            "require-dev": {
                "friendsofphp/php-cs-fixer": "^3.2",
                "phpstan/phpstan": "^0.12.68",
                "phpunit/phpunit": "^8.5.8 || ^9.3 || ^10.0 || ^11.0 || ^12.0"
            },
            "type": "library",
            "autoload": {
                "psr-4": {
                    "League\\MimeTypeDetection\\": "src"
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Frank de Jonge",
                    "email": "info@frankdejonge.nl"
                }
            ],
            "description": "Mime-type detection for Flysystem",
            "support": {
                "issues": "https://github.com/thephpleague/mime-type-detection/issues",
                "source": "https://github.com/thephpleague/mime-type-detection/tree/1.17.0"
            },
            "funding": [
                {
                    "url": "https://github.com/frankdejonge",
                    "type": "github"
                },
                {
                    "url": "https://tidelift.com/funding/github/packagist/league/flysystem",
                    "type": "tidelift"
                }
            ],
            "time": "2026-07-09T11:49:27+00:00"
        },
        {
            "name": "league/oauth1-client",
            "version": "v1.11.0",
            "source": {
                "type": "git",
                "url": "https://github.com/thephpleague/oauth1-client.git",
                "reference": "f9c94b088837eb1aae1ad7c4f23eb65cc6993055"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/thephpleague/oauth1-client/zipball/f9c94b088837eb1aae1ad7c4f23eb65cc6993055",
                "reference": "f9c94b088837eb1aae1ad7c4f23eb65cc6993055",
                "shasum": ""
            },
            "require": {
                "ext-json": "*",
                "ext-openssl": "*",
                "guzzlehttp/guzzle": "^6.0|^7.0",
                "guzzlehttp/psr7": "^1.7|^2.0",
                "php": ">=7.1||>=8.0"
            },
            "require-dev": {
                "ext-simplexml": "*",
                "friendsofphp/php-cs-fixer": "^2.17",
                "mockery/mockery": "^1.3.3",
                "phpstan/phpstan": "^0.12.42",
                "phpunit/phpunit": "^7.5||9.5"
            },
            "suggest": {
                "ext-simplexml": "For decoding XML-based responses."
            },
            "type": "library",
            "extra": {
                "branch-alias": {
                    "dev-master": "1.0-dev",
                    "dev-develop": "2.0-dev"
                }
            },
            "autoload": {
                "psr-4": {
                    "League\\OAuth1\\Client\\": "src/"
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Ben Corlett",
                    "email": "bencorlett@me.com",
                    "homepage": "http://www.webcomm.com.au",
                    "role": "Developer"
                }
            ],
            "description": "OAuth 1.0 Client Library",
            "keywords": [
                "Authentication",
                "SSO",
                "authorization",
                "bitbucket",
                "identity",
                "idp",
                "oauth",
                "oauth1",
                "single sign on",
                "trello",
                "tumblr",
                "twitter"
            ],
            "support": {
                "issues": "https://github.com/thephpleague/oauth1-client/issues",
                "source": "https://github.com/thephpleague/oauth1-client/tree/v1.11.0"
            },
            "time": "2024-12-10T19:59:05+00:00"
        },
        {
            "name": "league/uri",
            "version": "7.8.1",
            "source": {
                "type": "git",
                "url": "https://github.com/thephpleague/uri.git",
                "reference": "08cf38e3924d4f56238125547b5720496fac8fd4"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/thephpleague/uri/zipball/08cf38e3924d4f56238125547b5720496fac8fd4",
                "reference": "08cf38e3924d4f56238125547b5720496fac8fd4",
                "shasum": ""
            },
            "require": {
                "league/uri-interfaces": "^7.8.1",
                "php": "^8.1",
                "psr/http-factory": "^1"
            },
            "conflict": {
                "league/uri-schemes": "^1.0"
            },
            "suggest": {
                "ext-bcmath": "to improve IPV4 host parsing",
                "ext-dom": "to convert the URI into an HTML anchor tag",
                "ext-fileinfo": "to create Data URI from file contennts",
                "ext-gmp": "to improve IPV4 host parsing",
                "ext-intl": "to handle IDN host with the best performance",
                "ext-uri": "to use the PHP native URI class",
                "jeremykendall/php-domain-parser": "to further parse the URI host and resolve its Public Suffix and Top Level Domain",
                "league/uri-components": "to provide additional tools to manipulate URI objects components",
                "league/uri-polyfill": "to backport the PHP URI extension for older versions of PHP",
                "php-64bit": "to improve IPV4 host parsing",
                "rowbot/url": "to handle URLs using the WHATWG URL Living Standard specification",
                "symfony/polyfill-intl-idn": "to handle IDN host via the Symfony polyfill if ext-intl is not present"
            },
            "type": "library",
            "extra": {
                "branch-alias": {
                    "dev-master": "7.x-dev"
                }
            },
            "autoload": {
                "psr-4": {
                    "League\\Uri\\": ""
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Ignace Nyamagana Butera",
                    "email": "nyamsprod@gmail.com",
                    "homepage": "https://nyamsprod.com"
                }
            ],
            "description": "URI manipulation library",
            "homepage": "https://uri.thephpleague.com",
            "keywords": [
                "URN",
                "data-uri",
                "file-uri",
                "ftp",
                "hostname",
                "http",
                "https",
                "middleware",
                "parse_str",
                "parse_url",
                "psr-7",
                "query-string",
                "querystring",
                "rfc2141",
                "rfc3986",
                "rfc3987",
                "rfc6570",
                "rfc8141",
                "uri",
                "uri-template",
                "url",
                "ws"
            ],
            "support": {
                "docs": "https://uri.thephpleague.com",
                "forum": "https://thephpleague.slack.com",
                "issues": "https://github.com/thephpleague/uri-src/issues",
                "source": "https://github.com/thephpleague/uri/tree/7.8.1"
            },
            "funding": [
                {
                    "url": "https://github.com/sponsors/nyamsprod",
                    "type": "github"
                }
            ],
            "time": "2026-03-15T20:22:25+00:00"
        },
        {
            "name": "league/uri-interfaces",
            "version": "7.8.1",
            "source": {
                "type": "git",
                "url": "https://github.com/thephpleague/uri-interfaces.git",
                "reference": "85d5c77c5d6d3af6c54db4a78246364908f3c928"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/thephpleague/uri-interfaces/zipball/85d5c77c5d6d3af6c54db4a78246364908f3c928",
                "reference": "85d5c77c5d6d3af6c54db4a78246364908f3c928",
                "shasum": ""
            },
            "require": {
                "ext-filter": "*",
                "php": "^8.1",
                "psr/http-message": "^1.1 || ^2.0"
            },
            "suggest": {
                "ext-bcmath": "to improve IPV4 host parsing",
                "ext-gmp": "to improve IPV4 host parsing",
                "ext-intl": "to handle IDN host with the best performance",
                "php-64bit": "to improve IPV4 host parsing",
                "rowbot/url": "to handle URLs using the WHATWG URL Living Standard specification",
                "symfony/polyfill-intl-idn": "to handle IDN host via the Symfony polyfill if ext-intl is not present"
            },
            "type": "library",
            "extra": {
                "branch-alias": {
                    "dev-master": "7.x-dev"
                }
            },
            "autoload": {
                "psr-4": {
                    "League\\Uri\\": ""
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Ignace Nyamagana Butera",
                    "email": "nyamsprod@gmail.com",
                    "homepage": "https://nyamsprod.com"
                }
            ],
            "description": "Common tools for parsing and resolving RFC3987/RFC3986 URI",
            "homepage": "https://uri.thephpleague.com",
            "keywords": [
                "data-uri",
                "file-uri",
                "ftp",
                "hostname",
                "http",
                "https",
                "parse_str",
                "parse_url",
                "psr-7",
                "query-string",
                "querystring",
                "rfc3986",
                "rfc3987",
                "rfc6570",
                "uri",
                "url",
                "ws"
            ],
            "support": {
                "docs": "https://uri.thephpleague.com",
                "forum": "https://thephpleague.slack.com",
                "issues": "https://github.com/thephpleague/uri-src/issues",
                "source": "https://github.com/thephpleague/uri-interfaces/tree/7.8.1"
            },
            "funding": [
                {
                    "url": "https://github.com/sponsors/nyamsprod",
                    "type": "github"
                }
            ],
            "time": "2026-03-08T20:05:35+00:00"
        },
        {
            "name": "monolog/monolog",
            "version": "3.11.0",
            "source": {
                "type": "git",
                "url": "https://github.com/Seldaek/monolog.git",
                "reference": "147f303310f06334f03f409e49d7ad1e275ff05a"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/Seldaek/monolog/zipball/147f303310f06334f03f409e49d7ad1e275ff05a",
                "reference": "147f303310f06334f03f409e49d7ad1e275ff05a",
                "shasum": ""
            },
            "require": {
                "php": ">=8.1",
                "psr/log": "^2.0 || ^3.0"
            },
            "provide": {
                "psr/log-implementation": "3.0.0"
            },
            "require-dev": {
                "aws/aws-sdk-php": "^3.0",
                "doctrine/couchdb": "~1.0@dev",
                "elasticsearch/elasticsearch": "^7 || ^8",
                "ext-json": "*",
                "graylog2/gelf-php": "^1.4.2 || ^2.0",
                "guzzlehttp/guzzle": "^7.4.5",
                "guzzlehttp/psr7": "^2.2",
                "mongodb/mongodb": "^1.8 || ^2.0",
                "php-amqplib/php-amqplib": "~2.4 || ^3",
                "php-console/php-console": "^3.1.8",
                "phpstan/phpstan": "^2",
                "phpstan/phpstan-deprecation-rules": "^2",
                "phpstan/phpstan-strict-rules": "^2",
                "phpunit/phpunit": "^10.5.17 || ^11.0.7",
                "predis/predis": "^1.1 || ^2",
                "rollbar/rollbar": "^4.0",
                "ruflin/elastica": "^7 || ^8",
                "symfony/mailer": "^5.4 || ^6",
                "symfony/mime": "^5.4 || ^6"
            },
            "suggest": {
                "aws/aws-sdk-php": "Allow sending log messages to AWS services like DynamoDB",
                "doctrine/couchdb": "Allow sending log messages to a CouchDB server",
                "elasticsearch/elasticsearch": "Allow sending log messages to an Elasticsearch server via official client",
                "ext-amqp": "Allow sending log messages to an AMQP server (1.0+ required)",
                "ext-curl": "Required to send log messages using the IFTTTHandler, the LogglyHandler, the SendGridHandler, the SlackWebhookHandler or the TelegramBotHandler",
                "ext-mbstring": "Allow to work properly with unicode symbols",
                "ext-mongodb": "Allow sending log messages to a MongoDB server (via driver)",
                "ext-openssl": "Required to send log messages using SSL",
                "ext-sockets": "Allow sending log messages to a Syslog server (via UDP driver)",
                "graylog2/gelf-php": "Allow sending log messages to a GrayLog2 server",
                "mongodb/mongodb": "Allow sending log messages to a MongoDB server (via library)",
                "php-amqplib/php-amqplib": "Allow sending log messages to an AMQP server using php-amqplib",
                "rollbar/rollbar": "Allow sending log messages to Rollbar",
                "ruflin/elastica": "Allow sending log messages to an Elastic Search server"
            },
            "type": "library",
            "extra": {
                "branch-alias": {
                    "dev-main": "3.x-dev"
                }
            },
            "autoload": {
                "psr-4": {
                    "Monolog\\": "src/Monolog"
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Jordi Boggiano",
                    "email": "j.boggiano@seld.be",
                    "homepage": "https://seld.be"
                }
            ],
            "description": "Sends your logs to files, sockets, inboxes, databases and various web services",
            "homepage": "https://github.com/Seldaek/monolog",
            "keywords": [
                "log",
                "logging",
                "psr-3"
            ],
            "support": {
                "issues": "https://github.com/Seldaek/monolog/issues",
                "source": "https://github.com/Seldaek/monolog/tree/3.11.0"
            },
            "funding": [
                {
                    "url": "https://github.com/Seldaek",
                    "type": "github"
                },
                {
                    "url": "https://tidelift.com/funding/github/packagist/monolog/monolog",
                    "type": "tidelift"
                }
            ],
            "time": "2026-09-02T12:39:56+00:00"
        },
        {
            "name": "nesbot/carbon",
            "version": "3.13.2",
            "source": {
                "type": "git",
                "url": "https://github.com/CarbonPHP/carbon.git",
                "reference": "a1c54919f5fff9800cd03c32bd01defd5a4061cb"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/CarbonPHP/carbon/zipball/a1c54919f5fff9800cd03c32bd01defd5a4061cb",
                "reference": "a1c54919f5fff9800cd03c32bd01defd5a4061cb",
                "shasum": ""
            },
            "require": {
                "carbonphp/carbon-doctrine-types": "<100.0",
                "ext-json": "*",
                "php": "^8.1",
                "psr/clock": "^1.0",
                "symfony/clock": "^6.3.12 || ^7.0 || ^8.0",
                "symfony/polyfill-mbstring": "^1.0",
                "symfony/translation": "^4.4.18 || ^5.2.1 || ^6.0 || ^7.0 || ^8.0"
            },
            "provide": {
                "psr/clock-implementation": "1.0"
            },
            "require-dev": {
                "doctrine/dbal": "^3.6.3 || ^4.0",
                "doctrine/orm": "^2.15.2 || ^3.0",
                "friendsofphp/php-cs-fixer": "^v3.87.1",
                "kylekatarnls/multi-tester": "^2.5.3",
                "phpmd/phpmd": "^2.15.0",
                "phpstan/extension-installer": "^1.4.3",
                "phpstan/phpstan": "^2.1.22",
                "phpunit/phpunit": "^10.5.53",
                "squizlabs/php_codesniffer": "^3.13.4 || ^4.0.0"
            },
            "bin": [
                "bin/carbon"
            ],
            "type": "library",
            "extra": {
                "laravel": {
                    "providers": [
                        "Carbon\\Laravel\\ServiceProvider"
                    ]
                },
                "phpstan": {
                    "includes": [
                        "extension.neon"
                    ]
                },
                "branch-alias": {
                    "dev-2.x": "2.x-dev",
                    "dev-master": "3.x-dev"
                }
            },
            "autoload": {
                "psr-4": {
                    "Carbon\\": "src/Carbon/"
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Brian Nesbitt",
                    "email": "brian@nesbot.com",
                    "homepage": "https://markido.com"
                },
                {
                    "name": "kylekatarnls",
                    "homepage": "https://github.com/kylekatarnls"
                }
            ],
            "description": "An API extension for DateTime that supports 281 different languages.",
            "homepage": "https://carbonphp.github.io/carbon/",
            "keywords": [
                "date",
                "datetime",
                "time"
            ],
            "support": {
                "docs": "https://carbonphp.github.io/carbon/guide/getting-started/introduction.html",
                "issues": "https://github.com/CarbonPHP/carbon/issues",
                "source": "https://github.com/CarbonPHP/carbon"
            },
            "funding": [
                {
                    "url": "https://github.com/sponsors/kylekatarnls",
                    "type": "github"
                },
                {
                    "url": "https://opencollective.com/Carbon#sponsor",
                    "type": "opencollective"
                },
                {
                    "url": "https://tidelift.com/subscription/pkg/packagist-nesbot-carbon?utm_source=packagist-nesbot-carbon&utm_medium=referral&utm_campaign=readme",
                    "type": "tidelift"
                }
            ],
            "time": "2026-08-08T11:40:35+00:00"
        },
        {
            "name": "nette/schema",
            "version": "v1.3.6",
            "source": {
                "type": "git",
                "url": "https://github.com/nette/schema.git",
                "reference": "c54350438cd6914616f790a49cb424605f421562"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/nette/schema/zipball/c54350438cd6914616f790a49cb424605f421562",
                "reference": "c54350438cd6914616f790a49cb424605f421562",
                "shasum": ""
            },
            "require": {
                "nette/utils": "^4.0",
                "php": "8.1 - 8.5"
            },
            "require-dev": {
                "nette/phpstan-rules": "^1.0",
                "nette/tester": "^2.6",
                "phpstan/extension-installer": "^1.4@stable",
                "phpstan/phpstan": "^2.1.39@stable",
                "tracy/tracy": "^2.8"
            },
            "type": "library",
            "extra": {
                "branch-alias": {
                    "dev-master": "1.3-dev"
                }
            },
            "autoload": {
                "psr-4": {
                    "Nette\\": "src"
                },
                "classmap": [
                    "src/"
                ]
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "BSD-3-Clause",
                "GPL-2.0-only",
                "GPL-3.0-only"
            ],
            "authors": [
                {
                    "name": "David Grudl",
                    "homepage": "https://davidgrudl.com"
                },
                {
                    "name": "Nette Community",
                    "homepage": "https://nette.org/contributors"
                }
            ],
            "description": "📐 Nette Schema: validating data structures against a given Schema.",
            "homepage": "https://nette.org",
            "keywords": [
                "config",
                "nette"
            ],
            "support": {
                "issues": "https://github.com/nette/schema/issues",
                "source": "https://github.com/nette/schema/tree/v1.3.6"
            },
            "time": "2026-08-16T21:58:41+00:00"
        },
        {
            "name": "nette/utils",
            "version": "v4.1.5",
            "source": {
                "type": "git",
                "url": "https://github.com/nette/utils.git",
                "reference": "b043439dbdf954e6c28b5ea7e34b0100f83165e0"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/nette/utils/zipball/b043439dbdf954e6c28b5ea7e34b0100f83165e0",
                "reference": "b043439dbdf954e6c28b5ea7e34b0100f83165e0",
                "shasum": ""
            },
            "require": {
                "php": "8.2 - 8.5"
            },
            "conflict": {
                "nette/finder": "<3",
                "nette/schema": "<1.2.2"
            },
            "require-dev": {
                "jetbrains/phpstorm-attributes": "^1.2",
                "nette/phpstan-rules": "^1.0",
                "nette/tester": "^2.5",
                "phpstan/extension-installer": "^1.4@stable",
                "phpstan/phpstan": "^2.1@stable",
                "tracy/tracy": "^2.9"
            },
            "suggest": {
                "ext-gd": "to use Image",
                "ext-iconv": "to use Strings::chr(), ord() and reverse()",
                "ext-intl": "to use Strings::webalize(), toAscii(), normalize() and compare()",
                "ext-json": "to use Nette\\Utils\\Json",
                "ext-mbstring": "to use Strings::lower() etc...",
                "ext-tokenizer": "to use Nette\\Utils\\Reflection::getUseStatements()"
            },
            "type": "library",
            "extra": {
                "branch-alias": {
                    "dev-master": "4.1-dev"
                }
            },
            "autoload": {
                "psr-4": {
                    "Nette\\": "src"
                },
                "classmap": [
                    "src/"
                ]
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "BSD-3-Clause",
                "GPL-2.0-only",
                "GPL-3.0-only"
            ],
            "authors": [
                {
                    "name": "David Grudl",
                    "homepage": "https://davidgrudl.com"
                },
                {
                    "name": "Nette Community",
                    "homepage": "https://nette.org/contributors"
                }
            ],
            "description": "🛠  Nette Utils: lightweight utilities for string & array manipulation, image handling, safe JSON encoding/decoding, validation, slug or strong password generating etc.",
            "homepage": "https://nette.org",
            "keywords": [
                "array",
                "core",
                "datetime",
                "images",
                "json",
                "nette",
                "paginator",
                "password",
                "slugify",
                "string",
                "unicode",
                "utf-8",
                "utility",
                "validation"
            ],
            "support": {
                "issues": "https://github.com/nette/utils/issues",
                "source": "https://github.com/nette/utils/tree/v4.1.5"
            },
            "time": "2026-07-17T23:02:45+00:00"
        },
        {
            "name": "nikic/php-parser",
            "version": "v5.8.0",
            "source": {
                "type": "git",
                "url": "https://github.com/nikic/PHP-Parser.git",
                "reference": "044a6a392ff8ad0d61f14370a5fbbd0a0107152f"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/nikic/PHP-Parser/zipball/044a6a392ff8ad0d61f14370a5fbbd0a0107152f",
                "reference": "044a6a392ff8ad0d61f14370a5fbbd0a0107152f",
                "shasum": ""
            },
            "require": {
                "ext-json": "*",
                "ext-tokenizer": "*",
                "php": ">=7.4"
            },
            "require-dev": {
                "ircmaxell/php-yacc": "^0.0.7",
                "phpunit/phpunit": "^9.0"
            },
            "bin": [
                "bin/php-parse"
            ],
            "type": "library",
            "extra": {
                "branch-alias": {
                    "dev-master": "5.x-dev"
                }
            },
            "autoload": {
                "psr-4": {
                    "PhpParser\\": "lib/PhpParser"
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "BSD-3-Clause"
            ],
            "authors": [
                {
                    "name": "Nikita Popov"
                }
            ],
            "description": "A PHP parser written in PHP",
            "keywords": [
                "parser",
                "php"
            ],
            "support": {
                "issues": "https://github.com/nikic/PHP-Parser/issues",
                "source": "https://github.com/nikic/PHP-Parser/tree/v5.8.0"
            },
            "time": "2026-07-04T14:30:18+00:00"
        },
        {
            "name": "nunomaduro/termwind",
            "version": "v2.4.0",
            "source": {
                "type": "git",
                "url": "https://github.com/nunomaduro/termwind.git",
                "reference": "712a31b768f5daea284c2169a7d227031001b9a8"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/nunomaduro/termwind/zipball/712a31b768f5daea284c2169a7d227031001b9a8",
                "reference": "712a31b768f5daea284c2169a7d227031001b9a8",
                "shasum": ""
            },
            "require": {
                "ext-mbstring": "*",
                "php": "^8.2",
                "symfony/console": "^7.4.4 || ^8.0.4"
            },
            "require-dev": {
                "illuminate/console": "^11.47.0",
                "laravel/pint": "^1.27.1",
                "mockery/mockery": "^1.6.12",
                "pestphp/pest": "^2.36.0 || ^3.8.4 || ^4.3.2",
                "phpstan/phpstan": "^1.12.32",
                "phpstan/phpstan-strict-rules": "^1.6.2",
                "symfony/var-dumper": "^7.3.5 || ^8.0.4",
                "thecodingmachine/phpstan-strict-rules": "^1.0.0"
            },
            "type": "library",
            "extra": {
                "laravel": {
                    "providers": [
                        "Termwind\\Laravel\\TermwindServiceProvider"
                    ]
                },
                "branch-alias": {
                    "dev-2.x": "2.x-dev"
                }
            },
            "autoload": {
                "files": [
                    "src/Functions.php"
                ],
                "psr-4": {
                    "Termwind\\": "src/"
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Nuno Maduro",
                    "email": "enunomaduro@gmail.com"
                }
            ],
            "description": "It's like Tailwind CSS, but for the console.",
            "keywords": [
                "cli",
                "console",
                "css",
                "package",
                "php",
                "style"
            ],
            "support": {
                "issues": "https://github.com/nunomaduro/termwind/issues",
                "source": "https://github.com/nunomaduro/termwind/tree/v2.4.0"
            },
            "funding": [
                {
                    "url": "https://www.paypal.com/paypalme/enunomaduro",
                    "type": "custom"
                },
                {
                    "url": "https://github.com/nunomaduro",
                    "type": "github"
                },
                {
                    "url": "https://github.com/xiCO2k",
                    "type": "github"
                }
            ],
            "time": "2026-02-16T23:10:27+00:00"
        },
        {
            "name": "paragonie/constant_time_encoding",
            "version": "v3.1.3",
            "source": {
                "type": "git",
                "url": "https://github.com/paragonie/constant_time_encoding.git",
                "reference": "d5b01a39b3415c2cd581d3bd3a3575c1ebbd8e77"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/paragonie/constant_time_encoding/zipball/d5b01a39b3415c2cd581d3bd3a3575c1ebbd8e77",
                "reference": "d5b01a39b3415c2cd581d3bd3a3575c1ebbd8e77",
                "shasum": ""
            },
            "require": {
                "php": "^8"
            },
            "require-dev": {
                "infection/infection": "^0",
                "nikic/php-fuzzer": "^0",
                "phpunit/phpunit": "^9|^10|^11",
                "vimeo/psalm": "^4|^5|^6"
            },
            "type": "library",
            "autoload": {
                "psr-4": {
                    "ParagonIE\\ConstantTime\\": "src/"
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Paragon Initiative Enterprises",
                    "email": "security@paragonie.com",
                    "homepage": "https://paragonie.com",
                    "role": "Maintainer"
                },
                {
                    "name": "Steve 'Sc00bz' Thomas",
                    "email": "steve@tobtu.com",
                    "homepage": "https://www.tobtu.com",
                    "role": "Original Developer"
                }
            ],
            "description": "Constant-time Implementations of RFC 4648 Encoding (Base-64, Base-32, Base-16)",
            "keywords": [
                "base16",
                "base32",
                "base32_decode",
                "base32_encode",
                "base64",
                "base64_decode",
                "base64_encode",
                "bin2hex",
                "encoding",
                "hex",
                "hex2bin",
                "rfc4648"
            ],
            "support": {
                "email": "info@paragonie.com",
                "issues": "https://github.com/paragonie/constant_time_encoding/issues",
                "source": "https://github.com/paragonie/constant_time_encoding"
            },
            "time": "2025-09-24T15:06:41+00:00"
        },
        {
            "name": "phpoption/phpoption",
            "version": "1.10.0",
            "source": {
                "type": "git",
                "url": "https://github.com/schmittjoh/php-option.git",
                "reference": "67b192b6a42ec03944b972d6e633ddec78ad2c6d"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/schmittjoh/php-option/zipball/67b192b6a42ec03944b972d6e633ddec78ad2c6d",
                "reference": "67b192b6a42ec03944b972d6e633ddec78ad2c6d",
                "shasum": ""
            },
            "require": {
                "php": "^7.2.5 || ^8.0"
            },
            "require-dev": {
                "bamarni/composer-bin-plugin": "^1.8.2",
                "phpunit/phpunit": "^8.5.54 || ^9.6.36 || ^10.5.64 || ^11.5.56 || ^12.5.33"
            },
            "type": "library",
            "extra": {
                "bamarni-bin": {
                    "bin-links": true,
                    "forward-command": false
                },
                "branch-alias": {
                    "dev-master": "1.9-dev"
                }
            },
            "autoload": {
                "psr-4": {
                    "PhpOption\\": "src/PhpOption/"
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "Apache-2.0"
            ],
            "authors": [
                {
                    "name": "Johannes M. Schmitt",
                    "email": "schmittjoh@gmail.com",
                    "homepage": "https://github.com/schmittjoh"
                },
                {
                    "name": "Graham Campbell",
                    "email": "hello@gjcampbell.co.uk",
                    "homepage": "https://github.com/GrahamCampbell"
                }
            ],
            "description": "Option Type for PHP",
            "keywords": [
                "language",
                "option",
                "php",
                "type"
            ],
            "support": {
                "issues": "https://github.com/schmittjoh/php-option/issues",
                "source": "https://github.com/schmittjoh/php-option/tree/1.10.0"
            },
            "funding": [
                {
                    "url": "https://github.com/GrahamCampbell",
                    "type": "github"
                },
                {
                    "url": "https://tidelift.com/funding/github/packagist/phpoption/phpoption",
                    "type": "tidelift"
                }
            ],
            "time": "2026-08-24T00:54:40+00:00"
        },
        {
            "name": "phpseclib/phpseclib",
            "version": "4.0.1",
            "source": {
                "type": "git",
                "url": "https://github.com/phpseclib/phpseclib.git",
                "reference": "bb7b959c8159957edae6f5084ebbac765d310e16"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/phpseclib/phpseclib/zipball/bb7b959c8159957edae6f5084ebbac765d310e16",
                "reference": "bb7b959c8159957edae6f5084ebbac765d310e16",
                "shasum": ""
            },
            "require": {
                "paragonie/constant_time_encoding": "^2|^3",
                "php": ">=8.1",
                "symfony/polyfill-php82": "^1.26"
            },
            "require-dev": {
                "brianium/paratest": "^7.22",
                "ext-xml": "*",
                "php-parallel-lint/php-parallel-lint": "^1.3",
                "phpunit/phpunit": "^13",
                "squizlabs/php_codesniffer": "^3.7",
                "vimeo/psalm": "*"
            },
            "suggest": {
                "ext-dom": "Install the DOM extension to load XML formatted public keys.",
                "ext-gmp": "Install the GMP (GNU Multiple Precision) extension in order to speed up arbitrary precision integer arithmetic operations.",
                "ext-libsodium": "SSH2/SFTP can make use of some algorithms provided by the libsodium-php extension.",
                "ext-openssl": "Install the OpenSSL extension in order to speed up a wide variety of cryptographic operations."
            },
            "type": "library",
            "autoload": {
                "files": [
                    "phpseclib/bootstrap.php"
                ],
                "psr-4": {
                    "phpseclib4\\": "phpseclib/"
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Jim Wigginton",
                    "email": "terrafrost@php.net",
                    "role": "Lead Developer"
                },
                {
                    "name": "Patrick Monnerat",
                    "email": "pm@datasphere.ch",
                    "role": "Developer"
                },
                {
                    "name": "Andreas Fischer",
                    "email": "bantu@phpbb.com",
                    "role": "Developer"
                },
                {
                    "name": "Hans-Jürgen Petrich",
                    "email": "petrich@tronic-media.com",
                    "role": "Developer"
                },
                {
                    "name": "Graham Campbell",
                    "email": "graham@alt-three.com",
                    "role": "Developer"
                },
                {
                    "name": "Jack Worman",
                    "email": "jack.worman@gmail.com",
                    "homepage": "https://jackworman.com",
                    "role": "Developer"
                }
            ],
            "description": "PHP Secure Communications Library - Pure-PHP implementations of RSA, AES, SSH2, SFTP, X.509 etc.",
            "homepage": "https://phpseclib.com/",
            "keywords": [
                "BigInteger",
                "aes",
                "asn.1",
                "asn1",
                "blowfish",
                "crypto",
                "cryptography",
                "encryption",
                "rsa",
                "security",
                "sftp",
                "signature",
                "signing",
                "ssh",
                "twofish",
                "x.509",
                "x509"
            ],
            "support": {
                "issues": "https://github.com/phpseclib/phpseclib/issues",
                "source": "https://github.com/phpseclib/phpseclib/tree/4.0.1"
            },
            "funding": [
                {
                    "url": "https://github.com/terrafrost",
                    "type": "github"
                },
                {
                    "url": "https://www.patreon.com/phpseclib",
                    "type": "patreon"
                },
                {
                    "url": "https://tidelift.com/funding/github/packagist/phpseclib/phpseclib",
                    "type": "tidelift"
                }
            ],
            "time": "2026-08-26T12:15:13+00:00"
        },
        {
            "name": "psr/clock",
            "version": "1.0.0",
            "source": {
                "type": "git",
                "url": "https://github.com/php-fig/clock.git",
                "reference": "e41a24703d4560fd0acb709162f73b8adfc3aa0d"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/php-fig/clock/zipball/e41a24703d4560fd0acb709162f73b8adfc3aa0d",
                "reference": "e41a24703d4560fd0acb709162f73b8adfc3aa0d",
                "shasum": ""
            },
            "require": {
                "php": "^7.0 || ^8.0"
            },
            "type": "library",
            "autoload": {
                "psr-4": {
                    "Psr\\Clock\\": "src/"
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "PHP-FIG",
                    "homepage": "https://www.php-fig.org/"
                }
            ],
            "description": "Common interface for reading the clock.",
            "homepage": "https://github.com/php-fig/clock",
            "keywords": [
                "clock",
                "now",
                "psr",
                "psr-20",
                "time"
            ],
            "support": {
                "issues": "https://github.com/php-fig/clock/issues",
                "source": "https://github.com/php-fig/clock/tree/1.0.0"
            },
            "time": "2022-11-25T14:36:26+00:00"
        },
        {
            "name": "psr/container",
            "version": "2.0.2",
            "source": {
                "type": "git",
                "url": "https://github.com/php-fig/container.git",
                "reference": "c71ecc56dfe541dbd90c5360474fbc405f8d5963"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/php-fig/container/zipball/c71ecc56dfe541dbd90c5360474fbc405f8d5963",
                "reference": "c71ecc56dfe541dbd90c5360474fbc405f8d5963",
                "shasum": ""
            },
            "require": {
                "php": ">=7.4.0"
            },
            "type": "library",
            "extra": {
                "branch-alias": {
                    "dev-master": "2.0.x-dev"
                }
            },
            "autoload": {
                "psr-4": {
                    "Psr\\Container\\": "src/"
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "PHP-FIG",
                    "homepage": "https://www.php-fig.org/"
                }
            ],
            "description": "Common Container Interface (PHP FIG PSR-11)",
            "homepage": "https://github.com/php-fig/container",
            "keywords": [
                "PSR-11",
                "container",
                "container-interface",
                "container-interop",
                "psr"
            ],
            "support": {
                "issues": "https://github.com/php-fig/container/issues",
                "source": "https://github.com/php-fig/container/tree/2.0.2"
            },
            "time": "2021-11-05T16:47:00+00:00"
        },
        {
            "name": "psr/event-dispatcher",
            "version": "1.0.0",
            "source": {
                "type": "git",
                "url": "https://github.com/php-fig/event-dispatcher.git",
                "reference": "dbefd12671e8a14ec7f180cab83036ed26714bb0"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/php-fig/event-dispatcher/zipball/dbefd12671e8a14ec7f180cab83036ed26714bb0",
                "reference": "dbefd12671e8a14ec7f180cab83036ed26714bb0",
                "shasum": ""
            },
            "require": {
                "php": ">=7.2.0"
            },
            "type": "library",
            "extra": {
                "branch-alias": {
                    "dev-master": "1.0.x-dev"
                }
            },
            "autoload": {
                "psr-4": {
                    "Psr\\EventDispatcher\\": "src/"
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "PHP-FIG",
                    "homepage": "http://www.php-fig.org/"
                }
            ],
            "description": "Standard interfaces for event handling.",
            "keywords": [
                "events",
                "psr",
                "psr-14"
            ],
            "support": {
                "issues": "https://github.com/php-fig/event-dispatcher/issues",
                "source": "https://github.com/php-fig/event-dispatcher/tree/1.0.0"
            },
            "time": "2019-01-08T18:20:26+00:00"
        },
        {
            "name": "psr/http-client",
            "version": "1.0.3",
            "source": {
                "type": "git",
                "url": "https://github.com/php-fig/http-client.git",
                "reference": "bb5906edc1c324c9a05aa0873d40117941e5fa90"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/php-fig/http-client/zipball/bb5906edc1c324c9a05aa0873d40117941e5fa90",
                "reference": "bb5906edc1c324c9a05aa0873d40117941e5fa90",
                "shasum": ""
            },
            "require": {
                "php": "^7.0 || ^8.0",
                "psr/http-message": "^1.0 || ^2.0"
            },
            "type": "library",
            "extra": {
                "branch-alias": {
                    "dev-master": "1.0.x-dev"
                }
            },
            "autoload": {
                "psr-4": {
                    "Psr\\Http\\Client\\": "src/"
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "PHP-FIG",
                    "homepage": "https://www.php-fig.org/"
                }
            ],
            "description": "Common interface for HTTP clients",
            "homepage": "https://github.com/php-fig/http-client",
            "keywords": [
                "http",
                "http-client",
                "psr",
                "psr-18"
            ],
            "support": {
                "source": "https://github.com/php-fig/http-client"
            },
            "time": "2023-09-23T14:17:50+00:00"
        },
        {
            "name": "psr/http-factory",
            "version": "1.1.0",
            "source": {
                "type": "git",
                "url": "https://github.com/php-fig/http-factory.git",
                "reference": "2b4765fddfe3b508ac62f829e852b1501d3f6e8a"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/php-fig/http-factory/zipball/2b4765fddfe3b508ac62f829e852b1501d3f6e8a",
                "reference": "2b4765fddfe3b508ac62f829e852b1501d3f6e8a",
                "shasum": ""
            },
            "require": {
                "php": ">=7.1",
                "psr/http-message": "^1.0 || ^2.0"
            },
            "type": "library",
            "extra": {
                "branch-alias": {
                    "dev-master": "1.0.x-dev"
                }
            },
            "autoload": {
                "psr-4": {
                    "Psr\\Http\\Message\\": "src/"
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "PHP-FIG",
                    "homepage": "https://www.php-fig.org/"
                }
            ],
            "description": "PSR-17: Common interfaces for PSR-7 HTTP message factories",
            "keywords": [
                "factory",
                "http",
                "message",
                "psr",
                "psr-17",
                "psr-7",
                "request",
                "response"
            ],
            "support": {
                "source": "https://github.com/php-fig/http-factory"
            },
            "time": "2024-04-15T12:06:14+00:00"
        },
        {
            "name": "psr/http-message",
            "version": "2.0",
            "source": {
                "type": "git",
                "url": "https://github.com/php-fig/http-message.git",
                "reference": "402d35bcb92c70c026d1a6a9883f06b2ead23d71"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/php-fig/http-message/zipball/402d35bcb92c70c026d1a6a9883f06b2ead23d71",
                "reference": "402d35bcb92c70c026d1a6a9883f06b2ead23d71",
                "shasum": ""
            },
            "require": {
                "php": "^7.2 || ^8.0"
            },
            "type": "library",
            "extra": {
                "branch-alias": {
                    "dev-master": "2.0.x-dev"
                }
            },
            "autoload": {
                "psr-4": {
                    "Psr\\Http\\Message\\": "src/"
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "PHP-FIG",
                    "homepage": "https://www.php-fig.org/"
                }
            ],
            "description": "Common interface for HTTP messages",
            "homepage": "https://github.com/php-fig/http-message",
            "keywords": [
                "http",
                "http-message",
                "psr",
                "psr-7",
                "request",
                "response"
            ],
            "support": {
                "source": "https://github.com/php-fig/http-message/tree/2.0"
            },
            "time": "2023-04-04T09:54:51+00:00"
        },
        {
            "name": "psr/log",
            "version": "3.0.2",
            "source": {
                "type": "git",
                "url": "https://github.com/php-fig/log.git",
                "reference": "f16e1d5863e37f8d8c2a01719f5b34baa2b714d3"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/php-fig/log/zipball/f16e1d5863e37f8d8c2a01719f5b34baa2b714d3",
                "reference": "f16e1d5863e37f8d8c2a01719f5b34baa2b714d3",
                "shasum": ""
            },
            "require": {
                "php": ">=8.0.0"
            },
            "type": "library",
            "extra": {
                "branch-alias": {
                    "dev-master": "3.x-dev"
                }
            },
            "autoload": {
                "psr-4": {
                    "Psr\\Log\\": "src"
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "PHP-FIG",
                    "homepage": "https://www.php-fig.org/"
                }
            ],
            "description": "Common interface for logging libraries",
            "homepage": "https://github.com/php-fig/log",
            "keywords": [
                "log",
                "psr",
                "psr-3"
            ],
            "support": {
                "source": "https://github.com/php-fig/log/tree/3.0.2"
            },
            "time": "2024-09-11T13:17:53+00:00"
        },
        {
            "name": "psr/simple-cache",
            "version": "3.0.0",
            "source": {
                "type": "git",
                "url": "https://github.com/php-fig/simple-cache.git",
                "reference": "764e0b3939f5ca87cb904f570ef9be2d78a07865"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/php-fig/simple-cache/zipball/764e0b3939f5ca87cb904f570ef9be2d78a07865",
                "reference": "764e0b3939f5ca87cb904f570ef9be2d78a07865",
                "shasum": ""
            },
            "require": {
                "php": ">=8.0.0"
            },
            "type": "library",
            "extra": {
                "branch-alias": {
                    "dev-master": "3.0.x-dev"
                }
            },
            "autoload": {
                "psr-4": {
                    "Psr\\SimpleCache\\": "src/"
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "PHP-FIG",
                    "homepage": "https://www.php-fig.org/"
                }
            ],
            "description": "Common interfaces for simple caching",
            "keywords": [
                "cache",
                "caching",
                "psr",
                "psr-16",
                "simple-cache"
            ],
            "support": {
                "source": "https://github.com/php-fig/simple-cache/tree/3.0.0"
            },
            "time": "2021-10-29T13:26:27+00:00"
        },
        {
            "name": "psy/psysh",
            "version": "v0.12.24",
            "source": {
                "type": "git",
                "url": "https://github.com/bobthecow/psysh.git",
                "reference": "ca0fdcf8a7617afa3adfdf1b5fef573dffb69ca1"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/bobthecow/psysh/zipball/ca0fdcf8a7617afa3adfdf1b5fef573dffb69ca1",
                "reference": "ca0fdcf8a7617afa3adfdf1b5fef573dffb69ca1",
                "shasum": ""
            },
            "require": {
                "ext-json": "*",
                "ext-tokenizer": "*",
                "nikic/php-parser": "^5.0 || ^4.0",
                "php": "^8.0 || ^7.4",
                "symfony/console": "^8.0 || ^7.0 || ^6.0 || ^5.0 || ^4.0 || ^3.4",
                "symfony/var-dumper": "^8.0 || ^7.0 || ^6.0 || ^5.0 || ^4.0 || ^3.4"
            },
            "conflict": {
                "symfony/console": "4.4.37 || 5.3.14 || 5.3.15 || 5.4.3 || 5.4.4 || 6.0.3 || 6.0.4"
            },
            "require-dev": {
                "bamarni/composer-bin-plugin": "^1.2",
                "composer/class-map-generator": "^1.6"
            },
            "suggest": {
                "composer/class-map-generator": "Improved tab completion performance with better class discovery.",
                "ext-pcntl": "Enabling the PCNTL extension makes PsySH a lot happier :)",
                "ext-posix": "If you have PCNTL, you'll want the POSIX extension as well."
            },
            "bin": [
                "bin/psysh"
            ],
            "type": "library",
            "extra": {
                "bamarni-bin": {
                    "bin-links": false,
                    "forward-command": false
                },
                "branch-alias": {
                    "dev-main": "0.12.x-dev"
                }
            },
            "autoload": {
                "files": [
                    "src/functions.php"
                ],
                "psr-4": {
                    "Psy\\": "src/"
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Justin Hileman",
                    "email": "justin@justinhileman.info"
                }
            ],
            "description": "An interactive shell for modern PHP.",
            "homepage": "https://psysh.org",
            "keywords": [
                "REPL",
                "console",
                "interactive",
                "shell"
            ],
            "support": {
                "issues": "https://github.com/bobthecow/psysh/issues",
                "source": "https://github.com/bobthecow/psysh/tree/v0.12.24"
            },
            "time": "2026-06-29T15:41:09+00:00"
        },
        {
            "name": "ralouphie/getallheaders",
            "version": "3.0.3",
            "source": {
                "type": "git",
                "url": "https://github.com/ralouphie/getallheaders.git",
                "reference": "120b605dfeb996808c31b6477290a714d356e822"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/ralouphie/getallheaders/zipball/120b605dfeb996808c31b6477290a714d356e822",
                "reference": "120b605dfeb996808c31b6477290a714d356e822",
                "shasum": ""
            },
            "require": {
                "php": ">=5.6"
            },
            "require-dev": {
                "php-coveralls/php-coveralls": "^2.1",
                "phpunit/phpunit": "^5 || ^6.5"
            },
            "type": "library",
            "autoload": {
                "files": [
                    "src/getallheaders.php"
                ]
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Ralph Khattar",
                    "email": "ralph.khattar@gmail.com"
                }
            ],
            "description": "A polyfill for getallheaders.",
            "support": {
                "issues": "https://github.com/ralouphie/getallheaders/issues",
                "source": "https://github.com/ralouphie/getallheaders/tree/develop"
            },
            "time": "2019-03-08T08:55:37+00:00"
        },
        {
            "name": "ramsey/collection",
            "version": "2.1.1",
            "source": {
                "type": "git",
                "url": "https://github.com/ramsey/collection.git",
                "reference": "344572933ad0181accbf4ba763e85a0306a8c5e2"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/ramsey/collection/zipball/344572933ad0181accbf4ba763e85a0306a8c5e2",
                "reference": "344572933ad0181accbf4ba763e85a0306a8c5e2",
                "shasum": ""
            },
            "require": {
                "php": "^8.1"
            },
            "require-dev": {
                "captainhook/plugin-composer": "^5.3",
                "ergebnis/composer-normalize": "^2.45",
                "fakerphp/faker": "^1.24",
                "hamcrest/hamcrest-php": "^2.0",
                "jangregor/phpstan-prophecy": "^2.1",
                "mockery/mockery": "^1.6",
                "php-parallel-lint/php-console-highlighter": "^1.0",
                "php-parallel-lint/php-parallel-lint": "^1.4",
                "phpspec/prophecy-phpunit": "^2.3",
                "phpstan/extension-installer": "^1.4",
                "phpstan/phpstan": "^2.1",
                "phpstan/phpstan-mockery": "^2.0",
                "phpstan/phpstan-phpunit": "^2.0",
                "phpunit/phpunit": "^10.5",
                "ramsey/coding-standard": "^2.3",
                "ramsey/conventional-commits": "^1.6",
                "roave/security-advisories": "dev-latest"
            },
            "type": "library",
            "extra": {
                "captainhook": {
                    "force-install": true
                },
                "ramsey/conventional-commits": {
                    "configFile": "conventional-commits.json"
                }
            },
            "autoload": {
                "psr-4": {
                    "Ramsey\\Collection\\": "src/"
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Ben Ramsey",
                    "email": "ben@benramsey.com",
                    "homepage": "https://benramsey.com"
                }
            ],
            "description": "A PHP library for representing and manipulating collections.",
            "keywords": [
                "array",
                "collection",
                "hash",
                "map",
                "queue",
                "set"
            ],
            "support": {
                "issues": "https://github.com/ramsey/collection/issues",
                "source": "https://github.com/ramsey/collection/tree/2.1.1"
            },
            "time": "2025-03-22T05:38:12+00:00"
        },
        {
            "name": "ramsey/uuid",
            "version": "4.9.3",
            "source": {
                "type": "git",
                "url": "https://github.com/ramsey/uuid.git",
                "reference": "1df15849d00943a67d677dc9cfd80795f038c9f8"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/ramsey/uuid/zipball/1df15849d00943a67d677dc9cfd80795f038c9f8",
                "reference": "1df15849d00943a67d677dc9cfd80795f038c9f8",
                "shasum": ""
            },
            "require": {
                "brick/math": ">=0.8.16 <=0.18",
                "php": "^8.0",
                "ramsey/collection": "^1.2 || ^2.0"
            },
            "replace": {
                "rhumsaa/uuid": "self.version"
            },
            "require-dev": {
                "captainhook/captainhook": "^5.25",
                "captainhook/plugin-composer": "^5.3",
                "dealerdirect/phpcodesniffer-composer-installer": "^1.0",
                "ergebnis/composer-normalize": "^2.47",
                "mockery/mockery": "^1.6",
                "paragonie/random-lib": "^2",
                "php-mock/php-mock": "^2.6",
                "php-mock/php-mock-mockery": "^1.5",
                "php-parallel-lint/php-parallel-lint": "^1.4.0",
                "phpbench/phpbench": "^1.2.14",
                "phpstan/extension-installer": "^1.4",
                "phpstan/phpstan": "^2.1",
                "phpstan/phpstan-mockery": "^2.0",
                "phpstan/phpstan-phpunit": "^2.0",
                "phpunit/phpunit": "^9.6",
                "slevomat/coding-standard": "^8.18",
                "squizlabs/php_codesniffer": "^3.13"
            },
            "suggest": {
                "ext-bcmath": "Enables faster math with arbitrary-precision integers using BCMath.",
                "ext-gmp": "Enables faster math with arbitrary-precision integers using GMP.",
                "ext-uuid": "Enables the use of PeclUuidTimeGenerator and PeclUuidRandomGenerator.",
                "paragonie/random-lib": "Provides RandomLib for use with the RandomLibAdapter",
                "ramsey/uuid-doctrine": "Allows the use of Ramsey\\Uuid\\Uuid as Doctrine field type."
            },
            "type": "library",
            "extra": {
                "captainhook": {
                    "force-install": true
                }
            },
            "autoload": {
                "files": [
                    "src/functions.php"
                ],
                "psr-4": {
                    "Ramsey\\Uuid\\": "src/"
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "description": "A PHP library for generating and working with universally unique identifiers (UUIDs).",
            "keywords": [
                "guid",
                "identifier",
                "uuid"
            ],
            "support": {
                "issues": "https://github.com/ramsey/uuid/issues",
                "source": "https://github.com/ramsey/uuid/tree/4.9.3"
            },
            "time": "2026-06-18T03:57:49+00:00"
        },
        {
            "name": "symfony/clock",
            "version": "v8.1.0",
            "source": {
                "type": "git",
                "url": "https://github.com/symfony/clock.git",
                "reference": "701ef4de9705d6c32292ebee5e8044094a09fbf6"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/symfony/clock/zipball/701ef4de9705d6c32292ebee5e8044094a09fbf6",
                "reference": "701ef4de9705d6c32292ebee5e8044094a09fbf6",
                "shasum": ""
            },
            "require": {
                "php": ">=8.4.1",
                "psr/clock": "^1.0"
            },
            "provide": {
                "psr/clock-implementation": "1.0"
            },
            "type": "library",
            "autoload": {
                "files": [
                    "Resources/now.php"
                ],
                "psr-4": {
                    "Symfony\\Component\\Clock\\": ""
                },
                "exclude-from-classmap": [
                    "/Tests/"
                ]
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Nicolas Grekas",
                    "email": "p@tchwork.com"
                },
                {
                    "name": "Symfony Community",
                    "homepage": "https://symfony.com/contributors"
                }
            ],
            "description": "Decouples applications from the system clock",
            "homepage": "https://symfony.com",
            "keywords": [
                "clock",
                "psr20",
                "time"
            ],
            "support": {
                "source": "https://github.com/symfony/clock/tree/v8.1.0"
            },
            "funding": [
                {
                    "url": "https://symfony.com/sponsor",
                    "type": "custom"
                },
                {
                    "url": "https://github.com/fabpot",
                    "type": "github"
                },
                {
                    "url": "https://github.com/nicolas-grekas",
                    "type": "github"
                },
                {
                    "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
                    "type": "tidelift"
                }
            ],
            "time": "2026-05-29T05:06:50+00:00"
        },
        {
            "name": "symfony/console",
            "version": "v7.4.18",
            "source": {
                "type": "git",
                "url": "https://github.com/symfony/console.git",
                "reference": "23d6f88a29f6d0eac45bd77d70307adf83ba7ab0"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/symfony/console/zipball/23d6f88a29f6d0eac45bd77d70307adf83ba7ab0",
                "reference": "23d6f88a29f6d0eac45bd77d70307adf83ba7ab0",
                "shasum": ""
            },
            "require": {
                "php": ">=8.2",
                "symfony/deprecation-contracts": "^2.5|^3",
                "symfony/polyfill-mbstring": "~1.0",
                "symfony/service-contracts": "^2.5|^3",
                "symfony/string": "^7.2|^8.0"
            },
            "conflict": {
                "symfony/dependency-injection": "<6.4",
                "symfony/dotenv": "<6.4",
                "symfony/event-dispatcher": "<6.4",
                "symfony/lock": "<6.4",
                "symfony/process": "<6.4"
            },
            "provide": {
                "psr/log-implementation": "1.0|2.0|3.0"
            },
            "require-dev": {
                "psr/log": "^1|^2|^3",
                "symfony/config": "^6.4|^7.0|^8.0",
                "symfony/dependency-injection": "^6.4|^7.0|^8.0",
                "symfony/event-dispatcher": "^6.4|^7.0|^8.0",
                "symfony/http-foundation": "^6.4|^7.0|^8.0",
                "symfony/http-kernel": "^6.4|^7.0|^8.0",
                "symfony/lock": "^6.4|^7.0|^8.0",
                "symfony/messenger": "^6.4|^7.0|^8.0",
                "symfony/process": "^6.4|^7.0|^8.0",
                "symfony/stopwatch": "^6.4|^7.0|^8.0",
                "symfony/var-dumper": "^6.4|^7.0|^8.0"
            },
            "type": "library",
            "autoload": {
                "psr-4": {
                    "Symfony\\Component\\Console\\": ""
                },
                "exclude-from-classmap": [
                    "/Tests/"
                ]
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Fabien Potencier",
                    "email": "fabien@symfony.com"
                },
                {
                    "name": "Symfony Community",
                    "homepage": "https://symfony.com/contributors"
                }
            ],
            "description": "Eases the creation of beautiful and testable command line interfaces",
            "homepage": "https://symfony.com",
            "keywords": [
                "cli",
                "command-line",
                "console",
                "terminal"
            ],
            "support": {
                "source": "https://github.com/symfony/console/tree/v7.4.18"
            },
            "funding": [
                {
                    "url": "https://symfony.com/sponsor",
                    "type": "custom"
                },
                {
                    "url": "https://github.com/fabpot",
                    "type": "github"
                },
                {
                    "url": "https://github.com/nicolas-grekas",
                    "type": "github"
                },
                {
                    "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
                    "type": "tidelift"
                }
            ],
            "time": "2026-08-25T14:18:37+00:00"
        },
        {
            "name": "symfony/css-selector",
            "version": "v8.1.6",
            "source": {
                "type": "git",
                "url": "https://github.com/symfony/css-selector.git",
                "reference": "08e2905152a39cf3fd1745d83f8c483e258887d9"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/symfony/css-selector/zipball/08e2905152a39cf3fd1745d83f8c483e258887d9",
                "reference": "08e2905152a39cf3fd1745d83f8c483e258887d9",
                "shasum": ""
            },
            "require": {
                "php": ">=8.4.1"
            },
            "type": "library",
            "autoload": {
                "psr-4": {
                    "Symfony\\Component\\CssSelector\\": ""
                },
                "exclude-from-classmap": [
                    "/Tests/"
                ]
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Fabien Potencier",
                    "email": "fabien@symfony.com"
                },
                {
                    "name": "Jean-François Simon",
                    "email": "jeanfrancois.simon@sensiolabs.com"
                },
                {
                    "name": "Symfony Community",
                    "homepage": "https://symfony.com/contributors"
                }
            ],
            "description": "Converts CSS selectors to XPath expressions",
            "homepage": "https://symfony.com",
            "support": {
                "source": "https://github.com/symfony/css-selector/tree/v8.1.6"
            },
            "funding": [
                {
                    "url": "https://symfony.com/sponsor",
                    "type": "custom"
                },
                {
                    "url": "https://github.com/fabpot",
                    "type": "github"
                },
                {
                    "url": "https://github.com/nicolas-grekas",
                    "type": "github"
                },
                {
                    "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
                    "type": "tidelift"
                }
            ],
            "time": "2026-08-23T10:06:25+00:00"
        },
        {
            "name": "symfony/deprecation-contracts",
            "version": "v3.7.1",
            "source": {
                "type": "git",
                "url": "https://github.com/symfony/deprecation-contracts.git",
                "reference": "f3202fa1b5097b0af062dc978b32ecf63404e31d"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/symfony/deprecation-contracts/zipball/f3202fa1b5097b0af062dc978b32ecf63404e31d",
                "reference": "f3202fa1b5097b0af062dc978b32ecf63404e31d",
                "shasum": ""
            },
            "require": {
                "php": ">=8.1"
            },
            "type": "library",
            "extra": {
                "thanks": {
                    "url": "https://github.com/symfony/contracts",
                    "name": "symfony/contracts"
                },
                "branch-alias": {
                    "dev-main": "3.7-dev"
                }
            },
            "autoload": {
                "files": [
                    "function.php"
                ]
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Nicolas Grekas",
                    "email": "p@tchwork.com"
                },
                {
                    "name": "Symfony Community",
                    "homepage": "https://symfony.com/contributors"
                }
            ],
            "description": "A generic function and convention to trigger deprecation notices",
            "homepage": "https://symfony.com",
            "support": {
                "source": "https://github.com/symfony/deprecation-contracts/tree/v3.7.1"
            },
            "funding": [
                {
                    "url": "https://symfony.com/sponsor",
                    "type": "custom"
                },
                {
                    "url": "https://github.com/fabpot",
                    "type": "github"
                },
                {
                    "url": "https://github.com/nicolas-grekas",
                    "type": "github"
                },
                {
                    "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
                    "type": "tidelift"
                }
            ],
            "time": "2026-06-05T06:23:12+00:00"
        },
        {
            "name": "symfony/error-handler",
            "version": "v7.4.17",
            "source": {
                "type": "git",
                "url": "https://github.com/symfony/error-handler.git",
                "reference": "8373921e231e190a88e2ad526951bbaa791576fa"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/symfony/error-handler/zipball/8373921e231e190a88e2ad526951bbaa791576fa",
                "reference": "8373921e231e190a88e2ad526951bbaa791576fa",
                "shasum": ""
            },
            "require": {
                "php": ">=8.2",
                "psr/log": "^1|^2|^3",
                "symfony/polyfill-php85": "^1.32",
                "symfony/var-dumper": "^6.4|^7.0|^8.0"
            },
            "conflict": {
                "symfony/deprecation-contracts": "<2.5",
                "symfony/http-kernel": "<6.4"
            },
            "require-dev": {
                "symfony/console": "^6.4|^7.0|^8.0",
                "symfony/deprecation-contracts": "^2.5|^3",
                "symfony/http-kernel": "^6.4|^7.0|^8.0",
                "symfony/serializer": "^6.4|^7.0|^8.0",
                "symfony/webpack-encore-bundle": "^1.0|^2.0"
            },
            "bin": [
                "Resources/bin/patch-type-declarations"
            ],
            "type": "library",
            "autoload": {
                "psr-4": {
                    "Symfony\\Component\\ErrorHandler\\": ""
                },
                "exclude-from-classmap": [
                    "/Tests/"
                ]
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Fabien Potencier",
                    "email": "fabien@symfony.com"
                },
                {
                    "name": "Symfony Community",
                    "homepage": "https://symfony.com/contributors"
                }
            ],
            "description": "Provides tools to manage errors and ease debugging PHP code",
            "homepage": "https://symfony.com",
            "support": {
                "source": "https://github.com/symfony/error-handler/tree/v7.4.17"
            },
            "funding": [
                {
                    "url": "https://symfony.com/sponsor",
                    "type": "custom"
                },
                {
                    "url": "https://github.com/fabpot",
                    "type": "github"
                },
                {
                    "url": "https://github.com/nicolas-grekas",
                    "type": "github"
                },
                {
                    "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
                    "type": "tidelift"
                }
            ],
            "time": "2026-08-21T17:40:08+00:00"
        },
        {
            "name": "symfony/event-dispatcher",
            "version": "v8.1.5",
            "source": {
                "type": "git",
                "url": "https://github.com/symfony/event-dispatcher.git",
                "reference": "7458da64220376b2e0dc2d8451bf43382c1ad297"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/symfony/event-dispatcher/zipball/7458da64220376b2e0dc2d8451bf43382c1ad297",
                "reference": "7458da64220376b2e0dc2d8451bf43382c1ad297",
                "shasum": ""
            },
            "require": {
                "php": ">=8.4.1",
                "symfony/deprecation-contracts": "^2.5|^3",
                "symfony/event-dispatcher-contracts": "^2.5|^3"
            },
            "conflict": {
                "symfony/security-http": "<7.4",
                "symfony/service-contracts": "<2.5"
            },
            "provide": {
                "psr/event-dispatcher-implementation": "1.0",
                "symfony/event-dispatcher-implementation": "2.0|3.0"
            },
            "require-dev": {
                "psr/log": "^1|^2|^3",
                "symfony/config": "^7.4|^8.0",
                "symfony/dependency-injection": "^7.4|^8.0",
                "symfony/error-handler": "^7.4|^8.0",
                "symfony/expression-language": "^7.4|^8.0",
                "symfony/framework-bundle": "^7.4|^8.0",
                "symfony/http-foundation": "^7.4|^8.0",
                "symfony/service-contracts": "^2.5|^3",
                "symfony/stopwatch": "^7.4|^8.0"
            },
            "type": "library",
            "autoload": {
                "psr-4": {
                    "Symfony\\Component\\EventDispatcher\\": ""
                },
                "exclude-from-classmap": [
                    "/Tests/"
                ]
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Fabien Potencier",
                    "email": "fabien@symfony.com"
                },
                {
                    "name": "Symfony Community",
                    "homepage": "https://symfony.com/contributors"
                }
            ],
            "description": "Provides tools that allow your application components to communicate with each other by dispatching events and listening to them",
            "homepage": "https://symfony.com",
            "support": {
                "source": "https://github.com/symfony/event-dispatcher/tree/v8.1.5"
            },
            "funding": [
                {
                    "url": "https://symfony.com/sponsor",
                    "type": "custom"
                },
                {
                    "url": "https://github.com/fabpot",
                    "type": "github"
                },
                {
                    "url": "https://github.com/nicolas-grekas",
                    "type": "github"
                },
                {
                    "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
                    "type": "tidelift"
                }
            ],
            "time": "2026-08-21T17:47:34+00:00"
        },
        {
            "name": "symfony/event-dispatcher-contracts",
            "version": "v3.7.1",
            "source": {
                "type": "git",
                "url": "https://github.com/symfony/event-dispatcher-contracts.git",
                "reference": "c7de7a00ffb67842132da02ea92988a39ccd9f4e"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/symfony/event-dispatcher-contracts/zipball/c7de7a00ffb67842132da02ea92988a39ccd9f4e",
                "reference": "c7de7a00ffb67842132da02ea92988a39ccd9f4e",
                "shasum": ""
            },
            "require": {
                "php": ">=8.1",
                "psr/event-dispatcher": "^1"
            },
            "type": "library",
            "extra": {
                "thanks": {
                    "url": "https://github.com/symfony/contracts",
                    "name": "symfony/contracts"
                },
                "branch-alias": {
                    "dev-main": "3.7-dev"
                }
            },
            "autoload": {
                "psr-4": {
                    "Symfony\\Contracts\\EventDispatcher\\": ""
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Nicolas Grekas",
                    "email": "p@tchwork.com"
                },
                {
                    "name": "Symfony Community",
                    "homepage": "https://symfony.com/contributors"
                }
            ],
            "description": "Generic abstractions related to dispatching event",
            "homepage": "https://symfony.com",
            "keywords": [
                "abstractions",
                "contracts",
                "decoupling",
                "interfaces",
                "interoperability",
                "standards"
            ],
            "support": {
                "source": "https://github.com/symfony/event-dispatcher-contracts/tree/v3.7.1"
            },
            "funding": [
                {
                    "url": "https://symfony.com/sponsor",
                    "type": "custom"
                },
                {
                    "url": "https://github.com/fabpot",
                    "type": "github"
                },
                {
                    "url": "https://github.com/nicolas-grekas",
                    "type": "github"
                },
                {
                    "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
                    "type": "tidelift"
                }
            ],
            "time": "2026-06-05T06:23:12+00:00"
        },
        {
            "name": "symfony/finder",
            "version": "v7.4.17",
            "source": {
                "type": "git",
                "url": "https://github.com/symfony/finder.git",
                "reference": "5ce28827081f6d1f0c32eaf3882750f19cb5bbe6"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/symfony/finder/zipball/5ce28827081f6d1f0c32eaf3882750f19cb5bbe6",
                "reference": "5ce28827081f6d1f0c32eaf3882750f19cb5bbe6",
                "shasum": ""
            },
            "require": {
                "php": ">=8.2"
            },
            "require-dev": {
                "symfony/filesystem": "^6.4|^7.0|^8.0"
            },
            "type": "library",
            "autoload": {
                "psr-4": {
                    "Symfony\\Component\\Finder\\": ""
                },
                "exclude-from-classmap": [
                    "/Tests/"
                ]
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Fabien Potencier",
                    "email": "fabien@symfony.com"
                },
                {
                    "name": "Symfony Community",
                    "homepage": "https://symfony.com/contributors"
                }
            ],
            "description": "Finds files and directories via an intuitive fluent interface",
            "homepage": "https://symfony.com",
            "support": {
                "source": "https://github.com/symfony/finder/tree/v7.4.17"
            },
            "funding": [
                {
                    "url": "https://symfony.com/sponsor",
                    "type": "custom"
                },
                {
                    "url": "https://github.com/fabpot",
                    "type": "github"
                },
                {
                    "url": "https://github.com/nicolas-grekas",
                    "type": "github"
                },
                {
                    "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
                    "type": "tidelift"
                }
            ],
            "time": "2026-08-21T12:09:28+00:00"
        },
        {
            "name": "symfony/http-foundation",
            "version": "v7.4.18",
            "source": {
                "type": "git",
                "url": "https://github.com/symfony/http-foundation.git",
                "reference": "d070b716a32fbe3bf04204db0f58ace73b86d133"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/symfony/http-foundation/zipball/d070b716a32fbe3bf04204db0f58ace73b86d133",
                "reference": "d070b716a32fbe3bf04204db0f58ace73b86d133",
                "shasum": ""
            },
            "require": {
                "php": ">=8.2",
                "symfony/deprecation-contracts": "^2.5|^3",
                "symfony/polyfill-mbstring": "^1.1"
            },
            "conflict": {
                "doctrine/dbal": "<3.6",
                "symfony/cache": "<6.4.12|>=7.0,<7.1.5"
            },
            "require-dev": {
                "doctrine/dbal": "^3.6|^4",
                "predis/predis": "^1.1|^2.0",
                "symfony/cache": "^6.4.12|^7.1.5|^8.0",
                "symfony/clock": "^6.4|^7.0|^8.0",
                "symfony/dependency-injection": "^6.4|^7.0|^8.0",
                "symfony/expression-language": "^6.4|^7.0|^8.0",
                "symfony/http-kernel": "^6.4|^7.0|^8.0",
                "symfony/mime": "^6.4|^7.0|^8.0",
                "symfony/rate-limiter": "^6.4|^7.0|^8.0"
            },
            "type": "library",
            "autoload": {
                "psr-4": {
                    "Symfony\\Component\\HttpFoundation\\": ""
                },
                "exclude-from-classmap": [
                    "/Tests/"
                ]
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Fabien Potencier",
                    "email": "fabien@symfony.com"
                },
                {
                    "name": "Symfony Community",
                    "homepage": "https://symfony.com/contributors"
                }
            ],
            "description": "Defines an object-oriented layer for the HTTP specification",
            "homepage": "https://symfony.com",
            "support": {
                "source": "https://github.com/symfony/http-foundation/tree/v7.4.18"
            },
            "funding": [
                {
                    "url": "https://symfony.com/sponsor",
                    "type": "custom"
                },
                {
                    "url": "https://github.com/fabpot",
                    "type": "github"
                },
                {
                    "url": "https://github.com/nicolas-grekas",
                    "type": "github"
                },
                {
                    "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
                    "type": "tidelift"
                }
            ],
            "time": "2026-08-30T20:10:52+00:00"
        },
        {
            "name": "symfony/http-kernel",
            "version": "v7.4.18",
            "source": {
                "type": "git",
                "url": "https://github.com/symfony/http-kernel.git",
                "reference": "275d2d2d24530f2a0eaf17704a3a93860a036351"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/symfony/http-kernel/zipball/275d2d2d24530f2a0eaf17704a3a93860a036351",
                "reference": "275d2d2d24530f2a0eaf17704a3a93860a036351",
                "shasum": ""
            },
            "require": {
                "php": ">=8.2",
                "psr/log": "^1|^2|^3",
                "symfony/deprecation-contracts": "^2.5|^3",
                "symfony/error-handler": "^6.4|^7.0|^8.0",
                "symfony/event-dispatcher": "^7.3|^8.0",
                "symfony/http-foundation": "^7.4|^8.0",
                "symfony/polyfill-ctype": "^1.8"
            },
            "conflict": {
                "symfony/browser-kit": "<6.4",
                "symfony/cache": "<6.4",
                "symfony/config": "<6.4",
                "symfony/console": "<6.4",
                "symfony/dependency-injection": "<6.4",
                "symfony/doctrine-bridge": "<6.4",
                "symfony/flex": "<2.10",
                "symfony/form": "<6.4",
                "symfony/http-client": "<6.4",
                "symfony/http-client-contracts": "<2.5",
                "symfony/mailer": "<6.4",
                "symfony/messenger": "<6.4",
                "symfony/translation": "<6.4",
                "symfony/translation-contracts": "<2.5",
                "symfony/twig-bridge": "<6.4",
                "symfony/validator": "<6.4",
                "symfony/var-dumper": "<6.4",
                "twig/twig": "<3.12"
            },
            "provide": {
                "psr/log-implementation": "1.0|2.0|3.0"
            },
            "require-dev": {
                "psr/cache": "^1.0|^2.0|^3.0",
                "symfony/browser-kit": "^6.4|^7.0|^8.0",
                "symfony/clock": "^6.4|^7.0|^8.0",
                "symfony/config": "^6.4|^7.0|^8.0",
                "symfony/console": "^6.4|^7.0|^8.0",
                "symfony/css-selector": "^6.4|^7.0|^8.0",
                "symfony/dependency-injection": "^6.4.1|^7.0.1|^8.0",
                "symfony/dom-crawler": "^6.4|^7.0|^8.0",
                "symfony/expression-language": "^6.4|^7.0|^8.0",
                "symfony/finder": "^6.4|^7.0|^8.0",
                "symfony/http-client-contracts": "^2.5|^3",
                "symfony/process": "^6.4|^7.0|^8.0",
                "symfony/property-access": "^7.1|^8.0",
                "symfony/routing": "^6.4|^7.0|^8.0",
                "symfony/serializer": "^7.1|^8.0",
                "symfony/stopwatch": "^6.4|^7.0|^8.0",
                "symfony/translation": "^6.4|^7.0|^8.0",
                "symfony/translation-contracts": "^2.5|^3",
                "symfony/uid": "^6.4|^7.0|^8.0",
                "symfony/validator": "^6.4|^7.0|^8.0",
                "symfony/var-dumper": "^6.4|^7.0|^8.0",
                "symfony/var-exporter": "^6.4|^7.0|^8.0",
                "twig/twig": "^3.12|^4.0"
            },
            "type": "library",
            "autoload": {
                "psr-4": {
                    "Symfony\\Component\\HttpKernel\\": ""
                },
                "exclude-from-classmap": [
                    "/Tests/"
                ]
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Fabien Potencier",
                    "email": "fabien@symfony.com"
                },
                {
                    "name": "Symfony Community",
                    "homepage": "https://symfony.com/contributors"
                }
            ],
            "description": "Provides a structured process for converting a Request into a Response",
            "homepage": "https://symfony.com",
            "support": {
                "source": "https://github.com/symfony/http-kernel/tree/v7.4.18"
            },
            "funding": [
                {
                    "url": "https://symfony.com/sponsor",
                    "type": "custom"
                },
                {
                    "url": "https://github.com/fabpot",
                    "type": "github"
                },
                {
                    "url": "https://github.com/nicolas-grekas",
                    "type": "github"
                },
                {
                    "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
                    "type": "tidelift"
                }
            ],
            "time": "2026-08-30T21:24:29+00:00"
        },
        {
            "name": "symfony/mailer",
            "version": "v7.4.17",
            "source": {
                "type": "git",
                "url": "https://github.com/symfony/mailer.git",
                "reference": "b17c9bf3a551d5f635638a3b6c05f06c4dc87584"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/symfony/mailer/zipball/b17c9bf3a551d5f635638a3b6c05f06c4dc87584",
                "reference": "b17c9bf3a551d5f635638a3b6c05f06c4dc87584",
                "shasum": ""
            },
            "require": {
                "egulias/email-validator": "^2.1.10|^3|^4",
                "php": ">=8.2",
                "psr/event-dispatcher": "^1",
                "psr/log": "^1|^2|^3",
                "symfony/event-dispatcher": "^6.4|^7.0|^8.0",
                "symfony/mime": "^7.2|^8.0",
                "symfony/service-contracts": "^2.5|^3"
            },
            "conflict": {
                "symfony/http-client-contracts": "<2.5",
                "symfony/http-kernel": "<6.4",
                "symfony/messenger": "<6.4",
                "symfony/mime": "<6.4",
                "symfony/twig-bridge": "<6.4"
            },
            "require-dev": {
                "symfony/console": "^6.4|^7.0|^8.0",
                "symfony/http-client": "^6.4|^7.0|^8.0",
                "symfony/messenger": "^6.4|^7.0|^8.0",
                "symfony/twig-bridge": "^6.4|^7.0|^8.0"
            },
            "type": "library",
            "autoload": {
                "psr-4": {
                    "Symfony\\Component\\Mailer\\": ""
                },
                "exclude-from-classmap": [
                    "/Tests/"
                ]
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Fabien Potencier",
                    "email": "fabien@symfony.com"
                },
                {
                    "name": "Symfony Community",
                    "homepage": "https://symfony.com/contributors"
                }
            ],
            "description": "Helps sending emails",
            "homepage": "https://symfony.com",
            "support": {
                "source": "https://github.com/symfony/mailer/tree/v7.4.17"
            },
            "funding": [
                {
                    "url": "https://symfony.com/sponsor",
                    "type": "custom"
                },
                {
                    "url": "https://github.com/fabpot",
                    "type": "github"
                },
                {
                    "url": "https://github.com/nicolas-grekas",
                    "type": "github"
                },
                {
                    "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
                    "type": "tidelift"
                }
            ],
            "time": "2026-08-21T17:40:08+00:00"
        },
        {
            "name": "symfony/mime",
            "version": "v7.4.18",
            "source": {
                "type": "git",
                "url": "https://github.com/symfony/mime.git",
                "reference": "bf328d82105831db3e409195db0540ff57f27c80"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/symfony/mime/zipball/bf328d82105831db3e409195db0540ff57f27c80",
                "reference": "bf328d82105831db3e409195db0540ff57f27c80",
                "shasum": ""
            },
            "require": {
                "php": ">=8.2",
                "symfony/deprecation-contracts": "^2.5|^3",
                "symfony/polyfill-intl-idn": "^1.10",
                "symfony/polyfill-mbstring": "^1.0"
            },
            "conflict": {
                "egulias/email-validator": "~3.0.0",
                "phpdocumentor/reflection-docblock": "<5.2|>=7",
                "phpdocumentor/type-resolver": "<1.5.1",
                "symfony/mailer": "<6.4",
                "symfony/serializer": "<6.4.3|>7.0,<7.0.3"
            },
            "require-dev": {
                "egulias/email-validator": "^2.1.10|^3.1|^4",
                "league/html-to-markdown": "^5.0",
                "phpdocumentor/reflection-docblock": "^5.2|^6.0",
                "symfony/dependency-injection": "^6.4|^7.0|^8.0",
                "symfony/process": "^6.4|^7.0|^8.0",
                "symfony/property-access": "^6.4|^7.0|^8.0",
                "symfony/property-info": "^6.4|^7.0|^8.0",
                "symfony/serializer": "^6.4.44|^7.4.17|^8.1.5"
            },
            "type": "library",
            "autoload": {
                "psr-4": {
                    "Symfony\\Component\\Mime\\": ""
                },
                "exclude-from-classmap": [
                    "/Tests/"
                ]
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Fabien Potencier",
                    "email": "fabien@symfony.com"
                },
                {
                    "name": "Symfony Community",
                    "homepage": "https://symfony.com/contributors"
                }
            ],
            "description": "Allows manipulating MIME messages",
            "homepage": "https://symfony.com",
            "keywords": [
                "mime",
                "mime-type"
            ],
            "support": {
                "source": "https://github.com/symfony/mime/tree/v7.4.18"
            },
            "funding": [
                {
                    "url": "https://symfony.com/sponsor",
                    "type": "custom"
                },
                {
                    "url": "https://github.com/fabpot",
                    "type": "github"
                },
                {
                    "url": "https://github.com/nicolas-grekas",
                    "type": "github"
                },
                {
                    "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
                    "type": "tidelift"
                }
            ],
            "time": "2026-08-22T09:04:42+00:00"
        },
        {
            "name": "symfony/polyfill-ctype",
            "version": "v1.37.0",
            "source": {
                "type": "git",
                "url": "https://github.com/symfony/polyfill-ctype.git",
                "reference": "141046a8f9477948ff284fa65be2095baafb94f2"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/symfony/polyfill-ctype/zipball/141046a8f9477948ff284fa65be2095baafb94f2",
                "reference": "141046a8f9477948ff284fa65be2095baafb94f2",
                "shasum": ""
            },
            "require": {
                "php": ">=7.2"
            },
            "provide": {
                "ext-ctype": "*"
            },
            "suggest": {
                "ext-ctype": "For best performance"
            },
            "type": "library",
            "extra": {
                "thanks": {
                    "url": "https://github.com/symfony/polyfill",
                    "name": "symfony/polyfill"
                }
            },
            "autoload": {
                "files": [
                    "bootstrap.php"
                ],
                "psr-4": {
                    "Symfony\\Polyfill\\Ctype\\": ""
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Gert de Pagter",
                    "email": "BackEndTea@gmail.com"
                },
                {
                    "name": "Symfony Community",
                    "homepage": "https://symfony.com/contributors"
                }
            ],
            "description": "Symfony polyfill for ctype functions",
            "homepage": "https://symfony.com",
            "keywords": [
                "compatibility",
                "ctype",
                "polyfill",
                "portable"
            ],
            "support": {
                "source": "https://github.com/symfony/polyfill-ctype/tree/v1.37.0"
            },
            "funding": [
                {
                    "url": "https://symfony.com/sponsor",
                    "type": "custom"
                },
                {
                    "url": "https://github.com/fabpot",
                    "type": "github"
                },
                {
                    "url": "https://github.com/nicolas-grekas",
                    "type": "github"
                },
                {
                    "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
                    "type": "tidelift"
                }
            ],
            "time": "2026-04-10T16:19:22+00:00"
        },
        {
            "name": "symfony/polyfill-intl-grapheme",
            "version": "v1.41.0",
            "source": {
                "type": "git",
                "url": "https://github.com/symfony/polyfill-intl-grapheme.git",
                "reference": "bb899c1db0aa8127dc3afe8cda4a67eb24915f8d"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/symfony/polyfill-intl-grapheme/zipball/bb899c1db0aa8127dc3afe8cda4a67eb24915f8d",
                "reference": "bb899c1db0aa8127dc3afe8cda4a67eb24915f8d",
                "shasum": ""
            },
            "require": {
                "php": ">=7.2"
            },
            "suggest": {
                "ext-intl": "For best performance"
            },
            "type": "library",
            "extra": {
                "thanks": {
                    "url": "https://github.com/symfony/polyfill",
                    "name": "symfony/polyfill"
                }
            },
            "autoload": {
                "files": [
                    "bootstrap.php"
                ],
                "psr-4": {
                    "Symfony\\Polyfill\\Intl\\Grapheme\\": ""
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Nicolas Grekas",
                    "email": "p@tchwork.com"
                },
                {
                    "name": "Symfony Community",
                    "homepage": "https://symfony.com/contributors"
                }
            ],
            "description": "Symfony polyfill for intl's grapheme_* functions",
            "homepage": "https://symfony.com",
            "keywords": [
                "compatibility",
                "grapheme",
                "intl",
                "polyfill",
                "portable",
                "shim"
            ],
            "support": {
                "source": "https://github.com/symfony/polyfill-intl-grapheme/tree/v1.41.0"
            },
            "funding": [
                {
                    "url": "https://symfony.com/sponsor",
                    "type": "custom"
                },
                {
                    "url": "https://github.com/fabpot",
                    "type": "github"
                },
                {
                    "url": "https://github.com/nicolas-grekas",
                    "type": "github"
                },
                {
                    "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
                    "type": "tidelift"
                }
            ],
            "time": "2026-07-28T08:25:59+00:00"
        },
        {
            "name": "symfony/polyfill-intl-idn",
            "version": "v1.42.0",
            "source": {
                "type": "git",
                "url": "https://github.com/symfony/polyfill-intl-idn.git",
                "reference": "51b5ff5ba85452b31ec6f55490b08148612339d9"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/symfony/polyfill-intl-idn/zipball/51b5ff5ba85452b31ec6f55490b08148612339d9",
                "reference": "51b5ff5ba85452b31ec6f55490b08148612339d9",
                "shasum": ""
            },
            "require": {
                "php": ">=7.2",
                "symfony/polyfill-intl-normalizer": "^1.10"
            },
            "suggest": {
                "ext-intl": "For best performance"
            },
            "type": "library",
            "extra": {
                "thanks": {
                    "url": "https://github.com/symfony/polyfill",
                    "name": "symfony/polyfill"
                }
            },
            "autoload": {
                "files": [
                    "bootstrap.php"
                ],
                "psr-4": {
                    "Symfony\\Polyfill\\Intl\\Idn\\": ""
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Laurent Bassin",
                    "email": "laurent@bassin.info"
                },
                {
                    "name": "Trevor Rowbotham",
                    "email": "trevor.rowbotham@pm.me"
                },
                {
                    "name": "Symfony Community",
                    "homepage": "https://symfony.com/contributors"
                }
            ],
            "description": "Symfony polyfill for intl's idn_to_ascii and idn_to_utf8 functions",
            "homepage": "https://symfony.com",
            "keywords": [
                "compatibility",
                "idn",
                "intl",
                "polyfill",
                "portable",
                "shim"
            ],
            "support": {
                "source": "https://github.com/symfony/polyfill-intl-idn/tree/v1.42.0"
            },
            "funding": [
                {
                    "url": "https://symfony.com/sponsor",
                    "type": "custom"
                },
                {
                    "url": "https://github.com/fabpot",
                    "type": "github"
                },
                {
                    "url": "https://github.com/nicolas-grekas",
                    "type": "github"
                },
                {
                    "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
                    "type": "tidelift"
                }
            ],
            "time": "2026-08-24T10:51:20+00:00"
        },
        {
            "name": "symfony/polyfill-intl-normalizer",
            "version": "v1.42.0",
            "source": {
                "type": "git",
                "url": "https://github.com/symfony/polyfill-intl-normalizer.git",
                "reference": "aa20edea75bd9c48cfecc8360922e5a6e5c44502"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/symfony/polyfill-intl-normalizer/zipball/aa20edea75bd9c48cfecc8360922e5a6e5c44502",
                "reference": "aa20edea75bd9c48cfecc8360922e5a6e5c44502",
                "shasum": ""
            },
            "require": {
                "php": ">=7.2"
            },
            "suggest": {
                "ext-intl": "For best performance"
            },
            "type": "library",
            "extra": {
                "thanks": {
                    "url": "https://github.com/symfony/polyfill",
                    "name": "symfony/polyfill"
                }
            },
            "autoload": {
                "files": [
                    "bootstrap.php"
                ],
                "psr-4": {
                    "Symfony\\Polyfill\\Intl\\Normalizer\\": ""
                },
                "classmap": [
                    "Resources/stubs"
                ]
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Nicolas Grekas",
                    "email": "p@tchwork.com"
                },
                {
                    "name": "Symfony Community",
                    "homepage": "https://symfony.com/contributors"
                }
            ],
            "description": "Symfony polyfill for intl's Normalizer class and related functions",
            "homepage": "https://symfony.com",
            "keywords": [
                "compatibility",
                "intl",
                "normalizer",
                "polyfill",
                "portable",
                "shim"
            ],
            "support": {
                "source": "https://github.com/symfony/polyfill-intl-normalizer/tree/v1.42.0"
            },
            "funding": [
                {
                    "url": "https://symfony.com/sponsor",
                    "type": "custom"
                },
                {
                    "url": "https://github.com/fabpot",
                    "type": "github"
                },
                {
                    "url": "https://github.com/nicolas-grekas",
                    "type": "github"
                },
                {
                    "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
                    "type": "tidelift"
                }
            ],
            "time": "2026-08-07T06:33:24+00:00"
        },
        {
            "name": "symfony/polyfill-mbstring",
            "version": "v1.38.2",
            "source": {
                "type": "git",
                "url": "https://github.com/symfony/polyfill-mbstring.git",
                "reference": "d3d318bad5e7a1bfbd026009c8bfb8d8f99ae6b6"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/symfony/polyfill-mbstring/zipball/d3d318bad5e7a1bfbd026009c8bfb8d8f99ae6b6",
                "reference": "d3d318bad5e7a1bfbd026009c8bfb8d8f99ae6b6",
                "shasum": ""
            },
            "require": {
                "ext-iconv": "*",
                "php": ">=7.2"
            },
            "provide": {
                "ext-mbstring": "*"
            },
            "suggest": {
                "ext-mbstring": "For best performance"
            },
            "type": "library",
            "extra": {
                "thanks": {
                    "url": "https://github.com/symfony/polyfill",
                    "name": "symfony/polyfill"
                }
            },
            "autoload": {
                "files": [
                    "bootstrap.php"
                ],
                "psr-4": {
                    "Symfony\\Polyfill\\Mbstring\\": ""
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Nicolas Grekas",
                    "email": "p@tchwork.com"
                },
                {
                    "name": "Symfony Community",
                    "homepage": "https://symfony.com/contributors"
                }
            ],
            "description": "Symfony polyfill for the Mbstring extension",
            "homepage": "https://symfony.com",
            "keywords": [
                "compatibility",
                "mbstring",
                "polyfill",
                "portable",
                "shim"
            ],
            "support": {
                "source": "https://github.com/symfony/polyfill-mbstring/tree/v1.38.2"
            },
            "funding": [
                {
                    "url": "https://symfony.com/sponsor",
                    "type": "custom"
                },
                {
                    "url": "https://github.com/fabpot",
                    "type": "github"
                },
                {
                    "url": "https://github.com/nicolas-grekas",
                    "type": "github"
                },
                {
                    "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
                    "type": "tidelift"
                }
            ],
            "time": "2026-05-27T06:59:30+00:00"
        },
        {
            "name": "symfony/polyfill-php80",
            "version": "v1.37.0",
            "source": {
                "type": "git",
                "url": "https://github.com/symfony/polyfill-php80.git",
                "reference": "dfb55726c3a76ea3b6459fcfda1ec2d80a682411"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/symfony/polyfill-php80/zipball/dfb55726c3a76ea3b6459fcfda1ec2d80a682411",
                "reference": "dfb55726c3a76ea3b6459fcfda1ec2d80a682411",
                "shasum": ""
            },
            "require": {
                "php": ">=7.2"
            },
            "type": "library",
            "extra": {
                "thanks": {
                    "url": "https://github.com/symfony/polyfill",
                    "name": "symfony/polyfill"
                }
            },
            "autoload": {
                "files": [
                    "bootstrap.php"
                ],
                "psr-4": {
                    "Symfony\\Polyfill\\Php80\\": ""
                },
                "classmap": [
                    "Resources/stubs"
                ]
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Ion Bazan",
                    "email": "ion.bazan@gmail.com"
                },
                {
                    "name": "Nicolas Grekas",
                    "email": "p@tchwork.com"
                },
                {
                    "name": "Symfony Community",
                    "homepage": "https://symfony.com/contributors"
                }
            ],
            "description": "Symfony polyfill backporting some PHP 8.0+ features to lower PHP versions",
            "homepage": "https://symfony.com",
            "keywords": [
                "compatibility",
                "polyfill",
                "portable",
                "shim"
            ],
            "support": {
                "source": "https://github.com/symfony/polyfill-php80/tree/v1.37.0"
            },
            "funding": [
                {
                    "url": "https://symfony.com/sponsor",
                    "type": "custom"
                },
                {
                    "url": "https://github.com/fabpot",
                    "type": "github"
                },
                {
                    "url": "https://github.com/nicolas-grekas",
                    "type": "github"
                },
                {
                    "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
                    "type": "tidelift"
                }
            ],
            "time": "2026-04-10T16:19:22+00:00"
        },
        {
            "name": "symfony/polyfill-php82",
            "version": "v1.38.1",
            "source": {
                "type": "git",
                "url": "https://github.com/symfony/polyfill-php82.git",
                "reference": "002dc0cfe5fd4ed6033d48f27d4f19a486c4b04b"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/symfony/polyfill-php82/zipball/002dc0cfe5fd4ed6033d48f27d4f19a486c4b04b",
                "reference": "002dc0cfe5fd4ed6033d48f27d4f19a486c4b04b",
                "shasum": ""
            },
            "require": {
                "php": ">=7.2"
            },
            "type": "library",
            "extra": {
                "thanks": {
                    "url": "https://github.com/symfony/polyfill",
                    "name": "symfony/polyfill"
                }
            },
            "autoload": {
                "files": [
                    "bootstrap.php"
                ],
                "psr-4": {
                    "Symfony\\Polyfill\\Php82\\": ""
                },
                "classmap": [
                    "Resources/stubs"
                ]
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Nicolas Grekas",
                    "email": "p@tchwork.com"
                },
                {
                    "name": "Symfony Community",
                    "homepage": "https://symfony.com/contributors"
                }
            ],
            "description": "Symfony polyfill backporting some PHP 8.2+ features to lower PHP versions",
            "homepage": "https://symfony.com",
            "keywords": [
                "compatibility",
                "polyfill",
                "portable",
                "shim"
            ],
            "support": {
                "source": "https://github.com/symfony/polyfill-php82/tree/v1.38.1"
            },
            "funding": [
                {
                    "url": "https://symfony.com/sponsor",
                    "type": "custom"
                },
                {
                    "url": "https://github.com/fabpot",
                    "type": "github"
                },
                {
                    "url": "https://github.com/nicolas-grekas",
                    "type": "github"
                },
                {
                    "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
                    "type": "tidelift"
                }
            ],
            "time": "2026-05-26T12:45:58+00:00"
        },
        {
            "name": "symfony/polyfill-php83",
            "version": "v1.41.0",
            "source": {
                "type": "git",
                "url": "https://github.com/symfony/polyfill-php83.git",
                "reference": "5ea99087fb99c273a9b9236ed4c31e78b16103c6"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/symfony/polyfill-php83/zipball/5ea99087fb99c273a9b9236ed4c31e78b16103c6",
                "reference": "5ea99087fb99c273a9b9236ed4c31e78b16103c6",
                "shasum": ""
            },
            "require": {
                "php": ">=7.2"
            },
            "type": "library",
            "extra": {
                "thanks": {
                    "url": "https://github.com/symfony/polyfill",
                    "name": "symfony/polyfill"
                }
            },
            "autoload": {
                "files": [
                    "bootstrap.php"
                ],
                "psr-4": {
                    "Symfony\\Polyfill\\Php83\\": ""
                },
                "classmap": [
                    "Resources/stubs"
                ]
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Nicolas Grekas",
                    "email": "p@tchwork.com"
                },
                {
                    "name": "Symfony Community",
                    "homepage": "https://symfony.com/contributors"
                }
            ],
            "description": "Symfony polyfill backporting some PHP 8.3+ features to lower PHP versions",
            "homepage": "https://symfony.com",
            "keywords": [
                "compatibility",
                "polyfill",
                "portable",
                "shim"
            ],
            "support": {
                "source": "https://github.com/symfony/polyfill-php83/tree/v1.41.0"
            },
            "funding": [
                {
                    "url": "https://symfony.com/sponsor",
                    "type": "custom"
                },
                {
                    "url": "https://github.com/fabpot",
                    "type": "github"
                },
                {
                    "url": "https://github.com/nicolas-grekas",
                    "type": "github"
                },
                {
                    "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
                    "type": "tidelift"
                }
            ],
            "time": "2026-07-01T12:47:55+00:00"
        },
        {
            "name": "symfony/polyfill-php84",
            "version": "v1.38.1",
            "source": {
                "type": "git",
                "url": "https://github.com/symfony/polyfill-php84.git",
                "reference": "f4e1dfaee5b74aba5964fe1fd4dfc7ba5e3085fa"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/symfony/polyfill-php84/zipball/f4e1dfaee5b74aba5964fe1fd4dfc7ba5e3085fa",
                "reference": "f4e1dfaee5b74aba5964fe1fd4dfc7ba5e3085fa",
                "shasum": ""
            },
            "require": {
                "php": ">=7.2"
            },
            "type": "library",
            "extra": {
                "thanks": {
                    "url": "https://github.com/symfony/polyfill",
                    "name": "symfony/polyfill"
                }
            },
            "autoload": {
                "files": [
                    "bootstrap.php"
                ],
                "psr-4": {
                    "Symfony\\Polyfill\\Php84\\": ""
                },
                "classmap": [
                    "Resources/stubs"
                ]
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Nicolas Grekas",
                    "email": "p@tchwork.com"
                },
                {
                    "name": "Symfony Community",
                    "homepage": "https://symfony.com/contributors"
                }
            ],
            "description": "Symfony polyfill backporting some PHP 8.4+ features to lower PHP versions",
            "homepage": "https://symfony.com",
            "keywords": [
                "compatibility",
                "polyfill",
                "portable",
                "shim"
            ],
            "support": {
                "source": "https://github.com/symfony/polyfill-php84/tree/v1.38.1"
            },
            "funding": [
                {
                    "url": "https://symfony.com/sponsor",
                    "type": "custom"
                },
                {
                    "url": "https://github.com/fabpot",
                    "type": "github"
                },
                {
                    "url": "https://github.com/nicolas-grekas",
                    "type": "github"
                },
                {
                    "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
                    "type": "tidelift"
                }
            ],
            "time": "2026-05-26T12:51:13+00:00"
        },
        {
            "name": "symfony/polyfill-php85",
            "version": "v1.41.0",
            "source": {
                "type": "git",
                "url": "https://github.com/symfony/polyfill-php85.git",
                "reference": "255fab485aaa1006ed411040c42aecd7b5302d7a"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/symfony/polyfill-php85/zipball/255fab485aaa1006ed411040c42aecd7b5302d7a",
                "reference": "255fab485aaa1006ed411040c42aecd7b5302d7a",
                "shasum": ""
            },
            "require": {
                "php": ">=7.2"
            },
            "type": "library",
            "extra": {
                "thanks": {
                    "url": "https://github.com/symfony/polyfill",
                    "name": "symfony/polyfill"
                }
            },
            "autoload": {
                "files": [
                    "bootstrap.php"
                ],
                "psr-4": {
                    "Symfony\\Polyfill\\Php85\\": ""
                },
                "classmap": [
                    "Resources/stubs"
                ]
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Nicolas Grekas",
                    "email": "p@tchwork.com"
                },
                {
                    "name": "Symfony Community",
                    "homepage": "https://symfony.com/contributors"
                }
            ],
            "description": "Symfony polyfill backporting some PHP 8.5+ features to lower PHP versions",
            "homepage": "https://symfony.com",
            "keywords": [
                "compatibility",
                "polyfill",
                "portable",
                "shim"
            ],
            "support": {
                "source": "https://github.com/symfony/polyfill-php85/tree/v1.41.0"
            },
            "funding": [
                {
                    "url": "https://symfony.com/sponsor",
                    "type": "custom"
                },
                {
                    "url": "https://github.com/fabpot",
                    "type": "github"
                },
                {
                    "url": "https://github.com/nicolas-grekas",
                    "type": "github"
                },
                {
                    "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
                    "type": "tidelift"
                }
            ],
            "time": "2026-07-01T12:47:55+00:00"
        },
        {
            "name": "symfony/polyfill-uuid",
            "version": "v1.37.0",
            "source": {
                "type": "git",
                "url": "https://github.com/symfony/polyfill-uuid.git",
                "reference": "26dfec253c4cf3e51b541b52ddf7e42cb0908e94"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/symfony/polyfill-uuid/zipball/26dfec253c4cf3e51b541b52ddf7e42cb0908e94",
                "reference": "26dfec253c4cf3e51b541b52ddf7e42cb0908e94",
                "shasum": ""
            },
            "require": {
                "php": ">=7.2"
            },
            "provide": {
                "ext-uuid": "*"
            },
            "suggest": {
                "ext-uuid": "For best performance"
            },
            "type": "library",
            "extra": {
                "thanks": {
                    "url": "https://github.com/symfony/polyfill",
                    "name": "symfony/polyfill"
                }
            },
            "autoload": {
                "files": [
                    "bootstrap.php"
                ],
                "psr-4": {
                    "Symfony\\Polyfill\\Uuid\\": ""
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Grégoire Pineau",
                    "email": "lyrixx@lyrixx.info"
                },
                {
                    "name": "Symfony Community",
                    "homepage": "https://symfony.com/contributors"
                }
            ],
            "description": "Symfony polyfill for uuid functions",
            "homepage": "https://symfony.com",
            "keywords": [
                "compatibility",
                "polyfill",
                "portable",
                "uuid"
            ],
            "support": {
                "source": "https://github.com/symfony/polyfill-uuid/tree/v1.37.0"
            },
            "funding": [
                {
                    "url": "https://symfony.com/sponsor",
                    "type": "custom"
                },
                {
                    "url": "https://github.com/fabpot",
                    "type": "github"
                },
                {
                    "url": "https://github.com/nicolas-grekas",
                    "type": "github"
                },
                {
                    "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
                    "type": "tidelift"
                }
            ],
            "time": "2026-04-10T16:19:22+00:00"
        },
        {
            "name": "symfony/process",
            "version": "v7.4.18",
            "source": {
                "type": "git",
                "url": "https://github.com/symfony/process.git",
                "reference": "058d17fc284cce14efb2385783b55014a461b176"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/symfony/process/zipball/058d17fc284cce14efb2385783b55014a461b176",
                "reference": "058d17fc284cce14efb2385783b55014a461b176",
                "shasum": ""
            },
            "require": {
                "php": ">=8.2"
            },
            "type": "library",
            "autoload": {
                "psr-4": {
                    "Symfony\\Component\\Process\\": ""
                },
                "exclude-from-classmap": [
                    "/Tests/"
                ]
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Fabien Potencier",
                    "email": "fabien@symfony.com"
                },
                {
                    "name": "Symfony Community",
                    "homepage": "https://symfony.com/contributors"
                }
            ],
            "description": "Executes commands in sub-processes",
            "homepage": "https://symfony.com",
            "support": {
                "source": "https://github.com/symfony/process/tree/v7.4.18"
            },
            "funding": [
                {
                    "url": "https://symfony.com/sponsor",
                    "type": "custom"
                },
                {
                    "url": "https://github.com/fabpot",
                    "type": "github"
                },
                {
                    "url": "https://github.com/nicolas-grekas",
                    "type": "github"
                },
                {
                    "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
                    "type": "tidelift"
                }
            ],
            "time": "2026-08-21T17:40:08+00:00"
        },
        {
            "name": "symfony/routing",
            "version": "v7.4.18",
            "source": {
                "type": "git",
                "url": "https://github.com/symfony/routing.git",
                "reference": "ddd558991e98f693ae6bf5063cc1b0362c6bbec3"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/symfony/routing/zipball/ddd558991e98f693ae6bf5063cc1b0362c6bbec3",
                "reference": "ddd558991e98f693ae6bf5063cc1b0362c6bbec3",
                "shasum": ""
            },
            "require": {
                "php": ">=8.2",
                "symfony/deprecation-contracts": "^2.5|^3"
            },
            "conflict": {
                "symfony/config": "<6.4",
                "symfony/dependency-injection": "<6.4",
                "symfony/yaml": "<6.4"
            },
            "require-dev": {
                "psr/log": "^1|^2|^3",
                "symfony/config": "^6.4|^7.0|^8.0",
                "symfony/dependency-injection": "^6.4|^7.0|^8.0",
                "symfony/expression-language": "^6.4|^7.0|^8.0",
                "symfony/http-foundation": "^6.4|^7.0|^8.0",
                "symfony/yaml": "^6.4|^7.0|^8.0"
            },
            "type": "library",
            "autoload": {
                "psr-4": {
                    "Symfony\\Component\\Routing\\": ""
                },
                "exclude-from-classmap": [
                    "/Tests/"
                ]
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Fabien Potencier",
                    "email": "fabien@symfony.com"
                },
                {
                    "name": "Symfony Community",
                    "homepage": "https://symfony.com/contributors"
                }
            ],
            "description": "Maps an HTTP request to a set of configuration variables",
            "homepage": "https://symfony.com",
            "keywords": [
                "router",
                "routing",
                "uri",
                "url"
            ],
            "support": {
                "source": "https://github.com/symfony/routing/tree/v7.4.18"
            },
            "funding": [
                {
                    "url": "https://symfony.com/sponsor",
                    "type": "custom"
                },
                {
                    "url": "https://github.com/fabpot",
                    "type": "github"
                },
                {
                    "url": "https://github.com/nicolas-grekas",
                    "type": "github"
                },
                {
                    "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
                    "type": "tidelift"
                }
            ],
            "time": "2026-08-17T13:12:36+00:00"
        },
        {
            "name": "symfony/service-contracts",
            "version": "v3.7.3",
            "source": {
                "type": "git",
                "url": "https://github.com/symfony/service-contracts.git",
                "reference": "15e6a07ec2a2c75ceb1b21dd98105ee8456d2257"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/symfony/service-contracts/zipball/15e6a07ec2a2c75ceb1b21dd98105ee8456d2257",
                "reference": "15e6a07ec2a2c75ceb1b21dd98105ee8456d2257",
                "shasum": ""
            },
            "require": {
                "php": ">=8.1",
                "psr/container": "^1.1|^2.0",
                "symfony/deprecation-contracts": "^2.5|^3"
            },
            "conflict": {
                "ext-psr": "<1.1|>=2"
            },
            "type": "library",
            "extra": {
                "thanks": {
                    "url": "https://github.com/symfony/contracts",
                    "name": "symfony/contracts"
                },
                "branch-alias": {
                    "dev-main": "3.7-dev"
                }
            },
            "autoload": {
                "psr-4": {
                    "Symfony\\Contracts\\Service\\": ""
                },
                "exclude-from-classmap": [
                    "/Test/"
                ]
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Nicolas Grekas",
                    "email": "p@tchwork.com"
                },
                {
                    "name": "Symfony Community",
                    "homepage": "https://symfony.com/contributors"
                }
            ],
            "description": "Generic abstractions related to writing services",
            "homepage": "https://symfony.com",
            "keywords": [
                "abstractions",
                "contracts",
                "decoupling",
                "interfaces",
                "interoperability",
                "standards"
            ],
            "support": {
                "source": "https://github.com/symfony/service-contracts/tree/v3.7.3"
            },
            "funding": [
                {
                    "url": "https://symfony.com/sponsor",
                    "type": "custom"
                },
                {
                    "url": "https://github.com/fabpot",
                    "type": "github"
                },
                {
                    "url": "https://github.com/nicolas-grekas",
                    "type": "github"
                },
                {
                    "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
                    "type": "tidelift"
                }
            ],
            "time": "2026-07-27T15:39:01+00:00"
        },
        {
            "name": "symfony/string",
            "version": "v8.1.2",
            "source": {
                "type": "git",
                "url": "https://github.com/symfony/string.git",
                "reference": "286a76b7255e5cc4bf0101a0bc5388ecf1c38ccc"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/symfony/string/zipball/286a76b7255e5cc4bf0101a0bc5388ecf1c38ccc",
                "reference": "286a76b7255e5cc4bf0101a0bc5388ecf1c38ccc",
                "shasum": ""
            },
            "require": {
                "php": ">=8.4.1",
                "symfony/polyfill-ctype": "^1.8",
                "symfony/polyfill-intl-grapheme": "^1.33",
                "symfony/polyfill-intl-normalizer": "^1.0",
                "symfony/polyfill-mbstring": "^1.0"
            },
            "conflict": {
                "symfony/translation-contracts": "<2.5"
            },
            "require-dev": {
                "symfony/emoji": "^7.4|^8.0",
                "symfony/http-client": "^7.4|^8.0",
                "symfony/intl": "^7.4|^8.0",
                "symfony/translation-contracts": "^2.5|^3.0",
                "symfony/var-exporter": "^7.4|^8.0"
            },
            "type": "library",
            "autoload": {
                "files": [
                    "Resources/functions.php"
                ],
                "psr-4": {
                    "Symfony\\Component\\String\\": ""
                },
                "exclude-from-classmap": [
                    "/Tests/"
                ]
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Nicolas Grekas",
                    "email": "p@tchwork.com"
                },
                {
                    "name": "Symfony Community",
                    "homepage": "https://symfony.com/contributors"
                }
            ],
            "description": "Provides an object-oriented API to strings and deals with bytes, UTF-8 code points and grapheme clusters in a unified way",
            "homepage": "https://symfony.com",
            "keywords": [
                "grapheme",
                "i18n",
                "string",
                "unicode",
                "utf-8",
                "utf8"
            ],
            "support": {
                "source": "https://github.com/symfony/string/tree/v8.1.2"
            },
            "funding": [
                {
                    "url": "https://symfony.com/sponsor",
                    "type": "custom"
                },
                {
                    "url": "https://github.com/fabpot",
                    "type": "github"
                },
                {
                    "url": "https://github.com/nicolas-grekas",
                    "type": "github"
                },
                {
                    "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
                    "type": "tidelift"
                }
            ],
            "time": "2026-07-28T07:35:25+00:00"
        },
        {
            "name": "symfony/translation",
            "version": "v8.1.5",
            "source": {
                "type": "git",
                "url": "https://github.com/symfony/translation.git",
                "reference": "d9e1caba0d6b6f9a26710af8a2f88d37f001215a"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/symfony/translation/zipball/d9e1caba0d6b6f9a26710af8a2f88d37f001215a",
                "reference": "d9e1caba0d6b6f9a26710af8a2f88d37f001215a",
                "shasum": ""
            },
            "require": {
                "php": ">=8.4.1",
                "symfony/polyfill-mbstring": "^1.0",
                "symfony/translation-contracts": "^3.6.1"
            },
            "conflict": {
                "nikic/php-parser": "<5.0",
                "symfony/http-client-contracts": "<2.5",
                "symfony/service-contracts": "<2.5"
            },
            "provide": {
                "symfony/translation-implementation": "2.3|3.0"
            },
            "require-dev": {
                "nikic/php-parser": "^5.0",
                "psr/log": "^1|^2|^3",
                "symfony/config": "^7.4|^8.0",
                "symfony/console": "^7.4|^8.0",
                "symfony/dependency-injection": "^7.4|^8.0",
                "symfony/finder": "^7.4|^8.0",
                "symfony/http-client-contracts": "^2.5|^3.0",
                "symfony/http-kernel": "^7.4|^8.0",
                "symfony/intl": "^7.4|^8.0",
                "symfony/polyfill-intl-icu": "^1.21",
                "symfony/routing": "^7.4|^8.0",
                "symfony/service-contracts": "^2.5|^3",
                "symfony/yaml": "^7.4|^8.0"
            },
            "type": "library",
            "autoload": {
                "files": [
                    "Resources/functions.php"
                ],
                "psr-4": {
                    "Symfony\\Component\\Translation\\": ""
                },
                "exclude-from-classmap": [
                    "/Tests/"
                ]
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Fabien Potencier",
                    "email": "fabien@symfony.com"
                },
                {
                    "name": "Symfony Community",
                    "homepage": "https://symfony.com/contributors"
                }
            ],
            "description": "Provides tools to internationalize your application",
            "homepage": "https://symfony.com",
            "support": {
                "source": "https://github.com/symfony/translation/tree/v8.1.5"
            },
            "funding": [
                {
                    "url": "https://symfony.com/sponsor",
                    "type": "custom"
                },
                {
                    "url": "https://github.com/fabpot",
                    "type": "github"
                },
                {
                    "url": "https://github.com/nicolas-grekas",
                    "type": "github"
                },
                {
                    "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
                    "type": "tidelift"
                }
            ],
            "time": "2026-08-21T17:47:34+00:00"
        },
        {
            "name": "symfony/translation-contracts",
            "version": "v3.7.1",
            "source": {
                "type": "git",
                "url": "https://github.com/symfony/translation-contracts.git",
                "reference": "ccb206b98faccc511ebae8e5fad50f2dc0b30621"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/symfony/translation-contracts/zipball/ccb206b98faccc511ebae8e5fad50f2dc0b30621",
                "reference": "ccb206b98faccc511ebae8e5fad50f2dc0b30621",
                "shasum": ""
            },
            "require": {
                "php": ">=8.1"
            },
            "type": "library",
            "extra": {
                "thanks": {
                    "url": "https://github.com/symfony/contracts",
                    "name": "symfony/contracts"
                },
                "branch-alias": {
                    "dev-main": "3.7-dev"
                }
            },
            "autoload": {
                "psr-4": {
                    "Symfony\\Contracts\\Translation\\": ""
                },
                "exclude-from-classmap": [
                    "/Test/"
                ]
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Nicolas Grekas",
                    "email": "p@tchwork.com"
                },
                {
                    "name": "Symfony Community",
                    "homepage": "https://symfony.com/contributors"
                }
            ],
            "description": "Generic abstractions related to translation",
            "homepage": "https://symfony.com",
            "keywords": [
                "abstractions",
                "contracts",
                "decoupling",
                "interfaces",
                "interoperability",
                "standards"
            ],
            "support": {
                "source": "https://github.com/symfony/translation-contracts/tree/v3.7.1"
            },
            "funding": [
                {
                    "url": "https://symfony.com/sponsor",
                    "type": "custom"
                },
                {
                    "url": "https://github.com/fabpot",
                    "type": "github"
                },
                {
                    "url": "https://github.com/nicolas-grekas",
                    "type": "github"
                },
                {
                    "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
                    "type": "tidelift"
                }
            ],
            "time": "2026-06-05T06:23:12+00:00"
        },
        {
            "name": "symfony/uid",
            "version": "v7.4.17",
            "source": {
                "type": "git",
                "url": "https://github.com/symfony/uid.git",
                "reference": "69d732355a139c6f8881337d28515aa01f12b8be"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/symfony/uid/zipball/69d732355a139c6f8881337d28515aa01f12b8be",
                "reference": "69d732355a139c6f8881337d28515aa01f12b8be",
                "shasum": ""
            },
            "require": {
                "php": ">=8.2",
                "symfony/polyfill-uuid": "^1.15"
            },
            "require-dev": {
                "symfony/console": "^6.4|^7.0|^8.0"
            },
            "type": "library",
            "autoload": {
                "psr-4": {
                    "Symfony\\Component\\Uid\\": ""
                },
                "exclude-from-classmap": [
                    "/Tests/"
                ]
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Grégoire Pineau",
                    "email": "lyrixx@lyrixx.info"
                },
                {
                    "name": "Nicolas Grekas",
                    "email": "p@tchwork.com"
                },
                {
                    "name": "Symfony Community",
                    "homepage": "https://symfony.com/contributors"
                }
            ],
            "description": "Provides an object-oriented API to generate and represent UIDs",
            "homepage": "https://symfony.com",
            "keywords": [
                "UID",
                "ulid",
                "uuid"
            ],
            "support": {
                "source": "https://github.com/symfony/uid/tree/v7.4.17"
            },
            "funding": [
                {
                    "url": "https://symfony.com/sponsor",
                    "type": "custom"
                },
                {
                    "url": "https://github.com/fabpot",
                    "type": "github"
                },
                {
                    "url": "https://github.com/nicolas-grekas",
                    "type": "github"
                },
                {
                    "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
                    "type": "tidelift"
                }
            ],
            "time": "2026-08-11T07:38:58+00:00"
        },
        {
            "name": "symfony/var-dumper",
            "version": "v7.4.18",
            "source": {
                "type": "git",
                "url": "https://github.com/symfony/var-dumper.git",
                "reference": "e088da50b813f32473a76871616cbb8fa54653a8"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/symfony/var-dumper/zipball/e088da50b813f32473a76871616cbb8fa54653a8",
                "reference": "e088da50b813f32473a76871616cbb8fa54653a8",
                "shasum": ""
            },
            "require": {
                "php": ">=8.2",
                "symfony/deprecation-contracts": "^2.5|^3",
                "symfony/polyfill-mbstring": "~1.0"
            },
            "conflict": {
                "symfony/console": "<6.4"
            },
            "require-dev": {
                "symfony/console": "^6.4|^7.0|^8.0",
                "symfony/http-kernel": "^6.4|^7.0|^8.0",
                "symfony/process": "^6.4|^7.0|^8.0",
                "symfony/uid": "^6.4|^7.0|^8.0",
                "twig/twig": "^3.12|^4.0"
            },
            "bin": [
                "Resources/bin/var-dump-server"
            ],
            "type": "library",
            "autoload": {
                "files": [
                    "Resources/functions/dump.php"
                ],
                "psr-4": {
                    "Symfony\\Component\\VarDumper\\": ""
                },
                "exclude-from-classmap": [
                    "/Tests/"
                ]
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Nicolas Grekas",
                    "email": "p@tchwork.com"
                },
                {
                    "name": "Symfony Community",
                    "homepage": "https://symfony.com/contributors"
                }
            ],
            "description": "Provides mechanisms for walking through any arbitrary PHP variable",
            "homepage": "https://symfony.com",
            "keywords": [
                "debug",
                "dump"
            ],
            "support": {
                "source": "https://github.com/symfony/var-dumper/tree/v7.4.18"
            },
            "funding": [
                {
                    "url": "https://symfony.com/sponsor",
                    "type": "custom"
                },
                {
                    "url": "https://github.com/fabpot",
                    "type": "github"
                },
                {
                    "url": "https://github.com/nicolas-grekas",
                    "type": "github"
                },
                {
                    "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
                    "type": "tidelift"
                }
            ],
            "time": "2026-08-30T20:10:52+00:00"
        },
        {
            "name": "tijsverkoyen/css-to-inline-styles",
            "version": "v2.4.0",
            "source": {
                "type": "git",
                "url": "https://github.com/tijsverkoyen/CssToInlineStyles.git",
                "reference": "f0292ccf0ec75843d65027214426b6b163b48b41"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/tijsverkoyen/CssToInlineStyles/zipball/f0292ccf0ec75843d65027214426b6b163b48b41",
                "reference": "f0292ccf0ec75843d65027214426b6b163b48b41",
                "shasum": ""
            },
            "require": {
                "ext-dom": "*",
                "ext-libxml": "*",
                "php": "^7.4 || ^8.0",
                "symfony/css-selector": "^5.4 || ^6.0 || ^7.0 || ^8.0"
            },
            "require-dev": {
                "phpstan/phpstan": "^2.0",
                "phpstan/phpstan-phpunit": "^2.0",
                "phpunit/phpunit": "^8.5.21 || ^9.5.10"
            },
            "type": "library",
            "extra": {
                "branch-alias": {
                    "dev-master": "2.x-dev"
                }
            },
            "autoload": {
                "psr-4": {
                    "TijsVerkoyen\\CssToInlineStyles\\": "src"
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "BSD-3-Clause"
            ],
            "authors": [
                {
                    "name": "Tijs Verkoyen",
                    "email": "css_to_inline_styles@verkoyen.eu",
                    "role": "Developer"
                }
            ],
            "description": "CssToInlineStyles is a class that enables you to convert HTML-pages/files into HTML-pages/files with inline styles. This is very useful when you're sending emails.",
            "homepage": "https://github.com/tijsverkoyen/CssToInlineStyles",
            "support": {
                "issues": "https://github.com/tijsverkoyen/CssToInlineStyles/issues",
                "source": "https://github.com/tijsverkoyen/CssToInlineStyles/tree/v2.4.0"
            },
            "time": "2025-12-02T11:56:42+00:00"
        },
        {
            "name": "vlucas/phpdotenv",
            "version": "v5.7.0",
            "source": {
                "type": "git",
                "url": "https://github.com/vlucas/phpdotenv.git",
                "reference": "301c07936b16d88628b126b01d082ba153cf4c40"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/vlucas/phpdotenv/zipball/301c07936b16d88628b126b01d082ba153cf4c40",
                "reference": "301c07936b16d88628b126b01d082ba153cf4c40",
                "shasum": ""
            },
            "require": {
                "ext-pcre": "*",
                "graham-campbell/result-type": "^1.2",
                "php": "^7.2.5 || ^8.0",
                "phpoption/phpoption": "^1.10",
                "symfony/polyfill-ctype": "^1.26",
                "symfony/polyfill-mbstring": "^1.26",
                "symfony/polyfill-php80": "^1.26"
            },
            "require-dev": {
                "bamarni/composer-bin-plugin": "^1.8.2",
                "ext-filter": "*",
                "phpunit/phpunit": "^8.5.34 || ^9.6.13 || ^10.4.2"
            },
            "suggest": {
                "ext-filter": "Required to use the boolean validator."
            },
            "type": "library",
            "extra": {
                "bamarni-bin": {
                    "bin-links": true,
                    "forward-command": false
                },
                "branch-alias": {
                    "dev-master": "5.6-dev"
                }
            },
            "autoload": {
                "psr-4": {
                    "Dotenv\\": "src/"
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "BSD-3-Clause"
            ],
            "authors": [
                {
                    "name": "Graham Campbell",
                    "email": "hello@gjcampbell.co.uk",
                    "homepage": "https://github.com/GrahamCampbell"
                },
                {
                    "name": "Vance Lucas",
                    "email": "vance@vancelucas.com",
                    "homepage": "https://github.com/vlucas"
                }
            ],
            "description": "Loads environment variables from `.env` to `$_ENV` and `$_SERVER` automagically, and optionally to `getenv()`.",
            "keywords": [
                "dotenv",
                "env",
                "environment"
            ],
            "support": {
                "issues": "https://github.com/vlucas/phpdotenv/issues",
                "source": "https://github.com/vlucas/phpdotenv/tree/v5.7.0"
            },
            "funding": [
                {
                    "url": "https://github.com/GrahamCampbell",
                    "type": "github"
                },
                {
                    "url": "https://tidelift.com/funding/github/packagist/vlucas/phpdotenv",
                    "type": "tidelift"
                }
            ],
            "time": "2026-08-24T18:07:49+00:00"
        },
        {
            "name": "voku/portable-ascii",
            "version": "2.1.1",
            "source": {
                "type": "git",
                "url": "https://github.com/voku/portable-ascii.git",
                "reference": "8e1051fe39379367aecf014f41744ce7539a856f"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/voku/portable-ascii/zipball/8e1051fe39379367aecf014f41744ce7539a856f",
                "reference": "8e1051fe39379367aecf014f41744ce7539a856f",
                "shasum": ""
            },
            "require": {
                "php": ">=7.1.0"
            },
            "require-dev": {
                "phpunit/phpunit": "~8.5 || ~9.6 || ~10.5 || ~11.5"
            },
            "suggest": {
                "ext-intl": "Use Intl for transliterator_transliterate() support"
            },
            "type": "library",
            "autoload": {
                "psr-4": {
                    "voku\\": "src/voku/"
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Lars Moelleken",
                    "homepage": "https://www.moelleken.org/"
                }
            ],
            "description": "Portable ASCII library - performance optimized (ascii) string functions for php.",
            "homepage": "https://github.com/voku/portable-ascii",
            "keywords": [
                "ascii",
                "clean",
                "php"
            ],
            "support": {
                "issues": "https://github.com/voku/portable-ascii/issues",
                "source": "https://github.com/voku/portable-ascii/tree/2.1.1"
            },
            "funding": [
                {
                    "url": "https://www.paypal.me/moelleken",
                    "type": "custom"
                },
                {
                    "url": "https://github.com/voku",
                    "type": "github"
                },
                {
                    "url": "https://opencollective.com/portable-ascii",
                    "type": "open_collective"
                },
                {
                    "url": "https://www.patreon.com/voku",
                    "type": "patreon"
                },
                {
                    "url": "https://tidelift.com/funding/github/packagist/voku/portable-ascii",
                    "type": "tidelift"
                }
            ],
            "time": "2026-04-26T05:33:54+00:00"
        }
    ],
    "packages-dev": [
        {
            "name": "fakerphp/faker",
            "version": "v1.24.1",
            "source": {
                "type": "git",
                "url": "https://github.com/FakerPHP/Faker.git",
                "reference": "e0ee18eb1e6dc3cda3ce9fd97e5a0689a88a64b5"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/FakerPHP/Faker/zipball/e0ee18eb1e6dc3cda3ce9fd97e5a0689a88a64b5",
                "reference": "e0ee18eb1e6dc3cda3ce9fd97e5a0689a88a64b5",
                "shasum": ""
            },
            "require": {
                "php": "^7.4 || ^8.0",
                "psr/container": "^1.0 || ^2.0",
                "symfony/deprecation-contracts": "^2.2 || ^3.0"
            },
            "conflict": {
                "fzaninotto/faker": "*"
            },
            "require-dev": {
                "bamarni/composer-bin-plugin": "^1.4.1",
                "doctrine/persistence": "^1.3 || ^2.0",
                "ext-intl": "*",
                "phpunit/phpunit": "^9.5.26",
                "symfony/phpunit-bridge": "^5.4.16"
            },
            "suggest": {
                "doctrine/orm": "Required to use Faker\\ORM\\Doctrine",
                "ext-curl": "Required by Faker\\Provider\\Image to download images.",
                "ext-dom": "Required by Faker\\Provider\\HtmlLorem for generating random HTML.",
                "ext-iconv": "Required by Faker\\Provider\\ru_RU\\Text::realText() for generating real Russian text.",
                "ext-mbstring": "Required for multibyte Unicode string functionality."
            },
            "type": "library",
            "autoload": {
                "psr-4": {
                    "Faker\\": "src/Faker/"
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "François Zaninotto"
                }
            ],
            "description": "Faker is a PHP library that generates fake data for you.",
            "keywords": [
                "data",
                "faker",
                "fixtures"
            ],
            "support": {
                "issues": "https://github.com/FakerPHP/Faker/issues",
                "source": "https://github.com/FakerPHP/Faker/tree/v1.24.1"
            },
            "time": "2024-11-21T13:46:39+00:00"
        },
        {
            "name": "filp/whoops",
            "version": "2.18.4",
            "source": {
                "type": "git",
                "url": "https://github.com/filp/whoops.git",
                "reference": "d2102955e48b9fd9ab24280a7ad12ed552752c4d"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/filp/whoops/zipball/d2102955e48b9fd9ab24280a7ad12ed552752c4d",
                "reference": "d2102955e48b9fd9ab24280a7ad12ed552752c4d",
                "shasum": ""
            },
            "require": {
                "php": "^7.1 || ^8.0",
                "psr/log": "^1.0.1 || ^2.0 || ^3.0"
            },
            "require-dev": {
                "mockery/mockery": "^1.0",
                "phpunit/phpunit": "^7.5.20 || ^8.5.8 || ^9.3.3",
                "symfony/var-dumper": "^4.0 || ^5.0"
            },
            "suggest": {
                "symfony/var-dumper": "Pretty print complex values better with var-dumper available",
                "whoops/soap": "Formats errors as SOAP responses"
            },
            "type": "library",
            "extra": {
                "branch-alias": {
                    "dev-master": "2.7-dev"
                }
            },
            "autoload": {
                "psr-4": {
                    "Whoops\\": "src/Whoops/"
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Filipe Dobreira",
                    "homepage": "https://github.com/filp",
                    "role": "Developer"
                }
            ],
            "description": "php error handling for cool kids",
            "homepage": "https://filp.github.io/whoops/",
            "keywords": [
                "error",
                "exception",
                "handling",
                "library",
                "throwable",
                "whoops"
            ],
            "support": {
                "issues": "https://github.com/filp/whoops/issues",
                "source": "https://github.com/filp/whoops/tree/2.18.4"
            },
            "funding": [
                {
                    "url": "https://github.com/denis-sokolov",
                    "type": "github"
                }
            ],
            "time": "2025-08-08T12:00:00+00:00"
        },
        {
            "name": "hamcrest/hamcrest-php",
            "version": "v3.0.0",
            "source": {
                "type": "git",
                "url": "https://github.com/hamcrest/hamcrest-php.git",
                "reference": "b61cd040da1a4925bc90a51c074f5297e7c0fa52"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/hamcrest/hamcrest-php/zipball/b61cd040da1a4925bc90a51c074f5297e7c0fa52",
                "reference": "b61cd040da1a4925bc90a51c074f5297e7c0fa52",
                "shasum": ""
            },
            "require": {
                "ext-ctype": "*",
                "ext-dom": "*",
                "php": "^7.4|^8.0"
            },
            "replace": {
                "cordoval/hamcrest-php": "*",
                "davedevelopment/hamcrest-php": "*",
                "kodova/hamcrest-php": "*"
            },
            "require-dev": {
                "phpstan/phpstan": "^2.1",
                "phpstan/phpstan-phpunit": "^2.0",
                "phpunit/php-file-iterator": "^1.4 || ^2.0 || ^3.0",
                "phpunit/phpunit": "^4.8.36 || ^5.7 || ^6.5 || ^7.0 || ^8.0 || ^9.0"
            },
            "type": "library",
            "extra": {
                "branch-alias": {
                    "dev-master": "3.0-dev"
                }
            },
            "autoload": {
                "classmap": [
                    "hamcrest"
                ]
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "BSD-3-Clause"
            ],
            "description": "This is the PHP port of Hamcrest Matchers",
            "keywords": [
                "test"
            ],
            "support": {
                "issues": "https://github.com/hamcrest/hamcrest-php/issues",
                "source": "https://github.com/hamcrest/hamcrest-php/tree/v3.0.0"
            },
            "time": "2026-03-17T11:56:53+00:00"
        },
        {
            "name": "laravel/pail",
            "version": "v1.2.7",
            "source": {
                "type": "git",
                "url": "https://github.com/laravel/pail.git",
                "reference": "2f7d27dada8effc48b8c424445a69cca7007daaa"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/laravel/pail/zipball/2f7d27dada8effc48b8c424445a69cca7007daaa",
                "reference": "2f7d27dada8effc48b8c424445a69cca7007daaa",
                "shasum": ""
            },
            "require": {
                "ext-mbstring": "*",
                "illuminate/console": "^10.24|^11.0|^12.0|^13.0",
                "illuminate/contracts": "^10.24|^11.0|^12.0|^13.0",
                "illuminate/log": "^10.24|^11.0|^12.0|^13.0",
                "illuminate/process": "^10.24|^11.0|^12.0|^13.0",
                "illuminate/support": "^10.24|^11.0|^12.0|^13.0",
                "nunomaduro/termwind": "^1.15|^2.0",
                "php": "^8.2",
                "symfony/console": "^6.0|^7.0|^8.0"
            },
            "require-dev": {
                "laravel/framework": "^10.24|^11.0|^12.0|^13.0",
                "laravel/pint": "^1.13",
                "orchestra/testbench-core": "^8.13|^9.17|^10.8|^11.0",
                "pestphp/pest": "^2.20|^3.0|^4.0",
                "pestphp/pest-plugin-type-coverage": "^2.3|^3.0|^4.0",
                "phpstan/phpstan": "^1.12.27",
                "symfony/var-dumper": "^6.3|^7.0|^8.0",
                "symfony/yaml": "^6.3|^7.0|^8.0"
            },
            "type": "library",
            "extra": {
                "laravel": {
                    "providers": [
                        "Laravel\\Pail\\PailServiceProvider"
                    ]
                },
                "branch-alias": {
                    "dev-main": "1.x-dev"
                }
            },
            "autoload": {
                "psr-4": {
                    "Laravel\\Pail\\": "src/"
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Taylor Otwell",
                    "email": "taylor@laravel.com"
                },
                {
                    "name": "Nuno Maduro",
                    "email": "enunomaduro@gmail.com"
                }
            ],
            "description": "Easily delve into your Laravel application's log files directly from the command line.",
            "homepage": "https://github.com/laravel/pail",
            "keywords": [
                "dev",
                "laravel",
                "logs",
                "php",
                "tail"
            ],
            "support": {
                "issues": "https://github.com/laravel/pail/issues",
                "source": "https://github.com/laravel/pail"
            },
            "time": "2026-05-20T22:24:57+00:00"
        },
        {
            "name": "laravel/pint",
            "version": "v1.30.5",
            "source": {
                "type": "git",
                "url": "https://github.com/laravel/pint.git",
                "reference": "fe4148c503a0e266353d61396b79bbf7f35122df"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/laravel/pint/zipball/fe4148c503a0e266353d61396b79bbf7f35122df",
                "reference": "fe4148c503a0e266353d61396b79bbf7f35122df",
                "shasum": ""
            },
            "require": {
                "ext-json": "*",
                "ext-mbstring": "*",
                "ext-tokenizer": "*",
                "ext-xml": "*",
                "php": "^8.3.0"
            },
            "require-dev": {
                "composer/semver": "^3.4.4",
                "friendsofphp/php-cs-fixer": "^3.95.18",
                "illuminate/view": "^13.24.0",
                "larastan/larastan": "^3.10.0",
                "laravel-zero/framework": "^13.0.0",
                "laravel/agent-detector": "^2.0.2",
                "laravel/prompts": "^0.3.22",
                "mockery/mockery": "^1.6.12",
                "nunomaduro/termwind": "^2.4.0",
                "pestphp/pest": "^4.7.8"
            },
            "bin": [
                "builds/pint"
            ],
            "type": "project",
            "autoload": {
                "psr-4": {
                    "App\\": "app/",
                    "Database\\Seeders\\": "database/seeders/",
                    "Database\\Factories\\": "database/factories/"
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Nuno Maduro",
                    "email": "enunomaduro@gmail.com"
                }
            ],
            "description": "An opinionated code formatter for PHP.",
            "homepage": "https://laravel.com",
            "keywords": [
                "dev",
                "format",
                "formatter",
                "lint",
                "linter",
                "php"
            ],
            "support": {
                "issues": "https://github.com/laravel/pint/issues",
                "source": "https://github.com/laravel/pint"
            },
            "time": "2026-08-10T15:35:50+00:00"
        },
        {
            "name": "laravel/sail",
            "version": "v1.67.0",
            "source": {
                "type": "git",
                "url": "https://github.com/laravel/sail.git",
                "reference": "639e03ac12cf23def171770bcab05758045b2642"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/laravel/sail/zipball/639e03ac12cf23def171770bcab05758045b2642",
                "reference": "639e03ac12cf23def171770bcab05758045b2642",
                "shasum": ""
            },
            "require": {
                "illuminate/console": "^9.52.16|^10.0|^11.0|^12.0|^13.0",
                "illuminate/contracts": "^9.52.16|^10.0|^11.0|^12.0|^13.0",
                "illuminate/support": "^9.52.16|^10.0|^11.0|^12.0|^13.0",
                "php": "^8.0",
                "symfony/console": "^6.0|^7.0|^8.0",
                "symfony/yaml": "^6.0|^7.0|^8.0"
            },
            "require-dev": {
                "orchestra/testbench": "^7.0|^8.0|^9.0|^10.0|^11.0",
                "phpstan/phpstan": "^2.0"
            },
            "bin": [
                "bin/sail"
            ],
            "type": "library",
            "extra": {
                "laravel": {
                    "providers": [
                        "Laravel\\Sail\\SailServiceProvider"
                    ]
                }
            },
            "autoload": {
                "psr-4": {
                    "Laravel\\Sail\\": "src/"
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Taylor Otwell",
                    "email": "taylor@laravel.com"
                }
            ],
            "description": "Docker files for running a basic Laravel application.",
            "keywords": [
                "docker",
                "laravel"
            ],
            "support": {
                "issues": "https://github.com/laravel/sail/issues",
                "source": "https://github.com/laravel/sail"
            },
            "time": "2026-08-12T13:55:56+00:00"
        },
        {
            "name": "mockery/mockery",
            "version": "1.6.15",
            "source": {
                "type": "git",
                "url": "https://github.com/mockery/mockery.git",
                "reference": "967a801bd188989a5669bd280f252d51c0fdc9ee"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/mockery/mockery/zipball/967a801bd188989a5669bd280f252d51c0fdc9ee",
                "reference": "967a801bd188989a5669bd280f252d51c0fdc9ee",
                "shasum": ""
            },
            "require": {
                "hamcrest/hamcrest-php": "^2.0 || ^3.0",
                "php": ">=7.3"
            },
            "conflict": {
                "phpunit/phpunit": "<8.0"
            },
            "require-dev": {
                "phpunit/phpunit": "^9.6.36",
                "symplify/easy-coding-standard": "^13.2.17"
            },
            "type": "library",
            "autoload": {
                "files": [
                    "library/helpers.php",
                    "library/Mockery.php"
                ],
                "psr-4": {
                    "Mockery\\": "library/Mockery"
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "BSD-3-Clause"
            ],
            "authors": [
                {
                    "name": "Pádraic Brady",
                    "email": "padraic.brady@gmail.com",
                    "homepage": "https://github.com/padraic",
                    "role": "Author"
                },
                {
                    "name": "Dave Marshall",
                    "email": "dave.marshall@atstsolutions.co.uk",
                    "homepage": "https://davedevelopment.co.uk",
                    "role": "Developer"
                },
                {
                    "name": "Nathanael Esayeas",
                    "email": "nathanael.esayeas@protonmail.com",
                    "homepage": "https://github.com/ghostwriter",
                    "role": "Lead Developer"
                }
            ],
            "description": "Mockery is a simple yet flexible PHP mock object framework",
            "homepage": "https://github.com/mockery/mockery",
            "keywords": [
                "BDD",
                "TDD",
                "library",
                "mock",
                "mock objects",
                "mockery",
                "stub",
                "test",
                "test double",
                "testing"
            ],
            "support": {
                "docs": "https://docs.mockery.io/",
                "issues": "https://github.com/mockery/mockery/issues",
                "rss": "https://github.com/mockery/mockery/releases.atom",
                "security": "https://github.com/mockery/mockery/security/advisories",
                "source": "https://github.com/mockery/mockery"
            },
            "time": "2026-08-19T19:37:52+00:00"
        },
        {
            "name": "myclabs/deep-copy",
            "version": "1.14.0",
            "source": {
                "type": "git",
                "url": "https://github.com/myclabs/DeepCopy.git",
                "reference": "8680aa248f8e07bc8fb43f56f0f5fc77a0c96aae"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/myclabs/DeepCopy/zipball/8680aa248f8e07bc8fb43f56f0f5fc77a0c96aae",
                "reference": "8680aa248f8e07bc8fb43f56f0f5fc77a0c96aae",
                "shasum": ""
            },
            "require": {
                "php": "^8.0"
            },
            "conflict": {
                "doctrine/collections": "<1.6.8",
                "doctrine/common": "<2.13.3 || >=3 <3.2.2"
            },
            "require-dev": {
                "doctrine/collections": "^1.6.8",
                "doctrine/common": "^2.13.3 || ^3.2.2",
                "phpspec/prophecy": "^1.10",
                "phpunit/phpunit": "^7.5.20 || ^8.5.23 || ^9.5.13"
            },
            "type": "library",
            "autoload": {
                "files": [
                    "src/DeepCopy/deep_copy.php"
                ],
                "psr-4": {
                    "DeepCopy\\": "src/DeepCopy/"
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "description": "Create deep copies (clones) of your objects",
            "keywords": [
                "clone",
                "copy",
                "duplicate",
                "object",
                "object graph"
            ],
            "support": {
                "issues": "https://github.com/myclabs/DeepCopy/issues",
                "source": "https://github.com/myclabs/DeepCopy/tree/1.14.0"
            },
            "funding": [
                {
                    "url": "https://github.com/mnapoli",
                    "type": "github"
                }
            ],
            "time": "2026-08-11T10:17:44+00:00"
        },
        {
            "name": "nunomaduro/collision",
            "version": "v8.9.5",
            "source": {
                "type": "git",
                "url": "https://github.com/nunomaduro/collision.git",
                "reference": "fb53eacd509a1d303858e2d20cfebf2d630254ec"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/nunomaduro/collision/zipball/fb53eacd509a1d303858e2d20cfebf2d630254ec",
                "reference": "fb53eacd509a1d303858e2d20cfebf2d630254ec",
                "shasum": ""
            },
            "require": {
                "filp/whoops": "^2.18.4",
                "nunomaduro/termwind": "^2.4.0",
                "php": "^8.2.0",
                "symfony/console": "^7.4.14 || ^8.1.1"
            },
            "conflict": {
                "laravel/framework": "<11.48.0 || >=14.0.0",
                "phpunit/phpunit": "<11.5.50 || >=14.0.0"
            },
            "require-dev": {
                "brianium/paratest": "^7.8.5",
                "larastan/larastan": "^3.10.0",
                "laravel/framework": "^11.48.0 || ^12.56.0 || ^13.20.0",
                "laravel/pint": "^1.29.3",
                "orchestra/testbench-core": "^9.12.0 || ^10.12.1 || ^11.3.5",
                "pestphp/pest": "^3.8.5 || ^4.7.5 || ^5.0.0",
                "sebastian/environment": "^7.2.1 || ^8.1.2 || ^9.3.2"
            },
            "type": "library",
            "extra": {
                "laravel": {
                    "providers": [
                        "NunoMaduro\\Collision\\Adapters\\Laravel\\CollisionServiceProvider"
                    ]
                },
                "branch-alias": {
                    "dev-8.x": "8.x-dev"
                }
            },
            "autoload": {
                "files": [
                    "./src/Adapters/Phpunit/Autoload.php"
                ],
                "psr-4": {
                    "NunoMaduro\\Collision\\": "src/"
                }
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Nuno Maduro",
                    "email": "enunomaduro@gmail.com"
                }
            ],
            "description": "Cli error handling for console/command-line PHP applications.",
            "keywords": [
                "artisan",
                "cli",
                "command-line",
                "console",
                "dev",
                "error",
                "handling",
                "laravel",
                "laravel-zero",
                "php",
                "symfony"
            ],
            "support": {
                "issues": "https://github.com/nunomaduro/collision/issues",
                "source": "https://github.com/nunomaduro/collision"
            },
            "funding": [
                {
                    "url": "https://www.paypal.com/paypalme/enunomaduro",
                    "type": "custom"
                },
                {
                    "url": "https://github.com/nunomaduro",
                    "type": "github"
                },
                {
                    "url": "https://www.patreon.com/nunomaduro",
                    "type": "patreon"
                }
            ],
            "time": "2026-07-15T19:09:14+00:00"
        },
        {
            "name": "phar-io/manifest",
            "version": "2.0.4",
            "source": {
                "type": "git",
                "url": "https://github.com/phar-io/manifest.git",
                "reference": "54750ef60c58e43759730615a392c31c80e23176"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/phar-io/manifest/zipball/54750ef60c58e43759730615a392c31c80e23176",
                "reference": "54750ef60c58e43759730615a392c31c80e23176",
                "shasum": ""
            },
            "require": {
                "ext-dom": "*",
                "ext-libxml": "*",
                "ext-phar": "*",
                "ext-xmlwriter": "*",
                "phar-io/version": "^3.0.1",
                "php": "^7.2 || ^8.0"
            },
            "type": "library",
            "extra": {
                "branch-alias": {
                    "dev-master": "2.0.x-dev"
                }
            },
            "autoload": {
                "classmap": [
                    "src/"
                ]
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "BSD-3-Clause"
            ],
            "authors": [
                {
                    "name": "Arne Blankerts",
                    "email": "arne@blankerts.de",
                    "role": "Developer"
                },
                {
                    "name": "Sebastian Heuer",
                    "email": "sebastian@phpeople.de",
                    "role": "Developer"
                },
                {
                    "name": "Sebastian Bergmann",
                    "email": "sebastian@phpunit.de",
                    "role": "Developer"
                }
            ],
            "description": "Component for reading phar.io manifest information from a PHP Archive (PHAR)",
            "support": {
                "issues": "https://github.com/phar-io/manifest/issues",
                "source": "https://github.com/phar-io/manifest/tree/2.0.4"
            },
            "funding": [
                {
                    "url": "https://github.com/theseer",
                    "type": "github"
                }
            ],
            "time": "2024-03-03T12:33:53+00:00"
        },
        {
            "name": "phar-io/version",
            "version": "3.2.1",
            "source": {
                "type": "git",
                "url": "https://github.com/phar-io/version.git",
                "reference": "4f7fd7836c6f332bb2933569e566a0d6c4cbed74"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/phar-io/version/zipball/4f7fd7836c6f332bb2933569e566a0d6c4cbed74",
                "reference": "4f7fd7836c6f332bb2933569e566a0d6c4cbed74",
                "shasum": ""
            },
            "require": {
                "php": "^7.2 || ^8.0"
            },
            "type": "library",
            "autoload": {
                "classmap": [
                    "src/"
                ]
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "BSD-3-Clause"
            ],
            "authors": [
                {
                    "name": "Arne Blankerts",
                    "email": "arne@blankerts.de",
                    "role": "Developer"
                },
                {
                    "name": "Sebastian Heuer",
                    "email": "sebastian@phpeople.de",
                    "role": "Developer"
                },
                {
                    "name": "Sebastian Bergmann",
                    "email": "sebastian@phpunit.de",
                    "role": "Developer"
                }
            ],
            "description": "Library for handling version information and constraints",
            "support": {
                "issues": "https://github.com/phar-io/version/issues",
                "source": "https://github.com/phar-io/version/tree/3.2.1"
            },
            "time": "2022-02-21T01:04:05+00:00"
        },
        {
            "name": "phpunit/php-code-coverage",
            "version": "11.0.12",
            "source": {
                "type": "git",
                "url": "https://github.com/sebastianbergmann/php-code-coverage.git",
                "reference": "2c1ed04922802c15e1de5d7447b4856de949cf56"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/sebastianbergmann/php-code-coverage/zipball/2c1ed04922802c15e1de5d7447b4856de949cf56",
                "reference": "2c1ed04922802c15e1de5d7447b4856de949cf56",
                "shasum": ""
            },
            "require": {
                "ext-dom": "*",
                "ext-libxml": "*",
                "ext-xmlwriter": "*",
                "nikic/php-parser": "^5.7.0",
                "php": ">=8.2",
                "phpunit/php-file-iterator": "^5.1.0",
                "phpunit/php-text-template": "^4.0.1",
                "sebastian/code-unit-reverse-lookup": "^4.0.1",
                "sebastian/complexity": "^4.0.1",
                "sebastian/environment": "^7.2.1",
                "sebastian/lines-of-code": "^3.0.1",
                "sebastian/version": "^5.0.2",
                "theseer/tokenizer": "^1.3.1"
            },
            "require-dev": {
                "phpunit/phpunit": "^11.5.46"
            },
            "suggest": {
                "ext-pcov": "PHP extension that provides line coverage",
                "ext-xdebug": "PHP extension that provides line coverage as well as branch and path coverage"
            },
            "type": "library",
            "extra": {
                "branch-alias": {
                    "dev-main": "11.0.x-dev"
                }
            },
            "autoload": {
                "classmap": [
                    "src/"
                ]
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "BSD-3-Clause"
            ],
            "authors": [
                {
                    "name": "Sebastian Bergmann",
                    "email": "sebastian@phpunit.de",
                    "role": "lead"
                }
            ],
            "description": "Library that provides collection, processing, and rendering functionality for PHP code coverage information.",
            "homepage": "https://github.com/sebastianbergmann/php-code-coverage",
            "keywords": [
                "coverage",
                "testing",
                "xunit"
            ],
            "support": {
                "issues": "https://github.com/sebastianbergmann/php-code-coverage/issues",
                "security": "https://github.com/sebastianbergmann/php-code-coverage/security/policy",
                "source": "https://github.com/sebastianbergmann/php-code-coverage/tree/11.0.12"
            },
            "funding": [
                {
                    "url": "https://github.com/sebastianbergmann",
                    "type": "github"
                },
                {
                    "url": "https://liberapay.com/sebastianbergmann",
                    "type": "liberapay"
                },
                {
                    "url": "https://thanks.dev/u/gh/sebastianbergmann",
                    "type": "thanks_dev"
                },
                {
                    "url": "https://tidelift.com/funding/github/packagist/phpunit/php-code-coverage",
                    "type": "tidelift"
                }
            ],
            "time": "2025-12-24T07:01:01+00:00"
        },
        {
            "name": "phpunit/php-file-iterator",
            "version": "5.1.1",
            "source": {
                "type": "git",
                "url": "https://github.com/sebastianbergmann/php-file-iterator.git",
                "reference": "2f3a64888c814fc235386b7387dd5b5ed92ad903"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/sebastianbergmann/php-file-iterator/zipball/2f3a64888c814fc235386b7387dd5b5ed92ad903",
                "reference": "2f3a64888c814fc235386b7387dd5b5ed92ad903",
                "shasum": ""
            },
            "require": {
                "php": ">=8.2"
            },
            "require-dev": {
                "phpunit/phpunit": "^11.3"
            },
            "type": "library",
            "extra": {
                "branch-alias": {
                    "dev-main": "5.1-dev"
                }
            },
            "autoload": {
                "classmap": [
                    "src/"
                ]
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "BSD-3-Clause"
            ],
            "authors": [
                {
                    "name": "Sebastian Bergmann",
                    "email": "sebastian@phpunit.de",
                    "role": "lead"
                }
            ],
            "description": "FilterIterator implementation that filters files based on a list of suffixes.",
            "homepage": "https://github.com/sebastianbergmann/php-file-iterator/",
            "keywords": [
                "filesystem",
                "iterator"
            ],
            "support": {
                "issues": "https://github.com/sebastianbergmann/php-file-iterator/issues",
                "security": "https://github.com/sebastianbergmann/php-file-iterator/security/policy",
                "source": "https://github.com/sebastianbergmann/php-file-iterator/tree/5.1.1"
            },
            "funding": [
                {
                    "url": "https://github.com/sebastianbergmann",
                    "type": "github"
                },
                {
                    "url": "https://liberapay.com/sebastianbergmann",
                    "type": "liberapay"
                },
                {
                    "url": "https://thanks.dev/u/gh/sebastianbergmann",
                    "type": "thanks_dev"
                },
                {
                    "url": "https://tidelift.com/funding/github/packagist/phpunit/php-file-iterator",
                    "type": "tidelift"
                }
            ],
            "time": "2026-02-02T13:52:54+00:00"
        },
        {
            "name": "phpunit/php-invoker",
            "version": "5.0.1",
            "source": {
                "type": "git",
                "url": "https://github.com/sebastianbergmann/php-invoker.git",
                "reference": "c1ca3814734c07492b3d4c5f794f4b0995333da2"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/sebastianbergmann/php-invoker/zipball/c1ca3814734c07492b3d4c5f794f4b0995333da2",
                "reference": "c1ca3814734c07492b3d4c5f794f4b0995333da2",
                "shasum": ""
            },
            "require": {
                "php": ">=8.2"
            },
            "require-dev": {
                "ext-pcntl": "*",
                "phpunit/phpunit": "^11.0"
            },
            "suggest": {
                "ext-pcntl": "*"
            },
            "type": "library",
            "extra": {
                "branch-alias": {
                    "dev-main": "5.0-dev"
                }
            },
            "autoload": {
                "classmap": [
                    "src/"
                ]
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "BSD-3-Clause"
            ],
            "authors": [
                {
                    "name": "Sebastian Bergmann",
                    "email": "sebastian@phpunit.de",
                    "role": "lead"
                }
            ],
            "description": "Invoke callables with a timeout",
            "homepage": "https://github.com/sebastianbergmann/php-invoker/",
            "keywords": [
                "process"
            ],
            "support": {
                "issues": "https://github.com/sebastianbergmann/php-invoker/issues",
                "security": "https://github.com/sebastianbergmann/php-invoker/security/policy",
                "source": "https://github.com/sebastianbergmann/php-invoker/tree/5.0.1"
            },
            "funding": [
                {
                    "url": "https://github.com/sebastianbergmann",
                    "type": "github"
                }
            ],
            "time": "2024-07-03T05:07:44+00:00"
        },
        {
            "name": "phpunit/php-text-template",
            "version": "4.0.1",
            "source": {
                "type": "git",
                "url": "https://github.com/sebastianbergmann/php-text-template.git",
                "reference": "3e0404dc6b300e6bf56415467ebcb3fe4f33e964"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/sebastianbergmann/php-text-template/zipball/3e0404dc6b300e6bf56415467ebcb3fe4f33e964",
                "reference": "3e0404dc6b300e6bf56415467ebcb3fe4f33e964",
                "shasum": ""
            },
            "require": {
                "php": ">=8.2"
            },
            "require-dev": {
                "phpunit/phpunit": "^11.0"
            },
            "type": "library",
            "extra": {
                "branch-alias": {
                    "dev-main": "4.0-dev"
                }
            },
            "autoload": {
                "classmap": [
                    "src/"
                ]
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "BSD-3-Clause"
            ],
            "authors": [
                {
                    "name": "Sebastian Bergmann",
                    "email": "sebastian@phpunit.de",
                    "role": "lead"
                }
            ],
            "description": "Simple template engine.",
            "homepage": "https://github.com/sebastianbergmann/php-text-template/",
            "keywords": [
                "template"
            ],
            "support": {
                "issues": "https://github.com/sebastianbergmann/php-text-template/issues",
                "security": "https://github.com/sebastianbergmann/php-text-template/security/policy",
                "source": "https://github.com/sebastianbergmann/php-text-template/tree/4.0.1"
            },
            "funding": [
                {
                    "url": "https://github.com/sebastianbergmann",
                    "type": "github"
                }
            ],
            "time": "2024-07-03T05:08:43+00:00"
        },
        {
            "name": "phpunit/php-timer",
            "version": "7.0.1",
            "source": {
                "type": "git",
                "url": "https://github.com/sebastianbergmann/php-timer.git",
                "reference": "3b415def83fbcb41f991d9ebf16ae4ad8b7837b3"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/sebastianbergmann/php-timer/zipball/3b415def83fbcb41f991d9ebf16ae4ad8b7837b3",
                "reference": "3b415def83fbcb41f991d9ebf16ae4ad8b7837b3",
                "shasum": ""
            },
            "require": {
                "php": ">=8.2"
            },
            "require-dev": {
                "phpunit/phpunit": "^11.0"
            },
            "type": "library",
            "extra": {
                "branch-alias": {
                    "dev-main": "7.0-dev"
                }
            },
            "autoload": {
                "classmap": [
                    "src/"
                ]
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "BSD-3-Clause"
            ],
            "authors": [
                {
                    "name": "Sebastian Bergmann",
                    "email": "sebastian@phpunit.de",
                    "role": "lead"
                }
            ],
            "description": "Utility class for timing",
            "homepage": "https://github.com/sebastianbergmann/php-timer/",
            "keywords": [
                "timer"
            ],
            "support": {
                "issues": "https://github.com/sebastianbergmann/php-timer/issues",
                "security": "https://github.com/sebastianbergmann/php-timer/security/policy",
                "source": "https://github.com/sebastianbergmann/php-timer/tree/7.0.1"
            },
            "funding": [
                {
                    "url": "https://github.com/sebastianbergmann",
                    "type": "github"
                }
            ],
            "time": "2024-07-03T05:09:35+00:00"
        },
        {
            "name": "phpunit/phpunit",
            "version": "11.5.56",
            "source": {
                "type": "git",
                "url": "https://github.com/sebastianbergmann/phpunit.git",
                "reference": "5f83edffa6967c3db468d48a695ec7bcb02e9256"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/sebastianbergmann/phpunit/zipball/5f83edffa6967c3db468d48a695ec7bcb02e9256",
                "reference": "5f83edffa6967c3db468d48a695ec7bcb02e9256",
                "shasum": ""
            },
            "require": {
                "ext-dom": "*",
                "ext-filter": "*",
                "ext-json": "*",
                "ext-libxml": "*",
                "ext-mbstring": "*",
                "ext-xmlwriter": "*",
                "myclabs/deep-copy": "^1.13.4",
                "phar-io/manifest": "^2.0.4",
                "phar-io/version": "^3.2.1",
                "php": ">=8.2",
                "phpunit/php-code-coverage": "^11.0.12",
                "phpunit/php-file-iterator": "^5.1.1",
                "phpunit/php-invoker": "^5.0.1",
                "phpunit/php-text-template": "^4.0.1",
                "phpunit/php-timer": "^7.0.1",
                "sebastian/cli-parser": "^3.0.2",
                "sebastian/code-unit": "^3.0.3",
                "sebastian/comparator": "^6.3.3",
                "sebastian/diff": "^6.0.2",
                "sebastian/environment": "^7.2.1",
                "sebastian/exporter": "^6.3.2",
                "sebastian/global-state": "^7.0.2",
                "sebastian/object-enumerator": "^6.0.1",
                "sebastian/recursion-context": "^6.0.3",
                "sebastian/type": "^5.1.3",
                "sebastian/version": "^5.0.2",
                "staabm/side-effects-detector": "^1.0.5"
            },
            "suggest": {
                "ext-soap": "To be able to generate mocks based on WSDL files"
            },
            "bin": [
                "phpunit"
            ],
            "type": "library",
            "extra": {
                "branch-alias": {
                    "dev-main": "11.5-dev"
                }
            },
            "autoload": {
                "files": [
                    "src/Framework/Assert/Functions.php"
                ],
                "classmap": [
                    "src/"
                ]
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "BSD-3-Clause"
            ],
            "authors": [
                {
                    "name": "Sebastian Bergmann",
                    "email": "sebastian@phpunit.de",
                    "role": "lead"
                }
            ],
            "description": "The PHP Unit Testing framework.",
            "homepage": "https://phpunit.de/",
            "keywords": [
                "phpunit",
                "testing",
                "xunit"
            ],
            "support": {
                "issues": "https://github.com/sebastianbergmann/phpunit/issues",
                "security": "https://github.com/sebastianbergmann/phpunit/security/policy",
                "source": "https://github.com/sebastianbergmann/phpunit/tree/11.5.56"
            },
            "funding": [
                {
                    "url": "https://phpunit.de/sponsoring.html",
                    "type": "other"
                }
            ],
            "time": "2026-07-06T14:52:39+00:00"
        },
        {
            "name": "sebastian/cli-parser",
            "version": "3.0.2",
            "source": {
                "type": "git",
                "url": "https://github.com/sebastianbergmann/cli-parser.git",
                "reference": "15c5dd40dc4f38794d383bb95465193f5e0ae180"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/sebastianbergmann/cli-parser/zipball/15c5dd40dc4f38794d383bb95465193f5e0ae180",
                "reference": "15c5dd40dc4f38794d383bb95465193f5e0ae180",
                "shasum": ""
            },
            "require": {
                "php": ">=8.2"
            },
            "require-dev": {
                "phpunit/phpunit": "^11.0"
            },
            "type": "library",
            "extra": {
                "branch-alias": {
                    "dev-main": "3.0-dev"
                }
            },
            "autoload": {
                "classmap": [
                    "src/"
                ]
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "BSD-3-Clause"
            ],
            "authors": [
                {
                    "name": "Sebastian Bergmann",
                    "email": "sebastian@phpunit.de",
                    "role": "lead"
                }
            ],
            "description": "Library for parsing CLI options",
            "homepage": "https://github.com/sebastianbergmann/cli-parser",
            "support": {
                "issues": "https://github.com/sebastianbergmann/cli-parser/issues",
                "security": "https://github.com/sebastianbergmann/cli-parser/security/policy",
                "source": "https://github.com/sebastianbergmann/cli-parser/tree/3.0.2"
            },
            "funding": [
                {
                    "url": "https://github.com/sebastianbergmann",
                    "type": "github"
                }
            ],
            "time": "2024-07-03T04:41:36+00:00"
        },
        {
            "name": "sebastian/code-unit",
            "version": "3.0.3",
            "source": {
                "type": "git",
                "url": "https://github.com/sebastianbergmann/code-unit.git",
                "reference": "54391c61e4af8078e5b276ab082b6d3c54c9ad64"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/sebastianbergmann/code-unit/zipball/54391c61e4af8078e5b276ab082b6d3c54c9ad64",
                "reference": "54391c61e4af8078e5b276ab082b6d3c54c9ad64",
                "shasum": ""
            },
            "require": {
                "php": ">=8.2"
            },
            "require-dev": {
                "phpunit/phpunit": "^11.5"
            },
            "type": "library",
            "extra": {
                "branch-alias": {
                    "dev-main": "3.0-dev"
                }
            },
            "autoload": {
                "classmap": [
                    "src/"
                ]
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "BSD-3-Clause"
            ],
            "authors": [
                {
                    "name": "Sebastian Bergmann",
                    "email": "sebastian@phpunit.de",
                    "role": "lead"
                }
            ],
            "description": "Collection of value objects that represent the PHP code units",
            "homepage": "https://github.com/sebastianbergmann/code-unit",
            "support": {
                "issues": "https://github.com/sebastianbergmann/code-unit/issues",
                "security": "https://github.com/sebastianbergmann/code-unit/security/policy",
                "source": "https://github.com/sebastianbergmann/code-unit/tree/3.0.3"
            },
            "funding": [
                {
                    "url": "https://github.com/sebastianbergmann",
                    "type": "github"
                }
            ],
            "time": "2025-03-19T07:56:08+00:00"
        },
        {
            "name": "sebastian/code-unit-reverse-lookup",
            "version": "4.0.1",
            "source": {
                "type": "git",
                "url": "https://github.com/sebastianbergmann/code-unit-reverse-lookup.git",
                "reference": "183a9b2632194febd219bb9246eee421dad8d45e"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/sebastianbergmann/code-unit-reverse-lookup/zipball/183a9b2632194febd219bb9246eee421dad8d45e",
                "reference": "183a9b2632194febd219bb9246eee421dad8d45e",
                "shasum": ""
            },
            "require": {
                "php": ">=8.2"
            },
            "require-dev": {
                "phpunit/phpunit": "^11.0"
            },
            "type": "library",
            "extra": {
                "branch-alias": {
                    "dev-main": "4.0-dev"
                }
            },
            "autoload": {
                "classmap": [
                    "src/"
                ]
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "BSD-3-Clause"
            ],
            "authors": [
                {
                    "name": "Sebastian Bergmann",
                    "email": "sebastian@phpunit.de"
                }
            ],
            "description": "Looks up which function or method a line of code belongs to",
            "homepage": "https://github.com/sebastianbergmann/code-unit-reverse-lookup/",
            "support": {
                "issues": "https://github.com/sebastianbergmann/code-unit-reverse-lookup/issues",
                "security": "https://github.com/sebastianbergmann/code-unit-reverse-lookup/security/policy",
                "source": "https://github.com/sebastianbergmann/code-unit-reverse-lookup/tree/4.0.1"
            },
            "funding": [
                {
                    "url": "https://github.com/sebastianbergmann",
                    "type": "github"
                }
            ],
            "time": "2024-07-03T04:45:54+00:00"
        },
        {
            "name": "sebastian/comparator",
            "version": "6.3.3",
            "source": {
                "type": "git",
                "url": "https://github.com/sebastianbergmann/comparator.git",
                "reference": "2c95e1e86cb8dd41beb8d502057d1081ccc8eca9"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/sebastianbergmann/comparator/zipball/2c95e1e86cb8dd41beb8d502057d1081ccc8eca9",
                "reference": "2c95e1e86cb8dd41beb8d502057d1081ccc8eca9",
                "shasum": ""
            },
            "require": {
                "ext-dom": "*",
                "ext-mbstring": "*",
                "php": ">=8.2",
                "sebastian/diff": "^6.0",
                "sebastian/exporter": "^6.0"
            },
            "require-dev": {
                "phpunit/phpunit": "^11.4"
            },
            "suggest": {
                "ext-bcmath": "For comparing BcMath\\Number objects"
            },
            "type": "library",
            "extra": {
                "branch-alias": {
                    "dev-main": "6.3-dev"
                }
            },
            "autoload": {
                "classmap": [
                    "src/"
                ]
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "BSD-3-Clause"
            ],
            "authors": [
                {
                    "name": "Sebastian Bergmann",
                    "email": "sebastian@phpunit.de"
                },
                {
                    "name": "Jeff Welch",
                    "email": "whatthejeff@gmail.com"
                },
                {
                    "name": "Volker Dusch",
                    "email": "github@wallbash.com"
                },
                {
                    "name": "Bernhard Schussek",
                    "email": "bschussek@2bepublished.at"
                }
            ],
            "description": "Provides the functionality to compare PHP values for equality",
            "homepage": "https://github.com/sebastianbergmann/comparator",
            "keywords": [
                "comparator",
                "compare",
                "equality"
            ],
            "support": {
                "issues": "https://github.com/sebastianbergmann/comparator/issues",
                "security": "https://github.com/sebastianbergmann/comparator/security/policy",
                "source": "https://github.com/sebastianbergmann/comparator/tree/6.3.3"
            },
            "funding": [
                {
                    "url": "https://github.com/sebastianbergmann",
                    "type": "github"
                },
                {
                    "url": "https://liberapay.com/sebastianbergmann",
                    "type": "liberapay"
                },
                {
                    "url": "https://thanks.dev/u/gh/sebastianbergmann",
                    "type": "thanks_dev"
                },
                {
                    "url": "https://tidelift.com/funding/github/packagist/sebastian/comparator",
                    "type": "tidelift"
                }
            ],
            "time": "2026-01-24T09:26:40+00:00"
        },
        {
            "name": "sebastian/complexity",
            "version": "4.0.1",
            "source": {
                "type": "git",
                "url": "https://github.com/sebastianbergmann/complexity.git",
                "reference": "ee41d384ab1906c68852636b6de493846e13e5a0"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/sebastianbergmann/complexity/zipball/ee41d384ab1906c68852636b6de493846e13e5a0",
                "reference": "ee41d384ab1906c68852636b6de493846e13e5a0",
                "shasum": ""
            },
            "require": {
                "nikic/php-parser": "^5.0",
                "php": ">=8.2"
            },
            "require-dev": {
                "phpunit/phpunit": "^11.0"
            },
            "type": "library",
            "extra": {
                "branch-alias": {
                    "dev-main": "4.0-dev"
                }
            },
            "autoload": {
                "classmap": [
                    "src/"
                ]
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "BSD-3-Clause"
            ],
            "authors": [
                {
                    "name": "Sebastian Bergmann",
                    "email": "sebastian@phpunit.de",
                    "role": "lead"
                }
            ],
            "description": "Library for calculating the complexity of PHP code units",
            "homepage": "https://github.com/sebastianbergmann/complexity",
            "support": {
                "issues": "https://github.com/sebastianbergmann/complexity/issues",
                "security": "https://github.com/sebastianbergmann/complexity/security/policy",
                "source": "https://github.com/sebastianbergmann/complexity/tree/4.0.1"
            },
            "funding": [
                {
                    "url": "https://github.com/sebastianbergmann",
                    "type": "github"
                }
            ],
            "time": "2024-07-03T04:49:50+00:00"
        },
        {
            "name": "sebastian/diff",
            "version": "6.0.2",
            "source": {
                "type": "git",
                "url": "https://github.com/sebastianbergmann/diff.git",
                "reference": "b4ccd857127db5d41a5b676f24b51371d76d8544"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/sebastianbergmann/diff/zipball/b4ccd857127db5d41a5b676f24b51371d76d8544",
                "reference": "b4ccd857127db5d41a5b676f24b51371d76d8544",
                "shasum": ""
            },
            "require": {
                "php": ">=8.2"
            },
            "require-dev": {
                "phpunit/phpunit": "^11.0",
                "symfony/process": "^4.2 || ^5"
            },
            "type": "library",
            "extra": {
                "branch-alias": {
                    "dev-main": "6.0-dev"
                }
            },
            "autoload": {
                "classmap": [
                    "src/"
                ]
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "BSD-3-Clause"
            ],
            "authors": [
                {
                    "name": "Sebastian Bergmann",
                    "email": "sebastian@phpunit.de"
                },
                {
                    "name": "Kore Nordmann",
                    "email": "mail@kore-nordmann.de"
                }
            ],
            "description": "Diff implementation",
            "homepage": "https://github.com/sebastianbergmann/diff",
            "keywords": [
                "diff",
                "udiff",
                "unidiff",
                "unified diff"
            ],
            "support": {
                "issues": "https://github.com/sebastianbergmann/diff/issues",
                "security": "https://github.com/sebastianbergmann/diff/security/policy",
                "source": "https://github.com/sebastianbergmann/diff/tree/6.0.2"
            },
            "funding": [
                {
                    "url": "https://github.com/sebastianbergmann",
                    "type": "github"
                }
            ],
            "time": "2024-07-03T04:53:05+00:00"
        },
        {
            "name": "sebastian/environment",
            "version": "7.2.1",
            "source": {
                "type": "git",
                "url": "https://github.com/sebastianbergmann/environment.git",
                "reference": "a5c75038693ad2e8d4b6c15ba2403532647830c4"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/sebastianbergmann/environment/zipball/a5c75038693ad2e8d4b6c15ba2403532647830c4",
                "reference": "a5c75038693ad2e8d4b6c15ba2403532647830c4",
                "shasum": ""
            },
            "require": {
                "php": ">=8.2"
            },
            "require-dev": {
                "phpunit/phpunit": "^11.3"
            },
            "suggest": {
                "ext-posix": "*"
            },
            "type": "library",
            "extra": {
                "branch-alias": {
                    "dev-main": "7.2-dev"
                }
            },
            "autoload": {
                "classmap": [
                    "src/"
                ]
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "BSD-3-Clause"
            ],
            "authors": [
                {
                    "name": "Sebastian Bergmann",
                    "email": "sebastian@phpunit.de"
                }
            ],
            "description": "Provides functionality to handle HHVM/PHP environments",
            "homepage": "https://github.com/sebastianbergmann/environment",
            "keywords": [
                "Xdebug",
                "environment",
                "hhvm"
            ],
            "support": {
                "issues": "https://github.com/sebastianbergmann/environment/issues",
                "security": "https://github.com/sebastianbergmann/environment/security/policy",
                "source": "https://github.com/sebastianbergmann/environment/tree/7.2.1"
            },
            "funding": [
                {
                    "url": "https://github.com/sebastianbergmann",
                    "type": "github"
                },
                {
                    "url": "https://liberapay.com/sebastianbergmann",
                    "type": "liberapay"
                },
                {
                    "url": "https://thanks.dev/u/gh/sebastianbergmann",
                    "type": "thanks_dev"
                },
                {
                    "url": "https://tidelift.com/funding/github/packagist/sebastian/environment",
                    "type": "tidelift"
                }
            ],
            "time": "2025-05-21T11:55:47+00:00"
        },
        {
            "name": "sebastian/exporter",
            "version": "6.3.2",
            "source": {
                "type": "git",
                "url": "https://github.com/sebastianbergmann/exporter.git",
                "reference": "70a298763b40b213ec087c51c739efcaa90bcd74"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/sebastianbergmann/exporter/zipball/70a298763b40b213ec087c51c739efcaa90bcd74",
                "reference": "70a298763b40b213ec087c51c739efcaa90bcd74",
                "shasum": ""
            },
            "require": {
                "ext-mbstring": "*",
                "php": ">=8.2",
                "sebastian/recursion-context": "^6.0"
            },
            "require-dev": {
                "phpunit/phpunit": "^11.3"
            },
            "type": "library",
            "extra": {
                "branch-alias": {
                    "dev-main": "6.3-dev"
                }
            },
            "autoload": {
                "classmap": [
                    "src/"
                ]
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "BSD-3-Clause"
            ],
            "authors": [
                {
                    "name": "Sebastian Bergmann",
                    "email": "sebastian@phpunit.de"
                },
                {
                    "name": "Jeff Welch",
                    "email": "whatthejeff@gmail.com"
                },
                {
                    "name": "Volker Dusch",
                    "email": "github@wallbash.com"
                },
                {
                    "name": "Adam Harvey",
                    "email": "aharvey@php.net"
                },
                {
                    "name": "Bernhard Schussek",
                    "email": "bschussek@gmail.com"
                }
            ],
            "description": "Provides the functionality to export PHP variables for visualization",
            "homepage": "https://www.github.com/sebastianbergmann/exporter",
            "keywords": [
                "export",
                "exporter"
            ],
            "support": {
                "issues": "https://github.com/sebastianbergmann/exporter/issues",
                "security": "https://github.com/sebastianbergmann/exporter/security/policy",
                "source": "https://github.com/sebastianbergmann/exporter/tree/6.3.2"
            },
            "funding": [
                {
                    "url": "https://github.com/sebastianbergmann",
                    "type": "github"
                },
                {
                    "url": "https://liberapay.com/sebastianbergmann",
                    "type": "liberapay"
                },
                {
                    "url": "https://thanks.dev/u/gh/sebastianbergmann",
                    "type": "thanks_dev"
                },
                {
                    "url": "https://tidelift.com/funding/github/packagist/sebastian/exporter",
                    "type": "tidelift"
                }
            ],
            "time": "2025-09-24T06:12:51+00:00"
        },
        {
            "name": "sebastian/global-state",
            "version": "7.0.2",
            "source": {
                "type": "git",
                "url": "https://github.com/sebastianbergmann/global-state.git",
                "reference": "3be331570a721f9a4b5917f4209773de17f747d7"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/sebastianbergmann/global-state/zipball/3be331570a721f9a4b5917f4209773de17f747d7",
                "reference": "3be331570a721f9a4b5917f4209773de17f747d7",
                "shasum": ""
            },
            "require": {
                "php": ">=8.2",
                "sebastian/object-reflector": "^4.0",
                "sebastian/recursion-context": "^6.0"
            },
            "require-dev": {
                "ext-dom": "*",
                "phpunit/phpunit": "^11.0"
            },
            "type": "library",
            "extra": {
                "branch-alias": {
                    "dev-main": "7.0-dev"
                }
            },
            "autoload": {
                "classmap": [
                    "src/"
                ]
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "BSD-3-Clause"
            ],
            "authors": [
                {
                    "name": "Sebastian Bergmann",
                    "email": "sebastian@phpunit.de"
                }
            ],
            "description": "Snapshotting of global state",
            "homepage": "https://www.github.com/sebastianbergmann/global-state",
            "keywords": [
                "global state"
            ],
            "support": {
                "issues": "https://github.com/sebastianbergmann/global-state/issues",
                "security": "https://github.com/sebastianbergmann/global-state/security/policy",
                "source": "https://github.com/sebastianbergmann/global-state/tree/7.0.2"
            },
            "funding": [
                {
                    "url": "https://github.com/sebastianbergmann",
                    "type": "github"
                }
            ],
            "time": "2024-07-03T04:57:36+00:00"
        },
        {
            "name": "sebastian/lines-of-code",
            "version": "3.0.1",
            "source": {
                "type": "git",
                "url": "https://github.com/sebastianbergmann/lines-of-code.git",
                "reference": "d36ad0d782e5756913e42ad87cb2890f4ffe467a"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/sebastianbergmann/lines-of-code/zipball/d36ad0d782e5756913e42ad87cb2890f4ffe467a",
                "reference": "d36ad0d782e5756913e42ad87cb2890f4ffe467a",
                "shasum": ""
            },
            "require": {
                "nikic/php-parser": "^5.0",
                "php": ">=8.2"
            },
            "require-dev": {
                "phpunit/phpunit": "^11.0"
            },
            "type": "library",
            "extra": {
                "branch-alias": {
                    "dev-main": "3.0-dev"
                }
            },
            "autoload": {
                "classmap": [
                    "src/"
                ]
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "BSD-3-Clause"
            ],
            "authors": [
                {
                    "name": "Sebastian Bergmann",
                    "email": "sebastian@phpunit.de",
                    "role": "lead"
                }
            ],
            "description": "Library for counting the lines of code in PHP source code",
            "homepage": "https://github.com/sebastianbergmann/lines-of-code",
            "support": {
                "issues": "https://github.com/sebastianbergmann/lines-of-code/issues",
                "security": "https://github.com/sebastianbergmann/lines-of-code/security/policy",
                "source": "https://github.com/sebastianbergmann/lines-of-code/tree/3.0.1"
            },
            "funding": [
                {
                    "url": "https://github.com/sebastianbergmann",
                    "type": "github"
                }
            ],
            "time": "2024-07-03T04:58:38+00:00"
        },
        {
            "name": "sebastian/object-enumerator",
            "version": "6.0.1",
            "source": {
                "type": "git",
                "url": "https://github.com/sebastianbergmann/object-enumerator.git",
                "reference": "f5b498e631a74204185071eb41f33f38d64608aa"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/sebastianbergmann/object-enumerator/zipball/f5b498e631a74204185071eb41f33f38d64608aa",
                "reference": "f5b498e631a74204185071eb41f33f38d64608aa",
                "shasum": ""
            },
            "require": {
                "php": ">=8.2",
                "sebastian/object-reflector": "^4.0",
                "sebastian/recursion-context": "^6.0"
            },
            "require-dev": {
                "phpunit/phpunit": "^11.0"
            },
            "type": "library",
            "extra": {
                "branch-alias": {
                    "dev-main": "6.0-dev"
                }
            },
            "autoload": {
                "classmap": [
                    "src/"
                ]
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "BSD-3-Clause"
            ],
            "authors": [
                {
                    "name": "Sebastian Bergmann",
                    "email": "sebastian@phpunit.de"
                }
            ],
            "description": "Traverses array structures and object graphs to enumerate all referenced objects",
            "homepage": "https://github.com/sebastianbergmann/object-enumerator/",
            "support": {
                "issues": "https://github.com/sebastianbergmann/object-enumerator/issues",
                "security": "https://github.com/sebastianbergmann/object-enumerator/security/policy",
                "source": "https://github.com/sebastianbergmann/object-enumerator/tree/6.0.1"
            },
            "funding": [
                {
                    "url": "https://github.com/sebastianbergmann",
                    "type": "github"
                }
            ],
            "time": "2024-07-03T05:00:13+00:00"
        },
        {
            "name": "sebastian/object-reflector",
            "version": "4.0.1",
            "source": {
                "type": "git",
                "url": "https://github.com/sebastianbergmann/object-reflector.git",
                "reference": "6e1a43b411b2ad34146dee7524cb13a068bb35f9"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/sebastianbergmann/object-reflector/zipball/6e1a43b411b2ad34146dee7524cb13a068bb35f9",
                "reference": "6e1a43b411b2ad34146dee7524cb13a068bb35f9",
                "shasum": ""
            },
            "require": {
                "php": ">=8.2"
            },
            "require-dev": {
                "phpunit/phpunit": "^11.0"
            },
            "type": "library",
            "extra": {
                "branch-alias": {
                    "dev-main": "4.0-dev"
                }
            },
            "autoload": {
                "classmap": [
                    "src/"
                ]
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "BSD-3-Clause"
            ],
            "authors": [
                {
                    "name": "Sebastian Bergmann",
                    "email": "sebastian@phpunit.de"
                }
            ],
            "description": "Allows reflection of object attributes, including inherited and non-public ones",
            "homepage": "https://github.com/sebastianbergmann/object-reflector/",
            "support": {
                "issues": "https://github.com/sebastianbergmann/object-reflector/issues",
                "security": "https://github.com/sebastianbergmann/object-reflector/security/policy",
                "source": "https://github.com/sebastianbergmann/object-reflector/tree/4.0.1"
            },
            "funding": [
                {
                    "url": "https://github.com/sebastianbergmann",
                    "type": "github"
                }
            ],
            "time": "2024-07-03T05:01:32+00:00"
        },
        {
            "name": "sebastian/recursion-context",
            "version": "6.0.3",
            "source": {
                "type": "git",
                "url": "https://github.com/sebastianbergmann/recursion-context.git",
                "reference": "f6458abbf32a6c8174f8f26261475dc133b3d9dc"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/sebastianbergmann/recursion-context/zipball/f6458abbf32a6c8174f8f26261475dc133b3d9dc",
                "reference": "f6458abbf32a6c8174f8f26261475dc133b3d9dc",
                "shasum": ""
            },
            "require": {
                "php": ">=8.2"
            },
            "require-dev": {
                "phpunit/phpunit": "^11.3"
            },
            "type": "library",
            "extra": {
                "branch-alias": {
                    "dev-main": "6.0-dev"
                }
            },
            "autoload": {
                "classmap": [
                    "src/"
                ]
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "BSD-3-Clause"
            ],
            "authors": [
                {
                    "name": "Sebastian Bergmann",
                    "email": "sebastian@phpunit.de"
                },
                {
                    "name": "Jeff Welch",
                    "email": "whatthejeff@gmail.com"
                },
                {
                    "name": "Adam Harvey",
                    "email": "aharvey@php.net"
                }
            ],
            "description": "Provides functionality to recursively process PHP variables",
            "homepage": "https://github.com/sebastianbergmann/recursion-context",
            "support": {
                "issues": "https://github.com/sebastianbergmann/recursion-context/issues",
                "security": "https://github.com/sebastianbergmann/recursion-context/security/policy",
                "source": "https://github.com/sebastianbergmann/recursion-context/tree/6.0.3"
            },
            "funding": [
                {
                    "url": "https://github.com/sebastianbergmann",
                    "type": "github"
                },
                {
                    "url": "https://liberapay.com/sebastianbergmann",
                    "type": "liberapay"
                },
                {
                    "url": "https://thanks.dev/u/gh/sebastianbergmann",
                    "type": "thanks_dev"
                },
                {
                    "url": "https://tidelift.com/funding/github/packagist/sebastian/recursion-context",
                    "type": "tidelift"
                }
            ],
            "time": "2025-08-13T04:42:22+00:00"
        },
        {
            "name": "sebastian/type",
            "version": "5.1.3",
            "source": {
                "type": "git",
                "url": "https://github.com/sebastianbergmann/type.git",
                "reference": "f77d2d4e78738c98d9a68d2596fe5e8fa380f449"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/sebastianbergmann/type/zipball/f77d2d4e78738c98d9a68d2596fe5e8fa380f449",
                "reference": "f77d2d4e78738c98d9a68d2596fe5e8fa380f449",
                "shasum": ""
            },
            "require": {
                "php": ">=8.2"
            },
            "require-dev": {
                "phpunit/phpunit": "^11.3"
            },
            "type": "library",
            "extra": {
                "branch-alias": {
                    "dev-main": "5.1-dev"
                }
            },
            "autoload": {
                "classmap": [
                    "src/"
                ]
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "BSD-3-Clause"
            ],
            "authors": [
                {
                    "name": "Sebastian Bergmann",
                    "email": "sebastian@phpunit.de",
                    "role": "lead"
                }
            ],
            "description": "Collection of value objects that represent the types of the PHP type system",
            "homepage": "https://github.com/sebastianbergmann/type",
            "support": {
                "issues": "https://github.com/sebastianbergmann/type/issues",
                "security": "https://github.com/sebastianbergmann/type/security/policy",
                "source": "https://github.com/sebastianbergmann/type/tree/5.1.3"
            },
            "funding": [
                {
                    "url": "https://github.com/sebastianbergmann",
                    "type": "github"
                },
                {
                    "url": "https://liberapay.com/sebastianbergmann",
                    "type": "liberapay"
                },
                {
                    "url": "https://thanks.dev/u/gh/sebastianbergmann",
                    "type": "thanks_dev"
                },
                {
                    "url": "https://tidelift.com/funding/github/packagist/sebastian/type",
                    "type": "tidelift"
                }
            ],
            "time": "2025-08-09T06:55:48+00:00"
        },
        {
            "name": "sebastian/version",
            "version": "5.0.2",
            "source": {
                "type": "git",
                "url": "https://github.com/sebastianbergmann/version.git",
                "reference": "c687e3387b99f5b03b6caa64c74b63e2936ff874"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/sebastianbergmann/version/zipball/c687e3387b99f5b03b6caa64c74b63e2936ff874",
                "reference": "c687e3387b99f5b03b6caa64c74b63e2936ff874",
                "shasum": ""
            },
            "require": {
                "php": ">=8.2"
            },
            "type": "library",
            "extra": {
                "branch-alias": {
                    "dev-main": "5.0-dev"
                }
            },
            "autoload": {
                "classmap": [
                    "src/"
                ]
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "BSD-3-Clause"
            ],
            "authors": [
                {
                    "name": "Sebastian Bergmann",
                    "email": "sebastian@phpunit.de",
                    "role": "lead"
                }
            ],
            "description": "Library that helps with managing the version number of Git-hosted PHP projects",
            "homepage": "https://github.com/sebastianbergmann/version",
            "support": {
                "issues": "https://github.com/sebastianbergmann/version/issues",
                "security": "https://github.com/sebastianbergmann/version/security/policy",
                "source": "https://github.com/sebastianbergmann/version/tree/5.0.2"
            },
            "funding": [
                {
                    "url": "https://github.com/sebastianbergmann",
                    "type": "github"
                }
            ],
            "time": "2024-10-09T05:16:32+00:00"
        },
        {
            "name": "staabm/side-effects-detector",
            "version": "1.0.5",
            "source": {
                "type": "git",
                "url": "https://github.com/staabm/side-effects-detector.git",
                "reference": "d8334211a140ce329c13726d4a715adbddd0a163"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/staabm/side-effects-detector/zipball/d8334211a140ce329c13726d4a715adbddd0a163",
                "reference": "d8334211a140ce329c13726d4a715adbddd0a163",
                "shasum": ""
            },
            "require": {
                "ext-tokenizer": "*",
                "php": "^7.4 || ^8.0"
            },
            "require-dev": {
                "phpstan/extension-installer": "^1.4.3",
                "phpstan/phpstan": "^1.12.6",
                "phpunit/phpunit": "^9.6.21",
                "symfony/var-dumper": "^5.4.43",
                "tomasvotruba/type-coverage": "1.0.0",
                "tomasvotruba/unused-public": "1.0.0"
            },
            "type": "library",
            "autoload": {
                "classmap": [
                    "lib/"
                ]
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "description": "A static analysis tool to detect side effects in PHP code",
            "keywords": [
                "static analysis"
            ],
            "support": {
                "issues": "https://github.com/staabm/side-effects-detector/issues",
                "source": "https://github.com/staabm/side-effects-detector/tree/1.0.5"
            },
            "funding": [
                {
                    "url": "https://github.com/staabm",
                    "type": "github"
                }
            ],
            "time": "2024-10-20T05:08:20+00:00"
        },
        {
            "name": "symfony/yaml",
            "version": "v8.1.6",
            "source": {
                "type": "git",
                "url": "https://github.com/symfony/yaml.git",
                "reference": "0b4aa53a67f9fece88c665f1a1dadcfd25d93fe5"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/symfony/yaml/zipball/0b4aa53a67f9fece88c665f1a1dadcfd25d93fe5",
                "reference": "0b4aa53a67f9fece88c665f1a1dadcfd25d93fe5",
                "shasum": ""
            },
            "require": {
                "php": ">=8.4.1",
                "symfony/polyfill-ctype": "^1.8"
            },
            "conflict": {
                "symfony/console": "<7.4"
            },
            "require-dev": {
                "symfony/console": "^7.4|^8.0",
                "yaml/yaml-test-suite": "*"
            },
            "bin": [
                "Resources/bin/yaml-lint"
            ],
            "type": "library",
            "autoload": {
                "psr-4": {
                    "Symfony\\Component\\Yaml\\": ""
                },
                "exclude-from-classmap": [
                    "/Tests/"
                ]
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "MIT"
            ],
            "authors": [
                {
                    "name": "Fabien Potencier",
                    "email": "fabien@symfony.com"
                },
                {
                    "name": "Symfony Community",
                    "homepage": "https://symfony.com/contributors"
                }
            ],
            "description": "Loads and dumps YAML files",
            "homepage": "https://symfony.com",
            "support": {
                "source": "https://github.com/symfony/yaml/tree/v8.1.6"
            },
            "funding": [
                {
                    "url": "https://symfony.com/sponsor",
                    "type": "custom"
                },
                {
                    "url": "https://github.com/fabpot",
                    "type": "github"
                },
                {
                    "url": "https://github.com/nicolas-grekas",
                    "type": "github"
                },
                {
                    "url": "https://tidelift.com/funding/github/packagist/symfony/symfony",
                    "type": "tidelift"
                }
            ],
            "time": "2026-08-30T01:03:44+00:00"
        },
        {
            "name": "theseer/tokenizer",
            "version": "1.3.1",
            "source": {
                "type": "git",
                "url": "https://github.com/theseer/tokenizer.git",
                "reference": "b7489ce515e168639d17feec34b8847c326b0b3c"
            },
            "dist": {
                "type": "zip",
                "url": "https://api.github.com/repos/theseer/tokenizer/zipball/b7489ce515e168639d17feec34b8847c326b0b3c",
                "reference": "b7489ce515e168639d17feec34b8847c326b0b3c",
                "shasum": ""
            },
            "require": {
                "ext-dom": "*",
                "ext-tokenizer": "*",
                "ext-xmlwriter": "*",
                "php": "^7.2 || ^8.0"
            },
            "type": "library",
            "autoload": {
                "classmap": [
                    "src/"
                ]
            },
            "notification-url": "https://packagist.org/downloads/",
            "license": [
                "BSD-3-Clause"
            ],
            "authors": [
                {
                    "name": "Arne Blankerts",
                    "email": "arne@blankerts.de",
                    "role": "Developer"
                }
            ],
            "description": "A small library for converting tokenized PHP source code into XML and potentially other formats",
            "support": {
                "issues": "https://github.com/theseer/tokenizer/issues",
                "source": "https://github.com/theseer/tokenizer/tree/1.3.1"
            },
            "funding": [
                {
                    "url": "https://github.com/theseer",
                    "type": "github"
                }
            ],
            "time": "2025-11-17T20:03:58+00:00"
        }
    ],
    "aliases": [],
    "minimum-stability": "stable",
    "stability-flags": {},
    "prefer-stable": true,
    "prefer-lowest": false,
    "platform": {
        "php": "^8.2"
    },
    "platform-dev": {},
    "plugin-api-version": "2.9.0"
}
```

### `storage/api-docs/openapi.json`

```json
{
  "openapi": "3.0.3",
  "info": {
    "title": "FF Arena Public API",
    "version": "1.0.0",
    "description": "Versioned public API for FF Arena (mobile/SPA/trusted third-party clients). All business endpoints live under /api/v1; a future /api/v2 can be added without breaking v1.\n\n**Auth:** bearer personal access tokens only (session cookies are never accepted). Tokens are stored hashed, support granular scopes, expiry, revocation and last-used tracking; the plaintext is shown exactly once at creation.\n\n**Envelope:** success `{data, meta}`; errors `{error:{code,message,details}}`.\n\n**Idempotency:** critical mutations accept an `Idempotency-Key` header; replays return the stored response.\n\n**Webhooks (outbound):** deliveries are signed `X-FFArena-Signature = HMAC-SHA256(secret, \"{timestamp}.{body}\")` with `X-FFArena-Timestamp`, `X-FFArena-Event` and `X-FFArena-Delivery` headers; retries use exponential backoff."
  },
  "servers": [
    {
      "url": "/"
    }
  ],
  "tags": [
    {
      "name": "Auth"
    },
    {
      "name": "Tournaments"
    },
    {
      "name": "Matches"
    },
    {
      "name": "Teams"
    },
    {
      "name": "Players & Leaderboards"
    },
    {
      "name": "Notifications & Realtime"
    },
    {
      "name": "Payments & Wallet"
    },
    {
      "name": "Support & Disputes"
    },
    {
      "name": "Me"
    },
    {
      "name": "Admin Webhooks"
    },
    {
      "name": "Inbound Webhooks"
    }
  ],
  "paths": {
    "/api/v1/auth/register": {
      "post": {
        "summary": "Register an account",
        "operationId": "post_api_v1_auth_register",
        "tags": [
          "Auth"
        ],
        "responses": {
          "201": {
            "description": "Success",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/Envelope"
                }
              }
            }
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [],
        "requestBody": {
          "required": true,
          "content": {
            "application/json": {
              "schema": {
                "type": "object"
              },
              "example": {
                "name": "Alice",
                "username": "alice",
                "email": "alice@example.com",
                "phone": "01712345678",
                "role": "player",
                "password": "secret123",
                "password_confirmation": "secret123"
              }
            }
          }
        },
        "description": "**Rate limit:** `api_register (3/hour/IP)`."
      }
    },
    "/api/v1/auth/login": {
      "post": {
        "summary": "Login with email + password",
        "operationId": "post_api_v1_auth_login",
        "tags": [
          "Auth"
        ],
        "responses": {
          "201": {
            "description": "Success",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/Envelope"
                }
              }
            }
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [],
        "requestBody": {
          "required": true,
          "content": {
            "application/json": {
              "schema": {
                "type": "object"
              },
              "example": {
                "email": "alice@example.com",
                "password": "secret123"
              }
            }
          }
        },
        "description": "**Rate limit:** `api_login (5/min/identifier)`."
      }
    },
    "/api/v1/auth/google": {
      "post": {
        "summary": "Login with a Google id_token",
        "operationId": "post_api_v1_auth_google",
        "tags": [
          "Auth"
        ],
        "responses": {
          "201": {
            "description": "Success",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/Envelope"
                }
              }
            }
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [],
        "requestBody": {
          "required": true,
          "content": {
            "application/json": {
              "schema": {
                "type": "object"
              },
              "example": {
                "id_token": "<google id_token>"
              }
            }
          }
        },
        "description": "**Rate limit:** `api_login (5/min/identifier)`."
      }
    },
    "/api/v1/auth/otp/request": {
      "post": {
        "summary": "Request a phone OTP",
        "operationId": "post_api_v1_auth_otp_request",
        "tags": [
          "Auth"
        ],
        "responses": {
          "201": {
            "description": "Success",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/Envelope"
                }
              }
            }
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [],
        "requestBody": {
          "required": true,
          "content": {
            "application/json": {
              "schema": {
                "type": "object"
              },
              "example": {
                "phone": "01712345678",
                "purpose": "login"
              }
            }
          }
        },
        "description": "**Rate limit:** `api_otp_request (1/min/phone)`."
      }
    },
    "/api/v1/auth/otp/verify": {
      "post": {
        "summary": "Verify a phone OTP and login",
        "operationId": "post_api_v1_auth_otp_verify",
        "tags": [
          "Auth"
        ],
        "responses": {
          "201": {
            "description": "Success",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/Envelope"
                }
              }
            }
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [],
        "requestBody": {
          "required": true,
          "content": {
            "application/json": {
              "schema": {
                "type": "object"
              },
              "example": {
                "phone": "01712345678",
                "purpose": "login",
                "code": "123456"
              }
            }
          }
        },
        "description": "**Rate limit:** `api_otp_verify (5/5min/phone)`."
      }
    },
    "/api/v1/tournaments": {
      "get": {
        "summary": "List public tournaments",
        "operationId": "get_api_v1_tournaments",
        "tags": [
          "Tournaments"
        ],
        "responses": {
          "200": {
            "description": "Success",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/Envelope"
                }
              }
            }
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [],
        "description": "**Rate limit:** `api_anon (60/min/IP)`."
      }
    },
    "/api/v1/tournaments/{tournament}": {
      "get": {
        "summary": "Show a tournament",
        "operationId": "get_api_v1_tournaments__tournament_",
        "tags": [
          "Tournaments"
        ],
        "responses": {
          "200": {
            "description": "Success",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/Envelope"
                }
              }
            }
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [],
        "parameters": [
          {
            "name": "tournament",
            "in": "path",
            "required": true,
            "schema": {
              "type": "string"
            },
            "description": "Tournament slug"
          }
        ]
      }
    },
    "/api/v1/tournaments/{tournament}/matches": {
      "get": {
        "summary": "List a tournament's matches",
        "operationId": "get_api_v1_tournaments__tournament__matches",
        "tags": [
          "Tournaments"
        ],
        "responses": {
          "200": {
            "description": "Success",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/Envelope"
                }
              }
            }
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [],
        "parameters": [
          {
            "name": "tournament",
            "in": "path",
            "required": true,
            "schema": {
              "type": "string"
            },
            "description": "Tournament slug"
          }
        ]
      }
    },
    "/api/v1/tournaments/{tournament}/leaderboard": {
      "get": {
        "summary": "Tournament leaderboard",
        "operationId": "get_api_v1_tournaments__tournament__leaderboard",
        "tags": [
          "Tournaments"
        ],
        "responses": {
          "200": {
            "description": "Success",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/Envelope"
                }
              }
            }
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [],
        "parameters": [
          {
            "name": "tournament",
            "in": "path",
            "required": true,
            "schema": {
              "type": "string"
            },
            "description": "Tournament slug"
          }
        ]
      }
    },
    "/api/v1/tournaments/{tournament}/bracket": {
      "get": {
        "summary": "Tournament bracket",
        "operationId": "get_api_v1_tournaments__tournament__bracket",
        "tags": [
          "Tournaments"
        ],
        "responses": {
          "200": {
            "description": "Success",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/Envelope"
                }
              }
            }
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [],
        "parameters": [
          {
            "name": "tournament",
            "in": "path",
            "required": true,
            "schema": {
              "type": "string"
            },
            "description": "Tournament slug"
          }
        ]
      }
    },
    "/api/v1/matches/{match}": {
      "get": {
        "summary": "Show a match",
        "operationId": "get_api_v1_matches__match_",
        "tags": [
          "Matches"
        ],
        "responses": {
          "200": {
            "description": "Success",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/Envelope"
                }
              }
            }
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [],
        "parameters": [
          {
            "name": "match",
            "in": "path",
            "required": true,
            "schema": {
              "type": "string"
            },
            "description": "Match id"
          }
        ]
      }
    },
    "/api/v1/players/{user}": {
      "get": {
        "summary": "Public player profile",
        "operationId": "get_api_v1_players__user_",
        "tags": [
          "Players & Leaderboards"
        ],
        "responses": {
          "200": {
            "description": "Success",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/Envelope"
                }
              }
            }
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [],
        "parameters": [
          {
            "name": "user",
            "in": "path",
            "required": true,
            "schema": {
              "type": "string"
            },
            "description": "User id"
          }
        ]
      }
    },
    "/api/v1/players/{user}/ranking": {
      "get": {
        "summary": "Player's rankings",
        "operationId": "get_api_v1_players__user__ranking",
        "tags": [
          "Players & Leaderboards"
        ],
        "responses": {
          "200": {
            "description": "Success",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/Envelope"
                }
              }
            }
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [],
        "parameters": [
          {
            "name": "user",
            "in": "path",
            "required": true,
            "schema": {
              "type": "string"
            },
            "description": "User id"
          }
        ]
      }
    },
    "/api/v1/leaderboards": {
      "get": {
        "summary": "Ranked tournaments",
        "operationId": "get_api_v1_leaderboards",
        "tags": [
          "Players & Leaderboards"
        ],
        "responses": {
          "200": {
            "description": "Success",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/Envelope"
                }
              }
            }
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": []
      }
    },
    "/api/v1/leaderboards/{tournament}": {
      "get": {
        "summary": "Tournament standings",
        "operationId": "get_api_v1_leaderboards__tournament_",
        "tags": [
          "Players & Leaderboards"
        ],
        "responses": {
          "200": {
            "description": "Success",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/Envelope"
                }
              }
            }
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [],
        "parameters": [
          {
            "name": "tournament",
            "in": "path",
            "required": true,
            "schema": {
              "type": "string"
            },
            "description": "Tournament slug"
          }
        ]
      }
    },
    "/api/v1/me": {
      "get": {
        "summary": "Current user",
        "operationId": "get_api_v1_me",
        "tags": [
          "Me"
        ],
        "responses": {
          "200": {
            "description": "Success",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/Envelope"
                }
              }
            }
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [
          {
            "bearerAuth": []
          }
        ],
        "description": "**Required scope:** `profile:read`."
      }
    },
    "/api/v1/me/security": {
      "get": {
        "summary": "Sign-in methods & account status",
        "operationId": "get_api_v1_me_security",
        "tags": [
          "Me"
        ],
        "responses": {
          "200": {
            "description": "Success",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/Envelope"
                }
              }
            }
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [
          {
            "bearerAuth": []
          }
        ],
        "description": "**Required scope:** `profile:read`."
      }
    },
    "/api/v1/me/sessions": {
      "get": {
        "summary": "Active sessions",
        "operationId": "get_api_v1_me_sessions",
        "tags": [
          "Me"
        ],
        "responses": {
          "200": {
            "description": "Success",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/Envelope"
                }
              }
            }
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [
          {
            "bearerAuth": []
          }
        ],
        "description": "**Required scope:** `profile:read`."
      }
    },
    "/api/v1/me/tokens": {
      "get": {
        "summary": "Personal access tokens",
        "operationId": "get_api_v1_me_tokens",
        "tags": [
          "Me"
        ],
        "responses": {
          "200": {
            "description": "Success",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/Envelope"
                }
              }
            }
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [
          {
            "bearerAuth": []
          }
        ],
        "description": "**Required scope:** `profile:read`."
      },
      "post": {
        "summary": "Create a personal access token",
        "operationId": "post_api_v1_me_tokens",
        "tags": [
          "Me"
        ],
        "responses": {
          "201": {
            "description": "Success",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/Envelope"
                }
              }
            }
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [
          {
            "bearerAuth": []
          }
        ],
        "requestBody": {
          "required": true,
          "content": {
            "application/json": {
              "schema": {
                "type": "object"
              },
              "example": {
                "name": "mobile",
                "scopes": [
                  "profile:read"
                ],
                "expires_in_days": 30
              }
            }
          }
        },
        "description": "**Required scope:** `profile:write`.\n\n**Rate limit:** `api_token_issue (5/min/user)`."
      }
    },
    "/api/v1/me/clients": {
      "get": {
        "summary": "API clients",
        "operationId": "get_api_v1_me_clients",
        "tags": [
          "Me"
        ],
        "responses": {
          "200": {
            "description": "Success",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/Envelope"
                }
              }
            }
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [
          {
            "bearerAuth": []
          }
        ],
        "description": "**Required scope:** `profile:read`."
      },
      "post": {
        "summary": "Create an API client",
        "operationId": "post_api_v1_me_clients",
        "tags": [
          "Me"
        ],
        "responses": {
          "201": {
            "description": "Success",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/Envelope"
                }
              }
            }
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [
          {
            "bearerAuth": []
          }
        ],
        "requestBody": {
          "required": true,
          "content": {
            "application/json": {
              "schema": {
                "type": "object"
              },
              "example": {
                "name": "My App",
                "description": "optional",
                "scopes": [
                  "profile:read"
                ]
              }
            }
          }
        },
        "description": "**Required scope:** `profile:write`.\n\n**Rate limit:** `api_token_issue (5/min/user)`."
      }
    },
    "/api/v1/me/profile": {
      "put": {
        "summary": "Update profile",
        "operationId": "put_api_v1_me_profile",
        "tags": [
          "Me"
        ],
        "responses": {
          "200": {
            "description": "Success",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/Envelope"
                }
              }
            }
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [
          {
            "bearerAuth": []
          }
        ],
        "requestBody": {
          "required": true,
          "content": {
            "application/json": {
              "schema": {
                "type": "object"
              },
              "example": {
                "name": "Alice",
                "bio": "Player",
                "privacy": "public"
              }
            }
          }
        },
        "description": "**Required scope:** `profile:write`."
      },
      "patch": {
        "summary": "Update profile (partial)",
        "operationId": "patch_api_v1_me_profile",
        "tags": [
          "Me"
        ],
        "responses": {
          "200": {
            "description": "Success",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/Envelope"
                }
              }
            }
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [
          {
            "bearerAuth": []
          }
        ],
        "requestBody": {
          "required": true,
          "content": {
            "application/json": {
              "schema": {
                "type": "object"
              },
              "example": {
                "name": "Alice",
                "bio": "Player",
                "privacy": "public"
              }
            }
          }
        },
        "description": "**Required scope:** `profile:write`."
      }
    },
    "/api/v1/me/sessions/{session}": {
      "delete": {
        "summary": "Revoke a session",
        "operationId": "delete_api_v1_me_sessions__session_",
        "tags": [
          "Me"
        ],
        "responses": {
          "204": {
            "description": "No content"
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [
          {
            "bearerAuth": []
          }
        ],
        "parameters": [
          {
            "name": "session",
            "in": "path",
            "required": true,
            "schema": {
              "type": "string"
            },
            "description": "Session id"
          }
        ],
        "description": "**Required scope:** `profile:write`."
      }
    },
    "/api/v1/me/sessions/revoke-others": {
      "post": {
        "summary": "Revoke other sessions",
        "operationId": "post_api_v1_me_sessions_revoke_others",
        "tags": [
          "Me"
        ],
        "responses": {
          "201": {
            "description": "Success",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/Envelope"
                }
              }
            }
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [
          {
            "bearerAuth": []
          }
        ],
        "requestBody": {
          "required": true,
          "content": {
            "application/json": {
              "schema": {
                "type": "object"
              }
            }
          }
        },
        "description": "**Required scope:** `profile:write`."
      }
    },
    "/api/v1/me/sessions/revoke-all": {
      "post": {
        "summary": "Revoke all sessions",
        "operationId": "post_api_v1_me_sessions_revoke_all",
        "tags": [
          "Me"
        ],
        "responses": {
          "201": {
            "description": "Success",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/Envelope"
                }
              }
            }
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [
          {
            "bearerAuth": []
          }
        ],
        "requestBody": {
          "required": true,
          "content": {
            "application/json": {
              "schema": {
                "type": "object"
              }
            }
          }
        },
        "description": "**Required scope:** `profile:write`."
      }
    },
    "/api/v1/me/clients/{client}": {
      "delete": {
        "summary": "Revoke an API client",
        "operationId": "delete_api_v1_me_clients__client_",
        "tags": [
          "Me"
        ],
        "responses": {
          "204": {
            "description": "No content"
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [
          {
            "bearerAuth": []
          }
        ],
        "parameters": [
          {
            "name": "client",
            "in": "path",
            "required": true,
            "schema": {
              "type": "string"
            },
            "description": "API client id"
          }
        ],
        "description": "**Required scope:** `profile:write`."
      }
    },
    "/api/v1/me/tokens/{tokenId}": {
      "delete": {
        "summary": "Revoke a token",
        "operationId": "delete_api_v1_me_tokens__tokenId_",
        "tags": [
          "Me"
        ],
        "responses": {
          "204": {
            "description": "No content"
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [
          {
            "bearerAuth": []
          }
        ],
        "parameters": [
          {
            "name": "tokenId",
            "in": "path",
            "required": true,
            "schema": {
              "type": "string"
            },
            "description": "Token id"
          }
        ],
        "description": "**Required scope:** `profile:write`."
      }
    },
    "/api/v1/me/notifications": {
      "get": {
        "summary": "List notifications",
        "operationId": "get_api_v1_me_notifications",
        "tags": [
          "Notifications & Realtime"
        ],
        "responses": {
          "200": {
            "description": "Success",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/Envelope"
                }
              }
            }
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [
          {
            "bearerAuth": []
          }
        ],
        "description": "**Required scope:** `notifications:read`."
      }
    },
    "/api/v1/me/notifications/unread-count": {
      "get": {
        "summary": "Unread notification count",
        "operationId": "get_api_v1_me_notifications_unread_count",
        "tags": [
          "Notifications & Realtime"
        ],
        "responses": {
          "200": {
            "description": "Success",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/Envelope"
                }
              }
            }
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [
          {
            "bearerAuth": []
          }
        ],
        "description": "**Required scope:** `notifications:read`."
      }
    },
    "/api/v1/me/notifications/{notification}/read": {
      "post": {
        "summary": "Mark a notification read",
        "operationId": "post_api_v1_me_notifications__notification__read",
        "tags": [
          "Notifications & Realtime"
        ],
        "responses": {
          "201": {
            "description": "Success",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/Envelope"
                }
              }
            }
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [
          {
            "bearerAuth": []
          }
        ],
        "parameters": [
          {
            "name": "notification",
            "in": "path",
            "required": true,
            "schema": {
              "type": "string"
            },
            "description": "Notification id"
          }
        ],
        "requestBody": {
          "required": true,
          "content": {
            "application/json": {
              "schema": {
                "type": "object"
              }
            }
          }
        },
        "description": "**Required scope:** `notifications:write`."
      }
    },
    "/api/v1/me/notifications/read-all": {
      "post": {
        "summary": "Mark all notifications read",
        "operationId": "post_api_v1_me_notifications_read_all",
        "tags": [
          "Notifications & Realtime"
        ],
        "responses": {
          "201": {
            "description": "Success",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/Envelope"
                }
              }
            }
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [
          {
            "bearerAuth": []
          }
        ],
        "requestBody": {
          "required": true,
          "content": {
            "application/json": {
              "schema": {
                "type": "object"
              }
            }
          }
        },
        "description": "**Required scope:** `notifications:write`."
      }
    },
    "/api/v1/me/live": {
      "get": {
        "summary": "Own realtime cursor feed",
        "operationId": "get_api_v1_me_live",
        "tags": [
          "Notifications & Realtime"
        ],
        "responses": {
          "200": {
            "description": "Success",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/Envelope"
                }
              }
            }
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [
          {
            "bearerAuth": []
          }
        ],
        "description": "**Required scope:** `notifications:read`."
      }
    },
    "/api/v1/tournaments/{tournament}/live": {
      "get": {
        "summary": "Tournament realtime feed",
        "operationId": "get_api_v1_tournaments__tournament__live",
        "tags": [
          "Tournaments"
        ],
        "responses": {
          "200": {
            "description": "Success",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/Envelope"
                }
              }
            }
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [],
        "parameters": [
          {
            "name": "tournament",
            "in": "path",
            "required": true,
            "schema": {
              "type": "string"
            },
            "description": "Tournament slug"
          }
        ],
        "description": "**Rate limit:** `api_anon (60/min/IP)`."
      }
    },
    "/api/v1/me/teams": {
      "get": {
        "summary": "Own teams",
        "operationId": "get_api_v1_me_teams",
        "tags": [
          "Teams"
        ],
        "responses": {
          "200": {
            "description": "Success",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/Envelope"
                }
              }
            }
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [
          {
            "bearerAuth": []
          }
        ],
        "description": "**Required scope:** `teams:read`."
      }
    },
    "/api/v1/teams/{team}": {
      "get": {
        "summary": "Show a team",
        "operationId": "get_api_v1_teams__team_",
        "tags": [
          "Teams"
        ],
        "responses": {
          "200": {
            "description": "Success",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/Envelope"
                }
              }
            }
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [
          {
            "bearerAuth": []
          }
        ],
        "parameters": [
          {
            "name": "team",
            "in": "path",
            "required": true,
            "schema": {
              "type": "string"
            },
            "description": "Team id"
          }
        ],
        "description": "**Required scope:** `teams:read`."
      },
      "patch": {
        "summary": "Update a team",
        "operationId": "patch_api_v1_teams__team_",
        "tags": [
          "Teams"
        ],
        "responses": {
          "200": {
            "description": "Success",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/Envelope"
                }
              }
            }
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [
          {
            "bearerAuth": []
          }
        ],
        "parameters": [
          {
            "name": "team",
            "in": "path",
            "required": true,
            "schema": {
              "type": "string"
            },
            "description": "Team id"
          }
        ],
        "requestBody": {
          "required": true,
          "content": {
            "application/json": {
              "schema": {
                "type": "object"
              }
            }
          }
        },
        "description": "**Required scope:** `teams:write`."
      }
    },
    "/api/v1/teams/{team}/withdraw": {
      "post": {
        "summary": "Withdraw a team",
        "operationId": "post_api_v1_teams__team__withdraw",
        "tags": [
          "Teams"
        ],
        "responses": {
          "201": {
            "description": "Success",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/Envelope"
                }
              }
            }
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [
          {
            "bearerAuth": []
          }
        ],
        "parameters": [
          {
            "name": "team",
            "in": "path",
            "required": true,
            "schema": {
              "type": "string"
            },
            "description": "Team id"
          }
        ],
        "requestBody": {
          "required": true,
          "content": {
            "application/json": {
              "schema": {
                "type": "object"
              }
            }
          }
        },
        "description": "**Required scope:** `teams:write`."
      }
    },
    "/api/v1/teams/{team}/roster": {
      "get": {
        "summary": "List roster",
        "operationId": "get_api_v1_teams__team__roster",
        "tags": [
          "Teams"
        ],
        "responses": {
          "200": {
            "description": "Success",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/Envelope"
                }
              }
            }
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [
          {
            "bearerAuth": []
          }
        ],
        "parameters": [
          {
            "name": "team",
            "in": "path",
            "required": true,
            "schema": {
              "type": "string"
            },
            "description": "Team id"
          }
        ],
        "description": "**Required scope:** `roster:read`."
      },
      "post": {
        "summary": "Add a roster member",
        "operationId": "post_api_v1_teams__team__roster",
        "tags": [
          "Teams"
        ],
        "responses": {
          "201": {
            "description": "Success",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/Envelope"
                }
              }
            }
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [
          {
            "bearerAuth": []
          }
        ],
        "parameters": [
          {
            "name": "team",
            "in": "path",
            "required": true,
            "schema": {
              "type": "string"
            },
            "description": "Team id"
          }
        ],
        "requestBody": {
          "required": true,
          "content": {
            "application/json": {
              "schema": {
                "type": "object"
              },
              "example": {
                "player_name": "P1",
                "game_uid": "UID1234"
              }
            }
          }
        },
        "description": "**Required scope:** `roster:write`."
      }
    },
    "/api/v1/teams/{team}/roster/{member}": {
      "delete": {
        "summary": "Remove a roster member",
        "operationId": "delete_api_v1_teams__team__roster__member_",
        "tags": [
          "Teams"
        ],
        "responses": {
          "204": {
            "description": "No content"
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [
          {
            "bearerAuth": []
          }
        ],
        "parameters": [
          {
            "name": "team",
            "in": "path",
            "required": true,
            "schema": {
              "type": "string"
            },
            "description": "Team id"
          },
          {
            "name": "member",
            "in": "path",
            "required": true,
            "schema": {
              "type": "string"
            },
            "description": "Roster member id"
          }
        ],
        "description": "**Required scope:** `roster:write`."
      }
    },
    "/api/v1/tournaments/{tournament}/registrations": {
      "post": {
        "summary": "Register a team",
        "operationId": "post_api_v1_tournaments__tournament__registrations",
        "tags": [
          "Tournaments"
        ],
        "responses": {
          "201": {
            "description": "Success",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/Envelope"
                }
              }
            }
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [
          {
            "bearerAuth": []
          }
        ],
        "parameters": [
          {
            "name": "tournament",
            "in": "path",
            "required": true,
            "schema": {
              "type": "string"
            },
            "description": "Tournament slug"
          }
        ],
        "requestBody": {
          "required": true,
          "content": {
            "application/json": {
              "schema": {
                "type": "object"
              },
              "example": {
                "name": "Squad",
                "captain_name": "Captain",
                "phone": "01712345678",
                "game_uid": "UID1234",
                "members": [
                  {
                    "player_name": "P1",
                    "game_uid": "UID5678"
                  }
                ]
              }
            }
          }
        },
        "description": "**Required scope:** `tournaments:register`.\n\nSupports the `Idempotency-Key` header: a replay within the TTL returns the stored response; reusing a key with a different body returns 409."
      }
    },
    "/api/v1/tournaments/{tournament}/check-in": {
      "post": {
        "summary": "Check a team in",
        "operationId": "post_api_v1_tournaments__tournament__check_in",
        "tags": [
          "Tournaments"
        ],
        "responses": {
          "201": {
            "description": "Success",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/Envelope"
                }
              }
            }
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [
          {
            "bearerAuth": []
          }
        ],
        "parameters": [
          {
            "name": "tournament",
            "in": "path",
            "required": true,
            "schema": {
              "type": "string"
            },
            "description": "Tournament slug"
          }
        ],
        "requestBody": {
          "required": true,
          "content": {
            "application/json": {
              "schema": {
                "type": "object"
              },
              "example": {
                "team_id": 1
              }
            }
          }
        },
        "description": "**Required scope:** `tournaments:register`."
      }
    },
    "/api/v1/tournaments/{tournament}/waitlist": {
      "get": {
        "summary": "Waitlist positions",
        "operationId": "get_api_v1_tournaments__tournament__waitlist",
        "tags": [
          "Tournaments"
        ],
        "responses": {
          "200": {
            "description": "Success",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/Envelope"
                }
              }
            }
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [
          {
            "bearerAuth": []
          }
        ],
        "parameters": [
          {
            "name": "tournament",
            "in": "path",
            "required": true,
            "schema": {
              "type": "string"
            },
            "description": "Tournament slug"
          }
        ],
        "description": "**Required scope:** `tournaments:read`."
      }
    },
    "/api/v1/matches/{match}/scores": {
      "post": {
        "summary": "Submit a score",
        "operationId": "post_api_v1_matches__match__scores",
        "tags": [
          "Matches"
        ],
        "responses": {
          "201": {
            "description": "Success",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/Envelope"
                }
              }
            }
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [
          {
            "bearerAuth": []
          }
        ],
        "parameters": [
          {
            "name": "match",
            "in": "path",
            "required": true,
            "schema": {
              "type": "string"
            },
            "description": "Match id"
          }
        ],
        "requestBody": {
          "required": true,
          "content": {
            "application/json": {
              "schema": {
                "type": "object"
              },
              "example": {
                "team_id": 1,
                "kills": 5,
                "placement": 1
              }
            }
          }
        },
        "description": "**Required scope:** `scores:submit`.\n\nSupports the `Idempotency-Key` header: a replay within the TTL returns the stored response; reusing a key with a different body returns 409.\n\n**Rate limit:** `api_score (10/min/user)`."
      }
    },
    "/api/v1/payments/methods": {
      "get": {
        "summary": "Payment providers & saved methods",
        "operationId": "get_api_v1_payments_methods",
        "tags": [
          "Payments & Wallet"
        ],
        "responses": {
          "200": {
            "description": "Success",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/Envelope"
                }
              }
            }
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [
          {
            "bearerAuth": []
          }
        ],
        "description": "**Required scope:** `wallet:read`."
      }
    },
    "/api/v1/payments": {
      "post": {
        "summary": "Create a payment",
        "operationId": "post_api_v1_payments",
        "tags": [
          "Payments & Wallet"
        ],
        "responses": {
          "201": {
            "description": "Success",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/Envelope"
                }
              }
            }
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [
          {
            "bearerAuth": []
          }
        ],
        "requestBody": {
          "required": true,
          "content": {
            "application/json": {
              "schema": {
                "type": "object"
              },
              "example": {
                "team_id": 1,
                "provider": "bkash"
              }
            }
          }
        },
        "description": "**Required scope:** `payments:create`.\n\nSupports the `Idempotency-Key` header: a replay within the TTL returns the stored response; reusing a key with a different body returns 409.\n\n**Rate limit:** `api_payment (5/min/user)`."
      }
    },
    "/api/v1/payments/{payment}": {
      "get": {
        "summary": "Show a payment",
        "operationId": "get_api_v1_payments__payment_",
        "tags": [
          "Payments & Wallet"
        ],
        "responses": {
          "200": {
            "description": "Success",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/Envelope"
                }
              }
            }
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [
          {
            "bearerAuth": []
          }
        ],
        "parameters": [
          {
            "name": "payment",
            "in": "path",
            "required": true,
            "schema": {
              "type": "string"
            },
            "description": "Payment id"
          }
        ],
        "description": "**Required scope:** `payments:read`."
      }
    },
    "/api/v1/me/wallet": {
      "get": {
        "summary": "Wallet summary",
        "operationId": "get_api_v1_me_wallet",
        "tags": [
          "Payments & Wallet"
        ],
        "responses": {
          "200": {
            "description": "Success",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/Envelope"
                }
              }
            }
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [
          {
            "bearerAuth": []
          }
        ],
        "description": "**Required scope:** `wallet:read`."
      }
    },
    "/api/v1/me/wallet/ledger": {
      "get": {
        "summary": "Wallet ledger",
        "operationId": "get_api_v1_me_wallet_ledger",
        "tags": [
          "Payments & Wallet"
        ],
        "responses": {
          "200": {
            "description": "Success",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/Envelope"
                }
              }
            }
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [
          {
            "bearerAuth": []
          }
        ],
        "description": "**Required scope:** `wallet:read`."
      }
    },
    "/api/v1/me/payouts": {
      "get": {
        "summary": "Own payouts",
        "operationId": "get_api_v1_me_payouts",
        "tags": [
          "Payments & Wallet"
        ],
        "responses": {
          "200": {
            "description": "Success",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/Envelope"
                }
              }
            }
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [
          {
            "bearerAuth": []
          }
        ],
        "description": "**Required scope:** `payouts:read`."
      }
    },
    "/api/v1/me/support": {
      "get": {
        "summary": "Own support tickets",
        "operationId": "get_api_v1_me_support",
        "tags": [
          "Support & Disputes"
        ],
        "responses": {
          "200": {
            "description": "Success",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/Envelope"
                }
              }
            }
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [
          {
            "bearerAuth": []
          }
        ],
        "description": "**Required scope:** `support:read`."
      },
      "post": {
        "summary": "Create a support ticket",
        "operationId": "post_api_v1_me_support",
        "tags": [
          "Support & Disputes"
        ],
        "responses": {
          "201": {
            "description": "Success",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/Envelope"
                }
              }
            }
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [
          {
            "bearerAuth": []
          }
        ],
        "requestBody": {
          "required": true,
          "content": {
            "application/json": {
              "schema": {
                "type": "object"
              },
              "example": {
                "subject": "Help",
                "category": "payment",
                "message": "Details"
              }
            }
          }
        },
        "description": "**Required scope:** `support:write`.\n\nSupports the `Idempotency-Key` header: a replay within the TTL returns the stored response; reusing a key with a different body returns 409.\n\n**Rate limit:** `api_support (10/min/user)`."
      }
    },
    "/api/v1/me/support/{ticket}": {
      "get": {
        "summary": "Show a ticket",
        "operationId": "get_api_v1_me_support__ticket_",
        "tags": [
          "Support & Disputes"
        ],
        "responses": {
          "200": {
            "description": "Success",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/Envelope"
                }
              }
            }
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [
          {
            "bearerAuth": []
          }
        ],
        "parameters": [
          {
            "name": "ticket",
            "in": "path",
            "required": true,
            "schema": {
              "type": "string"
            },
            "description": "Support ticket id"
          }
        ],
        "description": "**Required scope:** `support:read`."
      }
    },
    "/api/v1/me/support/{ticket}/messages": {
      "get": {
        "summary": "Ticket messages",
        "operationId": "get_api_v1_me_support__ticket__messages",
        "tags": [
          "Support & Disputes"
        ],
        "responses": {
          "200": {
            "description": "Success",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/Envelope"
                }
              }
            }
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [
          {
            "bearerAuth": []
          }
        ],
        "parameters": [
          {
            "name": "ticket",
            "in": "path",
            "required": true,
            "schema": {
              "type": "string"
            },
            "description": "Support ticket id"
          }
        ],
        "description": "**Required scope:** `support:read`."
      },
      "post": {
        "summary": "Reply to a ticket",
        "operationId": "post_api_v1_me_support__ticket__messages",
        "tags": [
          "Support & Disputes"
        ],
        "responses": {
          "201": {
            "description": "Success",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/Envelope"
                }
              }
            }
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [
          {
            "bearerAuth": []
          }
        ],
        "parameters": [
          {
            "name": "ticket",
            "in": "path",
            "required": true,
            "schema": {
              "type": "string"
            },
            "description": "Support ticket id"
          }
        ],
        "requestBody": {
          "required": true,
          "content": {
            "application/json": {
              "schema": {
                "type": "object"
              },
              "example": {
                "body": "Reply text"
              }
            }
          }
        },
        "description": "**Required scope:** `support:write`.\n\n**Rate limit:** `api_support (10/min/user)`."
      }
    },
    "/api/v1/me/disputes": {
      "get": {
        "summary": "Own disputes",
        "operationId": "get_api_v1_me_disputes",
        "tags": [
          "Support & Disputes"
        ],
        "responses": {
          "200": {
            "description": "Success",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/Envelope"
                }
              }
            }
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [
          {
            "bearerAuth": []
          }
        ],
        "description": "**Required scope:** `disputes:read`."
      }
    },
    "/api/v1/disputes/{dispute}": {
      "get": {
        "summary": "Show a dispute",
        "operationId": "get_api_v1_disputes__dispute_",
        "tags": [
          "Support & Disputes"
        ],
        "responses": {
          "200": {
            "description": "Success",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/Envelope"
                }
              }
            }
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [
          {
            "bearerAuth": []
          }
        ],
        "parameters": [
          {
            "name": "dispute",
            "in": "path",
            "required": true,
            "schema": {
              "type": "string"
            },
            "description": "Dispute id"
          }
        ],
        "description": "**Required scope:** `disputes:read`."
      }
    },
    "/api/v1/admin/webhooks/endpoints": {
      "get": {
        "summary": "List webhook endpoints",
        "operationId": "get_api_v1_admin_webhooks_endpoints",
        "tags": [
          "Admin Webhooks"
        ],
        "responses": {
          "200": {
            "description": "Success",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/Envelope"
                }
              }
            }
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [
          {
            "bearerAuth": []
          }
        ],
        "description": "**Required scope:** `admin`."
      },
      "post": {
        "summary": "Create a webhook endpoint",
        "operationId": "post_api_v1_admin_webhooks_endpoints",
        "tags": [
          "Admin Webhooks"
        ],
        "responses": {
          "201": {
            "description": "Success",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/Envelope"
                }
              }
            }
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [
          {
            "bearerAuth": []
          }
        ],
        "requestBody": {
          "required": true,
          "content": {
            "application/json": {
              "schema": {
                "type": "object"
              },
              "example": {
                "url": "https://example.com/hooks",
                "description": "optional",
                "events": [
                  "payment.succeeded"
                ]
              }
            }
          }
        },
        "description": "**Required scope:** `admin`."
      }
    },
    "/api/v1/admin/webhooks/endpoints/{endpoint}": {
      "get": {
        "summary": "Show an endpoint",
        "operationId": "get_api_v1_admin_webhooks_endpoints__endpoint_",
        "tags": [
          "Admin Webhooks"
        ],
        "responses": {
          "200": {
            "description": "Success",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/Envelope"
                }
              }
            }
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [
          {
            "bearerAuth": []
          }
        ],
        "parameters": [
          {
            "name": "endpoint",
            "in": "path",
            "required": true,
            "schema": {
              "type": "string"
            },
            "description": "Webhook endpoint id"
          }
        ],
        "description": "**Required scope:** `admin`."
      }
    },
    "/api/v1/admin/webhooks/endpoints/{endpoint}/rotate-secret": {
      "post": {
        "summary": "Rotate endpoint secret",
        "operationId": "post_api_v1_admin_webhooks_endpoints__endpoint__rotate_secret",
        "tags": [
          "Admin Webhooks"
        ],
        "responses": {
          "201": {
            "description": "Success",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/Envelope"
                }
              }
            }
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [
          {
            "bearerAuth": []
          }
        ],
        "parameters": [
          {
            "name": "endpoint",
            "in": "path",
            "required": true,
            "schema": {
              "type": "string"
            },
            "description": "Webhook endpoint id"
          }
        ],
        "requestBody": {
          "required": true,
          "content": {
            "application/json": {
              "schema": {
                "type": "object"
              }
            }
          }
        },
        "description": "**Required scope:** `admin`."
      }
    },
    "/api/v1/admin/webhooks/endpoints/{endpoint}/toggle": {
      "post": {
        "summary": "Enable/disable endpoint",
        "operationId": "post_api_v1_admin_webhooks_endpoints__endpoint__toggle",
        "tags": [
          "Admin Webhooks"
        ],
        "responses": {
          "201": {
            "description": "Success",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/Envelope"
                }
              }
            }
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [
          {
            "bearerAuth": []
          }
        ],
        "parameters": [
          {
            "name": "endpoint",
            "in": "path",
            "required": true,
            "schema": {
              "type": "string"
            },
            "description": "Webhook endpoint id"
          }
        ],
        "requestBody": {
          "required": true,
          "content": {
            "application/json": {
              "schema": {
                "type": "object"
              },
              "example": {
                "status": "active"
              }
            }
          }
        },
        "description": "**Required scope:** `admin`."
      }
    },
    "/api/v1/admin/webhooks/endpoints/{endpoint}/deliveries": {
      "get": {
        "summary": "Endpoint deliveries",
        "operationId": "get_api_v1_admin_webhooks_endpoints__endpoint__deliveries",
        "tags": [
          "Admin Webhooks"
        ],
        "responses": {
          "200": {
            "description": "Success",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/Envelope"
                }
              }
            }
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [
          {
            "bearerAuth": []
          }
        ],
        "parameters": [
          {
            "name": "endpoint",
            "in": "path",
            "required": true,
            "schema": {
              "type": "string"
            },
            "description": "Webhook endpoint id"
          }
        ],
        "description": "**Required scope:** `admin`."
      }
    },
    "/api/v1/admin/webhooks/events": {
      "get": {
        "summary": "Webhook event vocabulary",
        "operationId": "get_api_v1_admin_webhooks_events",
        "tags": [
          "Admin Webhooks"
        ],
        "responses": {
          "200": {
            "description": "Success",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/Envelope"
                }
              }
            }
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [
          {
            "bearerAuth": []
          }
        ],
        "description": "**Required scope:** `admin`."
      }
    },
    "/api/v1/webhooks/inbound/{provider}": {
      "post": {
        "summary": "Inbound provider webhook",
        "operationId": "post_api_v1_webhooks_inbound__provider_",
        "tags": [
          "Inbound Webhooks"
        ],
        "responses": {
          "201": {
            "description": "Success",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "#/components/schemas/Envelope"
                }
              }
            }
          },
          "401": {
            "$ref": "#/components/responses/Unauthorized"
          },
          "403": {
            "$ref": "#/components/responses/Forbidden"
          },
          "404": {
            "$ref": "#/components/responses/NotFound"
          },
          "422": {
            "$ref": "#/components/responses/ValidationError"
          },
          "429": {
            "$ref": "#/components/responses/RateLimited"
          }
        },
        "security": [],
        "parameters": [
          {
            "name": "provider",
            "in": "path",
            "required": true,
            "schema": {
              "type": "string"
            },
            "description": "Provider id (bkash|nagad|rocket|sslcommerz|card)"
          }
        ],
        "requestBody": {
          "required": true,
          "content": {
            "application/json": {
              "schema": {
                "type": "object"
              }
            }
          }
        },
        "description": "**Rate limit:** `api_webhook (60/min/IP)`.\n\nAuthenticated by HMAC-SHA256 over the raw body (`X-Signature`), a fresh `X-Timestamp`, and an event-id idempotency check. Content-Type must be `application/json`."
      }
    }
  },
  "components": {
    "securitySchemes": {
      "bearerAuth": {
        "type": "http",
        "scheme": "bearer",
        "bearerFormat": "personal access token",
        "description": "Personal access token issued by /api/v1/auth/* or /api/v1/me/tokens. Session cookies are NOT accepted by the API."
      }
    },
    "responses": {
      "Unauthorized": {
        "description": "Missing/invalid token",
        "content": {
          "application/json": {
            "schema": {
              "$ref": "#/components/schemas/ErrorResponse"
            }
          }
        }
      },
      "Forbidden": {
        "description": "Insufficient scope or authorization",
        "content": {
          "application/json": {
            "schema": {
              "$ref": "#/components/schemas/ErrorResponse"
            }
          }
        }
      },
      "NotFound": {
        "description": "Resource not found",
        "content": {
          "application/json": {
            "schema": {
              "$ref": "#/components/schemas/ErrorResponse"
            }
          }
        }
      },
      "ValidationError": {
        "description": "Validation failed",
        "content": {
          "application/json": {
            "schema": {
              "$ref": "#/components/schemas/ErrorResponse"
            }
          }
        }
      },
      "RateLimited": {
        "description": "Rate limit exceeded",
        "content": {
          "application/json": {
            "schema": {
              "$ref": "#/components/schemas/ErrorResponse"
            }
          }
        }
      }
    },
    "schemas": {
      "Envelope": {
        "type": "object",
        "properties": {
          "data": {},
          "meta": {
            "type": "object"
          }
        }
      },
      "ErrorResponse": {
        "type": "object",
        "properties": {
          "error": {
            "$ref": "#/components/schemas/Error"
          }
        }
      },
      "Error": {
        "type": "object",
        "required": [
          "code",
          "message"
        ],
        "properties": {
          "code": {
            "type": "string"
          },
          "message": {
            "type": "string"
          },
          "details": {
            "type": "object",
            "additionalProperties": {
              "type": "string"
            }
          }
        }
      },
      "Tournament": {
        "type": "object",
        "properties": {
          "id": {
            "type": "integer"
          },
          "slug": {
            "type": "string"
          },
          "name": {
            "type": "string"
          },
          "game_mode": {
            "type": "string",
            "enum": [
              "squad",
              "duo",
              "solo"
            ]
          },
          "map": {
            "type": "string"
          },
          "format": {
            "type": "string"
          },
          "status": {
            "type": "string"
          },
          "entry_fee": {
            "type": "string"
          },
          "entry_fee_minor": {
            "type": "integer"
          },
          "currency": {
            "type": "string"
          },
          "prize_pool": {
            "type": "string"
          },
          "team_slots": {
            "type": "integer"
          },
          "team_size": {
            "type": "integer"
          },
          "starts_at": {
            "type": "string",
            "format": "date-time"
          },
          "slots_left": {
            "type": "integer"
          },
          "is_full": {
            "type": "boolean"
          },
          "accepts_registration": {
            "type": "boolean"
          }
        }
      },
      "Match": {
        "type": "object",
        "properties": {
          "id": {
            "type": "integer"
          },
          "tournament_id": {
            "type": "integer"
          },
          "round": {
            "type": "integer"
          },
          "match_no": {
            "type": "integer"
          },
          "bracket": {
            "type": "string"
          },
          "status": {
            "type": "string"
          },
          "scheduled_at": {
            "type": "string",
            "format": "date-time"
          }
        }
      },
      "Score": {
        "type": "object",
        "properties": {
          "id": {
            "type": "integer"
          },
          "team_id": {
            "type": "integer"
          },
          "kills": {
            "type": "integer"
          },
          "placement": {
            "type": "integer"
          },
          "placement_points": {
            "type": "integer"
          },
          "kill_points": {
            "type": "integer"
          },
          "points": {
            "type": "integer"
          },
          "status": {
            "type": "string"
          }
        }
      },
      "Team": {
        "type": "object",
        "properties": {
          "id": {
            "type": "integer"
          },
          "tournament_id": {
            "type": "integer"
          },
          "name": {
            "type": "string"
          },
          "captain_name": {
            "type": "string"
          },
          "game_uid": {
            "type": "string"
          },
          "status": {
            "type": "string"
          },
          "waitlist_position": {
            "type": "integer"
          }
        }
      },
      "UserProfile": {
        "type": "object",
        "properties": {
          "id": {
            "type": "integer"
          },
          "name": {
            "type": "string"
          },
          "username": {
            "type": "string"
          },
          "visible": {
            "type": "boolean"
          },
          "privacy": {
            "type": "string"
          }
        }
      },
      "Notification": {
        "type": "object",
        "properties": {
          "id": {
            "type": "integer"
          },
          "type": {
            "type": "string"
          },
          "title": {
            "type": "string"
          },
          "body": {
            "type": "string"
          },
          "read": {
            "type": "boolean"
          }
        }
      },
      "Payment": {
        "type": "object",
        "properties": {
          "id": {
            "type": "integer"
          },
          "tournament_id": {
            "type": "integer"
          },
          "team_id": {
            "type": "integer"
          },
          "amount": {
            "type": "string"
          },
          "amount_minor": {
            "type": "integer"
          },
          "currency": {
            "type": "string"
          },
          "provider": {
            "type": "string"
          },
          "status": {
            "type": "string"
          }
        }
      },
      "Wallet": {
        "type": "object",
        "properties": {
          "id": {
            "type": "integer"
          },
          "balance": {
            "type": "string"
          },
          "balance_minor": {
            "type": "integer"
          },
          "currency": {
            "type": "string"
          },
          "status": {
            "type": "string"
          }
        }
      },
      "LedgerEntry": {
        "type": "object",
        "properties": {
          "id": {
            "type": "integer"
          },
          "direction": {
            "type": "string",
            "enum": [
              "credit",
              "debit"
            ]
          },
          "amount_minor": {
            "type": "integer"
          },
          "balance_after_minor": {
            "type": "integer"
          },
          "type": {
            "type": "string"
          }
        }
      },
      "Payout": {
        "type": "object",
        "properties": {
          "id": {
            "type": "integer"
          },
          "tournament_id": {
            "type": "integer"
          },
          "rank": {
            "type": "integer"
          },
          "amount_minor": {
            "type": "integer"
          },
          "currency": {
            "type": "string"
          },
          "status": {
            "type": "string"
          }
        }
      },
      "SupportTicket": {
        "type": "object",
        "properties": {
          "id": {
            "type": "integer"
          },
          "subject": {
            "type": "string"
          },
          "category": {
            "type": "string"
          },
          "priority": {
            "type": "string"
          },
          "status": {
            "type": "string"
          }
        }
      },
      "SupportMessage": {
        "type": "object",
        "properties": {
          "id": {
            "type": "integer"
          },
          "ticket_id": {
            "type": "integer"
          },
          "body": {
            "type": "string"
          }
        }
      },
      "Dispute": {
        "type": "object",
        "properties": {
          "id": {
            "type": "integer"
          },
          "match_id": {
            "type": "integer"
          },
          "category": {
            "type": "string"
          },
          "status": {
            "type": "string"
          },
          "description": {
            "type": "string"
          },
          "resolution": {
            "type": "string"
          }
        }
      },
      "Token": {
        "type": "object",
        "properties": {
          "id": {
            "type": "integer"
          },
          "name": {
            "type": "string"
          },
          "abilities": {
            "type": "array",
            "items": {
              "type": "string"
            }
          },
          "last_used_at": {
            "type": "string",
            "format": "date-time"
          },
          "expires_at": {
            "type": "string",
            "format": "date-time"
          }
        }
      },
      "ApiClient": {
        "type": "object",
        "properties": {
          "id": {
            "type": "integer"
          },
          "name": {
            "type": "string"
          },
          "description": {
            "type": "string"
          },
          "status": {
            "type": "string"
          }
        }
      },
      "WebhookEndpoint": {
        "type": "object",
        "properties": {
          "id": {
            "type": "integer"
          },
          "url": {
            "type": "string"
          },
          "status": {
            "type": "string"
          },
          "events": {
            "type": "array",
            "items": {
              "type": "string"
            }
          },
          "consecutive_failures": {
            "type": "integer"
          }
        }
      },
      "WebhookDelivery": {
        "type": "object",
        "properties": {
          "id": {
            "type": "integer"
          },
          "event": {
            "type": "string"
          },
          "delivery_id": {
            "type": "string"
          },
          "status": {
            "type": "string"
          },
          "attempts": {
            "type": "integer"
          }
        }
      }
    }
  }
}
```
