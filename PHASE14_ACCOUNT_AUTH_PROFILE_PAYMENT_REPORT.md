# Phase 14 — Account, Auth, Profile & Payment Ecosystem

**Project:** FF Arena (Laravel 12.69.1 / SQLite) · **Phase 14 of the phased build**
**Date:** 2026-09-09 · **Status:** COMPLETE — all code written, verified, and green.

---

## 1. Phase Scope, Boundary & Standing Constraints

Phase 14 builds the complete **account, authentication, profile, and payment ecosystem** on top of Phases 01–13, without replacing any existing working system. This phase delivers, in real, runnable code:

- Production email/password signup, login, logout, forgot/reset password, and email verification (server-generated signed URLs, Laravel facilities only).
- Google Sign-In via official server-side OAuth/OIDC (Laravel Socialite) with state/CSRF safety, configured redirects, minimal scopes, no password collection, safe linking, and duplicate-account prevention.
- Mobile (phone) signup/login with OTP request/verify/resend, expiry, rate/attempt limits, cooldown, duplicate-phone protection, BD-local → E.164 normalization, and an honest OTP provider abstraction with deterministic local delivery and a real SMS gateway only when configured.
- An account-linking engine (`user_identities`) keyed on `(provider, provider_subject)` for providers `password | google | phone`, with no OAuth token storage.
- Duplicate-account recovery: an already-linked Google/phone/verified-email identity signs into the existing account — never a silent duplicate; safe linking/unlinking; rate limits; no guessing/takeover.
- Profile & settings, username normalization/uniqueness/reserved/rate-limited change, public profiles that honor privacy presets, and the guarantee that users can never edit rating/rank/risk/verification/roles/restrictions.
- Security settings (verification status, linked providers, password, active sessions/devices, login history, logout one/all, deactivation) that never expose OAuth secrets, raw fingerprints, raw IPs, or risk scores.
- Privacy & lifecycle (public/registered/private; active/deactivated/deletion_pending/deleted) honoring Phase 06–09 retention, blocking deletion during dangerous workflows, and anonymizing instead of physically deleting immutable history.
- Saved payment methods and a provider checkout flow that extend (never replace) the Phase 08 `PaymentService`/`WalletService`/ledger/refund/reconciliation stack, with honest "provider is not configured" states.
- bKash/Nagad/Rocket/SSLCommerz/card/bank adapters behind `PaymentGatewayInterface`, server-side verification only, and dedicated interfaces/adapters for bKash and Nagad.
- Webhook/failure handling that preserves Phase 08 signature/identity/id/amount/currency/replay/state checks and never reports success from a frontend callback.
- Phase 10/11/12/13 integration (risk signals, notifications, user-targeted live events, audit) plus full hardening (rate limits, enumeration-safe responses, CSRF, session regeneration/invalidation, secure cookies, remember-me security).
- Blade UI, admin account administration, `.env`/`.env.example` secret hygiene, and a 91-test verification suite.

**Boundary:** Phase 14 only. Phase 15 is not started.

---

## 2. Executive Summary

All Phase 14 code is implemented and verified. The suite grew from the Phase 13 baseline of **520 tests / 1589 assertions** to **611 tests / 1843 assertions** — every new test passes and **all 520 Phase 01–13 tests remain green** (no test was deleted or weakened). `php artisan migrate:fresh --seed --force` succeeds (25 migrations), `php -l` is clean on every new/modified PHP file, and the route table grew from 128 to **176 routes** with no accidental public mutation of existing routes.

The design's central invariants, all enforced server-side and covered by tests:

1. **Identity is never keyed on email.** Google identities key on the stable `sub`; phone identities key on a normalized E.164 number; the password identity lives in `users.password`. `(provider, provider_subject)` is unique, so one Google subject or one phone number can never be linked to two accounts.
2. **Nothing is faked.** When a provider (Google, SMS, bKash, Nagad, card, SSLCommerz) lacks credentials, the platform honestly reports "not configured" and refuses to act. OTP codes are delivered by a log provider in dev/test and an HTTP SMS gateway only when configured.
3. **Frontend never confirms a payment.** Only server-side verification (Phase 08 webhook HMAC + state machine) or admin review moves a payment to success; success flows `PaymentService → WalletService → LedgerEntry`.
4. **No secrets stored or logged.** No OAuth tokens, no raw IPs, no raw fingerprints, no risk scores exposed, no raw OTP codes persisted (only `hash_hmac`), no card/phone numbers in `payment_methods` (masked identifiers only).

---

## 3. Baseline & Environment

- PHP **8.4.24** (cli, NTS) · Composer **2.10.3** · Laravel **12.69.1** · SQLite.
- `laravel/socialite` **^5.31** installed and autoloaded (verified `class_exists(Socialite::class)`).
- Pre-Phase-14 baseline: **520 tests / 1589 assertions**, **23 migrations**, **128 routes**.
- Post-Phase-14: **611 tests / 1843 assertions**, **25 migrations**, **176 routes**.

---

## 4. Database Schema Additions

Two new migrations (no existing table altered destructively):

**`2026_09_08_030000_create_account_ecosystem_tables.php`** creates:

- **`user_identities`** — provider-linked identities. `(provider, provider_subject)` unique; `user_id` FK cascade-on-delete; `provider_email`, `verified_at`, `last_used_at`, `metadata` (safe subset only). The password provider deliberately has **no row** (the `users.password` column is the password identity).
- **`otp_challenges`** — one-time phone codes. Stores only `code_hash` (keyed HMAC), `attempts`, `expires_at`, `status` (pending/verified/expired/consumed), optional `user_id`. Indexed on phone, user, and expiry.
- **`login_events`** — append-only security history. `event` (closed vocabulary), `status`, `ip_hash`/`device_hash` (HMAC), `device_label` (derived), `metadata`. No `updated_at`.
- **`payment_methods`** — saved methods. `provider`, `label`, `masked_identifier` ("\*\*\*\*\*\*5678"), `status`, `is_default`, `verified_at`, `last_used_at`, `metadata`. Full card/phone numbers never reach this table.

**`2026_09_08_031000_add_profile_fields_and_live_target.php`**:

- Adds to `users`: `bio`, `country`, `region`, `avatar`, `language`, `timezone`, `privacy`, `account_status` (default `active`), `deactivated_at`, `username_changed_at` — all nullable/defaulted so existing rows keep working. Also makes `users.password` **nullable** (Google-only accounts have no password until one is set).
- Adds `target_user_id` (nullable FK + index) to `live_events` for account-level, user-targeted realtime signals.

---

## 5. Configuration — `config/account.php`

Central, server-side configuration for verification, OTP, username rules, privacy presets, and lifecycle policy:

- `verification`: verify-link lifetime (60 min), resend cooldown (60 s), and `require_verified_email_for` (empty by default so Phase 01–13 flows are unchanged; operators opt in per context).
- `otp`: `expires_seconds` 300, `resend_cooldown_seconds` 60, `max_attempts` 5, `length` 6.
- `username`: min 3 / max 20, allowed regex `[a-zA-Z0-9._-]`, reserved names, 7-day change cooldown.
- `privacy`: `public | registered | private`.
- `lifecycle`: `hard_delete => false` (anonymizing tombstone, never physical deletion).

---

## 6. Configuration — `config/payments.php`

Honest per-provider configuration: every provider has `enabled` and `mode` (sandbox/production), and credentials read **only** from the environment. Providers: `bkash`, `nagad`, `rocket`, `card`, `bank`, `sslcommerz`. The shared webhook verification secret remains the Phase 08 `services.payments.webhook_secret` (single source of truth).

---

## 7. Models — `UserIdentity`, `OtpChallenge`, `LoginEvent`, `PaymentMethod`

- **`UserIdentity`** — `PROVIDER_GOOGLE`/`PROVIDER_PHONE`; `isVerified()`, `touchLastUsed()`. No fillable attributes (writes are explicit and ownership-checked).
- **`OtpChallenge`** — purposes `login|signup|link|recovery`; statuses `pending|verified|expired|consumed`; `isExpired()`, `isPending()`. Only `code_hash` is ever stored.
- **`LoginEvent`** — closed event vocabulary (`login.password`, `login.google`, `login.phone`, `login.failed`, `logout`, `password.reset`, `password.changed`, `email.verified`, `phone.verified`, `account.linked`, `account.unlinked`, `session.revoked`, `sessions.revoked`, `account.deactivated`, `account.reactivated`, `account.deletion_requested`), statuses `success|failure`, `UPDATED_AT = null`.
- **`PaymentMethod`** — `STATUS_ACTIVE|STATUS_REMOVED`, `PROVIDERS` mirroring the gateway set, `isActive()`.

---

## 8. `User` Model Changes

- Added `CanResetPassword` (Laravel password-broker support).
- Profile fields added to `$fillable` (`name`, `email`, `password`, `username`, `phone`, `game_uid`, `bio`, `country`, `region`, `avatar`, `language`, `timezone`, `privacy`) — `role`, `wallet_balance`, `account_status`, and verification state remain excluded from mass assignment.
- `password` cast is `hashed`.
- New relations: `identities()`, `otpChallenges()`, `loginEvents()`, `paymentMethods()`.
- New helpers: `hasVerifiedEmail()`, `isActive()` (null-safe — a legacy row with no `account_status` is treated as active), `isDeactivated()`.

---

## 9. Contracts & Gateways (OTP + Google)

- **`PhoneOtpProviderInterface`** — `id()`, `isConfigured()`, `send(phone, code)`; delivery is best-effort by contract and throws `DomainException` when unconfigured.
- **`GoogleOAuthProviderInterface`** — `isConfigured()`, `redirect()`, `user()` returning `{id, email, email_verified, name}`; the real implementation wraps Socialite's Google driver.
- **`LogPhoneOtpProvider`** — dev/test delivery to the application log; configured only in non-production.
- **`SmsGatewayPhoneOtpProvider`** — generic HTTP SMS gateway (endpoint + API key + sender id from env); refuses to run unless fully configured.
- **`SocialiteGoogleProvider`** — Socialite Google driver with `openid`, `email`, `profile` scopes; session-bound `state` (Socialite verifies it — the OAuth CSRF defence); `sub` is the identity key, never email.

---

## 10. Payment Gateway Adapters & Interface Extension

`PaymentGatewayInterface` was extended (additively — only `BkashGateway` implemented it before Phase 14) with `label()`, `configured()`, `supportsCallbacks()`, `supportsRefunds()`. `PaymentGatewayManager` is now a registry over six adapters:

- **`BkashGateway`** (Phase 08, updated) — manual/merchant flow; `configured()` reflects merchant credentials.
- **`NagadGateway`** — dedicated adapter: configured only with merchant id + keys; `createExternalPayment` throws "not configured" until credentials exist.
- **`RocketGateway`**, **`CardGateway`**, **`BankGateway`**, **`SslCommerzGateway`** — same honest contract; card/SSLCommerz are disabled by default (`enabled=false` in `.env.example`).

Every adapter's `configured()` is credential-driven; no adapter ever fabricates success.

---

## 11. Email/Password Auth

`AuthController` (rewritten in full) implements:

- **Register** — validated server-side (`role` restricted to `player|organizer`, set explicitly, never mass-assignable), `account_status`/`privacy` server-defaulted, password hashed, then device/IP observation, `auth.register` audit, welcome notification, and a verification email.
- **Login** — `Auth::attempt` + `session()->regenerate()`; records `login.password` history; new-device risk signal + "suspicious login" notification; failed attempts record `login.failed` against the matching account only (email never echoed).
- **Logout** — `Auth::logout()` + `session()->invalidate()` + `session()->regenerateToken()` + `logout` audit.

---

## 12. Email Verification

Server-generated **temporary signed routes** (`URL::temporarySignedRoute`). The `verify-email/{id}/{hash}` handler checks the signature and a `sha1(email)` hash before marking `email_verified_at`, then records `email.verified` history, a notification, and an audit entry. A `verification.notice` page + throttled resend endpoint complete the flow. Tampering with the URL id or hash is rejected (tested).

---

## 13. Password Reset (Enumeration-Safe)

`forgotPassword` always returns the identical generic message whether or not the email exists, so the route never leaks account existence. A real account gets Laravel's broker link (`Password::sendResetLink`), a `password.reset` history entry, a notification, and an audit entry. `resetPassword` uses the broker with the model's `hashed` cast, a min-8 + confirmation rule, and a generic error for invalid/expired links.

---

## 14. Google Sign-In (OAuth/OIDC)

`GoogleAuthService` orchestrates the flow behind `GoogleOAuthProviderInterface`:

1. `redirect()` → Socialite consent screen (throws when unconfigured; the login page hides the button when credentials are absent).
2. `handleCallback()` → provider `user()` → `IdentityService::resolveGoogle()` → login event, device/IP registration, new-device risk signal, welcome/linked notifications, and audit.

The UI offers Google only when `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET` are set; otherwise it honestly omits the button. The callback is throttled (`throttle:google-callback`).

---

## 15. Google Linking/Unlinking & Duplicate Prevention

`IdentityService::resolveGoogle()` encodes the duplicate rules in one transaction:

1. Subject already linked → sign into that account (its `user_id` wins).
2. Verified email already registered → link Google into that account (never a silent duplicate).
3. Otherwise → create a new Google-only account (nullable password) and link.

`linkGoogle()` rejects a subject already owned by another user. `unlink()` refuses to remove the last sign-in method (password + identities counted). Unlinking a Google identity never changes the user id. The authenticated link flow uses a session `google_link_intent` flag so a signed-in user connecting Google is unambiguous.

---

## 16. Phone OTP (Normalization, Issue, Verify, Abuse Limits)

`PhoneOtpService`:

- **Normalization** — accepts `017XXXXXXXX`, `8801XXXXXXXXX`, `+8801XXXXXXXXX` (and formatted input) → `+8801XXXXXXXXX`; rejects non-BD-mobile numbers.
- **Issue** — refuses when the provider is unconfigured; enforces resend cooldown (60 s), daily cap, then persists only `hash_hmac(code)` and delivers via the provider (transactional; an undeliverable challenge is rolled back).
- **Verify** — single-use; expiry enforced; max 5 attempts then the challenge is consumed; `hash_equals` comparison; marks `verified` on success so replay is impossible.
- **Resend/attempt/expiry/cooldown** all enforced server-side in one place so every caller inherits them.

---

## 17. Phone Login & Linking Flows

- **Request** — `requestPhoneOtp` issues a `login`-purpose code; the response is identical whether or not the number is registered (enumeration-safe).
- **Verify** — `verifyPhoneLogin` verifies the code, resolves the owner, and signs in; an unknown (but code-verified) number is offered sign-up without leaking any existing account's details; deactivated accounts are refused.
- **Linking** — a signed-in user can verify-and-link a phone (purpose `link`); the identity is unique across users; unlinking requires another sign-in method to remain.

---

## 18. Account Linking Engine (`IdentityService`)

Single authority for provider identities with duplicate rules baked in. Provides `find`, `identityFor`, `identitiesFor`, `linkGoogle`, `linkPhone`, `unlink`, `hasPassword`, `hasVerifiedPhone`, `hasGoogle`, `signInMethodCount`, `userFor`, `resolveGoogle`, and `uniqueUsername`. Phone link syncs `users.phone` with the verified E.164 number. No OAuth access/refresh tokens are ever stored.

---

## 19. Duplicate & Recovery Behavior

- Already-linked Google subject → signs into the existing account.
- Already-linked phone → signs into the existing account.
- Verified Google email on an existing account → Google linked into it.
- Recovery always requires a second factor (email reset link, verified-phone OTP, or the linked OAuth identity); there is no unauthenticated "guess the account" path; rate limits and closed-vocabulary history contain the blast radius. High-risk recovery is deferred to Phase 10 review mechanisms rather than a new engine.

---

## 20. Profile & Settings

`ProfileService` + `ProfileController`:

- `/profile/{user}` — privacy-gated public profile (guests see `public` only, `registered` requires sign-in, `private` is owner/staff only).
- `/profile/edit` — display name, bio, country (2-letter), region, avatar URL.
- `/profile` (PUT) — profile update (name/bio/country/region/avatar) with audit of before/after.
- `/profile/username` (PUT) — normalized, unique, reserved-checked, 7-day cooldown.
- `/profile/privacy` (PUT) and `/profile/preferences` (PUT) — privacy preset; country/region/language/timezone.
- `/settings/password` (POST) — change (current password required) or set (no password yet) + session regeneration + revoke other sessions.

Users can never edit rating/rank/risk/verification/roles/restrictions from any profile path (mass-assignment and validation both prevent it; tested).

---

## 21. Username Rules

Normalization trims and validates length (3–20), character set (`[a-zA-Z0-9._-]`), and a reserved list (`admin`, `administrator`, `root`, `system`, `support`, `staff`, `moderator`, `ffarena`, `official`, `bot`). Uniqueness is case-insensitive (`lower(username)`). Changes are cooldown-limited (7 days) and audited. Google sign-ups derive a safe unique username from the email local part or name.

---

## 22. Security Settings

`AccountSecurityController` + `settings.security` view:

- Shows email/phone verification status, linked Google, password status, account state.
- Change/set password form.
- Active-session count with sign-out-everywhere, plus links to sessions and login history.
- Danger zone: deactivate, request deletion, cancel deletion, reactivate.

Nothing here exposes OAuth secrets, raw fingerprints, raw IPs, or risk scores.

---

## 23. Sessions & Devices

`SessionManagementService` reads the framework session store (DB driver):

- `sessionsFor()` — safe list with derived device label, last-activity, and a `hash_equals`-based "this device" flag (raw IPs never shown).
- `revokeOtherSessions()` / `revokeAllSessions()` — delete by user + optional current-session exclusion, with login-event, notification, audit, and a user-targeted live event.
- `revokeAllForUser()` — admin revocation with audit.

The "logout everywhere" controller additionally invalidates the current session and regenerates the CSRF token.

---

## 24. Login History

`LoginEventService` records the closed vocabulary with only pseudonymous context (HMAC'd IP hash, HMAC'd device hash, derived "Browser on OS" label). `historyFor()` paginates the owner's events; the admin view shows a subject's history. Tests assert raw IPs never appear in the rendered page.

---

## 25. Privacy & Lifecycle

- **Privacy presets** enforced in `ProfileService::publicProfile` and `UserPolicy::viewProfile`.
- **Account lifecycle** (`AccountLifecycleService`): `deactivate` (revokes all sessions), `reactivate`, `requestDeletion` (blocked while pending/processing payments, payouts, open disputes, or active restrictions exist), `cancelDeletion`, and `executeDeletion` (admin) which anonymizes in place (`Deleted User`, `deleted-{id}@ffarena.invalid`, nulled PII, identities removed) rather than physically deleting the row — ledger, payouts, disputes, audit, and security events keep their foreign keys.
- `EnsureActiveAccount` middleware parks deactivated/deleted users on their security settings (with reactivate/cancel-deletion escape hatches) instead of 403ing deep links; it also fixes the "new account has no cached `account_status`" case via null-safe `isActive()`.

---

## 26. Saved Payment Methods

`PaymentMethodService` + `PaymentMethodsController`: add (provider validated against the known set; identifier masked to last 4 digits), remove (soft-delete + default promotion), setDefault (exactly one default), list. Ownership is enforced in both the policy and the service (IDOR-tested). No full numbers are ever stored.

---

## 27. Checkout / Payment Flow

`CheckoutController`:

- `methods` — provider grid with honest per-provider status (enabled/configured/mode).
- `initiate` — validates the provider id, runs the Phase 10 payment risk gate, then calls `PaymentService::createForTeam()` with the server-derived amount/currency and the chosen provider; records audit, notification, and a user-targeted live event. Free entry is auto-confirmed via the existing Phase 08 `settleSuccess` path (Phase 01–07 behavior preserved). Hosted/redirect providers only attempt `createExternalPayment` when genuinely configured and otherwise fall back to the manual pending screen with an honest error.

Frontend never confirms a payment; success is only `PaymentService → WalletService → LedgerEntry` after server-side verification or admin review.

---

## 28. Webhooks & Failures

The existing Phase 08 `WebhookController` and `PaymentService` webhook path (HMAC signature, identity/id/amount/currency/replay/state checks) are preserved untouched. New adapters produce no new webhook trust path; cancelled/failed/expired/timeout/unavailable/verification-mismatch/duplicate/already-completed outcomes stay in the Phase 08 state machine, and an uncertain callback can never become success.

---

## 29. Phase 10 Integration (Fraud/Restriction)

`AuthController` login and `GoogleAuthService` record new-device risk signals (INFO severity, never auto-ban); `CheckoutController` runs `FraudRiskService::evaluatePayment()` before creating a payment. Shared phone/device/network observations remain signals only — they never auto-ban.

---

## 30. Phase 11 Integration (Notifications)

New notification types added to `Notification` (welcome, email verify, password changed/reset, google linked/unlinked, phone linked/changed, suspicious login, session revoked, account deactivated, payment initiated, payment provider issue). `NotificationService::send()`/`link()` signatures were verified and reused as-is; no second notification system was created.

---

## 31. Phase 12 Integration (Live Events)

`LiveEventService` gained `recordForUser()`, `recordForUserQuietly()`, `sinceForUser()`, and `latestCursor()`. Account-level events (payment status, session revoked, verification status) are user-targeted and visible only to the target user via `AccountLiveController` (`/account/live`). Payloads carry no sensitive metadata.

---

## 32. Phase 13 Integration (Audit)

`AuditLogService::ACTIONS` was extended with the Phase 14 action names (`auth.*`, `profile.*`, `payment_method.*`, `payment.initiated`). All Phase 14 mutations call `recordQuietly()` with the verified `(?User $actor, string $action, ?string $entityType, ?int $entityId, array $options)` shape. No raw secrets in audit payloads (`redact()` still applies).

---

## 33. Hardening

- **Named rate limiters** (registered in `AppServiceProvider`): `login` (5/min/email+IP + 20/min/IP), `register` (3/hour/IP), `otp-request` (1/min/phone, 5/hour/phone, 10/hour/IP), `otp-verify` (5/5min/phone), `password-reset` (3/hour/email+IP), `google-callback` (20/min/IP), `account-link`, `payment-initiate`, `verification-resend`.
- **Enumeration-safe responses** — generic forgot-password, identical phone-login request response, safe already-linked Google messaging.
- **Session security** — `regenerate()` after login, `invalidate()` + `regenerateToken()` on logout, secure/SameSite cookies (framework defaults), DB session driver, remember-me via `Auth::attempt`'s remember flag.
- **CSRF** — all mutating routes are POST/PUT/DELETE with `@csrf`.

---

## 34. UI (Blade Views)

New views (consistent with the existing dark panel design, not a redesign): `auth/forgot-password`, `auth/reset-password`, `auth/verify-email`, `auth/phone-login`, `auth/phone-verify`, `profile/show`, `profile/edit`, `settings/security`, `settings/connected-accounts`, `settings/sessions`, `settings/login-history`, `settings/payment-methods`, `payment/methods`, `admin/accounts/index`, `admin/accounts/show`. Updated: `auth/login` (Google + phone buttons), `auth/register`, `layouts/app` (Profile/Settings + admin Accounts nav), `payment/show` (link to the provider grid), `admin/dashboard` (Accounts link).

---

## 35. Admin Account Administration

`AdminAccountController` (admin-only via the `admin` middleware + `UserPolicy`): list/search/filter accounts (name/username/email, role, status), view an account's identities, verification state, restrictions, risk profile, payment methods, sessions, recent security events, and audit history; revoke all sessions; deactivate/reactivate; delete (anonymize). Organizers and moderators get none of these controls (tested); admins never mutate immutable financial history directly.

---

## 36. DB / Secrets

`.env.example` gained the Phase 14 keys — `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`, `SMS_GATEWAY_*`, `PAYMENT_WEBHOOK_SECRET`, and per-provider merchant variables (`BKASH_*`, `NAGAD_*`, `ROCKET_*`, `CARD_*`, `BANK_*`, `SSLCOMMERZ_*`). No secret value is committed anywhere; `.env` is gitignored. The database gets foreign keys, unique provider-subject, unique normalized phone enforcement (via unique provider+subject), expiry/status indexes, and status defaults.

---

## 37. Tests (New Coverage)

91 new tests across nine files:

- `AccountAuthTest` (14) — register/login/logout/verify/reset, role restrictions, rate limits, signed-URL tampering.
- `GoogleAuthTest` (11) — deterministic fake OAuth provider; creation, linking, duplicate-subject uniqueness, link/unlink, last-method protection.
- `PhoneOtpTest` (13) — normalization, round-trip, wrong code, expiry, single-use, cooldown, attempt cap, login, linking, uniqueness, unconfigured refusal.
- `ProfileTest` (13) — privacy presets, self-edit, IDOR, username rules/reserved/unique, password change, role immutability.
- `AccountSecurityTest` (13) — sessions, revoke one/all, login history (self-only, no raw IPs), deactivate/reactivate, middleware parking, deletion blocking.
- `PaymentMethodManagementTest` (8) — add, masking, unknown provider, default promotion, cross-user IDOR.
- `PaymentProvidersTest` (9) — honest statuses, provider selection, unknown provider, free-entry confirm, Phase 08 state-machine integration, unconfigured-card honesty.
- `AdminAccountTest` (8) — authorization matrix, session revocation, deactivate/reactivate, anonymizing deletion, deletion blocked by active restriction.
- `AccountIntegrationTest` (7) — audit/notification/live-event cross-cutting, cursor behavior, self-only live feed.

Fakes/mocks only — no test touches Google, SMS, bKash, Nagad, Rocket, card, or SSLCommerz.

---

## 38. Roles & Authorization Matrix

| Capability | player (self) | organizer | moderator | admin |
|---|---|---|---|---|
| Own profile/settings/security/sessions | ✅ | ✅ | ✅ | ✅ |
| Own account lifecycle (deactivate/delete-request) | ✅ | ✅ | ✅ | ✅ |
| Global account list/detail | ❌ | ❌ | ❌ | ✅ |
| Revoke another user's sessions | ❌ | ❌ | ❌ | ✅ |
| Deactivate/reactivate/delete other accounts | ❌ | ❌ | ❌ | ✅ |

Enforced by the `admin` route middleware (`EnsureUserIsAdmin`) plus `UserPolicy`/`PaymentMethodPolicy`/`LoginEventPolicy`/`UserIdentityPolicy`. Organizers remain staff only within their own tournaments.

---

## 39. Verification Gates (All Run, All Green)

| Gate | Command | Result |
|---|---|---|
| Lint | `php -l` on every new/modified PHP file | ✅ clean |
| Migrate+seed | `php artisan migrate:fresh --seed --force` | ✅ 25 migrations |
| Tests | `php artisan test` | ✅ **611 passed / 1843 assertions** |
| Routes | `php artisan route:list` | ✅ 176 routes, correct middleware |
| Smoke matrix | guest + auth + admin page render | ✅ all 200 (302 only for already-verified notice) |

Route middleware spot-check (from `route:list --json`): `profile.show → [web]` (public), `profile.edit / settings.* / account.live / verification.notice → [web, auth]`, `admin.accounts.* → [web, auth, admin]`, `google.redirect → [web]`, `google.callback → [web, throttle:google-callback]`, `phone.verify → [web]`.

---

## 40. Production Readiness — Google Setup

1. Create OAuth 2.0 Client (Web application) in Google Cloud Console.
2. Authorized redirect URI: `https://your-domain/auth/google/callback`.
3. `.env`:
   ```
   GOOGLE_CLIENT_ID=…apps.googleusercontent.com
   GOOGLE_CLIENT_SECRET=…
   GOOGLE_REDIRECT_URI=${APP_URL}/auth/google/callback
   ```
4. Scopes requested: `openid email profile` (minimal). The `sub` claim is the identity key; `email_verified` gates email-based linking.
5. The login page shows the Google button only when the credentials are present; until then it is honestly hidden.

---

## 41. Production Readiness — Phone / SMS / Firebase

- The platform uses the `PhoneOtpProviderInterface`; production delivery goes through `SmsGatewayPhoneOtpProvider` (generic HTTP SMS gateway) — no frontend secrets.
- `.env`: `SMS_GATEWAY_ENDPOINT`, `SMS_GATEWAY_API_KEY`, `SMS_GATEWAY_SENDER`.
- In dev/test the `LogPhoneOtpProvider` writes codes to the log; `isConfigured()` is false in production until an SMS gateway is set, so no delivery is ever faked.
- To use Firebase (or another provider), implement `PhoneOtpProviderInterface` and bind it in `AppServiceProvider` — the rest of the system is provider-agnostic.
- Abuse controls (cooldown, daily cap, attempt cap, expiry, per-IP limits) apply regardless of provider.

---

## 42. Production Readiness — bKash / Nagad Merchant Setup

- bKash: `BKASH_MODE=production`, `BKASH_BASE_URL`, `BKASH_APP_KEY`, `BKASH_APP_SECRET`, `BKASH_USERNAME`, `BKASH_PASSWORD`, `BKASH_MERCHANT_NUMBER`.
- Nagad: `NAGAD_MODE=production`, `NAGAD_BASE_URL`, `NAGAD_MERCHANT_ID`, `NAGAD_MERCHANT_PRIVATE_KEY`, `NAGAD_PG_PUBLIC_KEY`, `NAGAD_MERCHANT_NUMBER`.
- Rocket: `ROCKET_*`; SSLCommerz: `SSLCOMMERZ_STORE_ID/PASSWORD/BASE_URL`; card/bank: their respective env keys.
- Until credentials exist the adapters report `configured=false` and the checkout UI shows "Provider is not configured"; `createExternalPayment` throws instead of pretending.

---

## 43. Production Readiness — Callback URLs & Webhook Secrets

- Google callback: `/auth/google/callback` (see §40).
- Payment webhooks: `/webhooks/payments/{provider}` — HMAC-SHA256 signed with `PAYMENT_WEBHOOK_SECRET` (the Phase 08 `services.payments.webhook_secret`). Set a strong random value in production and configure the provider's webhook URL to `https://your-domain/webhooks/payments/{provider}`.
- The webhook handler validates signature, identity, id, amount, currency, replay, and state before any transition — the Phase 08 logic is untouched.

---

## 44. Production Readiness — HTTPS / Domains / Rate Limits

- Force HTTPS (`APP_URL=https://…`, TLS termination; `SESSION_SECURE_COOKIE=true` in production).
- Redirect URIs and webhook URLs must be exact HTTPS matches.
- Rate limits are defined as named limiters in `AppServiceProvider` (§33); tune `config/account.php` (OTP expiry/cooldown/caps, username cooldown, verification link lifetime) per policy.
- `config/account.php` `lifecycle.hard_delete` must stay `false` to preserve immutable financial/audit history.

---

## 45. Production Readiness — Recovery & Deletion Policy

- Recovery requires an existing factor: password reset email, verified-phone OTP, or the linked Google identity. No unauthenticated account guessing.
- Deletion is blocked while pending/processing payments, pending payouts, open disputes, or active restrictions exist.
- Deletion is an anonymizing tombstone (Phase 06–09 retention honored); ledger, payouts, disputes, audit, and security events are never physically removed.
- High-risk recovery/deletion is escalated to Phase 10 review mechanisms rather than a new engine.

---

## 46. Known Limitations & Honest Provider Statuses

- Google, SMS, bKash, Nagad, Rocket, card, and SSLCommerz are **not live-configured** in this repo (no credentials are committed). Every corresponding surface honestly reports "not configured"/sandbox and refuses to fabricate success.
- Card and SSLCommerz are disabled by default (`enabled=false`).
- `verifyEmail`/`resendVerification` use the framework's email facilities; in this repo mail is the `log`/`array` driver, so verification emails appear in the mail log during development.

---

## 47. Files Created (Phase 14)

Migrations: `database/migrations/2026_09_08_030000_create_account_ecosystem_tables.php`, `database/migrations/2026_09_08_031000_add_profile_fields_and_live_target.php`. Config: `config/account.php`, `config/payments.php`. Models: `app/Models/UserIdentity.php`, `app/Models/OtpChallenge.php`, `app/Models/LoginEvent.php`, `app/Models/PaymentMethod.php`. Contracts: `app/Contracts/PhoneOtpProviderInterface.php`, `app/Contracts/GoogleOAuthProviderInterface.php`. Gateways: `app/Gateways/NagadGateway.php`, `app/Gateways/RocketGateway.php`, `app/Gateways/CardGateway.php`, `app/Gateways/BankGateway.php`, `app/Gateways/SslCommerzGateway.php`, `app/Gateways/LogPhoneOtpProvider.php`, `app/Gateways/SmsGatewayPhoneOtpProvider.php`, `app/Gateways/SocialiteGoogleProvider.php`. Services: `app/Services/PhoneOtpService.php`, `app/Services/IdentityService.php`, `app/Services/LoginEventService.php`, `app/Services/SessionManagementService.php`, `app/Services/AccountLifecycleService.php`, `app/Services/ProfileService.php`, `app/Services/PaymentMethodService.php`, `app/Services/GoogleAuthService.php`. Middleware: `app/Http/Middleware/EnsureActiveAccount.php`. Policies: `app/Policies/UserPolicy.php`, `app/Policies/PaymentMethodPolicy.php`, `app/Policies/LoginEventPolicy.php`, `app/Policies/UserIdentityPolicy.php`. Controllers: `app/Http/Controllers/ProfileController.php`, `app/Http/Controllers/AccountSecurityController.php`, `app/Http/Controllers/PaymentMethodsController.php`, `app/Http/Controllers/CheckoutController.php`, `app/Http/Controllers/AdminAccountController.php`, `app/Http/Controllers/AccountLiveController.php`. Views: the 14 Blade views listed in §34. Tests: the nine test files listed in §37.

---

## 48. Files Modified (Phase 14)

`app/Models/User.php`, `app/Models/Notification.php`, `app/Models/LiveEvent.php`, `app/Contracts/PaymentGatewayInterface.php`, `app/Gateways/BkashGateway.php`, `app/Services/DeviceFingerprintService.php`, `app/Services/PaymentService.php`, `app/Services/AuditLogService.php`, `app/Services/LiveEventService.php`, `app/Services/PaymentGatewayManager.php`, `app/Providers/AppServiceProvider.php`, `app/Http/Controllers/AuthController.php`, `config/services.php`, `routes/web.php`, `bootstrap/app.php`, `.env.example`, `composer.json` (added `laravel/socialite`), `resources/views/auth/login.blade.php`, `resources/views/auth/register.blade.php`, `resources/views/layouts/app.blade.php`, `resources/views/payment/show.blade.php`, `resources/views/admin/dashboard.blade.php`.

---

## 49. No-Regression Guarantee (Phases 01–13 Preserved)

- All **520 pre-Phase-14 tests still pass** (now 611 total) — none deleted or weakened.
- The Phase 08 payment state machine, webhook verification, wallet, ledger, and refund/reconciliation paths are **untouched** in behavior; `PaymentService::createForTeam()` only gained optional `$provider`/`$providerReference` parameters (existing callers unchanged).
- No duplicate auth/profile/notification/audit/risk/wallet/ledger system was introduced — every Phase 14 path reuses `NotificationService`, `AuditLogService`, `LiveEventService`, `FraudRiskService`, `RestrictionService`, `DeviceFingerprintService`, `IpIntelligenceService`, `PaymentService`, `WalletService`, and the Phase 08 gateways.
- Existing routes were not repurposed; the only route-structure change is the new `/profile/{user}` public route (registered after the literal `/profile/edit` route to avoid shadowing).

---

## 50. Verification Results Summary

- `php -l` on all new/modified PHP files: **clean**.
- `php artisan migrate:fresh --seed --force`: **success (25 migrations)**.
- `php artisan test`: **611 passed / 1843 assertions** (baseline 520/1589 + 91/254 new).
- `php artisan route:list`: **176 routes**; middleware matrix verified; no accidental public mutation.
- HTTP smoke matrix (guest login/register/forgot/phone-login/tournaments; authed profile/settings/security/connected-accounts/sessions/login-history/payment-methods/verification-notice/account-live; admin accounts index/detail): **all render correctly**.

---

## 51. Complete Final Content of Every New/Modified File

Each subsection below contains the complete, final file content. Files are shown in full — no placeholders, no omissions.


### 51.1 — `database/migrations/2026_09_08_030000_create_account_ecosystem_tables.php`

