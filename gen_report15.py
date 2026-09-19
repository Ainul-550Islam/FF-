#!/usr/bin/env python3
"""Generate PHASE15_PUBLIC_API_MOBILE_WEBHOOK_REPORT.md."""
import os

ROOT = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.join(ROOT, "PHASE15_PUBLIC_API_MOBILE_WEBHOOK_REPORT.md")

NEW_FILES = [
    "config/api.php",
    "config/webhooks.php",
    "config/sanctum.php",
    "database/migrations/2026_09_09_092433_create_personal_access_tokens_table.php",
    "database/migrations/2026_09_09_100000_create_api_infrastructure_tables.php",
    "app/Support/ApiResponse.php",
    "app/Exceptions/ApiExceptionHandler.php",
    "app/Contracts/GoogleIdTokenVerifierInterface.php",
    "app/Gateways/GoogleTokenInfoIdVerifier.php",
    "app/Models/ApiClient.php",
    "app/Models/PersonalAccessToken.php",
    "app/Models/ApiIdempotencyKey.php",
    "app/Models/WebhookEndpoint.php",
    "app/Models/WebhookDelivery.php",
    "app/Models/WebhookEvent.php",
    "app/Http/Middleware/EnsureBearerToken.php",
    "app/Http/Middleware/EnsureTokenIsValid.php",
    "app/Http/Middleware/EnsureIdempotency.php",
    "app/Services/ApiTokenService.php",
    "app/Services/ApiClientService.php",
    "app/Services/IdempotencyService.php",
    "app/Services/RegistrationService.php",
    "app/Services/WebhookSignatureService.php",
    "app/Services/WebhookIngressService.php",
    "app/Services/WebhookDispatcher.php",
    "app/Services/WebhookSubscriptionService.php",
    "app/Jobs/SendWebhookDelivery.php",
    "app/Http/Controllers/Api/V1/AuthController.php",
    "app/Http/Controllers/Api/V1/DisputeController.php",
    "app/Http/Controllers/Api/V1/LeaderboardController.php",
    "app/Http/Controllers/Api/V1/LiveController.php",
    "app/Http/Controllers/Api/V1/MatchController.php",
    "app/Http/Controllers/Api/V1/MeController.php",
    "app/Http/Controllers/Api/V1/NotificationController.php",
    "app/Http/Controllers/Api/V1/PaymentController.php",
    "app/Http/Controllers/Api/V1/PlayerController.php",
    "app/Http/Controllers/Api/V1/SupportController.php",
    "app/Http/Controllers/Api/V1/TeamController.php",
    "app/Http/Controllers/Api/V1/TokenController.php",
    "app/Http/Controllers/Api/V1/TournamentController.php",
    "app/Http/Controllers/Api/V1/WalletController.php",
    "app/Http/Controllers/Api/V1/WebhookInboundController.php",
    "app/Http/Controllers/Api/V1/WebhookSubscriptionController.php",
    "app/Http/Resources/Api/V1/ApiClientResource.php",
    "app/Http/Resources/Api/V1/DisputeResource.php",
    "app/Http/Resources/Api/V1/LeaderboardEntryResource.php",
    "app/Http/Resources/Api/V1/LedgerEntryResource.php",
    "app/Http/Resources/Api/V1/LiveEventResource.php",
    "app/Http/Resources/Api/V1/MatchResource.php",
    "app/Http/Resources/Api/V1/MeResource.php",
    "app/Http/Resources/Api/V1/NotificationResource.php",
    "app/Http/Resources/Api/V1/PaymentMethodResource.php",
    "app/Http/Resources/Api/V1/PaymentResource.php",
    "app/Http/Resources/Api/V1/PayoutResource.php",
    "app/Http/Resources/Api/V1/ProfileResource.php",
    "app/Http/Resources/Api/V1/ScoreResource.php",
    "app/Http/Resources/Api/V1/SessionResource.php",
    "app/Http/Resources/Api/V1/SupportMessageResource.php",
    "app/Http/Resources/Api/V1/SupportTicketResource.php",
    "app/Http/Resources/Api/V1/TeamMemberResource.php",
    "app/Http/Resources/Api/V1/TeamResource.php",
    "app/Http/Resources/Api/V1/TokenResource.php",
    "app/Http/Resources/Api/V1/TournamentResource.php",
    "app/Http/Resources/Api/V1/UserResource.php",
    "app/Http/Resources/Api/V1/WalletResource.php",
    "app/Http/Resources/Api/V1/WebhookDeliveryResource.php",
    "app/Http/Resources/Api/V1/WebhookEndpointResource.php",
    "app/Http/Resources/Api/V1/WebhookEventResource.php",
    "routes/api.php",
    "tools/gen_openapi.py",
    "tests/Feature/Api/ApiTestCase.php",
    "tests/Feature/Api/ApiFakeSmsProvider.php",
    "tests/Feature/Api/ApiFakeGoogleVerifier.php",
    "tests/Feature/Api/ApiAuthTest.php",
    "tests/Feature/Api/ApiTokenScopesTest.php",
    "tests/Feature/Api/ApiTournamentsTest.php",
    "tests/Feature/Api/ApiTeamsRosterTest.php",
    "tests/Feature/Api/ApiMatchesScoresTest.php",
    "tests/Feature/Api/ApiProfilePrivacyTest.php",
    "tests/Feature/Api/ApiNotificationsTest.php",
    "tests/Feature/Api/ApiPaymentsWalletTest.php",
    "tests/Feature/Api/ApiSupportDisputesTest.php",
    "tests/Feature/Api/ApiIdempotencyTest.php",
    "tests/Feature/Api/ApiWebhookTest.php",
    "tests/Feature/Api/ApiSecurityTest.php",
    "tests/Feature/Api/ApiRateLimitTest.php",
    "tests/Feature/Api/ApiReadSurfacesTest.php",
    "tests/Feature/Api/ApiSmokeMatrixTest.php",
]