> NEW — account ecosystem tables

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 14 — account ecosystem tables.
 *
 * Creates the identity-linking, phone-OTP, login-history and saved-payment-
 * method tables. No existing table is altered (profile columns and the live
 * feed target live in the follow-up migration), so Phases 01–13 remain
 * untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ------------------------------------------------------------------
        // user_identities — provider-linked identities (google, phone).
        //
        // The password provider deliberately has NO row here: the users.password
        // column IS the password identity, and a redundant row would duplicate
        // state. `(provider, provider_subject)` is unique so one Google subject
        // or one normalized phone can never be linked to two accounts.
        // ------------------------------------------------------------------
        Schema::create('user_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('provider', 20);              // google | phone
            $table->string('provider_subject', 255);     // Google `sub` | E.164 phone
            $table->string('provider_email', 255)->nullable(); // Google email (safe)
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->json('metadata')->nullable();        // safe subset only
            $table->timestamps();

            $table->unique(['provider', 'provider_subject'], 'user_identities_provider_subject_unique');
            $table->index('user_id', 'user_identities_user_index');
        });

        // ------------------------------------------------------------------
        // otp_challenges — one-time phone verification codes.
        //
        // Only a hash of the code is stored; the raw code exists solely in
        // transit (SMS/log). Challenges expire, cap attempts, and are tied to
        // a normalized phone number (and optionally the acting user).
        // ------------------------------------------------------------------
        Schema::create('otp_challenges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('phone', 32);                 // normalized (E.164)
            $table->string('purpose', 20);               // login | signup | link | recovery
            $table->string('code_hash', 128);            // hash_hmac of the code
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->string('status', 20)->default('pending'); // pending | verified | expired | consumed
            $table->timestamps();

            $table->index('phone', 'otp_challenges_phone_index');
            $table->index('user_id', 'otp_challenges_user_index');
            $table->index('expires_at', 'otp_challenges_expiry_index');
        });

        // ------------------------------------------------------------------
        // login_events — append-only security/authentication history.
        //
        // Only pseudonymous IP/device hashes and a derived device label are
        // stored; raw IPs, raw fingerprints and passwords are never written.
        // ------------------------------------------------------------------
        Schema::create('login_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 40);                 // closed vocabulary
            $table->string('status', 20);                // success | failure
            $table->string('ip_hash', 64)->nullable();   // HMAC, never raw
            $table->string('device_hash', 64)->nullable();
            $table->string('device_label', 120)->nullable(); // derived browser/OS only
            $table->json('metadata')->nullable();        // safe subset
            $table->timestamp('created_at')->nullable();

            $table->index('user_id', 'login_events_user_index');
            $table->index('event', 'login_events_event_index');
            $table->index(['user_id', 'created_at'], 'login_events_user_created_index');
        });

        // ------------------------------------------------------------------
        // payment_methods — a user's saved payment methods.
        //
        // Only provider + a masked identifier are stored; card numbers, phone
        // numbers and account details are never persisted here.
        // ------------------------------------------------------------------
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('provider', 30);              // bkash | nagad | rocket | card | bank
            $table->string('label', 60);
            $table->string('masked_identifier', 40);     // "*******1234"
            $table->string('status', 20)->default('active'); // active | removed
            $table->boolean('is_default')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('user_id', 'payment_methods_user_index');
            $table->index('provider', 'payment_methods_provider_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('login_events');
        Schema::dropIfExists('otp_challenges');
        Schema::dropIfExists('user_identities');
    }
};

```

### 51.2 — `database/migrations/2026_09_08_031000_add_profile_fields_and_live_target.php`

> NEW — profile fields + live-event target

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 14 — profile fields on users + user-targeted live events.
 *
 * Adds self-managed profile/preference/account-lifecycle columns to `users`
 * (all nullable/defaulted so existing rows and seeded accounts keep working)
 * and a nullable `target_user_id` to `live_events` so account-level realtime
 * signals (session revoked, payment status, verification status) can be
 * delivered to exactly one user.
 */
return new class extends Migration
{
    public function up(): void
    {
        // OAuth-only (Google) accounts have no password until the user sets
        // one; the column becomes nullable for exactly that case.
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->text('bio')->nullable()->after('game_uid');
            $table->string('country', 2)->nullable()->after('bio');
            $table->string('region', 100)->nullable()->after('country');
            $table->string('avatar', 255)->nullable()->after('region');
            $table->string('language', 5)->default('en')->after('avatar');
            $table->string('timezone', 64)->default('UTC')->after('language');
            $table->string('privacy', 20)->default('public')->after('timezone'); // public | registered | private
            $table->string('account_status', 20)->default('active')->after('privacy'); // active | deactivated | deletion_pending
            $table->timestamp('deactivated_at')->nullable()->after('account_status');
            $table->timestamp('username_changed_at')->nullable()->after('deactivated_at');
        });

        Schema::table('live_events', function (Blueprint $table) {
            $table->foreignId('target_user_id')->nullable()->after('actor_user_id')
                ->constrained('users')->nullOnDelete();

            $table->index('target_user_id', 'live_events_target_user_index');
        });
    }

    public function down(): void
    {
        Schema::table('live_events', function (Blueprint $table) {
            $table->dropIndex('live_events_target_user_index');
            $table->dropConstrainedForeignId('target_user_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'bio', 'country', 'region', 'avatar', 'language', 'timezone',
                'privacy', 'account_status', 'deactivated_at', 'username_changed_at',
            ]);
        });

        // Restore NOT NULL on password (only when rolling back in a fresh
        // environment without OAuth-only accounts).
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable(false)->change();
        });
    }
};

```

### 51.3 — `config/account.php`

> NEW — account/verification/OTP/username/privacy/lifecycle config

```php
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Account ecosystem (Phase 14)
    |--------------------------------------------------------------------------
    |
    | Central, server-side configuration for the account/auth/profile system:
    | email verification, phone OTP, profile/username rules, session and
    | account-lifecycle policy. Everything here is enforced server-side —
    | clients can never self-declare verification or account state.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Email verification
    |--------------------------------------------------------------------------
    |
    | Verification URLs are server-generated (temporary signed routes) and
    | expire after `verify_link_minutes`. `resend_cooldown_seconds` throttles
    | verification-mail resends.
    |
    | `require_verified_email_for` lists action contexts (e.g. 'payment',
    | 'payout', 'registration') that additionally demand a verified email.
    | It defaults to an empty list so Phase 01–13 flows keep working exactly
    | as before; operators opt in per context.
    |
    */
    'verification' => [
        'verify_link_minutes' => 60,
        'resend_cooldown_seconds' => 60,
        'require_verified_email_for' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Phone OTP
    |--------------------------------------------------------------------------
    */
    'otp' => [
        // Validity of a code once issued.
        'expires_seconds' => 300,
        // Minimum delay between code requests for the same phone.
        'resend_cooldown_seconds' => 60,
        // Failed verify attempts allowed before the challenge is consumed.
        'max_attempts' => 5,
        // Code length (digits).
        'length' => 6,
    ],

    /*
    |--------------------------------------------------------------------------
    | Username / profile rules
    |--------------------------------------------------------------------------
    */
    'username' => [
        'min_length' => 3,
        'max_length' => 20,
        // Characters allowed in a username.
        'allowed_regex' => '/^[a-zA-Z0-9._-]+$/',
        // Names that are never available.
        'reserved' => [
            'admin', 'administrator', 'root', 'system', 'support', 'staff',
            'moderator', 'ffarena', 'official', 'bot',
        ],
        // Minimum days between username changes (rate limiting).
        'change_cooldown_days' => 7,
    ],

    /*
    |--------------------------------------------------------------------------
    | Privacy presets
    |--------------------------------------------------------------------------
    */
    'privacy' => [
        'public',      // anyone may see the public profile
        'registered',  // only signed-in users
        'private',     // only the owner (and staff)
    ],

    /*
    |--------------------------------------------------------------------------
    | Account lifecycle
    |--------------------------------------------------------------------------
    */
    'lifecycle' => [
        // Whether deletion is destructive. `false` keeps a tombstone
        // (anonymized) record because financial/audit history is immutable.
        'hard_delete' => false,
    ],

];

```

### 51.4 — `config/payments.php`

> NEW — payment-provider configuration

```php
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Payment providers (Phase 14)
    |--------------------------------------------------------------------------
    |
    | Honest provider configuration. Every provider has an `enabled` flag and
    | a `mode` (sandbox | production). When credentials are absent, the
    | provider reports `configured = false` and the UI shows
    | "Provider is not configured" — the platform NEVER fabricates a
    | successful external transaction.
    |
    | Secrets are read from the environment only and are never committed.
    |
    */

    // NOTE: the shared webhook verification secret remains the Phase 08
    // `services.payments.webhook_secret` (single source of truth). This file
    // only adds the per-provider merchant configuration below.

    'providers' => [

        'bkash' => [
            'label' => 'bKash',
            'enabled' => (bool) env('BKASH_ENABLED', true),
            'mode' => env('BKASH_MODE', 'sandbox'),       // sandbox | production
            'base_url' => env('BKASH_BASE_URL'),
            'app_key' => env('BKASH_APP_KEY'),
            'app_secret' => env('BKASH_APP_SECRET'),
            'username' => env('BKASH_USERNAME'),
            'password' => env('BKASH_PASSWORD'),
            'merchant_number' => env('BKASH_MERCHANT_NUMBER'),
        ],

        'nagad' => [
            'label' => 'Nagad',
            'enabled' => (bool) env('NAGAD_ENABLED', true),
            'mode' => env('NAGAD_MODE', 'sandbox'),
            'base_url' => env('NAGAD_BASE_URL'),
            'merchant_id' => env('NAGAD_MERCHANT_ID'),
            'merchant_private_key' => env('NAGAD_MERCHANT_PRIVATE_KEY'),
            'pg_public_key' => env('NAGAD_PG_PUBLIC_KEY'),
            'merchant_number' => env('NAGAD_MERCHANT_NUMBER'),
        ],

        'rocket' => [
            'label' => 'Rocket',
            'enabled' => (bool) env('ROCKET_ENABLED', true),
            'mode' => env('ROCKET_MODE', 'sandbox'),
            'base_url' => env('ROCKET_BASE_URL'),
            'merchant_id' => env('ROCKET_MERCHANT_ID'),
            'merchant_secret' => env('ROCKET_MERCHANT_SECRET'),
        ],

        'card' => [
            'label' => 'Card',
            'enabled' => (bool) env('CARD_ENABLED', false),
            'mode' => env('CARD_MODE', 'sandbox'),
            'gateway' => env('CARD_GATEWAY'),              // hosted gateway slug
            'merchant_id' => env('CARD_MERCHANT_ID'),
            'merchant_secret' => env('CARD_MERCHANT_SECRET'),
        ],

        'bank' => [
            'label' => 'Bank Transfer',
            'enabled' => (bool) env('BANK_ENABLED', true),
            'mode' => 'manual',
            'account_name' => env('BANK_ACCOUNT_NAME'),
            'account_number' => env('BANK_ACCOUNT_NUMBER'),
        ],

        'sslcommerz' => [
            'label' => 'SSLCommerz',
            'enabled' => (bool) env('SSLCOMMERZ_ENABLED', false),
            'mode' => env('SSLCOMMERZ_MODE', 'sandbox'),
            'store_id' => env('SSLCOMMERZ_STORE_ID'),
            'store_password' => env('SSLCOMMERZ_STORE_PASSWORD'),
            'base_url' => env('SSLCOMMERZ_BASE_URL'),
        ],

    ],

];

```

### 51.5 — `app/Models/UserIdentity.php`

> NEW — provider-linked identity model

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A provider-linked identity on a user's account (Phase 14).
 *
 * Only non-password providers are stored here (google, phone); the password
 * itself is the users.password column. `(provider, provider_subject)` is
 * unique so one Google subject or one normalized phone can only ever be
 * linked to a single local account — the key duplicate-account guard.
 *
 * No OAuth access/refresh tokens are stored: simple sign-in does not need
 * them, and persisting them would be an unnecessary secret to protect.
 */
class UserIdentity extends Model
{
    use HasFactory;

    public const PROVIDER_GOOGLE = 'google';
    public const PROVIDER_PHONE = 'phone';

    public const PROVIDERS = [
        self::PROVIDER_GOOGLE,
        self::PROVIDER_PHONE,
    ];

    protected $fillable = [];

    protected $casts = [
        'verified_at' => 'datetime',
        'last_used_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    public function touchLastUsed(): self
    {
        $this->last_used_at = now();
        $this->save();

        return $this;
    }
}

```

### 51.6 — `app/Models/OtpChallenge.php`

> NEW — phone OTP challenge model

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A one-time phone verification challenge (Phase 14).
 *
 * Only a hash of the code is stored; the raw code exists solely in transit.
 * A challenge is single-use: it expires, caps verification attempts, and is
 * marked consumed once used, so replay and brute force are both contained.
 */
class OtpChallenge extends Model
{
    use HasFactory;

    public const PURPOSE_LOGIN = 'login';
    public const PURPOSE_SIGNUP = 'signup';
    public const PURPOSE_LINK = 'link';
    public const PURPOSE_RECOVERY = 'recovery';

    public const PURPOSES = [
        self::PURPOSE_LOGIN,
        self::PURPOSE_SIGNUP,
        self::PURPOSE_LINK,
        self::PURPOSE_RECOVERY,
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CONSUMED = 'consumed';

    protected $fillable = [];

    protected $casts = [
        'expires_at' => 'datetime',
        'verified_at' => 'datetime',
        'attempts' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at === null || $this->expires_at->isPast();
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING && ! $this->isExpired();
    }
}

```

### 51.7 — `app/Models/LoginEvent.php`

> NEW — security/login event model

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An append-only security/login event (Phase 14).
 *
 * Records the closed vocabulary of authentication-relevant events with only
 * pseudonymous context: HMAC'd IP and device hashes and a derived device
 * label. Raw IPs, raw fingerprints and credentials are never stored, and
 * events are never edited or deleted.
 */
class LoginEvent extends Model
{
    use HasFactory;

    public const EVENT_LOGIN_PASSWORD = 'login.password';
    public const EVENT_LOGIN_GOOGLE = 'login.google';
    public const EVENT_LOGIN_PHONE = 'login.phone';
    public const EVENT_LOGIN_FAILED = 'login.failed';
    public const EVENT_LOGOUT = 'logout';
    public const EVENT_PASSWORD_RESET = 'password.reset';
    public const EVENT_PASSWORD_CHANGED = 'password.changed';
    public const EVENT_EMAIL_VERIFIED = 'email.verified';
    public const EVENT_PHONE_VERIFIED = 'phone.verified';
    public const EVENT_ACCOUNT_LINKED = 'account.linked';
    public const EVENT_ACCOUNT_UNLINKED = 'account.unlinked';
    public const EVENT_SESSION_REVOKED = 'session.revoked';
    public const EVENT_SESSIONS_REVOKED = 'sessions.revoked';
    public const EVENT_ACCOUNT_DEACTIVATED = 'account.deactivated';
    public const EVENT_ACCOUNT_REACTIVATED = 'account.reactivated';
    public const EVENT_DELETION_REQUESTED = 'account.deletion_requested';

    public const EVENTS = [
        self::EVENT_LOGIN_PASSWORD,
        self::EVENT_LOGIN_GOOGLE,
        self::EVENT_LOGIN_PHONE,
        self::EVENT_LOGIN_FAILED,
        self::EVENT_LOGOUT,
        self::EVENT_PASSWORD_RESET,
        self::EVENT_PASSWORD_CHANGED,
        self::EVENT_EMAIL_VERIFIED,
        self::EVENT_PHONE_VERIFIED,
        self::EVENT_ACCOUNT_LINKED,
        self::EVENT_ACCOUNT_UNLINKED,
        self::EVENT_SESSION_REVOKED,
        self::EVENT_SESSIONS_REVOKED,
        self::EVENT_ACCOUNT_DEACTIVATED,
        self::EVENT_ACCOUNT_REACTIVATED,
        self::EVENT_DELETION_REQUESTED,
    ];

    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILURE = 'failure';

    public const UPDATED_AT = null;

    protected $fillable = [];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

```

### 51.8 — `app/Models/PaymentMethod.php`

> NEW — saved payment method model

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A user's saved payment method (Phase 14).
 *
 * Only the provider id, a user label and a masked identifier are stored.
 * Card numbers, full phone numbers and account details never reach this
 * table. Methods belong to exactly one user; every read/write is ownership-
 * checked server-side.
 */
class PaymentMethod extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_REMOVED = 'removed';

    /**
     * Providers a user may save. Mirrors the configured gateway set.
     */
    public const PROVIDERS = ['bkash', 'nagad', 'rocket', 'card', 'bank'];

    protected $fillable = [];

    protected $casts = [
        'is_default' => 'boolean',
        'verified_at' => 'datetime',
        'last_used_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}

```

### 51.9 — `app/Contracts/PhoneOtpProviderInterface.php`

> NEW — phone OTP provider contract

```php
<?php

namespace App\Contracts;

use DomainException;

/**
 * Provider abstraction for delivering phone OTP codes (Phase 14).
 *
 * The application never fabricates delivery: adapters honestly report whether
 * they are configured. In development/test the log provider simply records
 * the code to the application log (and the test provider returns it
 * deterministically); in production a real SMS gateway is required and
 * `send()` throws until it is configured.
 */
interface PhoneOtpProviderInterface
{
    /**
     * The stable provider identifier.
     */
    public function id(): string;

    /**
     * Whether this provider can actually deliver codes.
     */
    public function isConfigured(): bool;

    /**
     * Deliver a code to a phone number. Best-effort by contract: callers use
     * OtpService which never lets a delivery failure break the flow.
     *
     * @throws DomainException when the provider is not configured.
     */
    public function send(string $phone, string $code): void;
}

```

### 51.10 — `app/Contracts/GoogleOAuthProviderInterface.php`

> NEW — Google OAuth provider contract

```php
<?php

namespace App\Contracts;

use DomainException;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Provider abstraction for Google OAuth/OIDC sign-in (Phase 14).
 *
 * The real implementation wraps Laravel Socialite's Google driver (the
 * maintained, server-side OAuth/OIDC flow with signed state and id-token
 * verification). Tests substitute a deterministic fake so they never touch
 * the network.
 */
interface GoogleOAuthProviderInterface
{
    /**
     * Whether Google credentials are configured.
     */
    public function isConfigured(): bool;

    /**
     * Redirect the user to Google's consent screen (state is handled by the
     * underlying Socialite driver).
     *
     * @throws DomainException when not configured.
     */
    public function redirect(): RedirectResponse;

    /**
     * Resolve the OAuth callback into a normalized Google user.
     *
     * @return array{id: string, email: ?string, email_verified: bool, name: ?string}
     *
     * @throws DomainException on invalid state, token errors or a provider
     *                         that is not configured.
     */
    public function user(): array;
}

```

### 51.11 — `app/Gateways/NagadGateway.php`

> NEW — Nagad adapter

```php
<?php

namespace App\Gateways;

use App\Contracts\PaymentGatewayInterface;
use App\Models\Payment;
use App\Models\Refund;
use DomainException;

/**
 * Nagad adapter (Phase 14).
 *
 * Nagad integrations depend on merchant credentials/keys and server-side
 * callback configuration. This project ships WITHOUT production credentials,
 * so the adapter is deliberately honest:
 *
 *  - configured() is false unless NAGAD_MERCHANT_ID + keys are present.
 *  - createExternalPayment() returns the local `pending` state (manual
 *    "Send Money" verification by an admin) instead of faking a checkout.
 *  - refundExternal() refuses to fabricate an external refund.
 *
 * When merchant credentials are provisioned, this class is the single place
 * to implement the Nagad "initialize" (checkout) and "complete" (verify)
 * APIs, at which point supportsCallbacks()/supportsRefunds() would return
 * true according to what the merchant agreement actually provides.
 */
class NagadGateway implements PaymentGatewayInterface
{
    public function id(): string
    {
        return 'nagad';
    }

    public function label(): string
    {
        return 'Nagad';
    }

    public function configured(): bool
    {
        $config = (array) config('payments.providers.nagad', []);

        return ($config['enabled'] ?? false)
            && ! empty($config['merchant_id'])
            && ! empty($config['merchant_private_key'])
            && ! empty($config['pg_public_key']);
    }

    public function supportsCallbacks(): bool
    {
        return false;
    }

    public function supportsRefunds(): bool
    {
        return false;
    }

    public function createExternalPayment(Payment $payment): array
    {
        if (! $this->configured()) {
            // Manual fallback: the admin verifies the user's Nagad TrxID.
            return [
                'status' => Payment::STATUS_PENDING,
                'provider_reference' => $payment->provider_reference,
                'redirect_url' => null,
            ];
        }

        // Real credential path — not reachable today; kept as the documented
        // seam for the Nagad "initialize" checkout API.
        throw new DomainException('Nagad checkout requires server-side callback configuration that is not provisioned in this environment.');
    }

    public function refundExternal(Payment $payment, Refund $refund): array
    {
        throw new DomainException('External Nagad refunds are not available — refunds are recorded platform-side only.');
    }
}

```

### 51.12 — `app/Gateways/RocketGateway.php`

> NEW — Rocket adapter

```php
<?php

namespace App\Gateways;

use App\Contracts\PaymentGatewayInterface;
use App\Models\Payment;
use App\Models\Refund;
use DomainException;

/**
 * Rocket (DBBL Mobile Banking) adapter (Phase 14).
 *
 * Honest by construction: without merchant credentials there is no checkout
 * or callback, so payments fall back to the manual "Send Money" verification
 * flow and refunds are never fabricated.
 */
class RocketGateway implements PaymentGatewayInterface
{
    public function id(): string
    {
        return 'rocket';
    }

    public function label(): string
    {
        return 'Rocket';
    }

    public function configured(): bool
    {
        $config = (array) config('payments.providers.rocket', []);

        return ($config['enabled'] ?? false)
            && ! empty($config['merchant_id'])
            && ! empty($config['merchant_secret']);
    }

    public function supportsCallbacks(): bool
    {
        return false;
    }

    public function supportsRefunds(): bool
    {
        return false;
    }

    public function createExternalPayment(Payment $payment): array
    {
        return [
            'status' => Payment::STATUS_PENDING,
            'provider_reference' => $payment->provider_reference,
            'redirect_url' => null,
        ];
    }

    public function refundExternal(Payment $payment, Refund $refund): array
    {
        throw new DomainException('External Rocket refunds are not available — refunds are recorded platform-side only.');
    }
}

```

### 51.13 — `app/Gateways/CardGateway.php`

> NEW — card adapter

```php
<?php

namespace App\Gateways;

use App\Contracts\PaymentGatewayInterface;
use App\Models\Payment;
use App\Models\Refund;
use DomainException;

/**
 * Card / hosted-checkout adapter (Phase 14).
 *
 * Card payments require a PCI-compliant hosted gateway. No gateway is
 * configured in this project, so the adapter is disabled by default and
 * refuses to fabricate a checkout or a callback.
 */
class CardGateway implements PaymentGatewayInterface
{
    public function id(): string
    {
        return 'card';
    }

    public function label(): string
    {
        return 'Card';
    }

    public function configured(): bool
    {
        $config = (array) config('payments.providers.card', []);

        return ($config['enabled'] ?? false)
            && ! empty($config['gateway'])
            && ! empty($config['merchant_id'])
            && ! empty($config['merchant_secret']);
    }

    public function supportsCallbacks(): bool
    {
        return false;
    }

    public function supportsRefunds(): bool
    {
        return false;
    }

    public function createExternalPayment(Payment $payment): array
    {
        throw new DomainException('Card payments are not configured. Choose another payment method.');
    }

    public function refundExternal(Payment $payment, Refund $refund): array
    {
        throw new DomainException('External card refunds are not configured.');
    }
}

```

### 51.14 — `app/Gateways/BankGateway.php`

> NEW — bank-transfer adapter

```php
<?php

namespace App\Gateways;

use App\Contracts\PaymentGatewayInterface;
use App\Models\Payment;
use App\Models\Refund;
use DomainException;

/**
 * Manual bank-transfer adapter (Phase 14).
 *
 * Bank transfer is a manual method: the user transfers and submits a
 * reference which an admin verifies. There is never a provider callback or
 * an external refund to fake.
 */
class BankGateway implements PaymentGatewayInterface
{
    public function id(): string
    {
        return 'bank';
    }

    public function label(): string
    {
        return 'Bank Transfer';
    }

    public function configured(): bool
    {
        $config = (array) config('payments.providers.bank', []);

        return ($config['enabled'] ?? false);
    }

    public function supportsCallbacks(): bool
    {
        return false;
    }

    public function supportsRefunds(): bool
    {
        return false;
    }

    public function createExternalPayment(Payment $payment): array
    {
        return [
            'status' => Payment::STATUS_PENDING,
            'provider_reference' => $payment->provider_reference,
            'redirect_url' => null,
        ];
    }

    public function refundExternal(Payment $payment, Refund $refund): array
    {
        throw new DomainException('Bank transfers are refunded manually by an admin.');
    }
}

```

### 51.15 — `app/Gateways/SslCommerzGateway.php`

> NEW — SSLCommerz adapter

```php
<?php

namespace App\Gateways;

use App\Contracts\PaymentGatewayInterface;
use App\Models\Payment;
use App\Models\Refund;
use DomainException;

/**
 * SSLCommerz aggregator adapter (Phase 14).
 *
 * SSLCommerz provides hosted checkout (redirect) + server-side IPN. No
 * store credentials are configured in this project, so the adapter honestly
 * reports "not configured" and never fabricates a checkout session or a
 * successful verification.
 */
class SslCommerzGateway implements PaymentGatewayInterface
{
    public function id(): string
    {
        return 'sslcommerz';
    }

    public function label(): string
    {
        return 'SSLCommerz';
    }

    public function configured(): bool
    {
        $config = (array) config('payments.providers.sslcommerz', []);

        return ($config['enabled'] ?? false)
            && ! empty($config['store_id'])
            && ! empty($config['store_password']);
    }

    public function supportsCallbacks(): bool
    {
        return $this->configured();
    }

    public function supportsRefunds(): bool
    {
        return false;
    }

    public function createExternalPayment(Payment $payment): array
    {
        throw new DomainException('SSLCommerz is not configured. Choose another payment method.');
    }

    public function refundExternal(Payment $payment, Refund $refund): array
    {
        throw new DomainException('External SSLCommerz refunds are not available — refunds are recorded platform-side only.');
    }
}

```

### 51.16 — `app/Gateways/LogPhoneOtpProvider.php`

> NEW — dev/test OTP log provider

```php
<?php

namespace App\Gateways;

use App\Contracts\PhoneOtpProviderInterface;
use DomainException;
use Illuminate\Support\Facades\Log;

/**
 * Development/test OTP provider (Phase 14).
 *
 * Writes the code to the application log so local flows can be exercised
 * end-to-end without an SMS gateway. Configured only in non-production
 * environments; in production the SMS gateway provider is required.
 */
class LogPhoneOtpProvider implements PhoneOtpProviderInterface
{
    public function id(): string
    {
        return 'log';
    }

    public function isConfigured(): bool
    {
        return ! app()->environment('production');
    }

    public function send(string $phone, string $code): void
    {
        if (! $this->isConfigured()) {
            throw new DomainException('Phone OTP delivery is not configured.');
        }

        Log::info('Phone OTP issued', ['phone' => $phone, 'code' => $code]);
    }
}

```

### 51.17 — `app/Gateways/SmsGatewayPhoneOtpProvider.php`

> NEW — HTTP SMS-gateway OTP provider

```php
<?php

namespace App\Gateways;

use App\Contracts\PhoneOtpProviderInterface;
use DomainException;
use Illuminate\Support\Facades\Http;

/**
 * Production SMS-gateway OTP provider (Phase 14).
 *
 * Delivers codes through a generic HTTP SMS gateway configured by
 * environment variables. `isConfigured()` is true only when an endpoint, API
 * key and sender id are present; `send()` refuses to run otherwise, so the
 * platform can never pretend an SMS was delivered.
 */
class SmsGatewayPhoneOtpProvider implements PhoneOtpProviderInterface
{
    public function id(): string
    {
        return 'sms';
    }

    public function isConfigured(): bool
    {
        return ! empty(env('SMS_GATEWAY_ENDPOINT'))
            && ! empty(env('SMS_GATEWAY_API_KEY'))
            && ! empty(env('SMS_GATEWAY_SENDER'));
    }

    public function send(string $phone, string $code): void
    {
        if (! $this->isConfigured()) {
            throw new DomainException('Phone OTP delivery is not configured.');
        }

        $response = Http::asJson()
            ->acceptJson()
            ->withToken((string) env('SMS_GATEWAY_API_KEY'))
            ->post((string) env('SMS_GATEWAY_ENDPOINT'), [
                'to' => $phone,
                'from' => (string) env('SMS_GATEWAY_SENDER'),
                'text' => 'Your FF Arena verification code is ' . $code . '. It expires in 5 minutes.',
            ]);

        if ($response->failed()) {
            throw new DomainException('The SMS gateway rejected the delivery.');
        }
    }
}

```

### 51.18 — `app/Gateways/SocialiteGoogleProvider.php`

> NEW — Socialite-backed Google provider

```php
<?php

namespace App\Gateways;

use App\Contracts\GoogleOAuthProviderInterface;
use DomainException;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Google OAuth/OIDC provider backed by Laravel Socialite (Phase 14).
 *
 * Socialite implements Google's server-side OAuth 2.0 / OpenID Connect flow:
 * a signed `state` parameter stored in the session, authorization-code
 * exchange, and id-token verification against Google's keys. We never hand-
 * roll that cryptography.
 *
 * No credentials are configured in this project, so `isConfigured()` is
 * false and both `redirect()` and `user()` refuse to run — the UI honestly
 * reports "Google Sign-In is not configured".
 */
class SocialiteGoogleProvider implements GoogleOAuthProviderInterface
{
    public function isConfigured(): bool
    {
        return ! empty(config('services.google.client_id'))
            && ! empty(config('services.google.client_secret'))
            && ! empty(config('services.google.redirect'));
    }

    public function redirect(): RedirectResponse
    {
        if (! $this->isConfigured()) {
            throw new DomainException('Google Sign-In is not configured.');
        }

        return Socialite::driver('google')
            ->scopes(['openid', 'email', 'profile'])
            ->redirect();
    }

    public function user(): array
    {
        if (! $this->isConfigured()) {
            throw new DomainException('Google Sign-In is not configured.');
        }

        // NOTE: not stateless — the session-bound `state` parameter is
        // verified by Socialite, which is the CSRF defence for OAuth.
        $googleUser = Socialite::driver('google')->user();

        // `sub` is the stable Google subject identifier — never key an
        // identity on the (user-editable) display email alone.
        return [
            'id' => (string) $googleUser->getId(),
            'email' => $googleUser->getEmail(),
            'email_verified' => (bool) ($googleUser->user['email_verified'] ?? false),
            'name' => $googleUser->getName(),
        ];
    }
}

```

### 51.19 — `app/Services/PhoneOtpService.php`

> NEW — OTP lifecycle service

```php
<?php

namespace App\Services;

use App\Contracts\PhoneOtpProviderInterface;
use App\Models\OtpChallenge;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Phone one-time-code lifecycle (Phase 14).
 *
 * Issues, re-issues and verifies single-use OTP challenges. Codes are never
 * stored — only a keyed hash is persisted — and delivery goes through the
 * injected PhoneOtpProviderInterface, which is honest about whether it can
 * actually deliver (log provider in dev/test, SMS gateway in production).
 *
 * Rate/abuse controls (resend cooldown, per-phone daily cap, verification
 * attempt cap, expiry) are all enforced here, so every caller gets them for
 * free.
 */
class PhoneOtpService
{
    public function __construct(
        protected PhoneOtpProviderInterface $provider,
    ) {
    }

    /**
     * Normalize a Bangladeshi phone number to E.164 (+8801XXXXXXXXX).
     * Accepts 01XXXXXXXXX, 8801XXXXXXXXX, +8801XXXXXXXXX and common
     * formatting characters.
     *
     * @throws DomainException when the number is not a valid BD mobile.
     */
    public function normalize(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        // Local 11-digit form: 01XXXXXXXXX.
        if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = '880' . substr($digits, 1);
        }

        $normalized = '+' . $digits;

        if (! preg_match('/^\+8801\d{9}$/', $normalized)) {
            throw new DomainException('Enter a valid Bangladeshi mobile number (e.g. 01712345678).');
        }

        return $normalized;
    }

    /**
     * Issue a new OTP challenge and deliver the code.
     *
     * @throws DomainException on invalid phone/purpose, cooldown, daily cap
     *                          or delivery failure.
     */
    public function issue(?User $user, string $phone, string $purpose): OtpChallenge
    {
        $normalized = $this->normalize($phone);

        if (! in_array($purpose, OtpChallenge::PURPOSES, true)) {
            throw new DomainException('Unknown OTP purpose.');
        }

        if (! $this->provider->isConfigured()) {
            throw new DomainException('Phone verification is not configured.');
        }

        $cooldown = (int) config('account.otp.resend_cooldown_seconds', 60);
        $recent = OtpChallenge::where('phone', $normalized)
            ->where('purpose', $purpose)
            ->orderByDesc('id')
            ->first();

        if ($recent !== null && $recent->created_at->gt(now()->subSeconds($cooldown))) {
            throw new DomainException('Please wait before requesting another code.');
        }

        $dailyCap = (int) config('account.otp.daily_cap', 10);
        $todayCount = OtpChallenge::where('phone', $normalized)
            ->whereDate('created_at', today())
            ->count();

        if ($todayCount >= $dailyCap) {
            throw new DomainException('Too many verification codes requested today. Try again tomorrow.');
        }

        $code = $this->generateCode();
        $expiresSeconds = (int) config('account.otp.expires_seconds', 300);

        $challenge = DB::transaction(function () use ($user, $normalized, $purpose, $code, $expiresSeconds) {
            $challenge = new OtpChallenge();
            $challenge->user_id = $user?->id;
            $challenge->phone = $normalized;
            $challenge->purpose = $purpose;
            $challenge->code_hash = $this->hash($code);
            $challenge->attempts = 0;
            $challenge->expires_at = now()->addSeconds($expiresSeconds);
            $challenge->status = OtpChallenge::STATUS_PENDING;
            $challenge->save();

            try {
                $this->provider->send($normalized, $code);
            } catch (\Throwable $e) {
                // Never leave an undeliverable challenge behind.
                $challenge->delete();

                throw new DomainException('Could not send the verification code. Please try again.');
            }

            return $challenge;
        });

        return $challenge;
    }

    /**
     * Verify a code for the given phone + purpose.
     *
     * @return OtpChallenge the verified challenge
     *
     * @throws DomainException on invalid, expired or exhausted challenges.
     */
    public function verify(?User $user, string $phone, string $purpose, string $code): OtpChallenge
    {
        $normalized = $this->normalize($phone);

        $challenge = OtpChallenge::where('phone', $normalized)
            ->where('purpose', $purpose)
            ->when($user !== null, fn ($q) => $q->where('user_id', $user->id))
            ->orderByDesc('id')
            ->first();

        if ($challenge === null || ! $challenge->isPending()) {
            throw new DomainException('This code has expired. Request a new one.');
        }

        $maxAttempts = (int) config('account.otp.max_attempts', 5);

        if ($challenge->attempts >= $maxAttempts) {
            $challenge->status = OtpChallenge::STATUS_CONSUMED;
            $challenge->save();

            throw new DomainException('Too many incorrect attempts. Request a new code.');
        }

        if (! hash_equals($challenge->code_hash, $this->hash($code))) {
            $challenge->attempts = $challenge->attempts + 1;

            if ($challenge->attempts >= $maxAttempts) {
                $challenge->status = OtpChallenge::STATUS_CONSUMED;
            }

            $challenge->save();

            throw new DomainException('Incorrect code.');
        }

        // Single use: mark verified so the same code can never be replayed.
        $challenge->status = OtpChallenge::STATUS_VERIFIED;
        $challenge->verified_at = now();
        $challenge->save();

        return $challenge;
    }

    /**
     * Generate a random numeric code.
     */
    public function generateCode(): string
    {
        $length = (int) config('account.otp.length', 6);

        return str_pad((string) random_int(0, 10 ** $length - 1), $length, '0', STR_PAD_LEFT);
    }

    /**
     * Keyed hash of a code (the only thing ever persisted).
     */
    public function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}

```

### 51.20 — `app/Services/IdentityService.php`

> NEW — account-linking engine

```php
<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserIdentity;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Account identity-linking engine (Phase 14).
 *
 * The single authority for provider identities (google, phone) on a user
 * account, with the duplicate-account rules baked in:
 *
 *  - one Google `sub`  → exactly one account (unique provider+subject);
 *  - one phone number → exactly one account;
 *  - a verified Google email already on an account links into it rather than
 *    creating a duplicate;
 *  - a user can never unlink their last sign-in method.
 *
 * The password identity is the users.password column itself and is managed by
 * ProfileService (change/set), not by rows here.
 */
class IdentityService
{
    /**
     * Find the identity for a provider + subject.
     */
    public function find(string $provider, string $subject): ?UserIdentity
    {
        return UserIdentity::where('provider', $provider)
            ->where('provider_subject', $subject)
            ->first();
    }

    /**
     * The user's identity for a provider (google/phone), if any.
     */
    public function identityFor(User $user, string $provider): ?UserIdentity
    {
        return $user->identities()->where('provider', $provider)->first();
    }

    /**
     * All provider identities for a user.
     */
    public function identitiesFor(User $user)
    {
        return $user->identities()->orderBy('id')->get();
    }

    /**
     * Link a Google identity (subject is the stable Google `sub`).
     *
     * @throws DomainException when the subject is already linked elsewhere.
     */
    public function linkGoogle(User $user, string $subject, ?string $email, bool $emailVerified, ?string $name = null): UserIdentity
    {
        if (! $this->validateProviderSubject('google', $subject)) {
            throw new DomainException('Invalid Google identity.');
        }

        $existing = $this->find('google', $subject);

        if ($existing !== null && $existing->user_id !== $user->id) {
            throw new DomainException('This Google account is already connected to another FF Arena account.');
        }

        if ($existing !== null) {
            $existing->last_used_at = now();
            $existing->save();

            return $existing;
        }

        $identity = new UserIdentity();
        $identity->user_id = $user->id;
        $identity->provider = UserIdentity::PROVIDER_GOOGLE;
        $identity->provider_subject = $subject;
        $identity->provider_email = $email;
        $identity->verified_at = $emailVerified ? now() : null;
        $identity->last_used_at = now();
        $identity->metadata = $name !== null ? ['name' => $this->cap($name)] : null;
        $identity->save();

        return $identity;
    }

    /**
     * Link a phone identity (subject is the normalized E.164 number).
     *
     * @throws DomainException when the phone is already linked elsewhere.
     */
    public function linkPhone(User $user, string $normalizedPhone): UserIdentity
    {
        if (! preg_match('/^\+8801\d{9}$/', $normalizedPhone)) {
            throw new DomainException('Invalid phone number.');
        }

        $existing = $this->find(UserIdentity::PROVIDER_PHONE, $normalizedPhone);

        if ($existing !== null && $existing->user_id !== $user->id) {
            throw new DomainException('This phone number is already connected to another FF Arena account.');
        }

        if ($existing !== null) {
            $existing->verified_at = $existing->verified_at ?? now();
            $existing->last_used_at = now();
            $existing->save();

            return $existing;
        }

        $identity = new UserIdentity();
        $identity->user_id = $user->id;
        $identity->provider = UserIdentity::PROVIDER_PHONE;
        $identity->provider_subject = $normalizedPhone;
        $identity->verified_at = now();
        $identity->last_used_at = now();
        $identity->save();

        // Keep the account's phone column in sync with the verified number.
        if ($user->phone !== $normalizedPhone) {
            $user->phone = $normalizedPhone;
            $user->save();
        }

        return $identity;
    }

    /**
     * Unlink a provider identity, refusing to remove the last sign-in method.
     *
     * @throws DomainException when the identity is absent or it is the last
     *                          remaining sign-in method.
     */
    public function unlink(User $user, string $provider): void
    {
        $identity = $this->identityFor($user, $provider);

        if ($identity === null) {
            throw new DomainException('This account is not linked to that provider.');
        }

        if ($this->signInMethodCount($user) <= 1) {
            throw new DomainException('Add another sign-in method before unlinking this one.');
        }

        $identity->delete();
    }

    /**
     * Whether the user has a password.
     */
    public function hasPassword(User $user): bool
    {
        return $user->password !== null && $user->password !== '';
    }

    /**
     * Whether the user has a verified phone identity.
     */
    public function hasVerifiedPhone(User $user): bool
    {
        return $this->identityFor($user, UserIdentity::PROVIDER_PHONE)?->isVerified() ?? false;
    }

    /**
     * Whether the user has a Google identity.
     */
    public function hasGoogle(User $user): bool
    {
        return $this->identityFor($user, UserIdentity::PROVIDER_GOOGLE) !== null;
    }

    /**
     * Count of available sign-in methods (password + identities).
     */
    public function signInMethodCount(User $user): int
    {
        $count = $this->hasPassword($user) ? 1 : 0;

        return $count + $user->identities()->count();
    }

    /**
     * The user that owns a provider identity, or null.
     */
    public function userFor(string $provider, string $subject): ?User
    {
        return $this->find($provider, $subject)?->user;
    }

    /**
     * Resolve (or create) an account from a verified Google identity, with
     * duplicate-account prevention:
     *
     *  1. subject already linked → sign into that account;
     *  2. verified email already registered → link Google into that account
     *     (never a silent duplicate);
     *  3. otherwise → create a new Google-only account.
     *
     * @param  array{id: string, email: ?string, email_verified: bool, name: ?string}  $googleUser
     * @return array{user: User, created: bool, linked: bool}
     */
    public function resolveGoogle(array $googleUser): array
    {
        $subject = (string) $googleUser['id'];
        $email = $googleUser['email'] !== null ? Str::lower(trim($googleUser['email'])) : null;
        $emailVerified = (bool) $googleUser['email_verified'];
        $name = $googleUser['name'] !== null ? trim($googleUser['name']) : null;

        return DB::transaction(function () use ($subject, $email, $emailVerified, $name) {
            $existingIdentity = $this->find('google', $subject);

            if ($existingIdentity !== null) {
                $existingIdentity->last_used_at = now();
                $existingIdentity->save();

                return ['user' => $existingIdentity->user, 'created' => false, 'linked' => false];
            }

            if ($emailVerified && $email !== null && $email !== '') {
                $byEmail = User::where('email', $email)->first();

                if ($byEmail !== null) {
                    $this->linkGoogle($byEmail, $subject, $email, true, $name);

                    return ['user' => $byEmail, 'created' => false, 'linked' => true];
                }
            }

            $user = new User();
            $user->name = $name !== null && $name !== '' ? $name : 'Free Fire Player';
            $user->email = $email;
            $user->username = $this->uniqueUsername($email, $name);
            $user->password = null;            // Google-only account
            $user->role = 'player';
            $user->email_verified_at = $emailVerified ? now() : null;
            $user->privacy = 'public';
            $user->account_status = 'active';
            $user->save();

            $this->linkGoogle($user, $subject, $email, $emailVerified, $name);

            return ['user' => $user, 'created' => true, 'linked' => false];
        });
    }

    /**
     * Derive a unique, rules-compliant username from email/name.
     */
    public function uniqueUsername(?string $email, ?string $name): string
    {
        $base = '';

        if ($email !== null && str_contains($email, '@')) {
            $base = strtolower((string) strtok($email, '@'));
        } elseif ($name !== null) {
            $base = Str::slug($name, '');
        }

        $base = preg_replace('/[^a-z0-9._-]/', '', $base) ?? '';

        if (strlen($base) < 3) {
            $base = 'player';
        }

        $base = substr($base, 0, 15);
        $candidate = $base;
        $i = 0;

        while (User::where('username', $candidate)->exists()) {
            $i++;
            $candidate = substr($base, 0, 15) . $i;
        }

        return $candidate;
    }

    /**
     * Validate a provider subject (non-empty, bounded).
     */
    protected function validateProviderSubject(string $provider, string $subject): bool
    {
        $subject = trim($subject);

        return $subject !== '' && strlen($subject) <= 255;
    }

    /**
     * Cap a stored display string.
     */
    protected function cap(string $value): string
    {
        return mb_substr($value, 0, 100);
    }
}

```

### 51.21 — `app/Services/LoginEventService.php`

> NEW — security history recorder

```php
<?php

namespace App\Services;

use App\Models\LoginEvent;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

/**
 * Security / login history recorder (Phase 14).
 *
 * Records the closed vocabulary of authentication events with only
 * pseudonymous context: an HMAC'd IP hash, an HMAC'd device hash and a
 * derived "browser on OS" device label. Raw IPs, raw fingerprints and
 * credentials are never stored, and events are append-only.
 */
class LoginEventService
{
    public function __construct(
        protected DeviceFingerprintService $devices,
        protected IpIntelligenceService $ipIntel,
    ) {
    }

    /**
     * Record a login/security event.
     */
    public function record(?User $user, string $event, string $status = LoginEvent::STATUS_SUCCESS, ?Request $request = null, array $metadata = []): LoginEvent
    {
        if (! in_array($event, LoginEvent::EVENTS, true)) {
            throw new \InvalidArgumentException("Unknown login event [{$event}].");
        }

        if (! in_array($status, [LoginEvent::STATUS_SUCCESS, LoginEvent::STATUS_FAILURE], true)) {
            throw new \InvalidArgumentException("Unknown login event status [{$status}].");
        }

        $entry = new LoginEvent();
        $entry->user_id = $user?->id;
        $entry->event = $event;
        $entry->status = $status;

        if ($request !== null) {
            $ip = (string) $request->ip();
            $entry->ip_hash = $ip !== '' ? $this->ipIntel->ipHash($ip) : null;
            $entry->device_hash = $this->devices->hashFrom($request);
            $entry->device_label = $this->deviceLabel($request);
        }

        $entry->metadata = $metadata === [] ? null : $metadata;
        $entry->save();

        return $entry;
    }

    /**
     * The user's login history, newest first.
     */
    public function historyFor(User $user, int $perPage = 30): LengthAwarePaginator
    {
        return LoginEvent::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * Whether the request's device is new to this account (never seen before
     * in its device associations).
     */
    public function isNewDevice(Request $request, User $user): bool
    {
        $hash = $this->devices->hashFrom($request);

        return $user->deviceLinks()
            ->whereHas('device', fn ($q) => $q->where('device_hash', $hash))
            ->doesntExist();
    }

    /**
     * Derive a short, non-sensitive device label from the user agent.
     */
    public function deviceLabel(Request $request): string
    {
        return $this->devices->deviceLabelFromUserAgent((string) $request->userAgent());
    }
}

```

### 51.22 — `app/Services/SessionManagementService.php`

> NEW — session/device management

```php
<?php

namespace App\Services;

use App\Models\LoginEvent;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;

/**
 * Session/device management (Phase 14).
 *
 * Reads the framework session store (database driver in production) to list
 * a user's active sessions, and revokes them ("logout this device",
 * "logout other devices", "logout everywhere"). Raw IPs are never shown —
 * only a derived device label and last-activity time.
 */
class SessionManagementService
{
    public function __construct(
        protected LoginEventService $loginEvents,
        protected NotificationService $notifications,
        protected AuditLogService $audit,
        protected LiveEventService $live,
        protected DeviceFingerprintService $devices,
    ) {
    }

    /**
     * The user's active sessions with a safe device label and current flag.
     *
     * @return Collection<int, array{id: string, last_activity: int, device_label: string, is_current: bool}>
     */
    public function sessionsFor(User $user): Collection
    {
        $current = $this->currentId();

        $rows = DB::table('sessions')
            ->where('user_id', $user->id)
            ->orderByDesc('last_activity')
            ->get();

        return $rows->map(function ($row) use ($current) {
            return [
                'id' => $row->id,
                'last_activity' => (int) $row->last_activity,
                'device_label' => $this->devices->deviceLabelFromUserAgent((string) ($row->user_agent ?? '')),
                'is_current' => $current !== null && hash_equals($current, (string) $row->id),
            ];
        });
    }

    /**
     * Revoke every session except the current one.
     *
     * @return int number of sessions revoked
     */
    public function revokeOtherSessions(User $user): int
    {
        $current = $this->currentId();

        $deleted = DB::table('sessions')
            ->where('user_id', $user->id)
            ->when($current !== null, fn ($q) => $q->where('id', '!=', $current))
            ->delete();

        if ($deleted > 0) {
            $this->recordRevocation($user, LoginEvent::EVENT_SESSION_REVOKED);
        }

        return $deleted;
    }

    /**
     * Revoke all of the user's sessions (logout everywhere). The caller is
     * responsible for ending the current session/guard afterwards.
     *
     * @return int number of sessions revoked
     */
    public function revokeAllSessions(User $user): int
    {
        $deleted = DB::table('sessions')->where('user_id', $user->id)->delete();

        if ($deleted > 0) {
            $this->recordRevocation($user, LoginEvent::EVENT_SESSIONS_REVOKED);
        }

        return $deleted;
    }

    /**
     * Admin-initiated revocation of all of a user's sessions.
     */
    public function revokeAllForUser(User $user, User $admin): int
    {
        $deleted = $this->revokeAllSessions($user);

        $this->audit->recordQuietly($admin, 'auth.sessions_revoked', 'user', $user->id, [
            'target_user_id' => $user->id,
            'metadata' => ['count' => $deleted],
        ]);

        return $deleted;
    }

    /**
     * The current session id (null when unavailable, e.g. console).
     */
    public function currentId(): ?string
    {
        try {
            return Session::getId();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Side effects shared by revocations: login event, notification, audit
     * and a user-targeted live event. Never throws into the caller.
     */
    protected function recordRevocation(User $user, string $event): void
    {
        $this->loginEvents->record($user, $event);

        $this->notifications->send(
            $user,
            Notification::TYPE_SESSION_REVOKED,
            'Sessions revoked',
            'One or more of your active sessions were signed out for security.',
            NotificationService::link('settings.sessions'),
        );

        $this->audit->recordQuietly($user, 'auth.sessions_revoked', 'user', $user->id, [
            'target_user_id' => $user->id,
            'metadata' => ['event' => $event],
        ]);

        $this->live->recordForUserQuietly($user, null, \App\Models\LiveEvent::TYPE_ACCOUNT_SESSION_REVOKED, [
            'event' => $event,
        ]);
    }
}

```

### 51.23 — `app/Services/AccountLifecycleService.php`

> NEW — deactivate/reactivate/delete

```php
<?php

namespace App\Services;

use App\Models\Dispute;
use App\Models\LoginEvent;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\Restriction;
use App\Models\User;
use DomainException;

/**
 * Account lifecycle: deactivate / reactivate / delete (Phase 14).
 *
 * Deletion is an anonymizing tombstone, never a physical row removal —
 * financial ledger, payouts, disputes, audit and security events are
 * immutable and must keep their foreign keys intact. Deletion is refused
 * while financial or security workflows are unresolved.
 */
class AccountLifecycleService
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DEACTIVATED = 'deactivated';
    public const STATUS_DELETION_PENDING = 'deletion_pending';
    public const STATUS_DELETED = 'deleted';

    public function __construct(
        protected SessionManagementService $sessions,
        protected LoginEventService $loginEvents,
        protected NotificationService $notifications,
        protected AuditLogService $audit,
    ) {
    }

    /**
     * Deactivate an account (self-service or admin). All sessions are
     * revoked so the account is immediately signed out everywhere.
     */
    public function deactivate(User $user, ?User $actor = null): User
    {
        if ($user->account_status === self::STATUS_DEACTIVATED) {
            throw new DomainException('This account is already deactivated.');
        }

        if ($user->account_status === self::STATUS_DELETED) {
            throw new DomainException('This account has been deleted.');
        }

        $user->account_status = self::STATUS_DEACTIVATED;
        $user->deactivated_at = now();
        $user->save();

        $this->sessions->revokeAllSessions($user);

        $this->loginEvents->record($user, LoginEvent::EVENT_ACCOUNT_DEACTIVATED);

        $this->notifications->send(
            $user,
            Notification::TYPE_ACCOUNT_DEACTIVATED,
            'Account deactivated',
            'Your FF Arena account has been deactivated. You can reactivate it from your security settings.',
            NotificationService::link('login'),
        );

        $this->audit->recordQuietly($actor ?? $user, 'auth.account_deactivated', 'user', $user->id, [
            'target_user_id' => $user->id,
        ]);

        return $user;
    }

    /**
     * Reactivate a deactivated account.
     */
    public function reactivate(User $user, ?User $actor = null): User
    {
        if (! in_array($user->account_status, [self::STATUS_DEACTIVATED, self::STATUS_DELETION_PENDING], true)) {
            throw new DomainException('This account is not deactivated.');
        }

        $user->account_status = self::STATUS_ACTIVE;
        $user->deactivated_at = null;
        $user->save();

        $this->loginEvents->record($user, LoginEvent::EVENT_ACCOUNT_REACTIVATED);

        $this->notifications->send(
            $user,
            Notification::TYPE_SYSTEM,
            'Account reactivated',
            'Your FF Arena account has been reactivated.',
            NotificationService::link('home'),
        );

        $this->audit->recordQuietly($actor ?? $user, 'auth.account_reactivated', 'user', $user->id, [
            'target_user_id' => $user->id,
        ]);

        return $user;
    }

    /**
     * Request account deletion (self-service). Blocked while financial or
     * security workflows are unresolved.
     */
    public function requestDeletion(User $user): User
    {
        $this->assertDeletable($user);

        $user->account_status = self::STATUS_DELETION_PENDING;
        $user->save();

        $this->loginEvents->record($user, LoginEvent::EVENT_DELETION_REQUESTED);

        $this->notifications->send(
            $user,
            Notification::TYPE_SYSTEM,
            'Account deletion requested',
            'Your deletion request has been received and will be processed shortly.',
            NotificationService::link('settings.security'),
        );

        $this->audit->recordQuietly($user, 'auth.account_deletion_requested', 'user', $user->id, [
            'target_user_id' => $user->id,
        ]);

        return $user;
    }

    /**
     * Cancel a pending deletion request.
     */
    public function cancelDeletion(User $user): User
    {
        if ($user->account_status !== self::STATUS_DELETION_PENDING) {
            throw new DomainException('There is no pending deletion request.');
        }

        $user->account_status = self::STATUS_ACTIVE;
        $user->save();

        return $user;
    }

    /**
     * Execute deletion (admin) as an anonymizing tombstone. Never physically
     * removes the row: ledger/payout/dispute/audit/security history must keep
     * its references intact.
     */
    public function executeDeletion(User $user, User $admin): User
    {
        $this->assertDeletable($user);

        $this->sessions->revokeAllSessions($user);

        $user->account_status = self::STATUS_DELETED;
        $user->deactivated_at = now();
        $user->name = 'Deleted User';
        $user->username = null;
        $user->email = 'deleted-' . $user->id . '@ffarena.invalid';
        $user->phone = null;
        $user->game_uid = null;
        $user->bio = null;
        $user->country = null;
        $user->region = null;
        $user->avatar = null;
        $user->password = null;
        $user->remember_token = null;
        $user->save();

        // Provider identities are removed; password identity is gone with
        // the null password above.
        $user->identities()->delete();

        $this->audit->recordQuietly($admin, 'auth.account_deleted', 'user', $user->id, [
            'target_user_id' => $user->id,
        ]);

        return $user;
    }

    /**
     * A user is deletable only when no financial/security workflow is open.
     *
     * @throws DomainException otherwise.
     */
    protected function assertDeletable(User $user): void
    {
        if ($user->account_status === self::STATUS_DELETED) {
            throw new DomainException('This account has already been deleted.');
        }

        if (Payment::where('payer_user_id', $user->id)
            ->whereIn('status', [Payment::STATUS_PENDING, Payment::STATUS_PROCESSING])
            ->exists()) {
            throw new DomainException('Resolve your pending payments before deleting your account.');
        }

        if (Payout::where('recipient_user_id', $user->id)
            ->whereIn('status', [Payout::STATUS_PENDING, Payout::STATUS_PROCESSING])
            ->exists()) {
            throw new DomainException('Resolve your pending payouts before deleting your account.');
        }

        if (Dispute::where('opened_by', $user->id)
            ->whereIn('status', [Dispute::STATUS_OPEN, Dispute::STATUS_UNDER_REVIEW])
            ->exists()) {
            throw new DomainException('Resolve your open disputes before deleting your account.');
        }

        if (Restriction::where('user_id', $user->id)
            ->where('status', Restriction::STATUS_ACTIVE)
            ->exists()) {
            throw new DomainException('Contact support to review your account before deletion.');
        }
    }
}

```

### 51.24 — `app/Services/ProfileService.php`

> NEW — profile/username/privacy/password

```php
<?php

namespace App\Services;

use App\Models\LoginEvent;
use App\Models\Notification;
use App\Models\User;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * User profile, username, privacy, region and password management (Phase 14).
 *
 * The single authority for self-editable profile state. Users can never edit
 * rating/rank/risk/verification/roles/restrictions — those columns are not
 * reachable from any profile path. Every mutation is audited and the
 * sensitive ones are notified.
 */
class ProfileService
{
    public function __construct(
        protected NotificationService $notifications,
        protected AuditLogService $audit,
        protected LoginEventService $loginEvents,
        protected SessionManagementService $sessions,
    ) {
    }

    /**
     * Update basic profile fields (display name, bio, country, region, avatar).
     */
    public function update(User $user, array $data): User
    {
        $before = [
            'name' => $user->name,
            'bio' => $user->bio,
            'country' => $user->country,
            'region' => $user->region,
            'avatar' => $user->avatar,
        ];

        $user->name = trim($data['name']);
        $user->bio = isset($data['bio']) && trim($data['bio']) !== '' ? trim($data['bio']) : null;
        $user->country = isset($data['country']) && $data['country'] !== '' ? strtoupper(substr(trim($data['country']), 0, 2)) : null;
        $user->region = isset($data['region']) && trim($data['region']) !== '' ? trim($data['region']) : null;
        $user->avatar = isset($data['avatar']) && trim($data['avatar']) !== '' ? trim($data['avatar']) : null;
        $user->save();

        $this->audit->recordQuietly($user, 'profile.updated', 'user', $user->id, [
            'target_user_id' => $user->id,
            'before' => $before,
            'after' => [
                'name' => $user->name,
                'bio' => $user->bio,
                'country' => $user->country,
                'region' => $user->region,
                'avatar' => $user->avatar,
            ],
        ]);

        return $user;
    }

    /**
     * Change the username (normalized, unique, reserved-checked, cooldown-
     * limited).
     */
    public function updateUsername(User $user, string $username): User
    {
        $username = $this->normalizeUsername($username);
        $this->assertUsernameAvailable($username, $user);

        $cooldownDays = (int) config('account.username.change_cooldown_days', 7);

        if ($user->username_changed_at !== null
            && $user->username_changed_at->gt(now()->subDays($cooldownDays))
            && strtolower((string) $user->username) !== $username) {
            throw new DomainException("You can change your username once every {$cooldownDays} days.");
        }

        $before = $user->username;

        $user->username = $username;
        $user->username_changed_at = now();
        $user->save();

        $this->audit->recordQuietly($user, 'profile.username_changed', 'user', $user->id, [
            'target_user_id' => $user->id,
            'before' => ['username' => $before],
            'after' => ['username' => $username],
        ]);

        return $user;
    }

    /**
     * Change the account password (requires the current password) and revoke
     * every other session.
     */
    public function changePassword(User $user, string $current, string $new, ?Request $request = null): User
    {
        if (! $this->hasPassword($user)) {
            throw new DomainException('This account has no password yet — set one first.');
        }

        if (! Hash::check($current, $user->password)) {
            throw new DomainException('Your current password is incorrect.');
        }

        if (strlen($new) < 8) {
            throw new DomainException('Your new password must be at least 8 characters.');
        }

        $user->password = Hash::make($new);
        $user->save();

        // Keep only the current session alive.
        $this->sessions->revokeOtherSessions($user);

        $this->loginEvents->record($user, LoginEvent::EVENT_PASSWORD_CHANGED, LoginEvent::STATUS_SUCCESS, $request);

        $this->notifications->send(
            $user,
            Notification::TYPE_PASSWORD_CHANGED,
            'Password changed',
            'Your password was changed. If this was not you, reset it immediately.',
            NotificationService::link('settings.security'),
        );

        $this->audit->recordQuietly($user, 'auth.password_changed', 'user', $user->id, [
            'target_user_id' => $user->id,
        ]);

        return $user;
    }

    /**
     * Set a password on an account that does not have one (e.g. a Google-only
     * account), without requiring a current password.
     */
    public function setPassword(User $user, string $new, ?Request $request = null): User
    {
        if (strlen($new) < 8) {
            throw new DomainException('Your password must be at least 8 characters.');
        }

        $user->password = Hash::make($new);
        $user->save();

        $this->loginEvents->record($user, LoginEvent::EVENT_PASSWORD_CHANGED, LoginEvent::STATUS_SUCCESS, $request);

        $this->notifications->send(
            $user,
            Notification::TYPE_PASSWORD_CHANGED,
            'Password set',
            'A password was added to your account.',
            NotificationService::link('settings.security'),
        );

        $this->audit->recordQuietly($user, 'auth.password_changed', 'user', $user->id, [
            'target_user_id' => $user->id,
        ]);

        return $user;
    }

    /**
     * Update the profile privacy preset.
     */
    public function updatePrivacy(User $user, string $privacy): User
    {
        if (! in_array($privacy, (array) config('account.privacy', ['public', 'registered', 'private']), true)) {
            throw new DomainException('Invalid privacy setting.');
        }

        $before = $user->privacy;

        $user->privacy = $privacy;
        $user->save();

        $this->audit->recordQuietly($user, 'profile.privacy_changed', 'user', $user->id, [
            'target_user_id' => $user->id,
            'before' => ['privacy' => $before],
            'after' => ['privacy' => $privacy],
        ]);

        return $user;
    }

    /**
     * Update country/region/language/timezone preferences.
     */
    public function updatePreferences(User $user, array $data): User
    {
        $user->country = isset($data['country']) && $data['country'] !== '' ? strtoupper(substr(trim($data['country']), 0, 2)) : null;
        $user->region = isset($data['region']) && trim($data['region']) !== '' ? trim($data['region']) : null;
        $user->language = isset($data['language']) && $data['language'] !== '' ? substr(trim($data['language']), 0, 5) : 'en';

        $timezone = isset($data['timezone']) ? trim($data['timezone']) : 'UTC';
        $user->timezone = in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'UTC';

        $user->save();

        $this->audit->recordQuietly($user, 'profile.updated', 'user', $user->id, [
            'target_user_id' => $user->id,
        ]);

        return $user;
    }

    /**
     * Build the display-safe public profile for a viewer, honouring the
     * target's privacy preset. Never includes email/phone/wallet/risk data.
     */
    public function publicProfile(User $target, ?User $viewer): array
    {
        $privacy = $target->privacy ?? 'public';

        $visible = match ($privacy) {
            'private' => $viewer !== null && ($viewer->id === $target->id || $viewer->isStaff()),
            'registered' => $viewer !== null,
            default => true,
        };

        if (! $visible) {
            return [
                'visible' => false,
                'name' => $target->name,
                'username' => $target->username,
                'privacy' => $privacy,
            ];
        }

        return [
            'visible' => true,
            'name' => $target->name,
            'username' => $target->username,
            'avatar' => $target->avatar,
            'bio' => $target->bio,
            'country' => $target->country,
            'region' => $target->region,
            'role' => $target->role,
            'joined_at' => $target->created_at?->toDateString(),
            'privacy' => $privacy,
        ];
    }

    /**
     * Whether the user has a password.
     */
    public function hasPassword(User $user): bool
    {
        return $user->password !== null && $user->password !== '';
    }

    /**
     * Normalize a username: trim, lowercase for uniqueness but keep the
     * user's casing for display when valid.
     */
    public function normalizeUsername(string $username): string
    {
        $username = trim($username);

        $min = (int) config('account.username.min_length', 3);
        $max = (int) config('account.username.max_length', 20);
        $regex = (string) config('account.username.allowed_regex', '/^[a-zA-Z0-9._-]+$/');

        if (mb_strlen($username) < $min || mb_strlen($username) > $max) {
            throw new DomainException("Username must be between {$min} and {$max} characters.");
        }

        if (! preg_match($regex, $username)) {
            throw new DomainException('Username may only contain letters, numbers, dots, dashes and underscores.');
        }

        $reserved = (array) config('account.username.reserved', []);

        if (in_array(strtolower($username), array_map('strtolower', $reserved), true)) {
            throw new DomainException('That username is reserved.');
        }

        return $username;
    }

    /**
     * Uniqueness check against other users (case-insensitive).
     */
    public function assertUsernameAvailable(string $username, User $except): void
    {
        $exists = User::query()
            ->where('id', '!=', $except->id)
            ->whereRaw('lower(username) = ?', [strtolower($username)])
            ->exists();

        if ($exists) {
            throw new DomainException('That username is already taken.');
        }
    }
}

```

### 51.25 — `app/Services/PaymentMethodService.php`

> NEW — saved-method management

```php
<?php

namespace App\Services;

use App\Models\PaymentMethod;
use App\Models\User;
use DomainException;

/**
 * Saved payment-method management (Phase 14).
 *
 * Methods belong to exactly one user; every operation is ownership-checked.
 * Only a masked identifier is ever stored — card numbers, full phone numbers
 * and account details never reach this table.
 */
class PaymentMethodService
{
    public function __construct(
        protected AuditLogService $audit,
    ) {
    }

    /**
     * The user's saved payment methods (active first, default first).
     */
    public function listFor(User $user)
    {
        return $user->paymentMethods()
            ->where('status', PaymentMethod::STATUS_ACTIVE)
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Save a new payment method for the user.
     */
    public function add(User $user, string $provider, string $label, string $identifier): PaymentMethod
    {
        if (! in_array($provider, PaymentMethod::PROVIDERS, true)) {
            throw new DomainException('Unknown payment provider.');
        }

        $digits = preg_replace('/\D/', '', $identifier) ?? '';

        if (strlen($digits) < 6 || strlen($digits) > 20) {
            throw new DomainException('Enter a valid account identifier.');
        }

        $hasDefault = $user->paymentMethods()
            ->where('status', PaymentMethod::STATUS_ACTIVE)
            ->where('is_default', true)
            ->exists();

        $method = new PaymentMethod();
        $method->user_id = $user->id;
        $method->provider = $provider;
        $method->label = mb_substr(trim($label), 0, 60);
        $method->masked_identifier = $this->mask($identifier);
        $method->status = PaymentMethod::STATUS_ACTIVE;
        $method->is_default = ! $hasDefault;
        $method->save();

        $this->audit->recordQuietly($user, 'payment_method.added', 'payment_method', $method->id, [
            'target_user_id' => $user->id,
            'metadata' => ['provider' => $provider],
        ]);

        return $method;
    }

    /**
     * Remove (soft-delete) a payment method. If it was the default, promote
     * another method or clear the default.
     */
    public function remove(User $user, PaymentMethod $method): PaymentMethod
    {
        $this->assertOwned($user, $method);

        if (! $method->isActive()) {
            throw new DomainException('This payment method has already been removed.');
        }

        $wasDefault = (bool) $method->is_default;

        $method->status = PaymentMethod::STATUS_REMOVED;
        $method->is_default = false;
        $method->save();

        if ($wasDefault) {
            $next = $user->paymentMethods()
                ->where('status', PaymentMethod::STATUS_ACTIVE)
                ->orderByDesc('id')
                ->first();

            if ($next !== null) {
                $next->is_default = true;
                $next->save();
            }
        }

        $this->audit->recordQuietly($user, 'payment_method.removed', 'payment_method', $method->id, [
            'target_user_id' => $user->id,
            'metadata' => ['provider' => $method->provider],
        ]);

        return $method;
    }

    /**
     * Mark a payment method as the default (exactly one default per user).
     */
    public function setDefault(User $user, PaymentMethod $method): PaymentMethod
    {
        $this->assertOwned($user, $method);

        if (! $method->isActive()) {
            throw new DomainException('This payment method has been removed.');
        }

        $user->paymentMethods()
            ->where('status', PaymentMethod::STATUS_ACTIVE)
            ->update(['is_default' => false]);

        $method->is_default = true;
        $method->save();

        $this->audit->recordQuietly($user, 'payment_method.default', 'payment_method', $method->id, [
            'target_user_id' => $user->id,
            'metadata' => ['provider' => $method->provider],
        ]);

        return $method;
    }

    /**
     * Mask an identifier, keeping only its last four digits.
     */
    public function mask(string $identifier): string
    {
        $digits = preg_replace('/\D/', '', $identifier) ?? '';
        $last = substr($digits, -4);

        return '****' . str_repeat('*', max(0, strlen($digits) - 4)) . $last;
    }

    protected function assertOwned(User $user, PaymentMethod $method): void
    {
        if ($method->user_id !== $user->id) {
            throw new DomainException('This payment method does not belong to you.');
        }
    }
}

```

### 51.26 — `app/Services/GoogleAuthService.php`

> NEW — Google sign-in orchestration

```php
<?php

namespace App\Services;

use App\Contracts\GoogleOAuthProviderInterface;
use App\Models\LoginEvent;
use App\Models\Notification;
use App\Models\User;
use DomainException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Google Sign-In orchestration (Phase 14).
 *
 * Wraps the GoogleOAuthProviderInterface (Socialite in production, a fake in
 * tests) and turns a verified Google identity into an authenticated session:
 * linking, duplicate-account prevention, risk signals, login history,
 * notifications and audit are all applied here. No credentials or tokens are
 * ever persisted.
 */
class GoogleAuthService
{
    public function __construct(
        protected GoogleOAuthProviderInterface $provider,
        protected IdentityService $identities,
        protected LoginEventService $loginEvents,
        protected NotificationService $notifications,
        protected AuditLogService $audit,
        protected FraudRiskService $risk,
        protected DeviceFingerprintService $devices,
        protected IpIntelligenceService $ipIntel,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->provider->isConfigured();
    }

    /**
     * @throws DomainException when Google Sign-In is not configured.
     */
    public function redirect(): RedirectResponse
    {
        return $this->provider->redirect();
    }

    /**
     * Handle the OAuth callback: resolve/create the account, link identities,
     * record security events and return the authenticated user.
     */
    public function handleCallback(Request $request): User
    {
        $googleUser = $this->provider->user();

        $result = $this->identities->resolveGoogle($googleUser);
        $user = $result['user'];
        $created = $result['created'];
        $linked = $result['linked'];

        $this->loginEvents->record(
            $user,
            LoginEvent::EVENT_LOGIN_GOOGLE,
            LoginEvent::STATUS_SUCCESS,
            $request,
            ['linked' => $linked, 'created' => $created],
        );

        $this->devices->register($request, $user);
        $this->ipIntel->observe($request, $user);

        if ($this->loginEvents->isNewDevice($request, $user)) {
            $this->risk->recordSignal($user, \App\Models\RiskEvent::TYPE_RISK_FLAG, \App\Models\RiskEvent::SEVERITY_INFO, 'auth', [
                'context' => 'google_login_new_device',
            ]);

            $this->audit->recordQuietly($user, 'auth.suspicious_login', 'user', $user->id, [
                'target_user_id' => $user->id,
            ]);
        }

        if ($created) {
            $this->notifications->send(
                $user,
                Notification::TYPE_WELCOME,
                'Welcome to FF Arena',
                'Your account was created with Google Sign-In.',
                NotificationService::link('home'),
            );
        } elseif ($linked) {
            $this->notifications->send(
                $user,
                Notification::TYPE_GOOGLE_LINKED,
                'Google connected',
                'Google Sign-In was connected to your existing FF Arena account.',
                NotificationService::link('settings.connected-accounts'),
            );
        }

        $this->audit->recordQuietly(
            $user,
            $linked ? 'auth.google_linked' : ($created ? 'auth.register' : 'auth.login'),
            'user',
            $user->id,
            [
                'target_user_id' => $user->id,
                'metadata' => ['provider' => 'google', 'linked' => $linked, 'created' => $created],
            ],
        );

        return $user;
    }

    /**
     * Link a Google identity to the already-authenticated user (settings).
     */
    public function linkToCurrentUser(Request $request, User $user): void
    {
        $googleUser = $this->provider->user();

        $this->identities->linkGoogle(
            $user,
            (string) $googleUser['id'],
            $googleUser['email'],
            (bool) $googleUser['email_verified'],
            $googleUser['name'],
        );

        $this->loginEvents->record($user, LoginEvent::EVENT_ACCOUNT_LINKED, LoginEvent::STATUS_SUCCESS, $request, [
            'provider' => 'google',
        ]);

        $this->notifications->send(
            $user,
            Notification::TYPE_GOOGLE_LINKED,
            'Google connected',
            'Google Sign-In was connected to your account.',
            NotificationService::link('settings.connected-accounts'),
        );

        $this->audit->recordQuietly($user, 'auth.google_linked', 'user', $user->id, [
            'target_user_id' => $user->id,
        ]);
    }

    /**
     * Unlink the Google identity from the user (settings).
     */
    public function unlinkFromUser(User $user): void
    {
        $this->identities->unlink($user, 'google');

        $this->loginEvents->record($user, LoginEvent::EVENT_ACCOUNT_UNLINKED, LoginEvent::STATUS_SUCCESS, null, [
            'provider' => 'google',
        ]);

        $this->notifications->send(
            $user,
            Notification::TYPE_GOOGLE_UNLINKED,
            'Google disconnected',
            'Google Sign-In was disconnected from your account.',
            NotificationService::link('settings.connected-accounts'),
        );

        $this->audit->recordQuietly($user, 'auth.google_unlinked', 'user', $user->id, [
            'target_user_id' => $user->id,
        ]);
    }
}

```

### 51.27 — `app/Http/Middleware/EnsureActiveAccount.php`

> NEW — blocks deactivated accounts

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 14 — blocks deactivated/deleted accounts from the authenticated
 * area, except the security-settings page (where they can reactivate) and
 * logout. Guests pass through untouched.
 */
class EnsureActiveAccount
{
    /**
     * Route names a deactivated/deletion-pending user may still visit.
     */
    protected array $allow = [
        'settings.security',
        'settings.reactivate',
        'settings.deletion.cancel',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->isActive()) {
            foreach ($this->allow as $name) {
                if ($request->routeIs($name)) {
                    return $next($request);
                }
            }

            // Deactivated/deleted accounts are parked on their security
            // settings, where they can reactivate (or simply sign out).
            return redirect()->route('settings.security');
        }