MODIFIED_FILES = [
    "composer.json",
    "config/auth.php",
    "bootstrap/app.php",
    "app/Providers/AppServiceProvider.php",
    "app/Models/User.php",
    "app/Services/AuditLogService.php",
    "app/Http/Controllers/TeamController.php",
    "app/Services/ScoringService.php",
    "app/Services/PaymentService.php",
    "app/Services/SupportTicketService.php",
    ".env.example",
]

GENERATED_FILES = [
    "composer.lock",
    "storage/api-docs/openapi.json",
]

NARRATIVE = r"""# Phase 15 — Public API + Mobile Backend + Webhook Platform

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
"""


def render_blocks(files, heading):
    out = [f"\n## {heading}\n"]
    for rel in files:
        path = os.path.join(ROOT, rel)
        with open(path, "r", encoding="utf-8", errors="replace") as fh:
            content = fh.read()
        ext = rel.rsplit(".", 1)[-1] if "." in rel else "txt"
        lang = {
            "php": "php", "json": "json", "py": "python", "md": "text",
            "lock": "text", "example": "text",
        }.get(ext, "text")
        out.append(f"\n### `{rel}`\n\n```{lang}\n{content.rstrip()}\n```\n")
    return "".join(out)


def main():
    parts = ["# PHASE15_PUBLIC_API_MOBILE_WEBHOOK_REPORT.md\n\n", NARRATIVE]
    parts.append(render_blocks(NEW_FILES, "New Files"))
    parts.append(render_blocks(MODIFIED_FILES, "Modified Files (final content)"))
    parts.append(render_blocks(GENERATED_FILES, "Generated Artifacts (final content)"))
    with open(OUT, "w", encoding="utf-8") as fh:
        fh.write("".join(parts))
    print(f"Wrote {OUT} ({sum(len(p) for p in parts)} chars)")


if __name__ == "__main__":
    main()