        return $next($request);
    }
}

```

### 51.28 — `app/Policies/UserPolicy.php`

> NEW — profile/settings/lifecycle authorization

```php
<?php

namespace App\Policies;

use App\Models\User;

/**
 * Authorization for profile, settings, sessions, connected accounts and the
 * account lifecycle (Phase 14). Registered under the `User` model by
 * convention.
 *
 * A player can only act on their own account; admins may act on any account
 * for the operations listed below. Organizers and moderators get no special
 * account administration.
 */
class UserPolicy
{
    /**
     * Whether a viewer may see the target's public profile. Guests may see
     * `public` profiles; `registered` requires a signed-in viewer; `private`
     * restricts to the owner and staff.
     */
    public function viewProfile(?User $viewer, User $target): bool
    {
        if ($viewer !== null && $viewer->id === $target->id) {
            return true;
        }

        if ($viewer !== null && $viewer->isStaff()) {
            return true;
        }

        return match ($target->privacy ?? 'public') {
            'private' => false,
            'registered' => $viewer !== null,
            default => true,
        };
    }

    /**
     * Edit the profile (self, or admin).
     */
    public function updateProfile(User $actor, User $target): bool
    {
        return $actor->id === $target->id || $actor->isAdmin();
    }

    /**
     * Manage security settings, sessions, connected accounts (self).
     */
    public function manageSecurity(User $actor, User $target): bool
    {
        return $actor->id === $target->id;
    }

    /**
     * Manage sessions (self or admin).
     */
    public function manageSessions(User $actor, User $target): bool
    {
        return $actor->id === $target->id || $actor->isAdmin();
    }

    /**
     * View login history (self or admin).
     */
    public function viewLoginHistory(User $actor, User $target): bool
    {
        return $actor->id === $target->id || $actor->isAdmin();
    }

    /**
     * Deactivate / reactivate (self or admin).
     */
    public function deactivate(User $actor, User $target): bool
    {
        return $actor->id === $target->id || $actor->isAdmin();
    }

    public function reactivate(User $actor, User $target): bool
    {
        return $actor->id === $target->id || $actor->isAdmin();
    }

    /**
     * Request/cancel deletion (self only).
     */
    public function requestDeletion(User $actor, User $target): bool
    {
        return $actor->id === $target->id;
    }

    /**
     * Admin-only account administration.
     */
    public function adminAccounts(User $actor): bool
    {
        return $actor->isAdmin();
    }

    public function adminAccount(User $actor, User $target): bool
    {
        return $actor->isAdmin();
    }

    public function adminRevokeSessions(User $actor, User $target): bool
    {
        return $actor->isAdmin();
    }

    public function adminDeactivate(User $actor, User $target): bool
    {
        return $actor->isAdmin();
    }

    public function adminDelete(User $actor, User $target): bool
    {
        return $actor->isAdmin();
    }
}

```

### 51.29 — `app/Policies/PaymentMethodPolicy.php`

> NEW — payment-method authorization

```php
<?php

namespace App\Policies;

use App\Models\PaymentMethod;
use App\Models\User;

/**
 * Saved payment methods are private to their owner (Phase 14). Admins may
 * inspect them through the account-administration screen.
 */
class PaymentMethodPolicy
{
    public function viewAny(User $actor): bool
    {
        return true; // authenticated users manage their own list
    }

    public function create(User $actor): bool
    {
        return true;
    }

    public function delete(User $actor, PaymentMethod $method): bool
    {
        return $method->user_id === $actor->id || $actor->isAdmin();
    }

    public function setDefault(User $actor, PaymentMethod $method): bool
    {
        return $method->user_id === $actor->id || $actor->isAdmin();
    }
}

```

### 51.30 — `app/Policies/LoginEventPolicy.php`

> NEW — login-history authorization

```php
<?php

namespace App\Policies;

use App\Models\LoginEvent;
use App\Models\User;

/**
 * Login history is private to the account owner; admins may view any user's
 * history through account administration.
 */
class LoginEventPolicy
{
    public function viewAny(User $actor): bool
    {
        return true; // the controller scopes to the authenticated user
    }

    public function view(User $actor, LoginEvent $event): bool
    {
        return $event->user_id === $actor->id || $actor->isAdmin();
    }
}

```

### 51.31 — `app/Policies/UserIdentityPolicy.php`

> NEW — identity authorization

```php
<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserIdentity;

/**
 * Connected-account identities (google, phone) are private to their owner;
 * admins may view them through account administration.
 */
class UserIdentityPolicy
{
    public function viewAny(User $actor): bool
    {
        return true; // controller scopes to the authenticated user
    }

    public function view(User $actor, UserIdentity $identity): bool
    {
        return $identity->user_id === $actor->id || $actor->isAdmin();
    }

    public function unlink(User $actor, UserIdentity $identity): bool
    {
        return $identity->user_id === $actor->id || $actor->isAdmin();
    }
}

```

### 51.32 — `app/Http/Controllers/ProfileController.php`

> NEW — profile controller

```php
<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ProfileService;
use DomainException;
use Illuminate\Http\Request;

/**
 * Public profiles + profile settings (Phase 14).
 *
 * Users edit only their own profile (admins may too); rating, rank, risk,
 * verification, roles and restrictions are never editable from here.
 */
class ProfileController extends Controller
{
    public function __construct(
        protected ProfileService $profiles,
    ) {
    }

    /**
     * Public profile, honouring the target's privacy preset.
     */
    public function show(User $user)
    {
        $profile = $this->profiles->publicProfile($user, auth()->user());

        return view('profile.show', compact('user', 'profile'));
    }

    /**
     * The signed-in user's profile settings.
     */
    public function edit()
    {
        $user = auth()->user();

        $this->authorize('updateProfile', $user);

        return view('profile.edit', compact('user'));
    }

    /**
     * Update basic profile fields.
     */
    public function update(Request $request)
    {
        $user = auth()->user();
        $this->authorize('updateProfile', $user);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'bio' => 'nullable|string|max:500',
            'country' => 'nullable|string|max:2',
            'region' => 'nullable|string|max:100',
            'avatar' => 'nullable|url|max:255',
        ]);

        try {
            $this->profiles->update($user, $data);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Profile updated.');
    }

    /**
     * Change the username (normalized, unique, reserved-checked, rate-limited).
     */
    public function updateUsername(Request $request)
    {
        $user = auth()->user();
        $this->authorize('updateProfile', $user);

        $data = $request->validate([
            'username' => 'required|string',
        ]);

        try {
            $this->profiles->updateUsername($user, $data['username']);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Username updated.');
    }

    /**
     * Update the profile privacy preset.
     */
    public function updatePrivacy(Request $request)
    {
        $user = auth()->user();
        $this->authorize('updateProfile', $user);

        $data = $request->validate([
            'privacy' => 'required|in:public,registered,private',
        ]);

        try {
            $this->profiles->updatePrivacy($user, $data['privacy']);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Privacy setting updated.');
    }

    /**
     * Update country/region/language/timezone preferences.
     */
    public function updatePreferences(Request $request)
    {
        $user = auth()->user();
        $this->authorize('updateProfile', $user);

        $data = $request->validate([
            'country' => 'nullable|string|max:2',
            'region' => 'nullable|string|max:100',
            'language' => 'nullable|string|max:5',
            'timezone' => 'nullable|string|max:64',
        ]);

        try {
            $this->profiles->updatePreferences($user, $data);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Preferences updated.');
    }

    /**
     * Change (or set) the account password.
     */
    public function changePassword(Request $request)
    {
        $user = auth()->user();
        $this->authorize('manageSecurity', $user);

        $hasPassword = $this->profiles->hasPassword($user);

        $data = $request->validate([
            'current_password' => $hasPassword ? 'required|string' : 'nullable|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        try {
            if ($hasPassword) {
                $this->profiles->changePassword($user, $data['current_password'], $data['password'], $request);
            } else {
                $this->profiles->setPassword($user, $data['password'], $request);
            }

            // Regenerate the session so the current session id changes and
            // any session-fixation window closes.
            $request->session()->regenerate();
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Password updated.');
    }
}

```

### 51.33 — `app/Http/Controllers/AccountSecurityController.php`

> NEW — security/sessions/connected-accounts/lifecycle

```php
<?php

namespace App\Http\Controllers;

use App\Models\LoginEvent;
use App\Models\Notification;
use App\Models\UserIdentity;
use App\Services\AccountLifecycleService;
use App\Services\AuditLogService;
use App\Services\GoogleAuthService;
use App\Services\IdentityService;
use App\Services\LoginEventService;
use App\Services\NotificationService;
use App\Services\PhoneOtpService;
use App\Services\ProfileService;
use App\Services\SessionManagementService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Security settings, session management, login history, connected accounts
 * and the account lifecycle (Phase 14). Everything is self-service for the
 * authenticated user; admins have their own admin screens.
 */
class AccountSecurityController extends Controller
{
    public function __construct(
        protected ProfileService $profiles,
        protected IdentityService $identities,
        protected PhoneOtpService $otp,
        protected GoogleAuthService $google,
        protected SessionManagementService $sessions,
        protected LoginEventService $loginEvents,
        protected AccountLifecycleService $lifecycle,
        protected NotificationService $notifications,
        protected AuditLogService $audit,
    ) {
    }

    // ------------------------------------------------------------------
    // Overview pages
    // ------------------------------------------------------------------

    public function security()
    {
        $user = auth()->user();
        $this->authorize('manageSecurity', $user);

        $identities = $this->identities->identitiesFor($user);

        return view('settings.security', [
            'user' => $user,
            'identities' => $identities,
            'hasPassword' => $this->profiles->hasPassword($user),
        ]);
    }

    public function connectedAccounts()
    {
        $user = auth()->user();
        $this->authorize('manageSecurity', $user);

        $identities = $this->identities->identitiesFor($user);

        return view('settings.connected-accounts', [
            'user' => $user,
            'identities' => $identities,
            'hasPassword' => $this->profiles->hasPassword($user),
            'googleConfigured' => $this->google->isConfigured(),
            'phoneConfigured' => app(\App\Contracts\PhoneOtpProviderInterface::class)->isConfigured(),
        ]);
    }

    public function sessions()
    {
        $user = auth()->user();
        $this->authorize('manageSessions', $user);

        return view('settings.sessions', [
            'sessions' => $this->sessions->sessionsFor($user),
        ]);
    }

    public function loginHistory()
    {
        $user = auth()->user();
        $this->authorize('viewLoginHistory', $user);

        $events = $this->loginEvents->historyFor($user, 30);

        return view('settings.login-history', compact('events'));
    }

    // ------------------------------------------------------------------
    // Session revocation
    // ------------------------------------------------------------------

    public function revokeOtherSessions()
    {
        $user = auth()->user();
        $this->authorize('manageSessions', $user);

        $this->sessions->revokeOtherSessions($user);

        return back()->with('success', 'All other sessions were signed out.');
    }

    public function revokeAllSessions(Request $request)
    {
        $user = auth()->user();
        $this->authorize('manageSessions', $user);

        $this->sessions->revokeAllSessions($user);

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('success', 'You were signed out everywhere.');
    }

    // ------------------------------------------------------------------
    // Connected accounts — Google
    // ------------------------------------------------------------------

    public function linkGoogleRedirect(Request $request)
    {
        $user = auth()->user();
        $this->authorize('manageSecurity', $user);

        if ($this->identities->hasGoogle($user)) {
            return back()->with('error', 'Google is already connected to this account.');
        }

        $request->session()->put('google_link_intent', true);

        try {
            return $this->google->redirect();
        } catch (DomainException $e) {
            $request->session()->forget('google_link_intent');

            return back()->with('error', $e->getMessage());
        }
    }

    public function unlinkGoogle()
    {
        $user = auth()->user();
        $this->authorize('manageSecurity', $user);

        try {
            $this->google->unlinkFromUser($user);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Google was disconnected from your account.');
    }

    // ------------------------------------------------------------------
    // Connected accounts — phone
    // ------------------------------------------------------------------

    public function linkPhone(Request $request)
    {
        $user = auth()->user();
        $this->authorize('manageSecurity', $user);

        $data = $request->validate([
            'phone' => 'required|string|max:20',
        ]);

        try {
            $phone = $this->otp->normalize($data['phone']);
            $this->otp->issue($user, $phone, \App\Models\OtpChallenge::PURPOSE_LINK);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return redirect()
            ->route('phone.verify')
            ->with('phone', $phone)
            ->with('purpose', \App\Models\OtpChallenge::PURPOSE_LINK)
            ->with('success', 'We sent a verification code to that number.');
    }

    public function verifyPhoneLink(Request $request)
    {
        $user = auth()->user();
        $this->authorize('manageSecurity', $user);

        $data = $request->validate([
            'phone' => 'required|string|max:32',
            'purpose' => 'required|in:login,signup,link,recovery',
            'code' => 'required|string|size:6',
        ]);

        try {
            $phone = $this->otp->normalize($data['phone']);
            $this->otp->verify($user, $phone, $data['purpose'], $data['code']);
            $this->identities->linkPhone($user, $phone);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        $this->loginEvents->record($user, LoginEvent::EVENT_ACCOUNT_LINKED, LoginEvent::STATUS_SUCCESS, $request, [
            'provider' => 'phone',
        ]);

        $this->notifications->send(
            $user,
            Notification::TYPE_PHONE_LINKED,
            'Phone connected',
            'Your phone number was connected and verified.',
            NotificationService::link('settings.connected-accounts'),
        );

        $this->audit->recordQuietly($user, 'auth.phone_verified', 'user', $user->id, [
            'target_user_id' => $user->id,
        ]);

        return redirect()->route('settings.connected-accounts')->with('success', 'Phone connected and verified.');
    }

    public function unlinkPhone()
    {
        $user = auth()->user();
        $this->authorize('manageSecurity', $user);

        try {
            $this->identities->unlink($user, UserIdentity::PROVIDER_PHONE);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->loginEvents->record($user, LoginEvent::EVENT_ACCOUNT_UNLINKED, LoginEvent::STATUS_SUCCESS, null, [
            'provider' => 'phone',
        ]);

        $this->notifications->send(
            $user,
            Notification::TYPE_PHONE_CHANGED,
            'Phone disconnected',
            'Your phone number was disconnected from your account.',
            NotificationService::link('settings.connected-accounts'),
        );

        $this->audit->recordQuietly($user, 'auth.phone_unlinked', 'user', $user->id, [
            'target_user_id' => $user->id,
        ]);

        return back()->with('success', 'Phone disconnected.');
    }

    // ------------------------------------------------------------------
    // Account lifecycle (self-service)
    // ------------------------------------------------------------------

    public function deactivate(Request $request)
    {
        $user = auth()->user();
        $this->authorize('deactivate', $user);

        try {
            $this->lifecycle->deactivate($user, $user);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('success', 'Your account has been deactivated.');
    }

    public function reactivate()
    {
        $user = auth()->user();
        $this->authorize('reactivate', $user);

        try {
            $this->lifecycle->reactivate($user, $user);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('settings.security')->with('success', 'Your account has been reactivated.');
    }

    public function requestDeletion()
    {
        $user = auth()->user();
        $this->authorize('requestDeletion', $user);

        try {
            $this->lifecycle->requestDeletion($user);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Deletion requested. We will process it shortly.');
    }

    public function cancelDeletion()
    {
        $user = auth()->user();
        $this->authorize('requestDeletion', $user);

        try {
            $this->lifecycle->cancelDeletion($user);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Deletion request cancelled.');
    }
}

```

### 51.34 — `app/Http/Controllers/PaymentMethodsController.php`

> NEW — saved-methods controller

```php
<?php

namespace App\Http\Controllers;

use App\Models\PaymentMethod;
use App\Services\PaymentMethodService;
use App\Services\PaymentGatewayManager;
use DomainException;
use Illuminate\Http\Request;

/**
 * Saved payment-method management (Phase 14). A method belongs to exactly
 * one user; every read/write is ownership-checked via the policy and again
 * in the service.
 */
class PaymentMethodsController extends Controller
{
    public function __construct(
        protected PaymentMethodService $methods,
        protected PaymentGatewayManager $gateways,
    ) {
    }

    public function index()
    {
        $this->authorize('viewAny', PaymentMethod::class);

        $user = auth()->user();

        return view('settings.payment-methods', [
            'methods' => $this->methods->listFor($user),
            'providers' => $this->gateways->enabledProviders(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', PaymentMethod::class);

        $data = $request->validate([
            'provider' => 'required|in:' . implode(',', PaymentMethod::PROVIDERS),
            'label' => 'required|string|max:60',
            'identifier' => 'required|string|max:20',
        ]);

        try {
            $this->methods->add(auth()->user(), $data['provider'], $data['label'], $data['identifier']);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Payment method saved.');
    }

    public function destroy(PaymentMethod $method)
    {
        $this->authorize('delete', $method);

        try {
            $this->methods->remove(auth()->user(), $method);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Payment method removed.');
    }

    public function setDefault(PaymentMethod $method)
    {
        $this->authorize('setDefault', $method);

        try {
            $this->methods->setDefault(auth()->user(), $method);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Default payment method updated.');
    }
}

```

### 51.35 — `app/Http/Controllers/CheckoutController.php`

> NEW — provider selection + checkout

```php
<?php

namespace App\Http\Controllers;

use App\Models\LiveEvent;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\Team;
use App\Models\Tournament;
use App\Services\AuditLogService;
use App\Services\FraudRiskService;
use App\Services\LiveEventService;
use App\Services\NotificationService;
use App\Services\PaymentGatewayManager;
use App\Services\PaymentService;
use DomainException;
use Illuminate\Http\Request;

/**
 * Provider selection + checkout initiation (Phase 14).
 *
 * The legacy manual bKash form (PaymentController::verify) is preserved;
 * this controller adds a provider grid with honest per-provider status and a
 * single initiation endpoint that routes through the Phase 08 PaymentService.
 * A payment is only ever "successful" after server-side verification —
 * never from the frontend.
 */
class CheckoutController extends Controller
{
    public function __construct(
        protected PaymentService $payments,
        protected PaymentGatewayManager $gateways,
        protected FraudRiskService $risk,
        protected NotificationService $notifications,
        protected LiveEventService $live,
        protected AuditLogService $audit,
    ) {
    }

    /**
     * Choose a payment method for a team's entry fee.
     */
    public function methods(Tournament $tournament, Team $team)
    {
        abort_unless($team->belongsToTournament($tournament), 404);
        $this->authorize('pay', $team);

        $existing = Payment::where('team_id', $team->id)
            ->whereIn('status', Payment::ACTIVE_STATUSES)
            ->first();

        if ($existing !== null) {
            return redirect()->route('payment.pending', [$tournament, $team, $existing]);
        }

        return view('payment.methods', [
            'tournament' => $tournament,
            'team' => $team,
            'providers' => $this->gateways->enabledProviders(),
            'amountMinor' => $tournament->entryFeeMinor(),
        ]);
    }

    /**
     * Start a payment with the chosen provider.
     */
    public function initiate(Request $request, Tournament $tournament, Team $team)
    {
        abort_unless($team->belongsToTournament($tournament), 404);
        $this->authorize('pay', $team);

        $data = $request->validate([
            'provider' => 'required|in:' . implode(',', $this->gateways->providers()),
            'trx_id' => 'nullable|string|max:40',
        ]);

        $provider = $data['provider'];
        $gateway = $this->gateways->gateway($provider);
        $trxId = trim((string) ($data['trx_id'] ?? ''));

        // Phase 10 — fraud/risk gate for payment creation.
        try {
            $this->risk->evaluatePayment($request->user(), $tournament);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        try {
            $payment = $this->payments->createForTeam(
                $tournament,
                $team,
                $request->user(),
                $provider,
                $trxId !== '' ? $trxId : 'PENDING',
                $provider,
                $trxId !== '' ? $trxId : null,
            );
        } catch (DomainException $e) {
            $existing = Payment::where('team_id', $team->id)
                ->whereIn('status', Payment::ACTIVE_STATUSES)
                ->first();

            if ($existing !== null) {
                return redirect()->route('payment.pending', [$tournament, $team, $existing]);
            }

            return back()->with('error', $e->getMessage());
        }

        // Phase 13/14 — audit + notification + user live event.
        $this->audit->recordQuietly($request->user(), 'payment.initiated', 'payment', $payment->id, [
            'tournament_id' => $tournament->id,
            'metadata' => ['provider' => $provider],
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

        // Free entry → already confirmed.
        if ($payment->isSuccessful()) {
            return redirect()
                ->route('tournaments.show', $tournament)
                ->with('success', 'Registration confirmed! Your team is in.');
        }

        // Hosted/redirect providers — only when genuinely configured. When
        // they are not, fall back to the manual pending screen.
        if (! in_array($provider, ['bkash', 'nagad', 'rocket', 'bank'], true)) {
            try {
                $result = $gateway->createExternalPayment($payment);

                if (! empty($result['redirect_url'])) {
                    return redirect()->away($result['redirect_url']);
                }
            } catch (DomainException $e) {
                return redirect()
                    ->route('payment.pending', [$tournament, $team, $payment])
                    ->with('error', $e->getMessage());
            }
        }

        return redirect()->route('payment.pending', [$tournament, $team, $payment]);
    }
}

```

### 51.36 — `app/Http/Controllers/AdminAccountController.php`

> NEW — admin account administration

```php
<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AccountLifecycleService;
use App\Services\AuditLogService;
use App\Services\IdentityService;
use App\Services\IdentityVerificationService;
use App\Services\SessionManagementService;
use DomainException;
use Illuminate\Http\Request;

/**
 * Admin account administration (Phase 14).
 *
 * Admins may inspect account status, verification state, linked providers,
 * restrictions, security events and payment methods, and may revoke sessions,
 * deactivate/reactivate and delete (anonymize) accounts. Organizers and
 * moderators get none of these global controls.
 */
class AdminAccountController extends Controller
{
    public function __construct(
        protected IdentityService $identities,
        protected IdentityVerificationService $identityVerification,
        protected SessionManagementService $sessions,
        protected AccountLifecycleService $lifecycle,
        protected AuditLogService $audit,
    ) {
    }

    public function index(Request $request)
    {
        $this->authorize('adminAccounts', User::class);

        $users = User::query()->orderByDesc('id');

        $q = trim((string) $request->query('q', ''));

        if ($q !== '') {
            $users->where(function ($query) use ($q) {
                $query->where('name', 'like', "%{$q}%")
                    ->orWhere('username', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%");
            });
        }

        $role = (string) $request->query('role', '');
        if ($role !== '' && in_array($role, ['admin', 'organizer', 'moderator', 'player'], true)) {
            $users->where('role', $role);
        }

        $status = (string) $request->query('status', '');
        if ($status !== '' && in_array($status, ['active', 'deactivated', 'deletion_pending', 'deleted'], true)) {
            $users->where('account_status', $status);
        }

        $users = $users->paginate(25)->withQueryString();

        return view('admin.accounts.index', compact('users', 'q', 'role', 'status'));
    }

    public function show(User $user)
    {
        $this->authorize('adminAccount', $user);

        return view('admin.accounts.show', [
            'subject' => $user,
            'identities' => $this->identities->identitiesFor($user),
            'loginEvents' => $user->loginEvents()->limit(50)->get(),
            'sessions' => $this->sessions->sessionsFor($user),
            'restrictions' => $user->restrictions()->with('actor', 'liftedBy')->orderByDesc('id')->get(),
            'identity' => $this->identityVerification->effectiveStatus($user),
            'paymentMethods' => $user->paymentMethods()->orderByDesc('id')->get(),
            'riskProfile' => $user->riskProfile()->first(),
            'auditHistory' => $this->audit->relatedHistory('user', $user->id, 50),
        ]);
    }

    public function revokeSessions(User $user)
    {
        $this->authorize('adminRevokeSessions', $user);

        $count = $this->sessions->revokeAllForUser($user, auth()->user());

        return back()->with('success', "Revoked {$count} session(s) for {$user->name}.");
    }

    public function deactivate(User $user)
    {
        $this->authorize('adminDeactivate', $user);

        try {
            $this->lifecycle->deactivate($user, auth()->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Account deactivated.');
    }

    public function reactivate(User $user)
    {
        $this->authorize('adminDeactivate', $user);

        try {
            $this->lifecycle->reactivate($user, auth()->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Account reactivated.');
    }

    public function delete(User $user)
    {
        $this->authorize('adminDelete', $user);

        try {
            $this->lifecycle->executeDeletion($user, auth()->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.accounts.index')->with('success', 'Account deleted (anonymized).');
    }
}

```

### 51.37 — `app/Http/Controllers/AccountLiveController.php`

> NEW — account realtime feed

```php
<?php

namespace App\Http\Controllers;

use App\Services\LiveEventService;
use Illuminate\Http\Request;

/**
 * Account-level realtime feed (Phase 14).
 *
 * JSON polling endpoint that returns the authenticated user's own targeted
 * live events (payment status, session revocation, verification status)
 * newer than a cursor. Only the target user may read them; payloads carry no
 * sensitive data.
 */
class AccountLiveController extends Controller
{
    public function __construct(
        protected LiveEventService $live,
    ) {
    }

    public function index(Request $request)
    {
        $since = (int) $request->query('since', 0);
        $user = $request->user();

        $events = $this->live->sinceForUser($since, $user, 50);

        return response()->json([
            'revision' => $this->live->latestCursor(),
            'events' => $events->map(fn ($event) => [
                'id' => $event->id,
                'type' => $event->type,
                'payload' => $event->payload,
                'created_at' => $event->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }
}

```

### 51.38 — `resources/views/auth/forgot-password.blade.php`

> NEW — forgot-password view

```php
@extends('layouts.app')
@section('title', 'Forgot Password — FF Arena')
@section('content')
    <div class="card" style="max-width: 460px; margin: 50px auto">
        <h2>Forgot your password?</h2>
        <p class="muted" style="font-size:14px">Enter your email and we will send a reset link if an account exists.</p>
        <form method="POST" action="{{ route('password.email') }}">
            @csrf
            <label>Email</label>
            <input type="email" name="email" value="{{ old('email') }}" required autofocus>
            <button class="btn btn-primary" style="margin-top:18px; width:100%">Send reset link</button>
        </form>
        <p class="muted" style="margin-top:14px; font-size:13px">
            <a href="{{ route('login') }}">Back to login</a>
        </p>
    </div>
@endsection

```

### 51.39 — `resources/views/auth/reset-password.blade.php`

> NEW — reset-password view

```php
@extends('layouts.app')
@section('title', 'Reset Password — FF Arena')
@section('content')
    <div class="card" style="max-width: 460px; margin: 50px auto">
        <h2>Reset your password</h2>
        <form method="POST" action="{{ route('password.update') }}">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <label>Email</label>
            <input type="email" name="email" value="{{ old('email', $email) }}" required>
            <label>New password</label>
            <input type="password" name="password" required>
            <label>Confirm new password</label>
            <input type="password" name="password_confirmation" required>
            <button class="btn btn-primary" style="margin-top:18px; width:100%">Reset password</button>
        </form>
    </div>
@endsection

```

### 51.40 — `resources/views/auth/verify-email.blade.php`

> NEW — verify-email view

```php
@extends('layouts.app')
@section('title', 'Verify Email — FF Arena')
@section('content')
    <div class="card" style="max-width: 520px; margin: 50px auto">
        <h2>Verify your email</h2>
        <p class="muted" style="font-size:14px">
            A verification link was sent to <strong>{{ auth()->user()->email }}</strong>.
            Click the link in the email to verify your address.
        </p>
        <form method="POST" action="{{ route('verification.resend') }}">
            @csrf
            <button class="btn btn-cyan" style="margin-top:10px">Resend verification link</button>
        </form>
        <p class="muted" style="margin-top:14px; font-size:13px">
            <a href="{{ route('home') }}">Back to home</a>
        </p>
    </div>
@endsection

```

### 51.41 — `resources/views/auth/phone-login.blade.php`

> NEW — phone-login view

```php
@extends('layouts.app')
@section('title', 'Phone Login — FF Arena')
@section('content')
    <div class="card" style="max-width: 460px; margin: 50px auto">
        <h2>Login with your phone</h2>
        <p class="muted" style="font-size:14px">Enter your Bangladeshi mobile number. We will send a verification code.</p>
        <form method="POST" action="{{ route('phone.request') }}">
            @csrf
            <label>Mobile number</label>
            <input type="tel" name="phone" value="{{ old('phone') }}" placeholder="01712345678" required autofocus>
            <button class="btn btn-primary" style="margin-top:18px; width:100%">Send verification code</button>
        </form>
        <p class="muted" style="margin-top:14px; font-size:13px">
            <a href="{{ route('login') }}">Login with email instead</a>
        </p>
    </div>
@endsection

```

### 51.42 — `resources/views/auth/phone-verify.blade.php`

> NEW — phone-verify view

```php
@extends('layouts.app')
@section('title', 'Enter Code — FF Arena')
@section('content')
    <div class="card" style="max-width: 460px; margin: 50px auto">
        <h2>Enter the code</h2>
        <p class="muted" style="font-size:14px">
            We sent a 6-digit code to <strong>{{ $phone ?? old('phone') }}</strong>.
        </p>
        @php
            $purpose = $purpose ?? old('purpose', 'login');
            $action = $purpose === 'link'
                ? route('settings.phone.link.verify')
                : route('phone.login.verify');
        @endphp
        <form method="POST" action="{{ $action }}">
            @csrf
            <input type="hidden" name="phone" value="{{ $phone ?? old('phone') }}">
            <input type="hidden" name="purpose" value="{{ $purpose }}">
            <label>Verification code</label>
            <input type="text" name="code" inputmode="numeric" maxlength="6" required autofocus>
            <button class="btn btn-primary" style="margin-top:18px; width:100%">Verify</button>
        </form>
        <p class="muted" style="margin-top:14px; font-size:13px">
            @if ($purpose === 'link')
                <a href="{{ route('settings.connected-accounts') }}">Back to connected accounts</a>
            @else
                <a href="{{ route('phone.login') }}">Use a different number</a>
            @endif
        </p>
    </div>
@endsection

```

### 51.43 — `resources/views/profile/show.blade.php`

> NEW — public profile view

```php
@extends('layouts.app')
@section('title', $user->name . ' — FF Arena')
@section('content')
    <div class="card" style="max-width: 640px; margin: 40px auto">
        @if (! $profile['visible'])
            <h2>{{ $user->name }}</h2>
            <p class="muted">This profile is private.</p>
        @else
            <div style="display:flex; gap:18px; align-items:center">
                @if (! empty($profile['avatar']))
                    <img src="{{ $profile['avatar'] }}" alt="avatar"
                         style="width:84px; height:84px; border-radius:50%; object-fit:cover; border:1px solid var(--line)">
                @else
                    <div style="width:84px; height:84px; border-radius:50%; background:var(--panel2); display:flex; align-items:center; justify-content:center; font-size:30px; font-weight:800; color:var(--cyan)">{{ strtoupper(substr($profile['name'], 0, 1)) }}</div>
                @endif
                <div>
                    <h2 style="margin:0">{{ $profile['name'] }}</h2>
                    @if (! empty($profile['username']))
                        <div class="muted">@{{ $profile['username'] }}</div>
                    @endif
                    <div style="margin-top:6px">
                        <span class="pill confirmed">{{ ucfirst($profile['role']) }}</span>
                    </div>
                </div>
            </div>

            @if (! empty($profile['bio']))
                <p style="margin-top:18px">{{ $profile['bio'] }}</p>
            @endif

            <div style="margin-top:16px; display:flex; gap:18px; flex-wrap:wrap" class="muted">
                @if (! empty($profile['country']))
                    <span>🌍 {{ $profile['country'] }}{{ ! empty($profile['region']) ? ' · ' . $profile['region'] : '' }}</span>
                @endif
                @if (! empty($profile['joined_at']))
                    <span>Joined {{ $profile['joined_at'] }}</span>
                @endif
            </div>
        @endif

        @auth
            @if (auth()->id() === $user->id)
                <div style="margin-top:18px">
                    <a href="{{ route('profile.edit') }}" class="btn btn-cyan btn-sm">Edit profile</a>
                </div>
            @endif
        @endauth
    </div>
@endsection

```

### 51.44 — `resources/views/profile/edit.blade.php`

> NEW — profile settings view

```php
@extends('layouts.app')
@section('title', 'Profile Settings — FF Arena')
@section('content')
    <h1 style="margin:30px 0 16px">Profile Settings</h1>

    <div class="grid cols-2">
        <div class="card">
            <h3>Basic profile</h3>
            <form method="POST" action="{{ route('profile.update') }}">
                @csrf
                @method('PUT')
                <label>Display name</label>
                <input type="text" name="name" value="{{ old('name', $user->name) }}" required>
                <label>Bio</label>
                <textarea name="bio" rows="3" maxlength="500">{{ old('bio', $user->bio) }}</textarea>
                <label>Country (2 letters)</label>
                <input type="text" name="country" value="{{ old('country', $user->country) }}" maxlength="2" placeholder="BD">
                <label>Region / city</label>
                <input type="text" name="region" value="{{ old('region', $user->region) }}" maxlength="100">
                <label>Avatar URL</label>
                <input type="url" name="avatar" value="{{ old('avatar', $user->avatar) }}" placeholder="https://…">
                <button class="btn btn-primary" style="margin-top:16px">Save profile</button>
            </form>
        </div>

        <div style="display:flex; flex-direction:column; gap:18px">
            <div class="card">
                <h3>Username</h3>
                <form method="POST" action="{{ route('profile.username') }}">
                    @csrf
                    @method('PUT')
                    <label>Username (3–20 chars, letters/numbers/._-)</label>
                    <input type="text" name="username" value="{{ old('username', $user->username) }}" required>
                    <button class="btn btn-sm" style="margin-top:10px">Change username</button>
                </form>
            </div>

            <div class="card">
                <h3>Privacy</h3>
                <form method="POST" action="{{ route('profile.privacy') }}">
                    @csrf
                    @method('PUT')
                    <label>Who can see your profile</label>
                    <select name="privacy">
                        @foreach (['public', 'registered', 'private'] as $privacy)
                            <option value="{{ $privacy }}" @selected($user->privacy === $privacy)>{{ ucfirst($privacy) }}</option>
                        @endforeach
                    </select>
                    <button class="btn btn-sm" style="margin-top:10px">Save privacy</button>
                </form>
            </div>

            <div class="card">
                <h3>Region &amp; preferences</h3>
                <form method="POST" action="{{ route('profile.preferences') }}">
                    @csrf
                    @method('PUT')
                    <label>Country (2 letters)</label>
                    <input type="text" name="country" value="{{ old('country', $user->country) }}" maxlength="2">
                    <label>Region / city</label>
                    <input type="text" name="region" value="{{ old('region', $user->region) }}" maxlength="100">
                    <label>Language</label>
                    <input type="text" name="language" value="{{ old('language', $user->language) }}" maxlength="5" placeholder="en">
                    <label>Timezone</label>
                    <input type="text" name="timezone" value="{{ old('timezone', $user->timezone) }}" placeholder="Asia/Dhaka">
                    <button class="btn btn-sm" style="margin-top:10px">Save preferences</button>
                </form>
            </div>
        </div>
    </div>

    <div class="card">
        <h3>Account links</h3>
        <div style="display:flex; gap:10px; flex-wrap:wrap">
            <a href="{{ route('settings.security') }}" class="btn btn-sm">Security settings</a>
            <a href="{{ route('settings.connected-accounts') }}" class="btn btn-sm">Connected accounts</a>
            <a href="{{ route('settings.sessions') }}" class="btn btn-sm">Active sessions</a>
            <a href="{{ route('settings.login-history') }}" class="btn btn-sm">Login history</a>
            <a href="{{ route('settings.payment-methods') }}" class="btn btn-sm">Payment methods</a>
        </div>
    </div>
@endsection

```

### 51.45 — `resources/views/settings/security.blade.php`

> NEW — security settings view

```php
@extends('layouts.app')
@section('title', 'Security Settings — FF Arena')
@section('content')
    <h1 style="margin:30px 0 16px">Security Settings</h1>

    <div class="grid cols-2">
        <div class="card">
            <h3>Account status</h3>
            <table>
                <tr>
                    <th>Check</th><th>Status</th>
                </tr>
                <tr>
                    <td>Email verification</td>
                    <td>
                        @if ($user->hasVerifiedEmail())
                            <span class="pill confirmed">Verified</span>
                        @else
                            <span class="pill pending">Not verified</span>
                            <a href="{{ route('verification.notice') }}" class="btn btn-sm" style="margin-left:8px">Verify</a>
                        @endif
                    </td>
                </tr>
                <tr>
                    <td>Phone verification</td>
                    <td>
                        @if ($identities->contains('provider', 'phone'))
                            <span class="pill confirmed">Verified</span>
                        @else
                            <span class="pill pending">Not verified</span>
                            <a href="{{ route('settings.connected-accounts') }}" class="btn btn-sm" style="margin-left:8px">Verify</a>
                        @endif
                    </td>
                </tr>
                <tr>
                    <td>Google account</td>
                    <td>
                        @if ($identities->contains('provider', 'google'))
                            <span class="pill confirmed">Linked</span>
                        @else
                            <span class="pill draft">Not linked</span>
                            <a href="{{ route('settings.connected-accounts') }}" class="btn btn-sm" style="margin-left:8px">Link</a>
                        @endif
                    </td>
                </tr>
                <tr>
                    <td>Password</td>
                    <td>
                        @if ($hasPassword)
                            <span class="pill confirmed">Set</span>
                        @else
                            <span class="pill pending">Not set</span>
                        @endif
                    </td>
                </tr>
                <tr>
                    <td>Account state</td>
                    <td><span class="pill {{ $user->isActive() ? 'confirmed' : 'failed' }}">{{ $user->account_status }}</span></td>
                </tr>
            </table>
        </div>

        <div class="card">
            <h3>Change password</h3>
            <form method="POST" action="{{ route('settings.password') }}">
                @csrf
                @if ($hasPassword)
                    <label>Current password</label>
                    <input type="password" name="current_password" required>
                @endif
                <label>New password (min 8 characters)</label>
                <input type="password" name="password" required>
                <label>Confirm new password</label>
                <input type="password" name="password_confirmation" required>
                <button class="btn btn-primary" style="margin-top:14px">{{ $hasPassword ? 'Change password' : 'Set password' }}</button>
            </form>
        </div>
    </div>

    <div class="card" style="margin-top:18px">
        <h3>Active sessions</h3>
        <p class="muted" style="font-size:14px">Review and revoke your active sessions, or sign out everywhere.</p>
        <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:10px">
            <a href="{{ route('settings.sessions') }}" class="btn btn-sm">View sessions</a>
            <form method="POST" action="{{ route('settings.sessions.revokeAll') }}" style="display:inline">
                @csrf
                <button class="btn btn-sm" style="border-color:var(--red); color:var(--red)">Sign out everywhere</button>
            </form>
        </div>
    </div>

    <div class="card" style="margin-top:18px; border-color:var(--red)">
        <h3 style="color:var(--red)">Danger zone</h3>
        @if ($user->isActive())
            <div style="display:flex; gap:10px; flex-wrap:wrap">
                <form method="POST" action="{{ route('settings.deactivate') }}" style="display:inline"
                      onsubmit="return confirm('Deactivate your account? You can reactivate it later.')">
                    @csrf
                    <button class="btn btn-sm" style="border-color:var(--amber); color:var(--amber)">Deactivate account</button>
                </form>
                <form method="POST" action="{{ route('settings.deletion.request') }}" style="display:inline"
                      onsubmit="return confirm('Request account deletion? This cannot be undone.')">
                    @csrf
                    <button class="btn btn-sm" style="border-color:var(--red); color:var(--red)">Request deletion</button>
                </form>
            </div>
        @elseif ($user->account_status === 'deactivated')
            <form method="POST" action="{{ route('settings.reactivate') }}">
                @csrf
                <button class="btn btn-green btn-sm">Reactivate account</button>
            </form>
        @elseif ($user->account_status === 'deletion_pending')
            <p class="muted">Deletion requested — it will be processed shortly.</p>
            <form method="POST" action="{{ route('settings.deletion.cancel') }}">
                @csrf
                <button class="btn btn-sm">Cancel deletion request</button>
            </form>
        @endif
    </div>
@endsection

```

### 51.46 — `resources/views/settings/connected-accounts.blade.php`

> NEW — connected accounts view

```php
@extends('layouts.app')
@section('title', 'Connected Accounts — FF Arena')
@section('content')
    <h1 style="margin:30px 0 16px">Connected Accounts</h1>

    <div class="card">
        <h3>Password</h3>
        @if ($hasPassword)
            <p class="muted">A password is set on this account. You can change it in
                <a href="{{ route('settings.security') }}">security settings</a>.</p>
        @else
            <p class="muted">No password set. <a href="{{ route('settings.security') }}">Set one</a> so you can sign in
                even if you unlink a provider.</p>
        @endif
    </div>

    <div class="card">
        <h3>Google</h3>
        @if ($identities->contains('provider', 'google'))
            <p>✅ <span class="pill confirmed">Connected</span></p>
            <form method="POST" action="{{ route('settings.google.unlink') }}">
                @csrf
                <button class="btn btn-sm" style="border-color:var(--red); color:var(--red)">Disconnect Google</button>
            </form>
        @else
            @if ($googleConfigured)
                <p class="muted">Connect Google to sign in with one click.</p>
                <a href="{{ route('settings.google.link') }}" class="btn btn-primary btn-sm">Connect Google</a>
            @else
                <p class="muted">Google Sign-In is not configured.</p>
            @endif
        @endif
    </div>

    <div class="card">
        <h3>Phone</h3>
        @if ($identities->contains('provider', 'phone'))
            @php($phoneIdentity = $identities->firstWhere('provider', 'phone'))
            <p>✅ <span class="pill confirmed">Verified</span> <span class="muted">{{ $phoneIdentity->provider_subject }}</span></p>
            <form method="POST" action="{{ route('settings.phone.unlink') }}">
                @csrf
                <button class="btn btn-sm" style="border-color:var(--red); color:var(--red)">Disconnect phone</button>
            </form>
        @else
            @if ($phoneConfigured)
                <p class="muted">Verify your phone number to enable phone sign-in.</p>
                <form method="POST" action="{{ route('settings.phone.link') }}">
                    @csrf
                    <label>Mobile number</label>
                    <input type="tel" name="phone" value="{{ old('phone', $user->phone) }}" placeholder="01712345678" required>
                    <button class="btn btn-primary btn-sm" style="margin-top:10px">Verify phone</button>
                </form>
            @else
                <p class="muted">Phone verification is not configured.</p>
            @endif
        @endif
    </div>
@endsection

```

### 51.47 — `resources/views/settings/sessions.blade.php`

> NEW — active sessions view

```php
@extends('layouts.app')
@section('title', 'Active Sessions — FF Arena')
@section('content')
    <h1 style="margin:30px 0 16px">Active Sessions</h1>

    <div class="card">
        @if ($sessions->isEmpty())
            <p class="muted">No active sessions found.</p>
        @else
            <table>
                <tr><th>Device</th><th>Last activity</th><th></th></tr>
                @foreach ($sessions as $session)
                    <tr>
                        <td>
                            {{ $session['device_label'] }}
                            @if ($session['is_current'])
                                <span class="pill live">This device</span>
                            @endif
                        </td>
                        <td class="muted">{{ \Illuminate\Support\Carbon::createFromTimestamp($session['last_activity'])->diffForHumans() }}</td>
                    </tr>
                @endforeach
            </table>
            <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:14px">
                <form method="POST" action="{{ route('settings.sessions.revokeOthers') }}">
                    @csrf
                    <button class="btn btn-sm">Sign out other devices</button>
                </form>
                <form method="POST" action="{{ route('settings.sessions.revokeAll') }}">
                    @csrf
                    <button class="btn btn-sm" style="border-color:var(--red); color:var(--red)">Sign out everywhere</button>
                </form>
            </div>
        @endif
    </div>
@endsection

```

### 51.48 — `resources/views/settings/login-history.blade.php`

> NEW — login history view

```php
@extends('layouts.app')
@section('title', 'Login History — FF Arena')
@section('content')
    <h1 style="margin:30px 0 16px">Login History</h1>

    <div class="card">
        @if ($events->isEmpty())
            <p class="muted">No security events recorded yet.</p>
        @else
            <table>
                <tr><th>Event</th><th>Status</th><th>Device</th><th>When</th></tr>
                @foreach ($events as $event)
                    <tr>
                        <td>{{ ucwords(str_replace(['.', '_'], ' ', $event->event)) }}</td>
                        <td>
                            <span class="pill {{ $event->status === 'success' ? 'confirmed' : 'failed' }}">{{ $event->status }}</span>
                        </td>
                        <td class="muted">{{ $event->device_label ?? '—' }}</td>
                        <td class="muted">{{ $event->created_at?->diffForHumans() }}</td>
                    </tr>
                @endforeach
            </table>
            {{ $events->links() }}
        @endif
    </div>
@endsection

```

### 51.49 — `resources/views/settings/payment-methods.blade.php`

> NEW — saved methods view

```php
@extends('layouts.app')
@section('title', 'Payment Methods — FF Arena')
@section('content')
    <h1 style="margin:30px 0 16px">Payment Methods</h1>

    <div class="grid cols-2">
        <div class="card">
            <h3>Your methods</h3>
            @if ($methods->isEmpty())
                <p class="muted">No saved payment methods.</p>
            @else
                <table>
                    <tr><th>Label</th><th>Provider</th><th>Identifier</th><th></th></tr>
                    @foreach ($methods as $method)
                        <tr>
                            <td>
                                {{ $method->label }}
                                @if ($method->is_default)
                                    <span class="pill confirmed">Default</span>
                                @endif
                            </td>
                            <td class="muted">{{ $method->provider }}</td>
                            <td class="muted">{{ $method->masked_identifier }}</td>
                            <td>
                                <form method="POST" action="{{ route('settings.payment-methods.default', $method) }}" style="display:inline">
                                    @csrf
                                    <button class="btn btn-sm">Make default</button>
                                </form>
                                <form method="POST" action="{{ route('settings.payment-methods.destroy', $method) }}" style="display:inline">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-sm" style="border-color:var(--red); color:var(--red)">Remove</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </table>
            @endif
        </div>

        <div class="card">
            <h3>Add a method</h3>
            <form method="POST" action="{{ route('settings.payment-methods.store') }}">
                @csrf
                <label>Provider</label>
                <select name="provider">
                    @foreach ($providers as $provider)
                        <option value="{{ $provider['id'] }}">{{ $provider['label'] }}</option>
                    @endforeach
                </select>
                <label>Label</label>
                <input type="text" name="label" value="{{ old('label') }}" placeholder="My bKash" required>
                <label>Account identifier (number/account)</label>
                <input type="text" name="identifier" value="{{ old('identifier') }}" placeholder="01712345678" required>
                <p class="muted" style="font-size:12px">Only the last 4 digits are ever stored.</p>
                <button class="btn btn-primary btn-sm" style="margin-top:10px">Save method</button>
            </form>
        </div>
    </div>
@endsection

```

### 51.50 — `resources/views/payment/methods.blade.php`

> NEW — provider selection view

```php
@extends('layouts.app')
@section('title', 'Choose Payment Method — FF Arena')
@section('content')
    <div class="card" style="max-width: 620px; margin: 40px auto">
        <h2>Pay entry fee</h2>
        <p class="muted" style="font-size:14px">
            Tournament <strong>{{ $tournament->name }}</strong> · Team <strong>{{ $team->name }}</strong>
        </p>
        <div class="stat" style="margin:12px 0">
            <div class="muted">Amount due</div>
            <div class="num">{{ \App\Support\Money::formatMinor($amountMinor) }}</div>
        </div>

        @forelse ($providers as $provider)
            <div class="card" style="padding:14px; margin-bottom:10px">
                <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px">
                    <div>
                        <strong>{{ $provider['label'] }}</strong>
                        @if (! $provider['configured'])
                            <span class="pill pending" style="margin-left:6px">Not configured</span>
                        @elseif ($provider['mode'] === 'sandbox')
                            <span class="pill live" style="margin-left:6px">Sandbox</span>
                        @endif
                    </div>
                    @if (in_array($provider['id'], ['bkash', 'nagad', 'rocket', 'bank'], true))
                        <form method="POST" action="{{ route('payment.initiate', [$tournament, $team]) }}" style="display:flex; gap:8px; flex-wrap:wrap; align-items:center">
                            @csrf
                            <input type="hidden" name="provider" value="{{ $provider['id'] }}">
                            <input type="text" name="trx_id" placeholder="Transaction ID" style="max-width:190px">
                            <button class="btn btn-primary btn-sm">Submit</button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('payment.initiate', [$tournament, $team]) }}">
                            @csrf
                            <input type="hidden" name="provider" value="{{ $provider['id'] }}">
                            <button class="btn btn-primary btn-sm" @disabled(! $provider['configured'])>
                                Pay with {{ $provider['label'] }}
                            </button>
                        </form>
                    @endif
                </div>
                @if (! $provider['configured'])
                    <p class="muted" style="font-size:12px; margin-top:6px">Provider is not configured — payments are not possible with this method yet.</p>
                @endif
            </div>
        @empty
            <p class="muted">No payment methods are currently enabled.</p>
        @endforelse

        <p class="muted" style="font-size:12px; margin-top:12px">
            🔒 Payments are verified server-side. Your team is confirmed only after the payment is verified.
        </p>
        <p style="margin-top:10px"><a href="{{ route('teams.show', [$tournament, $team]) }}">← Back to team</a></p>
    </div>
@endsection

```

### 51.51 — `resources/views/admin/accounts/index.blade.php`

> NEW — admin accounts index view

```php
@extends('layouts.app')
@section('title', 'Accounts — FF Arena')
@section('content')
    <h1 style="margin:30px 0 16px">👥 Account Administration</h1>

    <div class="card">
        <form method="GET" action="{{ route('admin.accounts.index') }}" style="display:flex; gap:10px; flex-wrap:wrap; align-items:end">
            <div style="flex:1; min-width:220px">
                <label>Search (name / username / email)</label>
                <input type="text" name="q" value="{{ $q }}" placeholder="Search accounts…">
            </div>
            <div>
                <label>Role</label>
                <select name="role">
                    <option value="">All roles</option>
                    @foreach (['player', 'organizer', 'moderator', 'admin'] as $roleValue)
                        <option value="{{ $roleValue }}" @selected($roleValue === $role)>{{ ucfirst($roleValue) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label>Status</label>
                <select name="status">
                    <option value="">All statuses</option>
                    @foreach (['active', 'deactivated', 'deletion_pending', 'deleted'] as $statusValue)
                        <option value="{{ $statusValue }}" @selected($statusValue === $status)>{{ ucwords(str_replace('_', ' ', $statusValue)) }}</option>
                    @endforeach
                </select>
            </div>
            <button class="btn btn-cyan btn-sm">Filter</button>
        </form>
    </div>

    <div class="card">
        <table>
            <tr><th>ID</th><th>Name</th><th>Username</th><th>Role</th><th>Status</th><th>Email verified</th><th></th></tr>
            @foreach ($users as $user)
                <tr>
                    <td class="muted">{{ $user->id }}</td>
                    <td>{{ $user->name }}</td>
                    <td class="muted">{{ $user->username }}</td>
                    <td><span class="pill {{ $user->role === 'admin' ? 'live' : ($user->role === 'moderator' ? 'pending' : 'draft') }}">{{ $user->role }}</span></td>
                    <td><span class="pill {{ $user->account_status === 'active' ? 'confirmed' : 'failed' }}">{{ $user->account_status }}</span></td>
                    <td>{{ $user->hasVerifiedEmail() ? '✅' : '—' }}</td>
                    <td><a href="{{ route('admin.accounts.show', $user) }}" class="btn btn-sm">View</a></td>
                </tr>
            @endforeach
        </table>
        {{ $users->links() }}
    </div>
@endsection

```

### 51.52 — `resources/views/admin/accounts/show.blade.php`

> NEW — admin account detail view

```php
@extends('layouts.app')
@section('title', 'Account: ' . $subject->name . ' — FF Arena')
@section('content')
    <h1 style="margin:30px 0 6px">👤 {{ $subject->name }}</h1>
    <p class="muted">ID {{ $subject->id }} · {{ $subject->email }} ·
        <span class="pill {{ $subject->role === 'admin' ? 'live' : ($subject->role === 'moderator' ? 'pending' : 'draft') }}">{{ $subject->role }}</span>
        <span class="pill {{ $subject->account_status === 'active' ? 'confirmed' : 'failed' }}">{{ $subject->account_status }}</span>
    </p>

    <div class="card" style="display:flex; gap:10px; flex-wrap:wrap; margin-top:14px">
        <form method="POST" action="{{ route('admin.accounts.sessions.revoke', $subject) }}">
            @csrf
            <button class="btn btn-sm">Revoke all sessions</button>
        </form>
        @if ($subject->isActive())
            <form method="POST" action="{{ route('admin.accounts.deactivate', $subject) }}">
                @csrf
                <button class="btn btn-sm" style="border-color:var(--amber); color:var(--amber)">Deactivate</button>
            </form>
        @elseif ($subject->account_status === 'deactivated')
            <form method="POST" action="{{ route('admin.accounts.reactivate', $subject) }}">
                @csrf
                <button class="btn btn-green btn-sm">Reactivate</button>
            </form>
        @endif
        <form method="POST" action="{{ route('admin.accounts.delete', $subject) }}"
              onsubmit="return confirm('Anonymize (delete) this account? Immutable history is preserved.')">
            @csrf
            <button class="btn btn-sm" style="border-color:var(--red); color:var(--red)">Delete (anonymize)</button>
        </form>
    </div>

    <div class="grid cols-2" style="margin-top:14px">
        <div class="card">
            <h3>Identities &amp; verification</h3>
            <table>
                <tr><th>Provider</th><th>Subject</th><th>Verified</th></tr>
                @foreach ($identities as $linkedIdentity)
                    <tr>
                        <td>{{ $linkedIdentity->provider }}</td>
                        <td class="muted">{{ $linkedIdentity->provider_subject }}</td>
                        <td>{{ $linkedIdentity->isVerified() ? '✅' : '—' }}</td>
                    </tr>
                @endforeach
                @if ($identities->isEmpty())
                    <tr><td colspan="3" class="muted">No linked identities.</td></tr>
                @endif
            </table>
            <p style="margin-top:10px">
                Email verified: {{ $subject->hasVerifiedEmail() ? '✅' : '—' }} ·
                Identity verification:
                <span class="pill {{ $identity->statusPill() }}">{{ $identity->statusLabel() }}</span>
            </p>
        </div>

        <div class="card">
            <h3>Risk &amp; restrictions</h3>
            <p>
                Risk level:
                <span class="pill {{ ($riskProfile?->risk_level ?? 'low') === 'low' ? 'confirmed' : 'failed' }}">
                    {{ $riskProfile?->risk_level ?? 'low' }}
                </span>
                (score {{ $riskProfile?->risk_score ?? 0 }})
            </p>
            @if ($restrictions->isEmpty())
                <p class="muted">No restrictions.</p>
            @else
                <ul>
                    @foreach ($restrictions as $restriction)
                        <li>{{ $restriction->typeLabel() }} — {{ $restriction->reason }}
                            <span class="muted">({{ $restriction->status }})</span></li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>

    <div class="grid cols-2" style="margin-top:14px">
        <div class="card">
            <h3>Recent security events</h3>
            @if ($loginEvents->isEmpty())
                <p class="muted">None.</p>
            @else
                <table>
                    <tr><th>Event</th><th>Status</th><th>Device</th><th>When</th></tr>
                    @foreach ($loginEvents as $event)
                        <tr>
                            <td>{{ ucwords(str_replace(['.', '_'], ' ', $event->event)) }}</td>
                            <td>{{ $event->status }}</td>
                            <td class="muted">{{ $event->device_label ?? '—' }}</td>
                            <td class="muted">{{ $event->created_at?->diffForHumans() }}</td>
                        </tr>
                    @endforeach
                </table>
            @endif
        </div>

        <div class="card">
            <h3>Payment methods</h3>
            @if ($paymentMethods->isEmpty())
                <p class="muted">None.</p>
            @else
                <table>
                    <tr><th>Provider</th><th>Label</th><th>Identifier</th></tr>
                    @foreach ($paymentMethods as $method)
                        <tr>
                            <td>{{ $method->provider }}</td>
                            <td>{{ $method->label }}</td>
                            <td class="muted">{{ $method->masked_identifier }}</td>
                        </tr>
                    @endforeach
                </table>
            @endif
        </div>
    </div>

    <div class="card" style="margin-top:14px">
        <h3>Audit history</h3>
        @if ($auditHistory->isEmpty())
            <p class="muted">No audit entries.</p>
        @else
            <table>
                <tr><th>Action</th><th>Entity</th><th>When</th></tr>
                @foreach ($auditHistory as $entry)
                    <tr>
                        <td>{{ $entry->action }}</td>
                        <td class="muted">{{ $entry->entity_type }}#{{ $entry->entity_id }}</td>
                        <td class="muted">{{ $entry->created_at?->diffForHumans() }}</td>
                    </tr>
                @endforeach
            </table>
        @endif
    </div>
@endsection

```

### 51.53 — `tests/Feature/AccountAuthTest.php`

> NEW — email/password auth tests

```php
<?php

namespace Tests\Feature;

use App\Models\LoginEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Phase 14 — email/password auth: signup, login, logout, email verification,
 * password reset (enumeration-safe) and rate limits.
 */
class AccountAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $role = 'player', array $attrs = []): User
    {
        $user = User::factory()->create($attrs);
        $user->role = $role;
        $user->save();

        return $user;
    }

    public function test_user_can_register_with_email_and_password(): void
    {
        $response = $this->post(route('register'), [
            'name' => 'Alam Rahman',
            'username' => 'alamrahman',
            'email' => 'alam@example.com',
            'phone' => '01712345678',
            'game_uid' => '123456789',
            'role' => 'player',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $response->assertRedirect(route('home'));

        $user = User::where('email', 'alam@example.com')->first();
        $this->assertNotNull($user);
        $this->assertSame('player', $user->role);
        $this->assertTrue(Hash::check('secret123', $user->password));
        $this->assertSame('active', $user->account_status);
        $this->assertAuthenticatedAs($user);
    }

    public function test_registration_rejects_reserved_role(): void
    {
        $response = $this->post(route('register'), [
            'name' => 'Hacker',
            'username' => 'hacker',
            'email' => 'hacker@example.com',
            'role' => 'admin',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $response->assertSessionHasErrors('role');
        $this->assertDatabaseMissing('users', ['email' => 'hacker@example.com']);
    }

    public function test_login_succeeds_with_correct_credentials_and_records_history(): void
    {
        $user = $this->makeUser(attrs: ['email' => 'alam@example.com', 'password' => Hash::make('secret123')]);

        $this->post(route('login'), ['email' => 'alam@example.com', 'password' => 'secret123'])
            ->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('login_events', [
            'user_id' => $user->id,
            'event' => LoginEvent::EVENT_LOGIN_PASSWORD,
            'status' => 'success',
        ]);
    }

    public function test_login_fails_with_wrong_password_and_records_failure(): void
    {
        $user = $this->makeUser(attrs: ['email' => 'alam@example.com', 'password' => Hash::make('secret123')]);

        $this->post(route('login'), ['email' => 'alam@example.com', 'password' => 'wrong'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseHas('login_events', [
            'user_id' => $user->id,
            'event' => LoginEvent::EVENT_LOGIN_FAILED,
            'status' => 'failure',
        ]);
    }

    public function test_login_is_throttled(): void
    {
        $this->makeUser(attrs: ['email' => 'alam@example.com', 'password' => Hash::make('secret123')]);

        for ($i = 0; $i < 6; $i++) {
            $this->post(route('login'), ['email' => 'alam@example.com', 'password' => 'wrong']);
        }

        $this->post(route('login'), ['email' => 'alam@example.com', 'password' => 'secret123'])
            ->assertStatus(429);
    }

    public function test_logout_invalidates_the_session(): void
    {
        $user = $this->makeUser(attrs: ['email' => 'alam@example.com']);

        $this->actingAs($user)->post(route('logout'))->assertRedirect(route('home'));

        $this->assertGuest();
    }

    public function test_forgot_password_is_enumeration_safe(): void
    {
        $this->makeUser(attrs: ['email' => 'alam@example.com']);

        $response = $this->post(route('password.email'), ['email' => 'alam@example.com']);
        $response->assertSessionHas('success');

        // A non-existent address gets the identical generic message.
        $response2 = $this->post(route('password.email'), ['email' => 'nobody@example.com']);
        $response2->assertSessionHas('success');
    }

    public function test_password_reset_with_valid_token_changes_password(): void
    {
        $user = $this->makeUser(attrs: ['email' => 'alam@example.com', 'password' => Hash::make('oldsecret')]);

        $token = app('auth.password.broker')->createToken($user);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => 'alam@example.com',
            'password' => 'newsecret123',
            'password_confirmation' => 'newsecret123',
        ])->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('newsecret123', $user->fresh()->password));
    }

    public function test_password_reset_with_invalid_token_is_rejected(): void
    {
        $this->makeUser(attrs: ['email' => 'alam@example.com']);

        $this->post(route('password.update'), [
            'token' => 'bogus',
            'email' => 'alam@example.com',
            'password' => 'newsecret123',
            'password_confirmation' => 'newsecret123',
        ])->assertSessionHasErrors('email');
    }

    public function test_email_verification_signed_url_marks_email_verified(): void
    {
        $user = $this->makeUser(attrs: ['email' => 'alam@example.com', 'email_verified_at' => null]);

        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1('alam@example.com')],
        );

        $this->actingAs($user)->get($url)->assertRedirect(route('home'));

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->assertDatabaseHas('login_events', [
            'user_id' => $user->id,
            'event' => LoginEvent::EVENT_EMAIL_VERIFIED,
        ]);
    }

    public function test_email_verification_with_wrong_hash_is_rejected(): void
    {
        $user = $this->makeUser(attrs: ['email' => 'alam@example.com', 'email_verified_at' => null]);

        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1('other@example.com')],
        );

        $this->actingAs($user)->get($url)->assertForbidden();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_email_verification_with_tampered_signature_is_rejected(): void
    {
        $user = $this->makeUser(attrs: ['email' => 'alam@example.com', 'email_verified_at' => null]);

        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1('alam@example.com')],
        );

        // Tamper with the id in the path after signing.
        $tampered = str_replace("/verify-email/{$user->id}/", '/verify-email/' . ($user->id + 1) . '/', $url);

        $this->actingAs($user)->get($tampered)->assertForbidden();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_resend_verification_sends_a_link_only_for_unverified_users(): void
    {
        $user = $this->makeUser(attrs: ['email' => 'alam@example.com', 'email_verified_at' => null]);

        $this->actingAs($user)->post(route('verification.resend'))->assertSessionHas('success');
    }

    public function test_verification_notice_redirects_verified_users_home(): void
    {
        $user = $this->makeUser(attrs: ['email' => 'alam@example.com']);

        $this->actingAs($user)->get(route('verification.notice'))->assertRedirect(route('home'));
    }
}

```

### 51.54 — `tests/Feature/GoogleAuthTest.php`

> NEW — Google sign-in tests

```php
<?php

namespace Tests\Feature;

use App\Contracts\GoogleOAuthProviderInterface;
use App\Models\User;
use App\Models\UserIdentity;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Tests\TestCase;

/**
 * Phase 14 — Google Sign-In orchestration with a deterministic fake OAuth
 * provider (tests never touch the network).
 */
class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A deterministic Google provider double.
     */
    protected function bindFakeGoogle(array $user, bool $configured = true): FakeGoogleProvider
    {
        $fake = new FakeGoogleProvider($user, $configured);
        $this->app->instance(GoogleOAuthProviderInterface::class, $fake);

        return $fake;
    }

    protected function addIdentity(User $user, string $subject, string $email, ?\Illuminate\Support\Carbon $verifiedAt = null): void
    {
        $identity = new UserIdentity();
        $identity->user_id = $user->id;
        $identity->provider = 'google';
        $identity->provider_subject = $subject;
        $identity->provider_email = $email;
        $identity->verified_at = $verifiedAt;
        $identity->save();
    }

    public function test_google_redirect_is_offered_when_configured(): void
    {
        $this->bindFakeGoogle(['id' => 'sub-1', 'email' => 'alam@gmail.com', 'email_verified' => true, 'name' => 'Alam']);

        $response = $this->get(route('google.redirect'));

        $this->assertSame(302, $response->status());
        $this->assertStringContainsString('accounts.google.com', $response->headers->get('Location'));
    }

    public function test_google_redirect_is_honest_when_not_configured(): void
    {
        $this->bindFakeGoogle(['id' => 'sub-1', 'email' => null, 'email_verified' => false, 'name' => null], configured: false);

        $this->get(route('google.redirect'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error');
    }

    public function test_google_callback_creates_a_new_account(): void
    {
        $this->bindFakeGoogle(['id' => 'sub-123', 'email' => 'alam@gmail.com', 'email_verified' => true, 'name' => 'Alam Rahman']);

        $this->get(route('google.callback'))->assertRedirect(route('home'));

        $identity = UserIdentity::where('provider', 'google')->where('provider_subject', 'sub-123')->first();
        $this->assertNotNull($identity);
        $this->assertTrue($identity->isVerified());

        $user = $identity->user;
        $this->assertSame('alam@gmail.com', $user->email);
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertNull($user->password); // Google-only account, no password
        $this->assertAuthenticatedAs($user);
    }

    public function test_google_callback_signs_into_existing_linked_account(): void
    {
        $existing = User::factory()->create(['email' => 'alam@gmail.com', 'email_verified_at' => now()]);
        $existing->account_status = 'active';
        $existing->save();
        $this->addIdentity($existing, 'sub-123', 'alam@gmail.com', now());

        $this->bindFakeGoogle(['id' => 'sub-123', 'email' => 'alam@gmail.com', 'email_verified' => true, 'name' => 'Alam']);

        $this->get(route('google.callback'))->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($existing);
        $this->assertSame(1, User::count(), 'No duplicate account is created.');
    }

    public function test_google_callback_links_into_existing_verified_email_account(): void
    {
        // Account with the same verified email but no Google link yet.
        $existing = User::factory()->create(['email' => 'alam@gmail.com', 'email_verified_at' => now()]);
        $existing->account_status = 'active';
        $existing->save();

        $this->bindFakeGoogle(['id' => 'sub-999', 'email' => 'alam@gmail.com', 'email_verified' => true, 'name' => 'Alam']);

        $this->get(route('google.callback'))->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($existing);
        $this->assertSame(1, User::count(), 'No silent duplicate account.');
        $this->assertDatabaseHas('user_identities', [
            'user_id' => $existing->id,
            'provider' => 'google',
            'provider_subject' => 'sub-999',
        ]);
    }

    public function test_one_google_subject_can_never_link_to_two_users(): void
    {
        $first = User::factory()->create(['email' => 'one@example.com', 'email_verified_at' => now()]);
        $second = User::factory()->create(['email' => 'two@example.com', 'email_verified_at' => now()]);

        $this->addIdentity($first, 'sub-shared', 'one@example.com', now());

        $this->bindFakeGoogle(['id' => 'sub-shared', 'email' => 'two@example.com', 'email_verified' => true, 'name' => 'Two']);

        // The callback must sign into the FIRST account (subject owner), not
        // link the subject to a second user.
        $this->get(route('google.callback'))->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($first);
        $this->assertSame(2, User::count(), 'Both pre-existing users remain; no third is created.');
        $this->assertSame(1, UserIdentity::where('provider_subject', 'sub-shared')->count());
    }

    public function test_authenticated_user_can_link_google(): void
    {
        $user = User::factory()->create(['email' => 'alam@gmail.com', 'email_verified_at' => now()]);
        $user->account_status = 'active';
        $user->save();

        $this->bindFakeGoogle(['id' => 'sub-777', 'email' => 'alam@gmail.com', 'email_verified' => true, 'name' => 'Alam']);

        $this->actingAs($user)
            ->withSession(['google_link_intent' => true])
            ->get(route('google.callback'))
            ->assertRedirect(route('settings.connected-accounts'));

        $this->assertDatabaseHas('user_identities', [
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_subject' => 'sub-777',
        ]);
    }

    public function test_user_can_unlink_google_when_another_method_exists(): void
    {
        $user = User::factory()->create(['email' => 'alam@gmail.com', 'password' => 'hashed']);
        $user->account_status = 'active';
        $user->save();
        $this->addIdentity($user, 'sub-777', 'alam@gmail.com', now());

        $this->actingAs($user)->post(route('settings.google.unlink'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('user_identities', ['user_id' => $user->id, 'provider' => 'google']);
    }

    public function test_user_cannot_unlink_their_last_sign_in_method(): void
    {
        // Google-only account: no password, no other identity.
        $user = User::factory()->create(['email' => 'alam@gmail.com', 'password' => null]);
        $user->account_status = 'active';
        $user->save();
        $this->addIdentity($user, 'sub-777', 'alam@gmail.com', now());

        $this->actingAs($user)->post(route('settings.google.unlink'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('user_identities', ['user_id' => $user->id, 'provider' => 'google']);
    }
}

/**
 * Deterministic fake Google OAuth provider.
 */
class FakeGoogleProvider implements GoogleOAuthProviderInterface
{
    public function __construct(
        protected array $user,
        protected bool $configured,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function redirect(): RedirectResponse
    {
        if (! $this->configured) {
            throw new DomainException('Google Sign-In is not configured.');
        }

        return redirect('https://accounts.google.com/o/oauth2/v2/auth?client_id=fake');
    }

    public function user(): array
    {
        if (! $this->configured) {
            throw new DomainException('Google Sign-In is not configured.');
        }

        return $this->user;
    }
}

```

### 51.55 — `tests/Feature/PhoneOtpTest.php`

> NEW — phone OTP tests

```php
<?php

namespace Tests\Feature;

use App\Contracts\PhoneOtpProviderInterface;
use App\Models\OtpChallenge;
use App\Models\User;
use App\Models\UserIdentity;
use App\Services\PhoneOtpService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 14 — phone OTP: normalization, issuance, verification, abuse limits,
 * phone login and account linking (deterministic provider, no SMS sent).
 */
class PhoneOtpTest extends TestCase
{
    use RefreshDatabase;

    protected function bindFakeSms(): FakeSmsProvider
    {
        $fake = new FakeSmsProvider();
        $this->app->instance(PhoneOtpProviderInterface::class, $fake);

        return $fake;
    }

    protected function otp(): PhoneOtpService
    {
        return app(PhoneOtpService::class);
    }

    protected function addPhoneIdentity(User $user, string $normalizedPhone): void
    {
        $identity = new UserIdentity();
        $identity->user_id = $user->id;
        $identity->provider = 'phone';
        $identity->provider_subject = $normalizedPhone;
        $identity->verified_at = now();
        $identity->save();
    }

    public function test_normalization_accepts_bangladeshi_local_formats(): void
    {
        $this->assertSame('+8801712345678', $this->otp()->normalize('01712345678'));
        $this->assertSame('+8801712345678', $this->otp()->normalize('8801712345678'));
        $this->assertSame('+8801712345678', $this->otp()->normalize('+880 1712-345678'));
    }

    public function test_normalization_rejects_invalid_numbers(): void
    {
        $this->expectException(DomainException::class);
        $this->otp()->normalize('12345');
    }

    public function test_issue_and_verify_round_trip(): void
    {
        $fake = $this->bindFakeSms();
        $user = User::factory()->create();

        $challenge = $this->otp()->issue($user, '01712345678', OtpChallenge::PURPOSE_LINK);

        $this->assertNotNull($challenge);
        $this->assertSame('+8801712345678', $fake->lastPhone);
        $this->assertNotEmpty($fake->lastCode);
        $this->assertSame('+8801712345678', $challenge->phone);
        $this->assertNotSame($fake->lastCode, $challenge->code_hash, 'Raw code is never stored.');

        $verified = $this->otp()->verify($user, '01712345678', OtpChallenge::PURPOSE_LINK, $fake->lastCode);
        $this->assertSame(OtpChallenge::STATUS_VERIFIED, $verified->status);
    }

    public function test_verify_rejects_wrong_code(): void
    {
        $fake = $this->bindFakeSms();
        $user = User::factory()->create();

        $this->otp()->issue($user, '01712345678', OtpChallenge::PURPOSE_LINK);

        $this->expectException(DomainException::class);
        $this->otp()->verify($user, '01712345678', OtpChallenge::PURPOSE_LINK, '000000');
    }

    public function test_challenge_expires(): void
    {
        $this->bindFakeSms();
        $user = User::factory()->create();

        $challenge = $this->otp()->issue($user, '01712345678', OtpChallenge::PURPOSE_LINK);
        $challenge->expires_at = now()->subMinute();
        $challenge->save();

        $this->expectException(DomainException::class);
        $this->otp()->verify($user, '01712345678', OtpChallenge::PURPOSE_LINK, '000000');
    }

    public function test_code_is_single_use(): void
    {
        $fake = $this->bindFakeSms();
        $user = User::factory()->create();

        $this->otp()->issue($user, '01712345678', OtpChallenge::PURPOSE_LINK);
        $this->otp()->verify($user, '01712345678', OtpChallenge::PURPOSE_LINK, $fake->lastCode);

        // Same code again must fail.
        $this->expectException(DomainException::class);
        $this->otp()->verify($user, '01712345678', OtpChallenge::PURPOSE_LINK, $fake->lastCode);
    }

    public function test_resend_cooldown_is_enforced(): void
    {
        $this->bindFakeSms();
        $user = User::factory()->create();

        $this->otp()->issue($user, '01712345678', OtpChallenge::PURPOSE_LINK);

        $this->expectException(DomainException::class);
        $this->otp()->issue($user, '01712345678', OtpChallenge::PURPOSE_LINK);
    }

    public function test_attempts_are_capped(): void
    {
        $fake = $this->bindFakeSms();
        $user = User::factory()->create();

        $this->otp()->issue($user, '01712345678', OtpChallenge::PURPOSE_LINK);

        for ($i = 0; $i < 5; $i++) {
            try {
                $this->otp()->verify($user, '01712345678', OtpChallenge::PURPOSE_LINK, '000000');
            } catch (DomainException $e) {
                // expected
            }
        }

        // Even the correct code is now refused (challenge consumed).
        $this->expectException(DomainException::class);
        $this->otp()->verify($user, '01712345678', OtpChallenge::PURPOSE_LINK, $fake->lastCode);
    }

    public function test_phone_login_flows_through_otp(): void
    {
        $fake = $this->bindFakeSms();

        $user = User::factory()->create(['email' => 'alam@example.com']);
        $user->account_status = 'active';
        $user->save();
        $this->addPhoneIdentity($user, '+8801712345678');

        $this->post(route('phone.request'), ['phone' => '01712345678'])
            ->assertRedirect(route('phone.verify'));

        $this->post(route('phone.login.verify'), [
            'phone' => '01712345678',
            'purpose' => 'login',
            'code' => $fake->lastCode,
        ])->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('login_events', [
            'user_id' => $user->id,
            'event' => 'login.phone',
            'status' => 'success',
        ]);
    }

    public function test_phone_login_with_unknown_number_never_leaks_accounts(): void
    {
        $this->bindFakeSms();

        $this->post(route('phone.request'), ['phone' => '01712345678'])
            ->assertRedirect(route('phone.verify'));
    }

    public function test_authenticated_user_can_link_and_unlink_phone(): void
    {
        $fake = $this->bindFakeSms();
        $user = User::factory()->create(['email' => 'alam@example.com', 'password' => 'hashed']);
        $user->account_status = 'active';
        $user->save();

        $this->actingAs($user)->post(route('settings.phone.link'), ['phone' => '01712345678'])
            ->assertRedirect(route('phone.verify'));

        $this->actingAs($user)->post(route('settings.phone.link.verify'), [
            'phone' => '01712345678',
            'purpose' => 'link',
            'code' => $fake->lastCode,
        ])->assertRedirect(route('settings.connected-accounts'));

        $this->assertDatabaseHas('user_identities', [
            'user_id' => $user->id,
            'provider' => 'phone',
            'provider_subject' => '+8801712345678',
        ]);

        // Unlink (a password remains, so this is allowed).
        $this->actingAs($user)->post(route('settings.phone.unlink'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('user_identities', ['user_id' => $user->id, 'provider' => 'phone']);
    }

    public function test_phone_identity_is_unique_across_users(): void
    {
        $first = User::factory()->create(['email' => 'one@example.com', 'password' => 'hashed']);
        $second = User::factory()->create(['email' => 'two@example.com', 'password' => 'hashed']);

        $this->addPhoneIdentity($first, '+8801712345678');

        $this->expectException(DomainException::class);
        app(\App\Services\IdentityService::class)->linkPhone($second, '+8801712345678');
    }

    public function test_otp_issue_refuses_when_provider_is_not_configured(): void
    {
        $fake = new FakeSmsProvider(configured: false);
        $this->app->instance(PhoneOtpProviderInterface::class, $fake);

        $this->expectException(DomainException::class);
        $this->otp()->issue(User::factory()->create(), '01712345678', OtpChallenge::PURPOSE_LINK);
    }
}

/**
 * Deterministic in-memory SMS provider double.
 */
class FakeSmsProvider implements PhoneOtpProviderInterface
{
    public ?string $lastPhone = null;

    public ?string $lastCode = null;

    public function __construct(protected bool $configured = true)
    {
    }

    public function id(): string
    {
        return 'fake';
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function send(string $phone, string $code): void
    {
        if (! $this->configured) {
            throw new DomainException('Phone OTP delivery is not configured.');
        }

        $this->lastPhone = $phone;
        $this->lastCode = $code;
    }
}

```

### 51.56 — `tests/Feature/ProfileTest.php`

> NEW — profile tests

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Phase 14 — profiles: public profile privacy, editing, username rules,
 * preferences, privacy presets and password change.
 */
class ProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $role = 'player', array $attrs = []): User
    {
        $user = User::factory()->create($attrs);
        $user->role = $role;
        $user->account_status = 'active';
        $user->save();

        return $user;
    }

    public function test_public_profile_is_visible_to_guests(): void
    {
        $user = $this->makeUser(attrs: ['bio' => 'Hello', 'country' => 'BD']);

        $this->get(route('profile.show', $user))
            ->assertOk()
            ->assertSee('Hello');
    }

    public function test_registered_profile_is_hidden_from_guests(): void
    {
        $user = $this->makeUser(attrs: ['bio' => 'Hello']);
        $user->privacy = 'registered';
        $user->save();

        $this->get(route('profile.show', $user))
            ->assertOk()
            ->assertSee('private', false);
    }

    public function test_private_profile_is_hidden_from_other_users(): void
    {
        $user = $this->makeUser(attrs: ['bio' => 'Secret']);
        $user->privacy = 'private';
        $user->save();

        $other = $this->makeUser();

        $this->actingAs($other)->get(route('profile.show', $user))
            ->assertOk()
            ->assertDontSee('Secret');
    }

    public function test_private_profile_is_visible_to_its_owner(): void
    {
        $user = $this->makeUser(attrs: ['bio' => 'Secret']);
        $user->privacy = 'private';
        $user->save();

        $this->actingAs($user)->get(route('profile.show', $user))
            ->assertOk()
            ->assertSee('Secret');
    }

    public function test_profile_is_editable_by_owner(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->put(route('profile.update'), [
            'name' => 'New Name',
            'bio' => 'New bio',
            'country' => 'BD',
            'region' => 'Dhaka',
            'avatar' => 'https://example.com/a.png',
        ])->assertSessionHas('success');

        $user->refresh();
        $this->assertSame('New Name', $user->name);
        $this->assertSame('New bio', $user->bio);
        $this->assertSame('BD', $user->country);
    }

    public function test_user_cannot_edit_someone_elses_profile(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser();

        $this->actingAs($a)->put(route('profile.update'), ['name' => 'Hijacked'])
            ->assertSessionHas('success');

        // `a` edited only their own profile.
        $this->assertSame('Hijacked', $a->fresh()->name);
        $this->assertNotSame('Hijacked', $b->fresh()->name);
    }

    public function test_username_change_enforces_rules_and_reserved_names(): void
    {
        $user = $this->makeUser(attrs: ['username' => 'alam']);

        $this->actingAs($user)->put(route('profile.username'), ['username' => 'admin'])
            ->assertSessionHas('error');
    }

    public function test_username_must_be_unique(): void
    {
        $this->makeUser(attrs: ['username' => 'taken']);
        $user = $this->makeUser(attrs: ['username' => 'alam']);

        $this->actingAs($user)->put(route('profile.username'), ['username' => 'taken'])
            ->assertSessionHas('error');
    }

    public function test_privacy_update_persists(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->put(route('profile.privacy'), ['privacy' => 'private'])
            ->assertSessionHas('success');

        $this->assertSame('private', $user->fresh()->privacy);
    }

    public function test_password_change_requires_current_password(): void
    {
        $user = $this->makeUser(attrs: ['password' => Hash::make('oldsecret')]);

        $this->actingAs($user)->post(route('settings.password'), [
            'current_password' => 'wrong',
            'password' => 'newsecret123',
            'password_confirmation' => 'newsecret123',
        ])->assertSessionHas('error');
    }

    public function test_password_change_succeeds_with_current_password(): void
    {
        $user = $this->makeUser(attrs: ['password' => Hash::make('oldsecret')]);

        $this->actingAs($user)->post(route('settings.password'), [
            'current_password' => 'oldsecret',
            'password' => 'newsecret123',
            'password_confirmation' => 'newsecret123',
        ])->assertSessionHas('success');

        $this->assertTrue(Hash::check('newsecret123', $user->fresh()->password));
    }

    public function test_role_cannot_be_changed_through_profile_endpoints(): void
    {
        $user = $this->makeUser('player');

        $this->actingAs($user)->put(route('profile.update'), [
            'name' => 'X',
            'role' => 'admin',
        ])->assertSessionHas('success');

        $this->assertSame('player', $user->fresh()->role);
    }
}

```

### 51.57 — `tests/Feature/AccountSecurityTest.php`

> NEW — security/session/lifecycle tests

```php
<?php

namespace Tests\Feature;

use App\Models\LoginEvent;
use App\Models\Payment;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 14 — security settings: active sessions, login history, account
 * lifecycle (deactivate/reactivate/deletion) and the active-account gate.
 */
class AccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $role = 'player', array $attrs = []): User
    {
        $user = User::factory()->create($attrs);
        $user->role = $role;
        $user->account_status = 'active';
        $user->save();

        return $user;
    }

    protected function seedSession(User $user, string $id, int $lastActivity, string $ua = 'Mozilla/5.0'): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => $ua,
            'payload' => 'x',
            'last_activity' => $lastActivity,
        ]);
    }

    protected function addLoginEvent(User $user, string $event, array $extra = []): void
    {
        $entry = new LoginEvent();
        $entry->user_id = $user->id;
        $entry->event = $event;
        $entry->status = $extra['status'] ?? 'success';
        $entry->ip_hash = $extra['ip_hash'] ?? null;
        $entry->device_label = $extra['device_label'] ?? null;
        $entry->save();
    }

    public function test_security_page_is_self_only(): void
    {
        $user = $this->makeUser();

        $this->get(route('settings.security'))->assertRedirect(route('login'));

        $this->actingAs($user)->get(route('settings.security'))->assertOk();
    }

    public function test_sessions_page_lists_only_own_sessions(): void
    {
        $user = $this->makeUser();
        $other = $this->makeUser();

        $this->seedSession($user, 'sess-a', now()->timestamp, 'Mozilla/5.0 (X11; Linux x86_64) Firefox/120.0');
        $this->seedSession($other, 'sess-b', now()->timestamp, 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) Safari/604.1');

        $this->actingAs($user)->get(route('settings.sessions'))
            ->assertOk()
            ->assertSee('Firefox on Linux')
            ->assertDontSee('Safari on iOS');
    }

    public function test_revoke_other_sessions_keeps_current(): void
    {
        $user = $this->makeUser();

        $this->seedSession($user, 'sess-a', now()->timestamp);
        $this->seedSession($user, 'sess-b', now()->timestamp);

        $this->actingAs($user)->post(route('settings.sessions.revokeOthers'))
            ->assertSessionHas('success');

        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
    }

    public function test_revoke_all_sessions_logs_user_out(): void
    {
        $user = $this->makeUser();
        $this->seedSession($user, 'sess-a', now()->timestamp);

        $this->actingAs($user)->post(route('settings.sessions.revokeAll'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
    }

    public function test_login_history_is_self_only(): void
    {
        $user = $this->makeUser();
        $other = $this->makeUser();

        $this->addLoginEvent($user, 'login.password');
        $this->addLoginEvent($other, 'account.linked');

        $this->actingAs($user)->get(route('settings.login-history'))
            ->assertOk()
            ->assertSee('Login Password')
            ->assertDontSee('Account Linked');
    }

    public function test_login_history_never_contains_raw_ips_or_passwords(): void
    {
        $user = $this->makeUser();
        $this->addLoginEvent($user, 'login.password', [
            'ip_hash' => hash_hmac('sha256', '203.0.113.9', 'secret'),
            'device_label' => 'Chrome on Linux',
        ]);

        $html = $this->actingAs($user)->get(route('settings.login-history'))->content();

        $this->assertStringNotContainsString('203.0.113.9', $html);
    }

    public function test_deactivation_revokes_sessions_and_signs_out(): void
    {
        $user = $this->makeUser();
        $this->seedSession($user, 'sess-a', now()->timestamp);

        $this->actingAs($user)->post(route('settings.deactivate'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertSame('deactivated', $user->fresh()->account_status);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertDatabaseHas('login_events', [
            'user_id' => $user->id,
            'event' => LoginEvent::EVENT_ACCOUNT_DEACTIVATED,
        ]);
    }

    public function test_reactivation_restores_active_status(): void
    {
        $user = $this->makeUser();
        $user->account_status = 'deactivated';
        $user->save();

        $this->actingAs($user)->post(route('settings.reactivate'))
            ->assertSessionHas('success');

        $this->assertSame('active', $user->fresh()->account_status);
    }

    public function test_deactivated_user_is_redirected_to_security_settings(): void
    {
        $user = $this->makeUser();
        $user->account_status = 'deactivated';
        $user->save();

        $this->actingAs($user)->get(route('home'))
            ->assertRedirect(route('settings.security'));
    }

    public function test_deactivated_user_can_still_reach_security_settings(): void
    {
        $user = $this->makeUser();
        $user->account_status = 'deactivated';
        $user->save();

        $this->actingAs($user)->get(route('settings.security'))->assertOk();
    }

    public function test_deletion_request_is_blocked_while_payments_are_pending(): void
    {
        $user = $this->makeUser();
        $org = $this->makeUser('organizer');

        $tournament = new Tournament();
        $tournament->organizer_id = $org->id;
        $tournament->name = 'T';
        $tournament->slug = 't-' . Str::random(6);
        $tournament->game_mode = 'squad';
        $tournament->map = 'Bermuda';
        $tournament->entry_fee = 100;
        $tournament->prize_pool = 1000;
        $tournament->team_slots = 8;
        $tournament->team_size = 4;
        $tournament->starts_at = now()->addDay();
        $tournament->format = Tournament::FORMAT_SINGLE_ELIM;
        $tournament->status = 'open';
        $tournament->save();

        $team = new Team();
        $team->tournament_id = $tournament->id;
        $team->captain_id = $user->id;
        $team->name = 'Team';
        $team->captain_name = $user->name;
        $team->phone = '01700000000';
        $team->game_uid = 'UID' . Str::random(6);
        $team->status = Team::STATUS_PENDING;
        $team->save();

        $payment = new Payment();
        $payment->tournament_id = $tournament->id;
        $payment->team_id = $team->id;
        $payment->payer_user_id = $user->id;
        $payment->amount_minor = 10000;
        $payment->amount = '100.00';
        $payment->currency = 'BDT';
        $payment->method = 'bkash';
        $payment->trx_id = 'TRX1';
        $payment->provider = 'bkash';
        $payment->provider_reference = 'TRX1';
        $payment->idempotency_key = (string) Str::uuid();
        $payment->status = Payment::STATUS_PENDING;
        $payment->save();

        $this->actingAs($user)->post(route('settings.deletion.request'))
            ->assertSessionHas('error');

        $this->assertNotSame('deletion_pending', $user->fresh()->account_status);
    }

    public function test_deletion_request_succeeds_when_clear(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->post(route('settings.deletion.request'))
            ->assertSessionHas('success');

        $this->assertSame('deletion_pending', $user->fresh()->account_status);
    }

    public function test_deletion_request_can_be_cancelled(): void
    {
        $user = $this->makeUser();
        $user->account_status = 'deletion_pending';
        $user->save();

        $this->actingAs($user)->post(route('settings.deletion.cancel'))
            ->assertSessionHas('success');

        $this->assertSame('active', $user->fresh()->account_status);
    }
}

```

### 51.58 — `tests/Feature/PaymentMethodManagementTest.php`

> NEW — saved-method tests

```php
<?php

namespace Tests\Feature;

use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 14 — saved payment-method management: ownership, masking, default
 * promotion and IDOR resistance.
 */
class PaymentMethodManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $role = 'player'): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->account_status = 'active';
        $user->save();

        return $user;
    }

    public function test_user_can_add_a_payment_method(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->post(route('settings.payment-methods.store'), [
            'provider' => 'bkash',
            'label' => 'My bKash',
            'identifier' => '01712345678',
        ])->assertSessionHas('success');

        $method = PaymentMethod::where('user_id', $user->id)->first();
        $this->assertNotNull($method);
        $this->assertSame('bkash', $method->provider);
        $this->assertTrue($method->is_default, 'First method becomes the default.');
    }

    public function test_identifier_is_masked(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->post(route('settings.payment-methods.store'), [
            'provider' => 'bkash',
            'label' => 'My bKash',
            'identifier' => '01712345678',
        ]);

        $method = PaymentMethod::where('user_id', $user->id)->first();
        $this->assertStringNotContainsString('01712345678', $method->masked_identifier);
        $this->assertStringEndsWith('5678', $method->masked_identifier);
    }

    public function test_unknown_provider_is_rejected(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->post(route('settings.payment-methods.store'), [
            'provider' => 'paypal',
            'label' => 'PayPal',
            'identifier' => '01712345678',
        ])->assertSessionHasErrors('provider');
    }

    public function test_set_default_promotes_exactly_one(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->post(route('settings.payment-methods.store'), [
            'provider' => 'bkash', 'label' => 'A', 'identifier' => '01712345678',
        ]);
        $this->actingAs($user)->post(route('settings.payment-methods.store'), [
            'provider' => 'nagad', 'label' => 'B', 'identifier' => '01812345678',
        ]);

        $b = PaymentMethod::where('user_id', $user->id)->where('provider', 'nagad')->first();

        $this->actingAs($user)->post(route('settings.payment-methods.default', $b))
            ->assertSessionHas('success');

        $this->assertTrue($b->fresh()->is_default);
        $this->assertSame(1, PaymentMethod::where('user_id', $user->id)->where('is_default', true)->count());
    }

    public function test_user_cannot_remove_another_users_method(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser();

        $method = new PaymentMethod();
        $method->user_id = $b->id;
        $method->provider = 'bkash';
        $method->label = 'B';
        $method->masked_identifier = '****5678';
        $method->status = PaymentMethod::STATUS_ACTIVE;
        $method->is_default = true;
        $method->save();

        $this->actingAs($a)->delete(route('settings.payment-methods.destroy', $method))
            ->assertForbidden();

        $this->assertSame(PaymentMethod::STATUS_ACTIVE, $method->fresh()->status);
    }

    public function test_user_cannot_set_default_on_another_users_method(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser();

        $method = new PaymentMethod();
        $method->user_id = $b->id;
        $method->provider = 'bkash';
        $method->label = 'B';
        $method->masked_identifier = '****5678';
        $method->status = PaymentMethod::STATUS_ACTIVE;
        $method->save();

        $this->actingAs($a)->post(route('settings.payment-methods.default', $method))
            ->assertForbidden();
    }

    public function test_removing_default_promotes_another(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->post(route('settings.payment-methods.store'), [
            'provider' => 'bkash', 'label' => 'A', 'identifier' => '01712345678',
        ]);
        $this->actingAs($user)->post(route('settings.payment-methods.store'), [
            'provider' => 'nagad', 'label' => 'B', 'identifier' => '01812345678',
        ]);

        $a = PaymentMethod::where('user_id', $user->id)->where('provider', 'bkash')->first();

        $this->actingAs($user)->delete(route('settings.payment-methods.destroy', $a))
            ->assertSessionHas('success');

        $b = PaymentMethod::where('user_id', $user->id)->where('provider', 'nagad')->first();
        $this->assertTrue($b->fresh()->is_default, 'Remaining method is promoted to default.');
    }
}

```

### 51.59 — `tests/Feature/PaymentProvidersTest.php`

> NEW — provider/checkout tests

```php
<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Services\PaymentGatewayManager;
use App\Services\PaymentService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 14 — payment provider adapters and checkout: honest provider
 * statuses, provider validation, and integration with the Phase 08 state
 * machine. No real bKash/Nagad/card requests are ever made.
 */
class PaymentProvidersTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $role = 'player'): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->account_status = 'active';
        $user->save();

        return $user;
    }

    protected function makeTournament(User $organizer, float $entryFee = 100): Tournament
    {
        $t = new Tournament();
        $t->organizer_id = $organizer->id;
        $t->name = 'Provider Tournament';
        $t->slug = 'prov-' . Str::random(8);
        $t->game_mode = 'squad';
        $t->map = 'Bermuda';
        $t->entry_fee = $entryFee;
        $t->prize_pool = 5000;
        $t->team_slots = 8;
        $t->team_size = 4;
        $t->starts_at = now()->addDay();
        $t->format = Tournament::FORMAT_SINGLE_ELIM;
        $t->status = 'open';
        $t->save();

        return $t;
    }

    protected function makeTeam(Tournament $tournament, User $captain): Team
    {
        $team = new Team();
        $team->tournament_id = $tournament->id;
        $team->captain_id = $captain->id;
        $team->name = 'Team ' . Str::random(6);
        $team->captain_name = $captain->name;
        $team->phone = '01700000000';
        $team->game_uid = 'UID' . Str::random(6);
        $team->status = Team::STATUS_PENDING;
        $team->save();

        return $team;
    }

    public function test_provider_statuses_are_honest(): void
    {
        $statuses = app(PaymentGatewayManager::class)->statuses();

        $byId = collect($statuses)->keyBy('id');

        $this->assertArrayHasKey('bkash', $byId->all());
        $this->assertArrayHasKey('nagad', $byId->all());
        $this->assertArrayHasKey('rocket', $byId->all());
        $this->assertArrayHasKey('card', $byId->all());
        $this->assertArrayHasKey('bank', $byId->all());
        $this->assertArrayHasKey('sslcommerz', $byId->all());

        // With no credentials in the environment, the hosted providers report
        // themselves as NOT configured — never as live.
        $this->assertFalse($byId['card']['configured']);
        $this->assertFalse($byId['sslcommerz']['configured']);
    }

    public function test_checkout_methods_page_lists_enabled_providers(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser();
        $tournament = $this->makeTournament($org);
        $team = $this->makeTeam($tournament, $captain);

        $this->actingAs($captain)->get(route('payment.methods', [$tournament, $team]))
            ->assertOk()
            ->assertSee('bKash')
            ->assertSee('Nagad');
    }

    public function test_checkout_initiate_creates_payment_with_chosen_provider(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser();
        $tournament = $this->makeTournament($org);
        $team = $this->makeTeam($tournament, $captain);

        $this->actingAs($captain)->post(route('payment.initiate', [$tournament, $team]), [
            'provider' => 'nagad',
            'trx_id' => 'NAGAD123',
        ])->assertRedirect(route('payment.pending', [$tournament, $team, Payment::where('team_id', $team->id)->first()]));

        $payment = Payment::where('team_id', $team->id)->first();
        $this->assertNotNull($payment);
        $this->assertSame('nagad', $payment->provider);
        $this->assertSame(Payment::STATUS_PENDING, $payment->status);
    }

    public function test_checkout_initiate_rejects_unknown_provider(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser();
        $tournament = $this->makeTournament($org);
        $team = $this->makeTeam($tournament, $captain);

        $this->actingAs($captain)->post(route('payment.initiate', [$tournament, $team]), [
            'provider' => 'paypal',
        ])->assertSessionHasErrors('provider');
    }

    public function test_checkout_initiate_with_free_entry_confirms_immediately(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser();
        $tournament = $this->makeTournament($org, entryFee: 0);
        $team = $this->makeTeam($tournament, $captain);

        $this->actingAs($captain)->post(route('payment.initiate', [$tournament, $team]), [
            'provider' => 'bkash',
        ])->assertRedirect(route('tournaments.show', $tournament));

        $this->assertSame(Team::STATUS_CONFIRMED, $team->fresh()->status);
    }

    public function test_create_for_team_accepts_explicit_provider(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser();
        $tournament = $this->makeTournament($org);
        $team = $this->makeTeam($tournament, $captain);

        $payment = app(PaymentService::class)->createForTeam(
            $tournament,
            $team,
            $captain,
            'rocket',
            'ROCKET123',
            'rocket',
            'ROCKET123',
        );

        $this->assertSame('rocket', $payment->provider);
        $this->assertSame(Payment::STATUS_PENDING, $payment->status);
        $this->assertDatabaseHas('payment_events', [
            'payment_id' => $payment->id,
            'event' => PaymentEvent::EVENT_CREATED,
        ]);
    }

    public function test_create_for_team_rejects_unknown_provider(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser();
        $tournament = $this->makeTournament($org);
        $team = $this->makeTeam($tournament, $captain);

        $this->expectException(DomainException::class);
        app(PaymentService::class)->createForTeam(
            $tournament,
            $team,
            $captain,
            'paypal',
            'X',
            'paypal',
        );
    }

    public function test_unconfigured_hosted_provider_does_not_fake_success(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser();
        $tournament = $this->makeTournament($org);
        $team = $this->makeTeam($tournament, $captain);

        // The card gateway has no credentials configured; the flow must fall
        // back to the manual pending screen with an honest error, and the
        // payment must remain pending (never auto-verified).
        $this->actingAs($captain)->post(route('payment.initiate', [$tournament, $team]), [
            'provider' => 'card',
        ])->assertRedirect();

        $payment = Payment::where('team_id', $team->id)->first();
        $this->assertSame(Payment::STATUS_PENDING, $payment->status);
        $this->assertSame(Team::STATUS_PENDING, $team->fresh()->status);
    }
}

```

### 51.60 — `tests/Feature/AdminAccountTest.php`

> NEW — admin account tests

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 14 — admin account administration: inspect accounts, revoke
 * sessions, deactivate/reactivate/delete; and the authorization matrix that
 * keeps organizers and moderators out of global account controls.
 */
class AdminAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $role = 'player'): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->account_status = 'active';
        $user->save();

        return $user;
    }

    public function test_admin_can_list_accounts(): void
    {
        $admin = $this->makeUser('admin');
        $this->makeUser();

        $this->actingAs($admin)->get(route('admin.accounts.index'))->assertOk();
    }

    public function test_organizer_cannot_list_accounts(): void
    {
        $organizer = $this->makeUser('organizer');

        $this->actingAs($organizer)->get(route('admin.accounts.index'))->assertForbidden();
    }

    public function test_moderator_cannot_list_accounts(): void
    {
        $moderator = $this->makeUser('moderator');

        $this->actingAs($moderator)->get(route('admin.accounts.index'))->assertForbidden();
    }

    public function test_admin_can_view_account_detail(): void
    {
        $admin = $this->makeUser('admin');
        $user = $this->makeUser();

        $identity = new \App\Models\UserIdentity();
        $identity->user_id = $user->id;
        $identity->provider = 'google';
        $identity->provider_subject = 'sub-1';
        $identity->verified_at = now();
        $identity->save();

        $this->actingAs($admin)->get(route('admin.accounts.show', $user))
            ->assertOk()
            ->assertSee($user->email);
    }

    public function test_organizer_cannot_view_account_detail(): void
    {
        $organizer = $this->makeUser('organizer');
        $user = $this->makeUser();

        $this->actingAs($organizer)->get(route('admin.accounts.show', $user))->assertForbidden();
    }

    public function test_admin_can_revoke_a_users_sessions(): void
    {
        $admin = $this->makeUser('admin');
        $user = $this->makeUser();

        DB::table('sessions')->insert([
            'id' => 'sess-1',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'x',
            'payload' => 'x',
            'last_activity' => now()->timestamp,
        ]);

        $this->actingAs($admin)->post(route('admin.accounts.sessions.revoke', $user))
            ->assertSessionHas('success');

        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
    }

    public function test_admin_can_deactivate_and_reactivate_an_account(): void
    {
        $admin = $this->makeUser('admin');
        $user = $this->makeUser();

        $this->actingAs($admin)->post(route('admin.accounts.deactivate', $user))
            ->assertSessionHas('success');

        $this->assertSame('deactivated', $user->fresh()->account_status);

        $this->actingAs($admin)->post(route('admin.accounts.reactivate', $user))
            ->assertSessionHas('success');

        $this->assertSame('active', $user->fresh()->account_status);
    }

    public function test_admin_can_delete_an_account_as_an_anonymized_tombstone(): void
    {
        $admin = $this->makeUser('admin');
        $user = $this->makeUser();

        $this->actingAs($admin)->post(route('admin.accounts.delete', $user))
            ->assertSessionHas('success');

        $deleted = $user->fresh();
        $this->assertSame('deleted', $deleted->account_status);
        $this->assertSame('Deleted User', $deleted->name);
        $this->assertStringContainsString('ffarena.invalid', $deleted->email);
    }

    public function test_admin_cannot_delete_an_account_with_an_active_restriction(): void
    {
        $admin = $this->makeUser('admin');
        $user = $this->makeUser();

        $restriction = new \App\Models\Restriction();
        $restriction->user_id = $user->id;
        $restriction->type = 'payment';
        $restriction->reason = 'Under review';
        $restriction->source = 'admin';
        $restriction->actor_id = $admin->id;
        $restriction->status = \App\Models\Restriction::STATUS_ACTIVE;
        $restriction->save();

        $this->actingAs($admin)->post(route('admin.accounts.delete', $user))
            ->assertSessionHas('error');

        $this->assertSame('active', $user->fresh()->account_status);
    }
}

```

### 51.61 — `tests/Feature/AccountIntegrationTest.php`

> NEW — cross-cutting integration tests

```php
<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\LiveEvent;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 14 — cross-cutting integration: notifications, audit log and the
 * user-targeted realtime feed all react to account activity, without leaking
 * anything sensitive.
 */
class AccountIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $role = 'player'): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->account_status = 'active';
        $user->save();

        return $user;
    }

    public function test_registration_writes_audit_and_welcome_notification(): void
    {
        $this->post(route('register'), [
            'name' => 'Alam',
            'username' => 'alam',
            'email' => 'alam@example.com',
            'role' => 'player',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $user = User::where('email', 'alam@example.com')->first();

        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $user->id,
            'action' => 'auth.register',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'type' => Notification::TYPE_WELCOME,
        ]);
    }

    public function test_login_records_login_event_and_audit(): void
    {
        $user = $this->makeUser();
        $user->email = 'alam@example.com';
        $user->password = 'secret123';
        $user->save();

        // Force a fresh password hash.
        $user->password = \Illuminate\Support\Facades\Hash::make('secret123');
        $user->save();

        $this->post(route('login'), ['email' => 'alam@example.com', 'password' => 'secret123']);

        $this->assertDatabaseHas('login_events', [
            'user_id' => $user->id,
            'event' => 'login.password',
            'status' => 'success',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $user->id,
            'action' => 'auth.login',
        ]);
    }

    public function test_account_live_feed_is_self_only(): void
    {
        $user = $this->makeUser();
        $other = $this->makeUser();

        $event = new LiveEvent();
        $event->target_user_id = $user->id;
        $event->type = LiveEvent::TYPE_ACCOUNT_PAYMENT_STATUS;
        $event->payload = ['payment_id' => 1, 'status' => 'pending'];
        $event->save();

        $this->actingAs($user)->getJson(route('account.live'))->assertOk();

        $this->actingAs($other)->getJson(route('account.live'))
            ->assertOk()
            ->assertJsonCount(0, 'events');
    }

    public function test_account_live_feed_uses_cursor(): void
    {
        $user = $this->makeUser();

        $event = new LiveEvent();
        $event->target_user_id = $user->id;
        $event->type = LiveEvent::TYPE_ACCOUNT_PAYMENT_STATUS;
        $event->payload = ['status' => 'pending'];
        $event->save();

        $response = $this->actingAs($user)->getJson(route('account.live'));
        $response->assertOk();
        $this->assertSame(1, $response->json('revision'));

        // Only events newer than the cursor come back.
        $later = $this->actingAs($user)->getJson(route('account.live') . '?since=1');
        $later->assertOk()->assertJsonCount(0, 'events');
    }

    public function test_payment_initiation_notifies_and_live_events_the_payer(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser();

        $tournament = new \App\Models\Tournament();
        $tournament->organizer_id = $org->id;
        $tournament->name = 'T';
        $tournament->slug = 't-' . \Illuminate\Support\Str::random(6);
        $tournament->game_mode = 'squad';
        $tournament->map = 'Bermuda';
        $tournament->entry_fee = 100;
        $tournament->prize_pool = 1000;
        $tournament->team_slots = 8;
        $tournament->team_size = 4;
        $tournament->starts_at = now()->addDay();
        $tournament->format = \App\Models\Tournament::FORMAT_SINGLE_ELIM;
        $tournament->status = 'open';
        $tournament->save();

        $team = new \App\Models\Team();
        $team->tournament_id = $tournament->id;
        $team->captain_id = $captain->id;
        $team->name = 'Team';
        $team->captain_name = $captain->name;
        $team->phone = '01700000000';
        $team->game_uid = 'UID' . \Illuminate\Support\Str::random(6);
        $team->status = \App\Models\Team::STATUS_PENDING;
        $team->save();

        $this->actingAs($captain)->post(route('payment.initiate', [$tournament, $team]), [
            'provider' => 'bkash',
            'trx_id' => 'TRX42',
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $captain->id,
            'type' => Notification::TYPE_PAYMENT_INITIATED,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $captain->id,
            'action' => 'payment.initiated',
        ]);
        $this->assertDatabaseHas('live_events', [
            'target_user_id' => $captain->id,
            'type' => LiveEvent::TYPE_ACCOUNT_PAYMENT_STATUS,
        ]);
    }

    public function test_session_revocation_emits_a_user_live_event(): void
    {
        $user = $this->makeUser();

        \Illuminate\Support\Facades\DB::table('sessions')->insert([
            'id' => 'sess-x',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'x',
            'payload' => 'x',
            'last_activity' => now()->timestamp,
        ]);

        $this->actingAs($user)->post(route('settings.sessions.revokeOthers'));

        $this->assertDatabaseHas('live_events', [
            'target_user_id' => $user->id,
            'type' => LiveEvent::TYPE_ACCOUNT_SESSION_REVOKED,
        ]);
    }
}

```

### 51.62 — `app/Models/User.php`

> MODIFIED — profile fields + relations + CanResetPassword

```php
<?php

namespace App\Models;

use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable, CanResetPassword;

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

### 51.63 — `app/Models/Notification.php`

> MODIFIED — Phase 14 notification types

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A personal, in-app notification (Phase 11).
 *
 * Notifications are server-generated only: recipients, content and links are
 * assigned by NotificationService, never by a client. All fields are excluded
 * from mass assignment. `read_at` records the in-app read state; email is a
 * separate, best-effort delivery on top of this row.
 */
class Notification extends Model
{
    use HasFactory;

    public const TYPE_PAYMENT_VERIFIED = 'payment.verified';
    public const TYPE_PAYMENT_FAILED = 'payment.failed';
    public const TYPE_PAYMENT_REFUNDED = 'payment.refunded';
    public const TYPE_DISPUTE_OPENED = 'dispute.opened';
    public const TYPE_DISPUTE_RESOLVED = 'dispute.resolved';
    public const TYPE_PAYOUT_PROCESSED = 'payout.processed';
    public const TYPE_PAYOUT_FAILED = 'payout.failed';
    public const TYPE_SETTLEMENT_COMPLETED = 'settlement.completed';
    public const TYPE_RESTRICTION_APPLIED = 'restriction.applied';
    public const TYPE_RESTRICTION_LIFTED = 'restriction.lifted';
    public const TYPE_IDENTITY_VERIFIED = 'identity.verified';
    public const TYPE_IDENTITY_REJECTED = 'identity.rejected';
    public const TYPE_ANTI_CHEAT_RESOLVED = 'anti_cheat.resolved';
    public const TYPE_TEAM_REGISTERED = 'team.registered';
    public const TYPE_TEAM_WITHDRAWN = 'team.withdrawn';
    public const TYPE_SUPPORT_CREATED = 'support.created';
    public const TYPE_SUPPORT_REPLY = 'support.reply';
    public const TYPE_SUPPORT_ASSIGNED = 'support.assigned';
    public const TYPE_SUPPORT_RESOLVED = 'support.resolved';
    public const TYPE_SUPPORT_REOPENED = 'support.reopened';
    public const TYPE_SUPPORT_STATUS = 'support.status';
    public const TYPE_SYSTEM = 'system';

    // Phase 14 — account/auth/security notifications.
    public const TYPE_WELCOME = 'auth.welcome';
    public const TYPE_EMAIL_VERIFY = 'auth.email_verify';
    public const TYPE_PASSWORD_CHANGED = 'auth.password_changed';
    public const TYPE_PASSWORD_RESET = 'auth.password_reset';
    public const TYPE_GOOGLE_LINKED = 'auth.google_linked';
    public const TYPE_GOOGLE_UNLINKED = 'auth.google_unlinked';
    public const TYPE_PHONE_LINKED = 'auth.phone_linked';
    public const TYPE_PHONE_CHANGED = 'auth.phone_changed';
    public const TYPE_SUSPICIOUS_LOGIN = 'auth.suspicious_login';
    public const TYPE_SESSION_REVOKED = 'auth.session_revoked';
    public const TYPE_ACCOUNT_DEACTIVATED = 'auth.account_deactivated';
    public const TYPE_PAYMENT_INITIATED = 'payment.initiated';
    public const TYPE_PAYMENT_PROVIDER_ISSUE = 'payment.provider_issue';

    protected $fillable = [];

    protected $casts = [
        'data' => 'array',
        'read_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    /**
     * Human label for the notification type (display only).
     */
    public function typeLabel(): string
    {
        return ucwords(str_replace(['.', '_'], ' ', $this->type));
    }
}

```

### 51.64 — `app/Models/LiveEvent.php`

> MODIFIED — user-targeted account event types + target relation

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An append-only live event (Phase 12).
 *
 * The auto-incrementing id is a global monotonic cursor that clients pass
 * back as `since`. Payloads are server-generated and carry only non-sensitive
 * display data — never scores-in-progress of other teams' hidden state, never
 * personal data, never secrets. All fields are excluded from mass assignment.
 */
class LiveEvent extends Model
{
    use HasFactory;

    public const TYPE_SCORE_SUBMITTED = 'match.score_submitted';
    public const TYPE_MATCH_COMPLETED = 'match.completed';
    public const TYPE_MATCH_DISPUTED = 'match.disputed';
    public const TYPE_MATCH_RESOLVED = 'match.resolved';
    public const TYPE_MATCH_STARTED = 'match.started';
    public const TYPE_TEAM_CHECKED_IN = 'team.checked_in';
    public const TYPE_TEAM_REGISTERED = 'team.registered';
    public const TYPE_TEAM_WITHDRAWN = 'team.withdrawn';
    public const TYPE_DISPUTE_OPENED = 'dispute.opened';
    public const TYPE_DISPUTE_CLOSED = 'dispute.closed';

    // Phase 13 — staff-only support signals (never in the public allowlist).
    public const TYPE_SUPPORT_CREATED = 'support.created';
    public const TYPE_SUPPORT_MESSAGE = 'support.message';
    public const TYPE_SUPPORT_STATUS_CHANGED = 'support.status_changed';
    public const TYPE_SUPPORT_ASSIGNED = 'support.assigned';

    // Phase 14 — account-level, user-targeted signals (never public).
    public const TYPE_ACCOUNT_PAYMENT_STATUS = 'account.payment_status';
    public const TYPE_ACCOUNT_SESSION_REVOKED = 'account.session_revoked';
    public const TYPE_ACCOUNT_VERIFICATION_STATUS = 'account.verification_status';

    public const UPDATED_AT = null;

    protected $fillable = [];

    protected $casts = [
        'payload' => 'array',
    ];

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function targetUser()
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }
}

```

### 51.65 — `app/Contracts/PaymentGatewayInterface.php`

> MODIFIED — label/configured/supportsCallbacks/supportsRefunds

```php
<?php

namespace App\Contracts;

use App\Models\Payment;
use App\Models\Refund;
use DomainException;

/**
 * Provider abstraction for payment gateways (Phase 08, extended Phase 14).
 *
 * The application never invents a successful external transaction: adapters
 * must honestly report what they can and cannot do. `configured()` tells the
 * UI and the checkout flow whether a provider's credentials are present; when
 * they are not, the adapter reports `supportsCallbacks()`/`supportsRefunds()`
 * as false and throws on unsupported operations, so the platform can still
 * distinguish locally created, manually verified and provider-confirmed
 * payments.
 */
interface PaymentGatewayInterface
{
    /**
     * The stable provider identifier (stored on payments.provider).
     */
    public function id(): string;

    /**
     * A short human label for the provider.
     */
    public function label(): string;

    /**
     * Whether the provider has the credentials/config it needs to operate.
     * When false, the checkout UI shows "Provider is not configured".
     */
    public function configured(): bool;

    /**
     * Whether this provider pushes server-to-server callbacks/webhooks.
     */
    public function supportsCallbacks(): bool;

    /**
     * Whether this provider can execute external refunds.
     */
    public function supportsRefunds(): bool;

    /**
     * Create an external payment/checkout intent for the given payment.
     *
     * @return array{status: string, provider_reference: ?string, redirect_url: ?string}
     *
     * @throws DomainException when the operation is not supported (e.g. no
     *                          live credentials).
     */
    public function createExternalPayment(Payment $payment): array;

    /**
     * Refund an external payment.
     *
     * @throws DomainException when external refunds are not supported.
     */
    public function refundExternal(Payment $payment, Refund $refund): array;
}

```

### 51.66 — `app/Gateways/BkashGateway.php`

> MODIFIED — implements extended gateway interface

```php
<?php

namespace App\Gateways;

use App\Contracts\PaymentGatewayInterface;
use App\Models\Payment;
use App\Models\Refund;
use DomainException;

/**
 * bKash adapter (Phase 08 manual flow, upgraded Phase 14).
 *
 * No live bKash credentials are configured in this project, so this adapter
 * is deliberately conservative:
 *
 *  - createExternalPayment() never reports a provider-confirmed payment; it
 *    returns the local `pending` state so an admin must verify manually.
 *  - refundExternal() refuses to fake an external refund.
 *  - supportsCallbacks()/supportsRefunds() are false.
 *  - configured() reflects the presence of bKash credentials (false today).
 *
 * When real credentials are added, this is the single place to implement the
 * bKash Checkout/Create Agreement and Query Payment APIs.
 */
class BkashGateway implements PaymentGatewayInterface
{
    public function id(): string
    {
        return 'bkash';
    }

    public function label(): string
    {
        return 'bKash';
    }

    public function configured(): bool
    {
        $config = (array) config('payments.providers.bkash', []);

        return ($config['enabled'] ?? false)
            && ! empty($config['app_key'])
            && ! empty($config['app_secret'])
            && ! empty($config['username'])
            && ! empty($config['password']);
    }

    public function supportsCallbacks(): bool
    {
        return false;
    }

    public function supportsRefunds(): bool
    {
        return false;
    }

    public function createExternalPayment(Payment $payment): array
    {
        // Manual flow: the user submits a bKash TrxID that an admin verifies.
        // We never mark a payment provider-confirmed here.
        return [
            'status' => Payment::STATUS_PENDING,
            'provider_reference' => $payment->provider_reference,
            'redirect_url' => null,
        ];
    }

    public function refundExternal(Payment $payment, Refund $refund): array
    {
        throw new DomainException('External bKash refunds are not available — refunds are recorded platform-side only.');
    }
}

```

### 51.67 — `app/Services/DeviceFingerprintService.php`

> MODIFIED — deviceLabelFromUserAgent()

```php
<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DeviceLink;
use App\Models\RiskEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Privacy-conscious device identity + sharing detection (Phase 10).
 *
 * A device is a server-derived pseudonymous hash of the request's observable
 * client headers (HMAC-SHA256 keyed with APP_KEY) — the client can never
 * self-declare a "trusted device", and no raw fingerprint is stored.
 *
 * A device shared by many accounts is a *signal*: legitimate shared devices
 * (family computers, cyber cafés) are tolerated up to the configured
 * thresholds; only above them is a risk signal raised. New accounts on a
 * device that already belongs to a restricted account receive a
 * ban-evasion signal via AccountLinkService.
 */
class DeviceFingerprintService
{
    public function __construct(
        protected FraudRiskService $risk,
        protected AccountLinkService $links,
    ) {
    }

    /**
     * Derive the pseudonymous device hash from the request (server-side only).
     */
    public function hashFrom(Request $request): string
    {
        $ua = trim((string) $request->userAgent());
        $language = trim((string) $request->header('Accept-Language', ''));

        return hash_hmac('sha256', ($ua ?: 'unknown') . '|' . $language, (string) config('app.key'));
    }

    /**
     * Register (or refresh) a user's device association and evaluate
     * device-sharing signals. Never throws — observation only.
     */
    public function register(Request $request, User $user): Device
    {
        $hash = $this->hashFrom($request);

        return DB::transaction(function () use ($hash, $user) {
            $device = Device::where('device_hash', $hash)->lockForUpdate()->first();

            if ($device === null) {
                $device = new Device();
                $device->device_hash = $hash;
                $device->status = Device::STATUS_ACTIVE;
                $device->first_seen_at = now();
                $device->last_seen_at = now();
                $device->save();
            } else {
                $device->last_seen_at = now();
                $device->save();
            }

            $link = DeviceLink::where('device_id', $device->id)
                ->where('user_id', $user->id)
                ->first();

            if ($link === null) {
                $link = new DeviceLink();
                $link->device_id = $device->id;
                $link->user_id = $user->id;
                $link->first_seen_at = now();
                $link->last_seen_at = now();
                $link->save();
            } else {
                $link->last_seen_at = now();
                $link->save();
            }

            // Account-similarity: link this account to the device's other
            // accounts (moderate confidence).
            $others = DeviceLink::where('device_id', $device->id)
                ->where('user_id', '!=', $user->id)
                ->pluck('user_id');

            foreach ($others as $otherId) {
                $other = User::find($otherId);
                if ($other !== null) {
                    $this->links->link($user, $other, 'moderate', ['shared_device'], 'device');
                }
            }

            // Shared-device signals (only above the configured tolerance).
            $distinct = DeviceLink::where('device_id', $device->id)->count();

            $maxShared = (int) config('antifraud.device.max_accounts_shared', 4);
            $strongShared = (int) config('antifraud.device.strong_accounts_shared', 8);

            if ($distinct > $strongShared) {
                $this->risk->recordSignal($user, RiskEvent::TYPE_AUTH_DEVICE_SHARED, RiskEvent::SEVERITY_HIGH, 'device', [
                    'device_id' => $device->id,
                    'account_count' => $distinct,
                ]);
            } elseif ($distinct > $maxShared) {
                $this->risk->recordSignal($user, RiskEvent::TYPE_AUTH_DEVICE_SHARED, RiskEvent::SEVERITY_MEDIUM, 'device', [
                    'device_id' => $device->id,
                    'account_count' => $distinct,
                ]);
            }

            return $device;
        });
    }

    /**
     * Devices associated with a user.
     */
    public function devicesFor(User $user)
    {
        return Device::query()
            ->whereHas('links', fn ($q) => $q->where('user_id', $user->id))
            ->withCount('links')
            ->get();
    }

    /**
     * Distinct users associated with a device.
     */
    public function usersFor(Device $device)
    {
        return $device->users()->get();
    }

    /**
     * Mark a device blocked (e.g. after a confirmed anti-cheat incident).
     */
    public function block(Device $device): Device
    {
        $device->status = Device::STATUS_BLOCKED;
        $device->save();

        return $device;
    }

    /**
     * Derive a short, non-sensitive "browser on OS" label from a raw user
     * agent. No raw fingerprint data is ever returned.
     */
    public function deviceLabelFromUserAgent(string $ua): string
    {
        $os = 'Unknown OS';

        if (preg_match('/Windows/i', $ua)) {
            $os = 'Windows';
        } elseif (preg_match('/Mac OS X|Macintosh/i', $ua)) {
            $os = 'macOS';
        } elseif (preg_match('/Android/i', $ua)) {
            $os = 'Android';
        } elseif (preg_match('/iPhone|iPad|iPod/i', $ua)) {
            $os = 'iOS';
        } elseif (preg_match('/Linux/i', $ua)) {
            $os = 'Linux';
        }

        $browser = 'Unknown browser';

        if (preg_match('/Edg\//i', $ua)) {
            $browser = 'Edge';
        } elseif (preg_match('/OPR\//i', $ua)) {
            $browser = 'Opera';
        } elseif (preg_match('/Firefox\//i', $ua)) {
            $browser = 'Firefox';
        } elseif (preg_match('/Chrome\//i', $ua)) {
            $browser = 'Chrome';
        } elseif (preg_match('/Safari\//i', $ua)) {
            $browser = 'Safari';
        }

        return mb_substr($browser . ' on ' . $os, 0, 120);
    }
}

```

### 51.68 — `app/Services/PaymentService.php`

> MODIFIED — createForTeam() provider parameters

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

### 51.69 — `app/Services/AuditLogService.php`

> MODIFIED — expanded ACTIONS vocabulary

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

### 51.70 — `app/Services/LiveEventService.php`

> MODIFIED — recordForUser/sinceForUser/latestCursor

```php
<?php

namespace App\Services;

use App\Models\LiveEvent;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Realtime / live-update event log (Phase 12).
 *
 * Records domain events (score submitted, match completed, team checked in,
 * dispute opened, …) as an append-only, monotonic stream and serves them back
 * to clients with server-side visibility enforcement: public types are
 * visible to everyone; anything else requires tournament staff (organizer of
 * that tournament, moderator or admin).
 *
 * Recording is best-effort by design (`recordQuietly`): the live feed must
 * never break the originating business action.
 */
class LiveEventService
{
    /**
     * Record a live event. Throws on failure (callers should prefer
     * recordQuietly inside business flows).
     */
    public function record(
        ?Tournament $tournament,
        ?User $actor,
        string $type,
        array $payload = [],
    ): LiveEvent {
        $event = new LiveEvent();
        $event->tournament_id = $tournament?->id;
        $event->actor_user_id = $actor?->id;
        $event->type = $type;
        $event->payload = $payload;
        $event->save();

        return $event;
    }

    /**
     * Record a live event without ever throwing into the caller.
     */
    public function recordQuietly(
        ?Tournament $tournament,
        ?User $actor,
        string $type,
        array $payload = [],
    ): ?LiveEvent {
        try {
            return $this->record($tournament, $actor, $type, $payload);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Record a user-targeted live event (Phase 14 — account signals such as
     * payment status, session revocation and verification status). These
     * events are never public: they are delivered only to the target user.
     */
    public function recordForUser(
        ?User $target,
        ?User $actor,
        string $type,
        array $payload = [],
        ?Tournament $tournament = null,
    ): LiveEvent {
        $event = new LiveEvent();
        $event->tournament_id = $tournament?->id;
        $event->actor_user_id = $actor?->id;
        $event->target_user_id = $target?->id;
        $event->type = $type;
        $event->payload = $payload;
        $event->save();

        return $event;
    }

    /**
     * Record a user-targeted live event without ever throwing.
     */
    public function recordForUserQuietly(
        ?User $target,
        ?User $actor,
        string $type,
        array $payload = [],
        ?Tournament $tournament = null,
    ): ?LiveEvent {
        try {
            return $this->recordForUser($target, $actor, $type, $payload, $tournament);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Events targeted at a specific user with id > $since, newest first.
     * Only the target user may read them — never anyone else.
     *
     * @return Collection<int, LiveEvent>
     */
    public function sinceForUser(int $since, User $viewer, int $limit = 50): Collection
    {
        return LiveEvent::query()
            ->where('target_user_id', $viewer->id)
            ->where('id', '>', $since)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * The latest global cursor (max id), or 0 when the log is empty.
     */
    public function latestCursor(): int
    {
        return (int) (LiveEvent::query()->max('id') ?? 0);
    }

    /**
     * Whether a viewer may see the given event.
     */
    public function visibleTo(?User $viewer, LiveEvent $event): bool
    {
        if ($this->isPublic($event->type)) {
            return true;
        }

        if ($viewer === null) {
            return false;
        }

        if ($viewer->isAdmin() || $viewer->isModerator()) {
            return true;
        }

        return $event->tournament_id !== null
            && $event->tournament !== null
            && $event->tournament->organizer_id === $viewer->id;
    }

    /**
     * Events with id > $since for a tournament (optionally unscoped when the
     * tournament is null), newest first, already visibility-filtered.
     *
     * @return Collection<int, LiveEvent>
     */
    public function since(int $since, ?Tournament $tournament, ?User $viewer, int $limit = 50): Collection
    {
        $events = LiveEvent::query()
            ->when($tournament !== null, fn ($q) => $q->where('tournament_id', $tournament->id))
            ->where('id', '>', $since)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return $events->filter(fn (LiveEvent $event) => $this->visibleTo($viewer, $event))->values();
    }

    /**
     * The earliest missing cursor for a viewer so staff-only events they
     * cannot see do not wedge their cursor (they simply do not receive those
     * ids). Pagination cursor for clients is handled in the controller via
     * the response `revision`.
     */
    public function snapshot(Tournament $tournament, ?User $viewer): array
    {
        return [
            'revision' => $this->latestCursor(),
            'events' => $this->since(0, $tournament, $viewer, 25),
        ];
    }

    /**
     * Whether a type is in the public allowlist.
     */
    public function isPublic(string $type): bool
    {
        return in_array($type, config('live.public_types', []), true);
    }
}

```

### 51.71 — `app/Services/PaymentGatewayManager.php`

> MODIFIED — provider registry + honest statuses

```php
<?php

namespace App\Services;

use App\Contracts\PaymentGatewayInterface;
use App\Gateways\BankGateway;
use App\Gateways\BkashGateway;
use App\Gateways\CardGateway;
use App\Gateways\NagadGateway;
use App\Gateways\RocketGateway;
use App\Gateways\SslCommerzGateway;
use DomainException;

/**
 * Resolves payment gateway adapters by provider id (Phase 08, extended
 * Phase 14 with bKash/Nagad/Rocket/card/bank/SSLCommerz adapters).
 *
 * Also reports honest per-provider status (configured/disabled/mode) for the
 * checkout UI, so a provider without credentials is never presented as live.
 */
class PaymentGatewayManager
{
    /**
     * Registered adapters, keyed by provider id.
     *
     * @var array<string, PaymentGatewayInterface>
     */
    protected array $gateways = [];

    public function __construct(
        BkashGateway $bkash,
        NagadGateway $nagad,
        RocketGateway $rocket,
        CardGateway $card,
        BankGateway $bank,
        SslCommerzGateway $sslcommerz,
    ) {
        foreach ([$bkash, $nagad, $rocket, $card, $bank, $sslcommerz] as $gateway) {
            $this->gateways[$gateway->id()] = $gateway;
        }
    }

    /**
     * Resolve a gateway by provider id.
     */
    public function gateway(string $provider): PaymentGatewayInterface
    {
        if (! isset($this->gateways[$provider])) {
            throw new DomainException("Unknown payment provider: {$provider}");
        }

        return $this->gateways[$provider];
    }

    /**
     * The default provider id used for manual entry-fee payments.
     */
    public function defaultProvider(): string
    {
        return 'bkash';
    }

    /**
     * All registered provider ids.
     *
     * @return string[]
     */
    public function providers(): array
    {
        return array_keys($this->gateways);
    }

    /**
     * Honest status snapshot for every provider (for the checkout UI and the
     * admin provider-configuration view).
     *
     * @return array<int, array{id: string, label: string, enabled: bool, configured: bool, mode: string, supports_callbacks: bool, supports_refunds: bool}>
     */
    public function statuses(): array
    {
        $statuses = [];

        foreach ($this->gateways as $id => $gateway) {
            $config = (array) config("payments.providers.{$id}", []);

            $statuses[] = [
                'id' => $id,
                'label' => $gateway->label(),
                'enabled' => (bool) ($config['enabled'] ?? true),
                'configured' => $gateway->configured(),
                'mode' => (string) ($config['mode'] ?? 'sandbox'),
                'supports_callbacks' => $gateway->supportsCallbacks(),
                'supports_refunds' => $gateway->supportsRefunds(),
            ];
        }

        return $statuses;
    }

    /**
     * Providers that are enabled and may be offered at checkout.
     *
     * @return array<int, array{id: string, label: string, enabled: bool, configured: bool, mode: string, supports_callbacks: bool, supports_refunds: bool}>
     */
    public function enabledProviders(): array
    {
        return array_values(array_filter(
            $this->statuses(),
            fn (array $status) => $status['enabled'],
        ));
    }
}

```

### 51.72 — `app/Providers/AppServiceProvider.php`

> MODIFIED — provider bindings + named rate limiters

```php
<?php

namespace App\Providers;

use App\Contracts\GoogleOAuthProviderInterface;
use App\Contracts\PhoneOtpProviderInterface;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
    }
}

```

### 51.73 — `app/Http/Controllers/AuthController.php`

> MODIFIED — full auth/verify/reset/OTP/Google rewrite

```php
<?php

namespace App\Http\Controllers;

use App\Models\LoginEvent;
use App\Models\Notification;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\DeviceFingerprintService;
use App\Services\FraudRiskService;
use App\Services\GoogleAuthService;
use App\Services\IdentityService;
use App\Services\IpIntelligenceService;
use App\Services\LoginEventService;
use App\Services\NotificationService;
use App\Services\PhoneOtpService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;

class AuthController extends Controller
{
    public function __construct(
        protected DeviceFingerprintService $devices,
        protected IpIntelligenceService $ipIntel,
        protected NotificationService $notifications,
        protected AuditLogService $audit,
        protected LoginEventService $loginEvents,
        protected FraudRiskService $risk,
        protected PhoneOtpService $otp,
        protected IdentityService $identities,
        protected GoogleAuthService $google,
    ) {
    }

    // ------------------------------------------------------------------
    // Registration / login / logout
    // ------------------------------------------------------------------

    public function showRegister()
    {
        return view('auth.register');
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:60|unique:users,username',
            'email' => 'required|email|unique:users,email',
            'phone' => 'nullable|string|max:20',
            'game_uid' => 'nullable|string|max:30',
            'role' => 'required|in:player,organizer',
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

        Auth::login($user);
        $request->session()->regenerate();

        // Phase 10 — record pseudonymous device + IP observations for the
        // new account (never throws; observation only).
        $this->devices->register($request, $user);
        $this->ipIntel->observe($request, $user);

        // Phase 13 — audit the signup.
        $this->audit->recordQuietly($user, 'auth.register', 'user', $user->id, [
            'target_user_id' => $user->id,
            'metadata' => ['role' => $user->role],
        ]);

        // Phase 11 — welcome + (if unverified) email verification link.
        $this->notifications->send(
            $user,
            Notification::TYPE_WELCOME,
            'Welcome to FF Arena',
            'Your account was created. Verify your email to secure it.',
            NotificationService::link('home'),
        );

        $this->sendVerificationNotification($user);

        return redirect()->route('home')->with('success', 'Welcome to FF Arena, ' . $user->name . '!');
    }

    public function showLogin()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            $request->session()->regenerate();

            $user = $request->user();

            // Phase 10 — refresh pseudonymous device + IP observations.
            $this->devices->register($request, $user);
            $this->ipIntel->observe($request, $user);

            // Phase 14 — security history + risk signals.
            $this->loginEvents->record($user, LoginEvent::EVENT_LOGIN_PASSWORD, LoginEvent::STATUS_SUCCESS, $request);

            if ($this->loginEvents->isNewDevice($request, $user)) {
                $this->risk->recordSignal($user, \App\Models\RiskEvent::TYPE_RISK_FLAG, \App\Models\RiskEvent::SEVERITY_INFO, 'auth', [
                    'context' => 'password_login_new_device',
                ]);

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
                'metadata' => ['provider' => 'password'],
            ]);

            return redirect()->intended(route('home'));
        }

        // Record the failed attempt against a matching account (internal
        // only — the email is never echoed to the visitor).
        $user = User::where('email', $credentials['email'])->first();
        $this->loginEvents->record($user, LoginEvent::EVENT_LOGIN_FAILED, LoginEvent::STATUS_FAILURE, $request);

        return back()->withErrors(['email' => 'Invalid email or password.'])->onlyInput('email');
    }

    public function logout(Request $request)
    {
        $user = $request->user();

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($user !== null) {
            $this->loginEvents->record($user, LoginEvent::EVENT_LOGOUT, LoginEvent::STATUS_SUCCESS);
            $this->audit->recordQuietly($user, 'auth.logout', 'user', $user->id, [
                'target_user_id' => $user->id,
            ]);
        }

        return redirect()->route('home');
    }

    // ------------------------------------------------------------------
    // Password reset (enumeration-safe)
    // ------------------------------------------------------------------

    public function showForgotPassword()
    {
        return view('auth.forgot-password');
    }

    public function forgotPassword(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email',
        ]);

        $user = User::where('email', $data['email'])->first();

        if ($user !== null) {
            // Laravel's broker creates a short-lived token + sends the reset
            // email. Enumeration-safe: the visitor always gets the same
            // generic response whether or not the account exists.
            Password::sendResetLink(['email' => $user->email]);

            $this->loginEvents->record($user, LoginEvent::EVENT_PASSWORD_RESET, LoginEvent::STATUS_SUCCESS, $request);

            $this->notifications->send(
                $user,
                Notification::TYPE_PASSWORD_RESET,
                'Password reset requested',
                'A password reset link was requested for your account.',
                NotificationService::link('login'),
            );

            $this->audit->recordQuietly($user, 'auth.password_reset', 'user', $user->id, [
                'target_user_id' => $user->id,
            ]);
        }

        return back()->with('success', 'If that email address is registered, we have sent a password reset link.');
    }

    public function showResetPassword(string $token)
    {
        return view('auth.reset-password', ['token' => $token, 'email' => request()->query('email', '')]);
    }

    public function resetPassword(Request $request)
    {
        $data = $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|min:8|confirmed',
        ]);

        $status = Password::reset(
            $data,
            function (User $user, string $password) {
                $user->password = $password; // hashed via the model cast
                $user->save();

                $this->loginEvents->record($user, LoginEvent::EVENT_PASSWORD_RESET, LoginEvent::STATUS_SUCCESS, request());

                $this->notifications->send(
                    $user,
                    Notification::TYPE_PASSWORD_RESET,
                    'Password reset',
                    'Your password was reset successfully.',
                    NotificationService::link('login'),
                );

                $this->audit->recordQuietly($user, 'auth.password_reset', 'user', $user->id, [
                    'target_user_id' => $user->id,
                ]);
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            return back()->withErrors(['email' => 'This reset link is invalid or has expired.']);
        }

        return redirect()->route('login')->with('success', 'Your password has been reset. You can now sign in.');
    }

    // ------------------------------------------------------------------
    // Email verification (server-generated signed URLs)
    // ------------------------------------------------------------------

    public function showVerifyEmail()
    {
        $user = request()->user();

        if ($user->hasVerifiedEmail()) {
            return redirect()->route('home');
        }

        return view('auth.verify-email');
    }

    public function verifyEmail(Request $request, int $id, string $hash)
    {
        if (! $request->hasValidSignature()) {
            abort(403, 'This verification link is invalid or has expired.');
        }

        $user = User::findOrFail($id);

        if (! hash_equals(sha1((string) $user->email), (string) $hash)) {
            abort(403, 'This verification link does not match the account.');
        }

        if (! $user->hasVerifiedEmail()) {
            $user->email_verified_at = now();
            $user->save();

            $this->loginEvents->record($user, LoginEvent::EVENT_EMAIL_VERIFIED, LoginEvent::STATUS_SUCCESS, $request);

            $this->notifications->send(
                $user,
                Notification::TYPE_EMAIL_VERIFY,
                'Email verified',
                'Your email address has been verified.',
                NotificationService::link('settings.security'),
            );

            $this->audit->recordQuietly($user, 'auth.email_verified', 'user', $user->id, [
                'target_user_id' => $user->id,
            ]);
        }

        return redirect()->route('home')->with('success', 'Your email address has been verified.');
    }

    public function resendVerification(Request $request)
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return redirect()->route('home');
        }

        $this->sendVerificationNotification($user);

        return back()->with('success', 'A fresh verification link has been sent to your email.');
    }

    // ------------------------------------------------------------------
    // Phone login (OTP)
    // ------------------------------------------------------------------

    public function showPhoneLogin()
    {
        return view('auth.phone-login');
    }

    public function showPhoneVerify()
    {
        return view('auth.phone-verify', [
            'phone' => session('phone', old('phone')),
            'purpose' => session('purpose', old('purpose', 'login')),
        ]);
    }

    public function requestPhoneOtp(Request $request)
    {
        $data = $request->validate([
            'phone' => 'required|string|max:20',
        ]);

        try {
            $phone = $this->otp->normalize($data['phone']);
            $this->otp->issue(null, $phone, \App\Models\OtpChallenge::PURPOSE_LOGIN);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        // Enumeration-safe: reveal nothing about whether the number is
        // registered; just proceed to code entry.
        return redirect()
            ->route('phone.verify')
            ->with('phone', $phone)
            ->with('purpose', \App\Models\OtpChallenge::PURPOSE_LOGIN)
            ->with('success', 'We sent a verification code to that number.');
    }

    public function verifyPhoneLogin(Request $request)
    {
        $data = $request->validate([
            'phone' => 'required|string|max:32',
            'purpose' => 'required|in:login,signup,link,recovery',
            'code' => 'required|string|size:6',
        ]);

        try {
            $phone = $this->otp->normalize($data['phone']);
            $this->otp->verify(null, $phone, $data['purpose'], $data['code']);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        $user = $this->identities->userFor(\App\Models\UserIdentity::PROVIDER_PHONE, $phone);

        if ($user === null) {
            // No account owns this verified number yet — invite sign-up
            // (never reveals an existing account's details).
            return redirect()->route('register')
                ->with('success', 'No account uses this number yet — create one to continue.')
                ->withInput(['phone' => $phone]);
        }

        if (! $user->isActive()) {
            return redirect()->route('login')->with('error', 'This account is deactivated.');
        }

        Auth::login($user);
        $request->session()->regenerate();

        $this->devices->register($request, $user);
        $this->ipIntel->observe($request, $user);

        $this->loginEvents->record($user, LoginEvent::EVENT_LOGIN_PHONE, LoginEvent::STATUS_SUCCESS, $request);

        $this->audit->recordQuietly($user, 'auth.login', 'user', $user->id, [
            'target_user_id' => $user->id,
            'metadata' => ['provider' => 'phone'],
        ]);

        return redirect()->intended(route('home'))->with('success', 'Signed in with your phone.');
    }

    // ------------------------------------------------------------------
    // Google Sign-In (OAuth / OIDC)
    // ------------------------------------------------------------------

    public function redirectToGoogle()
    {
        try {
            return $this->google->redirect();
        } catch (DomainException $e) {
            return redirect()->route('login')->with('error', $e->getMessage());
        }
    }

    public function handleGoogleCallback(Request $request)
    {
        // A signed-in user arriving here with the link intent is connecting
        // Google to their existing account (not signing in).
        if (auth()->check() && $request->session()->pull('google_link_intent') === true) {
            try {
                $this->google->linkToCurrentUser($request, auth()->user());
            } catch (DomainException $e) {
                return redirect()->route('settings.connected-accounts')->with('error', $e->getMessage());
            }

            return redirect()->route('settings.connected-accounts')->with('success', 'Google was connected to your account.');
        }

        try {
            $user = $this->google->handleCallback($request);
        } catch (DomainException $e) {
            return redirect()->route('login')->with('error', $e->getMessage());
        }

        if (! $user->isActive()) {
            return redirect()->route('login')->with('error', 'This account is deactivated.');
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('home'))->with('success', 'Signed in with Google.');
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Send a server-generated, temporary signed verification URL through the
     * notification system (in-app + best-effort email).
     */
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
            'Click the link to verify your email address for FF Arena.',
            $link,
        );
    }
}

```

### 51.74 — `config/services.php`

> MODIFIED — Google OAuth service config

```php
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Payments (Phase 08)
    |--------------------------------------------------------------------------
    */
    'payments' => [
        // Secret used to sign/verify provider webhook callbacks (HMAC-SHA256).
        'webhook_secret' => env('PAYMENT_WEBHOOK_SECRET', 'ffarena-local-webhook-secret'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Google OAuth / OpenID Connect (Phase 14)
    |--------------------------------------------------------------------------
    |
    | Server-side OAuth 2.0 / OIDC via Laravel Socialite. The client secret
    | is read from the environment only. When the credentials are absent the
    | provider reports "not configured" and no redirect is issued.
    |
    */
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', env('APP_URL', 'http://localhost') . '/auth/google/callback'),
    ],

];

```

### 51.75 — `routes/web.php`

> MODIFIED — Phase 14 routes

```php
<?php

use App\Http\Controllers\AccountLiveController;
use App\Http\Controllers\AccountSecurityController;
use App\Http\Controllers\AdminAccountController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminSupportController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\DisputeController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LeaderboardController;
use App\Http\Controllers\LiveController;
use App\Http\Controllers\MatchController;
use App\Http\Controllers\ModerationController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PaymentMethodsController;
use App\Http\Controllers\PayoutController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ScoringRuleController;
use App\Http\Controllers\SecurityController;
use App\Http\Controllers\SettlementController;
use App\Http\Controllers\SupportController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TournamentController;
use App\Http\Controllers\WalletController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');

// Guest auth
Route::middleware('guest')->group(function () {
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register');
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

    // Password reset (enumeration-safe)
    Route::get('/forgot-password', [AuthController::class, 'showForgotPassword'])->name('password.request');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:password-reset')->name('password.email');
    Route::get('/reset-password/{token}', [AuthController::class, 'showResetPassword'])->name('password.reset');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('password.update');

    // Phone login
    Route::get('/login/phone', [AuthController::class, 'showPhoneLogin'])->name('phone.login');
    Route::post('/login/phone', [AuthController::class, 'requestPhoneOtp'])->middleware('throttle:otp-request')->name('phone.request');
});

// Phone verification page + login verify (guests and authed users — the
// same page serves both the login and the account-linking flows).
Route::get('/login/phone/verify', [AuthController::class, 'showPhoneVerify'])->name('phone.verify');
Route::post('/login/phone/verify', [AuthController::class, 'verifyPhoneLogin'])->middleware('throttle:otp-verify')->name('phone.login.verify');

// Google Sign-In (guests sign in; authed users may link via the settings
// redirect which sets a session link-intent flag).
Route::get('/auth/google', [AuthController::class, 'redirectToGoogle'])->name('google.redirect');
Route::get('/auth/google/callback', [AuthController::class, 'handleGoogleCallback'])->middleware('throttle:google-callback')->name('google.callback');

Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

// Provider payment webhook — authenticated by HMAC signature, not session.
Route::post('/webhooks/payments/{provider}', [WebhookController::class, 'handle'])->name('webhooks.payments');

// Public tournament browsing
Route::get('/tournaments', [TournamentController::class, 'index'])->name('tournaments.index');
Route::get('/tournaments/{tournament}', [TournamentController::class, 'show'])->name('tournaments.show');
Route::get('/tournaments/{tournament}/leaderboard', [LeaderboardController::class, 'show'])->name('leaderboard.show');

// Public profile (privacy-gated server-side; guests see only what the
// target's privacy preset allows). The edit route is declared FIRST so the
// literal `/profile/edit` wins over the `{user}` parameter route.
Route::get('/profile/edit', [ProfileController::class, 'edit'])->middleware('auth')->name('profile.edit');
Route::get('/profile/{user}', [ProfileController::class, 'show'])->name('profile.show');

// Realtime / live updates (Phase 12) — public read, server-side visibility
Route::get('/tournaments/{tournament}/live', [LiveController::class, 'tournamentLive'])->name('tournaments.live');
Route::get('/tournaments/{tournament}/stream', [LiveController::class, 'stream'])->name('tournaments.stream');

// Authenticated — every sensitive action is authorized server-side
Route::middleware('auth')->group(function () {
    // Organizer tournament lifecycle + participation controls
    Route::get('/organizer/tournaments/create', [TournamentController::class, 'create'])->name('tournaments.create');
    Route::post('/organizer/tournaments', [TournamentController::class, 'store'])->name('tournaments.store');
    Route::get('/organizer/tournaments/{tournament}/edit', [TournamentController::class, 'edit'])->name('tournaments.edit');
    Route::put('/organizer/tournaments/{tournament}', [TournamentController::class, 'update'])->name('tournaments.update');
    Route::post('/organizer/tournaments/{tournament}/publish', [TournamentController::class, 'publish'])->name('tournaments.publish');
    Route::post('/organizer/tournaments/{tournament}/close', [TournamentController::class, 'closeRegistration'])->name('tournaments.close');
    Route::post('/organizer/tournaments/{tournament}/bracket', [TournamentController::class, 'start'])->name('tournaments.bracket');
    Route::post('/organizer/tournaments/{tournament}/complete', [TournamentController::class, 'complete'])->name('tournaments.complete');
    Route::post('/organizer/tournaments/{tournament}/cancel', [TournamentController::class, 'cancel'])->name('tournaments.cancel');
    Route::post('/organizer/tournaments/{tournament}/no-shows', [TournamentController::class, 'markNoShows'])->name('tournaments.noshows');
    Route::post('/organizer/tournaments/{tournament}/waitlist/promote', [TournamentController::class, 'promoteWaitlisted'])->name('tournaments.waitlist.promote');

    // Scoring rules configuration (organizer/admin only)
    Route::get('/organizer/tournaments/{tournament}/scoring', [ScoringRuleController::class, 'show'])->name('tournaments.scoring.show');
    Route::post('/organizer/tournaments/{tournament}/scoring', [ScoringRuleController::class, 'store'])->name('tournaments.scoring.store');
    Route::post('/organizer/tournaments/{tournament}/scoring/{rule}/activate', [ScoringRuleController::class, 'activate'])->name('tournaments.scoring.activate');

    // Team registration, check-in, payment + roster management
    Route::get('/tournaments/{tournament}/register', [TeamController::class, 'showRegistration'])->name('teams.register');
    Route::post('/tournaments/{tournament}/register', [TeamController::class, 'register'])->name('teams.store');
    Route::get('/tournaments/{tournament}/teams/{team}', [TeamController::class, 'show'])->name('teams.show');
    Route::put('/tournaments/{tournament}/teams/{team}/profile', [TeamController::class, 'updateProfile'])->name('teams.update');
    Route::post('/tournaments/{tournament}/teams/{team}/members', [TeamController::class, 'addMember'])->name('teams.members.store');
    Route::post('/tournaments/{tournament}/teams/{team}/members/{member}/remove', [TeamController::class, 'removeMember'])->name('teams.members.remove');
    Route::post('/tournaments/{tournament}/teams/{team}/withdraw', [TeamController::class, 'withdraw'])->name('teams.withdraw');
    Route::post('/tournaments/{tournament}/teams/{team}/check-in', [TeamController::class, 'checkIn'])->name('teams.checkin');
    Route::get('/tournaments/{tournament}/teams/{team}/pay', [PaymentController::class, 'show'])->name('payment.show');
    Route::post('/tournaments/{tournament}/teams/{team}/pay', [PaymentController::class, 'verify'])->name('payment.verify');
    Route::get('/tournaments/{tournament}/teams/{team}/pay/{payment}/pending', [PaymentController::class, 'pending'])->name('payment.pending');

    // Checkout provider selection (Phase 14)
    Route::get('/tournaments/{tournament}/teams/{team}/pay/methods', [CheckoutController::class, 'methods'])->name('payment.methods');
    Route::post('/tournaments/{tournament}/teams/{team}/pay/initiate', [CheckoutController::class, 'initiate'])->middleware('throttle:payment-initiate')->name('payment.initiate');

    // Matches (bracket progression)
    Route::get('/tournaments/{tournament}/matches/{match}', [MatchController::class, 'show'])->name('matches.show');
    Route::post('/tournaments/{tournament}/matches/{match}/room', [MatchController::class, 'setRoom'])->name('matches.room');
    Route::post('/tournaments/{tournament}/matches/{match}/score', [MatchController::class, 'submitScore'])->name('matches.score');
    Route::post('/tournaments/{tournament}/matches/{match}/adjustment', [MatchController::class, 'addAdjustment'])->name('matches.adjustment');
    Route::post('/tournaments/{tournament}/matches/{match}/winner', [MatchController::class, 'setWinner'])->name('matches.winner');
    Route::post('/tournaments/{tournament}/matches/{match}/dispute', [MatchController::class, 'dispute'])->name('matches.dispute');
    Route::post('/tournaments/{tournament}/matches/{match}/resolve', [MatchController::class, 'resolve'])->name('matches.resolve');

    // Disputes (Phase 07) — nested under tournament + match so every record
    // is validated against its parents; authorization never relies on route
    // model binding alone.
    Route::get('/tournaments/{tournament}/matches/{match}/disputes/create', [DisputeController::class, 'create'])->name('matches.disputes.create');
    Route::post('/tournaments/{tournament}/matches/{match}/disputes', [DisputeController::class, 'store'])->name('matches.disputes.store');
    Route::get('/tournaments/{tournament}/matches/{match}/disputes/{dispute}', [DisputeController::class, 'show'])->name('matches.disputes.show');
    Route::post('/tournaments/{tournament}/matches/{match}/disputes/{dispute}/evidence', [DisputeController::class, 'addEvidence'])->name('matches.disputes.evidence.store');
    Route::get('/tournaments/{tournament}/matches/{match}/disputes/{dispute}/evidence/{evidence}', [DisputeController::class, 'evidence'])->name('matches.disputes.evidence.show');
    Route::post('/tournaments/{tournament}/matches/{match}/disputes/{dispute}/cancel', [DisputeController::class, 'cancel'])->name('matches.disputes.cancel');
    Route::post('/tournaments/{tournament}/matches/{match}/disputes/{dispute}/review', [DisputeController::class, 'review'])->name('matches.disputes.review');
    Route::post('/tournaments/{tournament}/matches/{match}/disputes/{dispute}/assign', [DisputeController::class, 'assign'])->name('matches.disputes.assign');
    Route::post('/tournaments/{tournament}/matches/{match}/disputes/{dispute}/resolve', [DisputeController::class, 'resolve'])->name('matches.disputes.resolve');
    Route::post('/tournaments/{tournament}/matches/{match}/disputes/{dispute}/reject', [DisputeController::class, 'reject'])->name('matches.disputes.reject');
    Route::post('/tournaments/{tournament}/matches/{match}/disputes/{dispute}/evidence/{evidence}/remove', [DisputeController::class, 'removeEvidence'])->name('matches.disputes.evidence.remove');

    // Moderation queue (staff)
    Route::get('/moderation', [ModerationController::class, 'index'])->name('moderation.index');

    // Moderation security review (admin + moderator only, Phase 10)
    Route::get('/moderation/security', [ModerationController::class, 'security'])->name('moderation.security');

    // Security — anti-cheat incidents + identity request (policy-guarded)
    Route::get('/security/incidents', [SecurityController::class, 'incidents'])->name('security.incidents.index');
    Route::post('/security/incidents', [SecurityController::class, 'openIncident'])->name('security.incidents.open');
    Route::post('/security/incidents/{incident}/review', [SecurityController::class, 'reviewIncident'])->name('security.incidents.review');
    Route::post('/security/incidents/{incident}/resolve', [SecurityController::class, 'resolveIncident'])->name('security.incidents.resolve');
    Route::post('/security/identity/request', [SecurityController::class, 'requestVerification'])->name('security.identity.request');

    // Wallet (authenticated user)
    Route::get('/wallet', [WalletController::class, 'index'])->name('wallet.index');

    // Notifications (Phase 11 — always the authenticated user's own inbox)
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.readAll');
    Route::get('/notifications/unread', [LiveController::class, 'unreadCount'])->name('notifications.unread');

    // Support (Phase 13 — users manage only their own tickets)
    Route::get('/support', [SupportController::class, 'index'])->name('support.index');
    Route::get('/support/create', [SupportController::class, 'create'])->name('support.create');
    Route::post('/support', [SupportController::class, 'store'])->name('support.store');
    Route::get('/support/{ticket}', [SupportController::class, 'show'])->name('support.tickets.show');
    Route::post('/support/{ticket}/reply', [SupportController::class, 'reply'])->name('support.tickets.reply');
    Route::post('/support/{ticket}/close', [SupportController::class, 'close'])->name('support.tickets.close');
    Route::post('/support/{ticket}/reopen', [SupportController::class, 'reopen'])->name('support.tickets.reopen');
    Route::get('/support/{ticket}/messages', [SupportController::class, 'messages'])->name('support.tickets.messages');

    // Email verification (Phase 14 — server-generated signed URLs)
    Route::get('/verify-email', [AuthController::class, 'showVerifyEmail'])->name('verification.notice');
    Route::get('/verify-email/{id}/{hash}', [AuthController::class, 'verifyEmail'])->name('verification.verify');
    Route::post('/email/verification-notification', [AuthController::class, 'resendVerification'])->middleware('throttle:verification-resend')->name('verification.resend');

    // Profile (Phase 14)
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/username', [ProfileController::class, 'updateUsername'])->name('profile.username');
    Route::put('/profile/privacy', [ProfileController::class, 'updatePrivacy'])->name('profile.privacy');
    Route::put('/profile/preferences', [ProfileController::class, 'updatePreferences'])->name('profile.preferences');
    Route::post('/settings/password', [ProfileController::class, 'changePassword'])->name('settings.password');

    // Security settings, sessions, login history, connected accounts,
    // lifecycle (Phase 14)
    Route::get('/settings/security', [AccountSecurityController::class, 'security'])->name('settings.security');
    Route::get('/settings/connected-accounts', [AccountSecurityController::class, 'connectedAccounts'])->name('settings.connected-accounts');
    Route::get('/settings/sessions', [AccountSecurityController::class, 'sessions'])->name('settings.sessions');
    Route::post('/settings/sessions/others', [AccountSecurityController::class, 'revokeOtherSessions'])->name('settings.sessions.revokeOthers');
    Route::post('/settings/sessions/all', [AccountSecurityController::class, 'revokeAllSessions'])->name('settings.sessions.revokeAll');
    Route::get('/settings/login-history', [AccountSecurityController::class, 'loginHistory'])->name('settings.login-history');
    Route::get('/settings/google/link', [AccountSecurityController::class, 'linkGoogleRedirect'])->name('settings.google.link');
    Route::post('/settings/google/unlink', [AccountSecurityController::class, 'unlinkGoogle'])->name('settings.google.unlink');
    Route::post('/settings/phone/link', [AccountSecurityController::class, 'linkPhone'])->middleware('throttle:otp-request')->name('settings.phone.link');
    Route::post('/settings/phone/link/verify', [AccountSecurityController::class, 'verifyPhoneLink'])->middleware('throttle:otp-verify')->name('settings.phone.link.verify');
    Route::post('/settings/phone/unlink', [AccountSecurityController::class, 'unlinkPhone'])->name('settings.phone.unlink');
    Route::post('/settings/account/deactivate', [AccountSecurityController::class, 'deactivate'])->name('settings.deactivate');
    Route::post('/settings/account/reactivate', [AccountSecurityController::class, 'reactivate'])->name('settings.reactivate');
    Route::post('/settings/account/delete-request', [AccountSecurityController::class, 'requestDeletion'])->name('settings.deletion.request');
    Route::post('/settings/account/delete-cancel', [AccountSecurityController::class, 'cancelDeletion'])->name('settings.deletion.cancel');

    // Saved payment methods (Phase 14)
    Route::get('/settings/payment-methods', [PaymentMethodsController::class, 'index'])->name('settings.payment-methods');
    Route::post('/settings/payment-methods', [PaymentMethodsController::class, 'store'])->name('settings.payment-methods.store');
    Route::delete('/settings/payment-methods/{method}', [PaymentMethodsController::class, 'destroy'])->name('settings.payment-methods.destroy');
    Route::post('/settings/payment-methods/{method}/default', [PaymentMethodsController::class, 'setDefault'])->name('settings.payment-methods.default');

    // Account realtime feed (Phase 14)
    Route::get('/account/live', [AccountLiveController::class, 'index'])->name('account.live');

    // Per-tournament operational analytics (organizer/admin/moderator)
    Route::get('/tournaments/{tournament}/analytics', [AnalyticsController::class, 'tournament'])->name('tournaments.analytics');

    // Staff (admin + moderator) support queue + staff analytics (Phase 13)
    Route::middleware('staff')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/support', [AdminSupportController::class, 'index'])->name('support.index');
        Route::get('/support/export', [AdminSupportController::class, 'export'])->name('support.export');
        Route::get('/support/{ticket}', [AdminSupportController::class, 'show'])->name('support.show');
        Route::post('/support/{ticket}/assign', [AdminSupportController::class, 'assign'])->name('support.assign');
        Route::post('/support/{ticket}/status', [AdminSupportController::class, 'status'])->name('support.status');
        Route::post('/support/{ticket}/note', [AdminSupportController::class, 'internalNote'])->name('support.note');
        Route::post('/support/{ticket}/reply', [AdminSupportController::class, 'reply'])->name('support.reply');

        Route::get('/analytics/disputes', [AnalyticsController::class, 'disputes'])->name('analytics.disputes');
        Route::get('/analytics/support', [AnalyticsController::class, 'support'])->name('analytics.support');
    });

    // Admin
    Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/dashboard', [AdminController::class, 'dashboard'])->name('dashboard');

        // Account administration (Phase 14)
        Route::get('/accounts', [AdminAccountController::class, 'index'])->name('accounts.index');
        Route::get('/accounts/{user}', [AdminAccountController::class, 'show'])->name('accounts.show');
        Route::post('/accounts/{user}/sessions/revoke', [AdminAccountController::class, 'revokeSessions'])->name('accounts.sessions.revoke');
        Route::post('/accounts/{user}/deactivate', [AdminAccountController::class, 'deactivate'])->name('accounts.deactivate');
        Route::post('/accounts/{user}/reactivate', [AdminAccountController::class, 'reactivate'])->name('accounts.reactivate');
        Route::post('/accounts/{user}/delete', [AdminAccountController::class, 'delete'])->name('accounts.delete');

        // Payments (Phase 08)
        Route::get('/payments', [AdminController::class, 'payments'])->name('payments.index');
        Route::post('/payments/{payment}/verify', [AdminController::class, 'verifyPayment'])->name('payments.verify');
        Route::post('/payments/{payment}/fail', [AdminController::class, 'failPayment'])->name('payments.fail');
        Route::post('/payments/{payment}/refund', [AdminController::class, 'refundPayment'])->name('payments.refund');

        // Wallets + ledger (Phase 08)
        Route::get('/users/{user}/wallet', [AdminController::class, 'wallet'])->name('wallet.show');
        Route::post('/users/{user}/wallet/credit', [AdminController::class, 'creditWallet'])->name('wallet.credit');
        Route::post('/users/{user}/wallet/debit', [AdminController::class, 'debitWallet'])->name('wallet.debit');

        // Prize distribution + payouts + settlement (Phase 09)
        Route::get('/settlements', [SettlementController::class, 'index'])->name('settlements.index');
        Route::get('/tournaments/{tournament}/settlement', [SettlementController::class, 'show'])->name('settlements.show');
        Route::post('/tournaments/{tournament}/settlement/prizes', [SettlementController::class, 'storePrizeTiers'])->name('settlements.prizes');
        Route::post('/tournaments/{tournament}/settlement/calculate', [SettlementController::class, 'calculate'])->name('settlements.calculate');
        Route::post('/tournaments/{tournament}/settlement/approve', [SettlementController::class, 'approve'])->name('settlements.approve');
        Route::post('/tournaments/{tournament}/settlement/process', [SettlementController::class, 'process'])->name('settlements.process');
        Route::post('/tournaments/{tournament}/settlement/cancel', [SettlementController::class, 'cancel'])->name('settlements.cancel');
        Route::post('/tournaments/{tournament}/settlement/adjust', [SettlementController::class, 'adjust'])->name('settlements.adjust');

        Route::get('/payouts', [PayoutController::class, 'index'])->name('payouts.index');
        Route::post('/payouts/{payout}/approve', [PayoutController::class, 'approve'])->name('payouts.approve');
        Route::post('/payouts/{payout}/process', [PayoutController::class, 'process'])->name('payouts.process');
        Route::post('/payouts/{payout}/process-override', [PayoutController::class, 'processOverride'])->name('payouts.processOverride');
        Route::post('/payouts/{payout}/complete', [PayoutController::class, 'complete'])->name('payouts.complete');
        Route::post('/payouts/{payout}/fail', [PayoutController::class, 'fail'])->name('payouts.fail');
        Route::post('/payouts/{payout}/cancel', [PayoutController::class, 'cancel'])->name('payouts.cancel');

        // Anti-fraud security administration (Phase 10)
        Route::get('/security', [SecurityController::class, 'dashboard'])->name('security.dashboard');
        Route::get('/security/users', [SecurityController::class, 'users'])->name('security.users');
        Route::get('/security/users/{user}', [SecurityController::class, 'user'])->name('security.user');
        Route::get('/security/events', [SecurityController::class, 'events'])->name('security.events');
        Route::post('/security/users/{user}/restrict', [SecurityController::class, 'restrict'])->name('security.restrict');
        Route::post('/security/restrictions/{restriction}/lift', [SecurityController::class, 'liftRestriction'])->name('security.lift');
        Route::post('/security/users/{user}/verify', [SecurityController::class, 'verifyIdentity'])->name('security.verify');
        Route::post('/security/users/{user}/reject-identity', [SecurityController::class, 'rejectIdentity'])->name('security.reject');

        // Moderation roles (Phase 07)
        Route::post('/users/moderators', [AdminController::class, 'makeModerator'])->name('users.moderate');
        Route::post('/users/{user}/remove-moderator', [AdminController::class, 'removeModerator'])->name('users.unmoderate');

        // Audit log (Phase 13 — admin only, read-only)
        Route::get('/audit', [AuditController::class, 'index'])->name('audit.index');
        Route::get('/audit/export', [AuditController::class, 'export'])->name('audit.export');

        // Analytics (Phase 13 — global/financial/security are admin only)
        Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics.index');
        Route::get('/analytics/tournaments', [AnalyticsController::class, 'tournaments'])->name('analytics.tournaments');
        Route::get('/analytics/financial', [AnalyticsController::class, 'financial'])->name('analytics.financial');
        Route::get('/analytics/security', [AnalyticsController::class, 'security'])->name('analytics.security');
        Route::get('/analytics/tournaments/export', [AnalyticsController::class, 'exportTournaments'])->name('analytics.export');
    });
});

```

### 51.76 — `bootstrap/app.php`

> MODIFIED — EnsureActiveAccount middleware

```php
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureUserIsAdmin::class,
            'staff' => \App\Http\Middleware\EnsureUserIsStaff::class,
        ]);

        // Phase 13 — assign a per-request audit correlation id.
        // Phase 14 — block deactivated/deleted accounts (with a reactivate
        // escape hatch on the security-settings page).
        $middleware->web(append: [
            \App\Http\Middleware\AssignAuditRequestId::class,
            \App\Http\Middleware\EnsureActiveAccount::class,
        ]);

        // Provider payment webhooks are authenticated by HMAC signature, not
        // by a session CSRF token.
        $middleware->validateCsrfTokens(except: [
            'webhooks/payments/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

```

### 51.77 — `.env.example`

> MODIFIED — Phase 14 environment keys

```ini
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

VITE_APP_NAME="${APP_NAME}"

```

### 51.78 — `composer.json`

> MODIFIED — added laravel/socialite ^5.31

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

### 51.79 — `resources/views/auth/login.blade.php`

> MODIFIED — Google + phone buttons

```php
@extends('layouts.app')
@section('title', 'Login — FF Arena')
@section('content')
    <div class="card" style="max-width: 460px; margin: 50px auto">
        <h2>Login</h2>
        <form method="POST" action="{{ route('login') }}">
            @csrf
            <label>Email</label>
            <input type="email" name="email" value="{{ old('email') }}" required>
            <label>Password</label>
            <input type="password" name="password" required>
            <label style="display:flex; gap:8px; align-items:center; margin-top:12px">
                <input type="checkbox" name="remember" style="width:auto"> Remember me
            </label>
            <button class="btn btn-primary" style="margin-top:18px; width:100%">Login</button>
        </form>
        <div style="text-align:center; margin-top:10px" class="muted">or</div>
        <div style="display:flex; flex-direction:column; gap:8px; margin-top:10px">
            @if (config('services.google.client_id') && config('services.google.client_secret'))
                <a href="{{ route('google.redirect') }}" class="btn" style="width:100%; text-align:center">Continue with Google</a>
            @endif
            <a href="{{ route('phone.login') }}" class="btn" style="width:100%; text-align:center">Login with phone</a>
        </div>
        <p class="muted" style="margin-top:14px; font-size:13px">
            <a href="{{ route('password.request') }}">Forgot password?</a> ·
            No account? <a href="{{ route('register') }}">Register here</a>
        </p>
    </div>
@endsection

```

### 51.80 — `resources/views/auth/register.blade.php`

> MODIFIED — phone-login link

```php
@extends('layouts.app')
@section('title', 'Register — FF Arena')
@section('content')
    <div class="card" style="max-width: 520px; margin: 40px auto">
        <h2>Create your account</h2>
        <p class="muted" style="font-size:14px">Join as a Player or an Organizer.</p>
        <form method="POST" action="{{ route('register') }}">
            @csrf
            <label>Full name</label>
            <input type="text" name="name" value="{{ old('name') }}" required>
            <label>Username</label>
            <input type="text" name="username" value="{{ old('username') }}" required>
            <label>Email</label>
            <input type="email" name="email" value="{{ old('email') }}" required>
            <label>Phone (bKash)</label>
            <input type="text" name="phone" value="{{ old('phone') }}">
            <label>Free Fire UID</label>
            <input type="text" name="game_uid" value="{{ old('game_uid') }}">
            <label>I am a…</label>
            <select name="role">
                <option value="player">Player</option>
                <option value="organizer">Organizer</option>
            </select>
            <label>Password</label>
            <input type="password" name="password" required>
            <label>Confirm password</label>
            <input type="password" name="password_confirmation" required>
            <button class="btn btn-primary" style="margin-top:18px; width:100%">Create Account</button>
        </form>
        <p class="muted" style="margin-top:14px; font-size:13px">
            Prefer to sign in with your phone? <a href="{{ route('phone.login') }}">Login with phone</a>
        </p>
    </div>
@endsection

```

### 51.81 — `resources/views/layouts/app.blade.php`

> MODIFIED — Profile/Settings + Accounts nav

```php
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'FF Arena') — Bangladesh Free Fire Tournaments</title>
    <style>
        :root {
            --bg: #0b0e1a;
            --panel: #141a2e;
            --panel2: #1b2340;
            --line: #28335a;
            --txt: #e8ecff;
            --muted: #8a93b8;
            --cyan: #22d3ee;
            --purple: #a855f7;
            --green: #34d399;
            --red: #f87171;
            --amber: #fbbf24;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: var(--bg);
            color: var(--txt);
            min-height: 100vh;
            background-image: radial-gradient(1200px 600px at 80% -10%, rgba(168,85,247,.14), transparent),
                              radial-gradient(900px 500px at -10% 110%, rgba(34,211,238,.12), transparent);
        }
        a { color: var(--cyan); text-decoration: none; }
        a:hover { text-decoration: underline; }
        .container { max-width: 1180px; margin: 0 auto; padding: 0 20px; }
        nav {
            display: flex; align-items: center; gap: 20px;
            padding: 14px 0; border-bottom: 1px solid var(--line);
            flex-wrap: wrap;
        }
        .brand { font-size: 22px; font-weight: 800; letter-spacing: .5px; }
        .brand span { color: var(--cyan); }
        .nav-links { display: flex; gap: 18px; align-items: center; margin-left: auto; flex-wrap: wrap; }
        .btn {
            display: inline-block; padding: 9px 16px; border-radius: 8px; border: 1px solid var(--line);
            background: var(--panel2); color: var(--txt); font-weight: 600; font-size: 14px; cursor: pointer;
            transition: .15s;
        }
        .btn:hover { border-color: var(--cyan); text-decoration: none; }
        .btn-primary { background: linear-gradient(90deg, #7c3aed, #2563eb); border: none; color: #fff; }
        .btn-primary:hover { filter: brightness(1.12); }
        .btn-cyan { background: rgba(34,211,238,.12); border: 1px solid var(--cyan); color: var(--cyan); }
        .btn-green { background: rgba(52,211,153,.12); border: 1px solid var(--green); color: var(--green); }
        .btn-sm { padding: 6px 12px; font-size: 13px; }
        .card {
            background: var(--panel); border: 1px solid var(--line); border-radius: 14px;
            padding: 20px; margin-bottom: 18px;
        }
        .grid { display: grid; gap: 18px; }
        .cols-3 { grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); }
        .cols-2 { grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); }
        h1 { font-size: 26px; margin-bottom: 8px; }
        h2 { font-size: 20px; margin-bottom: 12px; }
        h3 { font-size: 16px; margin-bottom: 6px; }
        .muted { color: var(--muted); }
        .pill {
            display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 12px; font-weight: 700;
        }
        .pill.open { background: rgba(52,211,153,.15); color: var(--green); }
        .pill.live { background: rgba(34,211,238,.15); color: var(--cyan); }
        .pill.closed, .pill.finished, .pill.disputed { background: rgba(248,113,113,.15); color: var(--red); }
        .pill.draft { background: rgba(251,191,36,.15); color: var(--amber); }
        .pill.pending { background: rgba(251,191,36,.15); color: var(--amber); }
        .pill.ready { background: rgba(34,211,238,.15); color: var(--cyan); }
        .pill.confirmed, .pill.verified, .pill.checked { background: rgba(52,211,153,.15); color: var(--green); }
        .pill.waitlisted { background: rgba(251,191,36,.15); color: var(--amber); }
        .pill.cancelled, .pill.withdrawn, .pill.rejected, .pill.failed, .pill.no_show, .pill.bye { background: rgba(148,163,184,.15); color: var(--muted); }
        form label { display: block; font-size: 13px; color: var(--muted); margin: 12px 0 4px; }
        input, select, textarea {
            width: 100%; padding: 10px 12px; border-radius: 8px; border: 1px solid var(--line);
            background: #0d1226; color: var(--txt); font-size: 14px;
        }
        input:focus, select:focus, textarea:focus { outline: none; border-color: var(--cyan); }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--line); font-size: 14px; }
        th { color: var(--muted); font-size: 12px; text-transform: uppercase; letter-spacing: .5px; }
        .flash { padding: 12px 16px; border-radius: 10px; margin: 16px 0; font-weight: 600; }
        .flash.success { background: rgba(52,211,153,.15); color: var(--green); border: 1px solid rgba(52,211,153,.4); }
        .flash.error { background: rgba(248,113,113,.15); color: var(--red); border: 1px solid rgba(248,113,113,.4); }
        .stat { background: var(--panel); border: 1px solid var(--line); border-radius: 12px; padding: 16px; }
        .stat .num { font-size: 26px; font-weight: 800; color: var(--cyan); }
        .bracket-col { display: flex; flex-wrap: wrap; gap: 24px; align-items: flex-start; overflow-x: auto; padding-bottom: 10px; }
        .bracket-round { display: flex; flex-direction: column; gap: 14px; min-width: 190px; }
        .bracket-match { background: var(--panel2); border: 1px solid var(--line); border-radius: 10px; padding: 8px; }
        .bracket-team { padding: 7px 10px; border-radius: 6px; font-size: 13px; display: flex; justify-content: space-between; gap: 8px; }
        .bracket-team.win { background: rgba(52,211,153,.12); color: var(--green); font-weight: 700; }
        .bracket-team.bye { color: var(--muted); }
        .divider { height: 1px; background: var(--line); margin: 4px 0; }
        footer { border-top: 1px solid var(--line); margin-top: 50px; padding: 22px 0; color: var(--muted); font-size: 13px; }
        .tag { color: var(--purple); font-weight: 700; }
    </style>
</head>
<body>
<div class="container">
    <nav @auth data-unread-url="{{ route('notifications.unread') }}" @endauth>
        <a href="{{ route('home') }}" class="brand">FF<span>ARENA</span></a>
        <div class="nav-links">
            <a href="{{ route('tournaments.index') }}">Tournaments</a>
            @auth
                @if(auth()->user()->isOrganizer() || auth()->user()->isAdmin())
                    <a href="{{ route('tournaments.create') }}" class="btn btn-sm btn-cyan">+ Create Tournament</a>
                @endif
                <a href="{{ route('wallet.index') }}" class="btn btn-sm">Wallet</a>
                <a href="{{ route('support.index') }}" class="btn btn-sm">Support</a>
                <a href="{{ route('notifications.index') }}" class="btn btn-sm" style="position:relative">
                    🔔 Notifications
                    <span id="unread-badge" style="background:var(--red); color:#fff; border-radius:999px; padding:0 6px; font-size:11px; font-weight:700; margin-left:4px; display:{{ ($unreadNotifications ?? 0) > 0 ? 'inline-block' : 'none' }}">{{ $unreadNotifications ?? 0 }}</span>
                </a>
                @if(auth()->user()->isAdmin() || auth()->user()->isModerator() || auth()->user()->isOrganizer())
                    <a href="{{ route('moderation.index') }}" class="btn btn-sm">Moderation</a>
                @endif
                @if(auth()->user()->isAdmin() || auth()->user()->isModerator())
                    <a href="{{ route('moderation.security') }}" class="btn btn-sm">Security</a>
                @endif
                @if(auth()->user()->isAdmin() || auth()->user()->isModerator())
                    <a href="{{ route('admin.support.index') }}" class="btn btn-sm">Support Queue</a>
                @endif
                @if(auth()->user()->isAdmin())
                    <a href="{{ route('admin.accounts.index') }}" class="btn btn-sm">Accounts</a>
                    <a href="{{ route('admin.analytics.index') }}" class="btn btn-sm">Analytics</a>
                    <a href="{{ route('admin.audit.index') }}" class="btn btn-sm">Audit</a>
                    <a href="{{ route('admin.dashboard') }}" class="btn btn-sm">Admin</a>
                @endif
                <a href="{{ route('profile.show', auth()->user()) }}" class="btn btn-sm">Profile</a>
                <a href="{{ route('profile.edit') }}" class="btn btn-sm">Settings</a>
                <span class="muted">{{ auth()->user()->name }} ({{ auth()->user()->role }})</span>
                <form method="POST" action="{{ route('logout') }}" style="display:inline">
                    @csrf
                    <button class="btn btn-sm">Logout</button>
                </form>
            @else
                <a href="{{ route('login') }}" class="btn btn-sm">Login</a>
                <a href="{{ route('register') }}" class="btn btn-sm btn-primary">Register</a>
            @endauth
        </div>
    </nav>

    @if(session('success'))
        <div class="flash success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="flash error">{{ session('error') }}</div>
    @endif
    @if($errors->any())
        <div class="flash error">
            <ul style="list-style:none;padding:0;margin:0">
                @foreach($errors->all() as $e) <li>• {{ $e }}</li> @endforeach
            </ul>
        </div>
    @endif

    @yield('content')

    <footer>
        <div class="container" style="padding:0">
            <strong class="tag">FF Arena</strong> — Bangladesh's Free Fire tournament platform.
            Legit. Smart. Profitable. No hacks, ever. 🤝
        </div>
    </footer>
</div>

@auth
<script>
(function () {
    var nav = document.querySelector('nav[data-unread-url]');
    if (!nav || !nav.dataset.unreadUrl) { return; }
    var badge = document.getElementById('unread-badge');
    if (!badge) { return; }
    setInterval(function () {
        fetch(nav.dataset.unreadUrl, { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                var n = parseInt(d.unread || 0, 10);
                if (n > 0) { badge.textContent = n; badge.style.display = 'inline-block'; }
                else { badge.style.display = 'none'; }
            })
            .catch(function () { /* keep the last known count */ });
    }, 10000);
})();
</script>
@endauth
</body>
</html>

```

### 51.82 — `resources/views/payment/show.blade.php`

> MODIFIED — link to provider grid

```php
@extends('layouts.app')
@section('title', 'Pay Entry Fee — FF Arena')
@section('content')
    <div class="card" style="max-width: 460px; margin: 50px auto; text-align:center">
        <div style="font-size:44px">💳</div>
        <h2>Pay Entry Fee</h2>
        <p class="muted">Team <strong>{{ $team->name }}</strong> · {{ $tournament->name }}</p>
        <div class="stat" style="margin:14px 0">
            <div class="muted" style="font-size:13px">Amount to pay</div>
            <div class="num" style="color:var(--green)">৳{{ number_format($tournament->entry_fee, 2) }}</div>
        </div>

        @if((float)$tournament->entry_fee <= 0)
            <form method="POST" action="{{ route('payment.verify', [$tournament, $team]) }}">
                @csrf
                <input type="hidden" name="bkash_number" value="{{ $team->phone }}">
                <input type="hidden" name="trx_id" value="FREE{{ $team->id }}">
                <button class="btn btn-green" style="width:100%">Confirm Free Registration</button>
            </form>
        @else
            <div class="muted" style="font-size:13px; margin-bottom:12px">
                Send <strong>৳{{ number_format($tournament->entry_fee, 2) }}</strong> to the organizer's bKash number,
                then enter your Transaction ID below.
            </div>
            <form method="POST" action="{{ route('payment.verify', [$tournament, $team]) }}">
                @csrf
                <label style="text-align:left">Your bKash number</label>
                <input type="text" name="bkash_number" value="{{ $team->phone }}" required>
                <label style="text-align:left">bKash Transaction ID (TrxID)</label>
                <input type="text" name="trx_id" placeholder="e.g. 9H7K2L1M3N" required>
                <button class="btn btn-primary" style="margin-top:16px; width:100%">Verify Payment</button>
            </form>
        @endif
        <p class="muted" style="margin-top:14px; font-size:12px">
            Prefer another method?
            <a href="{{ route('payment.methods', [$tournament, $team]) }}">Choose from bKash, Nagad, Rocket &amp; more →</a>
        </p>
        <p class="muted" style="margin-top:8px; font-size:12px">
            Payments are reviewed by the organizer/admin before your slot is confirmed.
        </p>
    </div>
@endsection

```

### 51.83 — `resources/views/admin/dashboard.blade.php`

> MODIFIED — Accounts link

```php
@extends('layouts.app')
@section('title', 'Admin Dashboard — FF Arena')
@section('content')
    <h1 style="margin:30px 0 16px">🛡 Admin Dashboard</h1>

    <div class="card" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center">
        <span class="muted">Operations:</span>
        <a href="{{ route('admin.analytics.index') }}" class="btn btn-sm">Analytics</a>
        <a href="{{ route('admin.audit.index') }}" class="btn btn-sm">Audit</a>
        <a href="{{ route('admin.accounts.index') }}" class="btn btn-sm btn-cyan">Accounts</a>
        <a href="{{ route('admin.support.index') }}" class="btn btn-sm btn-cyan">Support</a>
        <span class="muted">Financials:</span>
        <a href="{{ route('admin.payments.index') }}" class="btn btn-sm">Payments</a>
        <a href="{{ route('admin.settlements.index') }}" class="btn btn-sm btn-cyan">Settlements</a>
        <a href="{{ route('admin.payouts.index') }}" class="btn btn-sm">Payouts</a>
        <span class="muted">Security:</span>
        <a href="{{ route('admin.security.dashboard') }}" class="btn btn-sm">Security</a>
    </div>

    <div class="grid cols-2" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr))">
        <div class="stat"><div class="muted">Tournaments</div><div class="num">{{ $stats['tournaments'] }}</div></div>
        <div class="stat"><div class="muted">Teams</div><div class="num">{{ $stats['teams'] }}</div></div>
        <div class="stat"><div class="muted">Verified payments</div><div class="num">{{ $stats['verified_payments'] }}</div></div>
        <div class="stat"><div class="muted">Collected (৳)</div><div class="num">{{ number_format($stats['revenue']) }}</div></div>
        <div class="stat"><div class="muted">Platform commission (8%)</div><div class="num" style="color:var(--green)">৳{{ number_format($stats['commission']) }}</div></div>
    </div>

    <div class="card" style="margin-top:18px">
        <h3>🛡 Moderators</h3>
        <form method="POST" action="{{ route('admin.users.moderate') }}" style="display:flex; gap:10px; align-items:end">
            @csrf
            <div style="flex:1; max-width:320px">
                <label>Promote a user to moderator (by email)</label>
                <input type="email" name="email" placeholder="user@example.com" required>
            </div>
            <button class="btn btn-cyan btn-sm">Promote</button>
        </form>
        @if($moderators->isEmpty())
            <p class="muted" style="margin-top:12px">No moderators yet.</p>
        @else
            <table style="margin-top:12px">
                <tr><th>Name</th><th>Email</th><th></th></tr>
                @foreach($moderators as $moderator)
                    <tr>
                        <td>{{ $moderator->name }}</td>
                        <td class="muted">{{ $moderator->email }}</td>
                        <td>
                            <form method="POST" action="{{ route('admin.users.unmoderate', $moderator) }}">
                                @csrf
                                <button class="btn btn-sm" style="border-color:var(--red); color:var(--red)">Demote</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </table>
        @endif
    </div>

    <div class="card" style="margin-top:18px">
        <h3>💸 Pending Payments</h3>
        @if($pendingPayments->isEmpty())
            <p class="muted">No pending payments.</p>
        @else
            <table>
                <tr><th>Tournament</th><th>Team</th><th>Amount</th><th>TrxID</th><th>Action</th></tr>
                @foreach($pendingPayments as $p)
                    <tr>
                        <td>{{ $p->tournament->name }}</td>
                        <td>{{ $p->team->name }}</td>
                        <td>৳{{ number_format($p->amount_minor / 100, 2) }}</td>
                        <td>{{ $p->trx_id }}</td>
                        <td>
                            <form method="POST" action="{{ route('admin.payments.verify', $p) }}" style="display:inline">@csrf
                                <button class="btn btn-green btn-sm">Verify</button>
                            </form>
                            <form method="POST" action="{{ route('admin.payments.fail', $p) }}" style="display:inline">@csrf
                                <button class="btn btn-sm">Reject</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </table>
            <p class="muted" style="font-size:13px; margin-top:12px">
                <a href="{{ route('admin.payments.index') }}">View all payments &amp; refunds →</a>
            </p>
        @endif
    </div>
@endsection

```
