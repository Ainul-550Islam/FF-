# FF-audit — Security Gap Report & Applied Fixes
**Repo:** FF- (main) · **Date:** 2026-10-08 · **Suite:** 1573 tests — **0 errors / 0 failures / 54 environment-skips** (baseline was 11 errors + 21 failures)

Every fix below is implemented in the working tree and mirrored under `FIXED_CODE/` (same paths as the repo). All changes carry an in-code `AUDIT FIX (2026-10-08, GAPS-xx)` comment at the edit site.

## CRITICAL

### GAPS-01 — Forged webhooks via a committed signing secret
`config/services.php` shipped a fallback (`'ffarena-local-webhook-secret'`), `WebhookIngressService::secretFor()` repeated it, and `.env.example` published a live dev value. Anyone reading the public source could sign a `payment.succeeded` webhook against any deployment that never rotated the key — instant settle of any entry fee.
**Fix:** no committed default (env-only); ingress fails closed loudly in production when the secret is unset/`CHANGE_ME_*` (503 + error log); `PaymentService::verifySignature()` refuses empty secrets always and placeholders in production (mirroring `ServiceAuthenticator` policy); `HealthService::productionIssues()` reports a **critical** `PAYMENT_WEBHOOK_SECRET` finding; `.env.example` carries `CHANGE_ME_*` placeholders + generation command; `ApiWebhookTest` pins its own secret in config (CI-safe).

### GAPS-02 — Business-signature layer (GAP-10 A5) did not exist in code
`config/webhooks.php` published a per-provider business-secret map + timestamp tolerance, docs described it, a full test (`ProviderBusinessSecretTest`) existed — `PaymentService::verifySignature()` implemented none of it (plain shared-secret compare), and `WebhookIngressService::processPayment()` **re-signed the body itself** so the state machine verified a signature it had just minted (tautology bypass).
**Fix:** `businessSecretFor()` (per-provider secret, shared-secret fallback), `verifySignature(rawBody, signature, provider, timestamp)` with dual scheme (raw-body legacy + `HMAC("{ts}.{body}")` when timestamped, freshness window enforced, stale never falls back), `handleProviderCallback(..., ?int timestamp)` verifying **before any state change**, ingress forwards the provider's ORIGINAL signature + timestamp untouched (no self-signing), `X-Timestamp` honoured only when present (legacy senders keep working — replay protection there is event-id idempotency).

### GAPS-03 — League trophy self-granting
`POST /api/v1/gameberry/league/trophies` let any authenticated user grant themselves up to 1000 trophies per call (no match proof) — full Bronze→Titan climb in minutes.
**Fix:** endpoint is staff-only (controller check + `admin` route middleware belt), `reason` required, every grant audited (`league.trophy_grant`), internal errors no longer echoed.

## HIGH

### GAPS-04 — Legacy webhooks dead on arrival (timestamp gate)
The HEAD ingress ran the freshness gate unconditionally, rejecting every timestampless Phase 08 webhook. **Fix:** gate only when `X-Timestamp` is present (part of GAPS-02 fix).

### GAPS-05 — Signature failures bypassed the per-surface contract
`WebhookSignatureRejected` was never thrown (dead class); legacy endpoint answered 401 to `PaymentSecurityTest` which documents 400. **Fix:** two refusal kinds with one message — non-digest-shaped credential (missing/empty/garbage) → `DomainException 401` (authentication failure on BOTH surfaces — what `PaymentEndpointThrottleTest` pins); well-formed hex digest that matches no scheme → `WebhookSignatureRejected` (legacy 400, API 401 — what `PaymentSecurityTest` + `ApiWebhookTest` pin). No oracle: identical messages and status sets per surface.

### GAPS-06 — Replayed callbacks inflated the settlement audit trail
An already-settled payment got a SECOND `payment.callback` event row per replay. **Fix:** replays fold into the original event (`metadata.duplicate`, `replay_count`) — trail stays 1:1 with real transitions; replay volume remains observable.

### GAPS-07 — Enforcement actions left no audit trail
`RestrictionService::restrict/lift` and `IdentityVerificationService::verifyManually/reject` never called `AuditLogService` although the vocabulary entries existed (`AuditIntegrationTest` + `DialectParityRegressionTest` red). **Fix:** `restriction.applied/lifted` (entity `restriction`, target user, bounded reason/type/source metadata) and `identity.verified/rejected` recorded inside the existing transactions.

### GAPS-08 — `settlement.reconciled` was not in the audit vocabulary
The reconcile command/job called `recordQuietly()` with it; the closed vocabulary rejected it and the catch swallowed the error — the whole reconciliation trail silently vanished (4 tests red). **Fix:** added to `AuditLogService::ACTIONS`.

### GAPS-09 — Admin settlement page 500
`SettlementController::show` called `PrizeDistributionService::tiersEditable()` which did not exist (only the protected `assertTiersEditable()` did). **Fix:** public `tiersEditable()` as the query-form of the same rule (single source of truth).

### GAPS-10 — Avatar upload: arbitrary file type + scriptable serving
`'avatar' => 'nullable'` accepted anything (HTML/SVG-with-script/50 MB) into the local disk; `response()->file()` served it with extension-guessed types (stored XSS on origin + disk exhaustion); the generated initials SVG interpolated the raw display name. **Fix:** uploads validated as `image|mimes:jpg,jpeg,png,webp|max:4096` + dimension bounds, stored under a random UUID name with a whitelisted extension, old upload cleaned up; external URL avatars restricted to `http(s)`; serving whitelisted to the four image types with explicit Content-Type, `nosniff`, per-response `default-src 'none'`, unknown extensions refused; initials escaped.

### GAPS-11 — Idempotency-Key TOCTOU
The store wrote only AFTER the response, so concurrent same-key requests both executed (`409 already in progress` branch was dead code). **Fix:** reserve-before-execute via the existing `(user_id, key)` unique constraint, in-flight marker rows, release on failure/exception, stale-reservation takeover (900 s) so crashes cannot wedge a key for the whole TTL; fingerprint is now recursively sorted (`Arr::sortRecursive`) to stop false "different body" 409s.

### GAPS-12 — Score screenshots accepted SVG
`nullable|image` includes SVG — scriptable content landing on the public disk. **Fix:** `mimes:jpg,jpeg,png,webp`.

## MEDIUM

### GAPS-13 — Backup manifests could not state "not encrypted"
`manifest.encrypted` existed only on encrypted backups; readers guessed. **Fix:** always written (`bool`), plus `verify()` now PROVES offsite claims (`offsite_present` + `offsite_checksum` against the recorded report).

### GAPS-14 — Offsite mirror was fire-and-forget; required failures destroyed the local copy
`mirrorOffsite()` returned void; `BACKUP_OFFSITE_REQUIRED=true` threw into `create()`'s catch which `deleteDirectory()`-ed the only remaining good copy; "required with no disk" silently passed. **Fix:** mirror returns a proven report (disk/path/sha256/error), recorded in BOTH local and offsite `manifest.json` (byte-identical), required failures → `ok:false` verdict with the local backup RETAINED, misconfig (required, no disk) reported with `BACKUP_OFFSITE_DISK...`.

### GAPS-15 — Video-ad reward race
Daily limit + cooldown were check-then-act with no guard — N parallel requests each collected gold+gems. **Fix:** per-user `Cache::lock` around gate+transaction (the Gameberry schema has no unique guard for "one reward per window").

### GAPS-16 — Seeder planted a known-password admin in production
**Fix:** refuses production runs without interactive confirmation; `SEED_ADMIN_PASSWORD` documented.

### GAPS-17 — League trophy updates lost to races
`addTrophies` read-modify-write without serialization. **Fix:** per-user `Cache::lock` in the service (covers both settlement and the staff path of GAPS-03).

### GAPS-18 — Compose shipped default credentials
`${POSTGRES_PASSWORD:-ffarena}`, `${REDIS_PASSWORD:-ffarena-redis-secret}` and bare `${APP_KEY}`/`${JWT_SECRET}`/`${WEBHOOK_SECRET}`/`${SERVICE_HMAC_SECRET}` in `docker-compose.yml` + `services/docker-compose.yml`. **Fix:** all secrets are now `VAR:?required` — a stack missing a credential fails at `compose up`, not at runtime.

### GAPS-19 — `.env.example` vs its own architecture tests
`DockerAndHealthTest` requires `CHANGE_ME` placeholders + the `ffarena:` Redis namespace marker; the file had neither. **Fix:** placeholder policy + `CACHE_PREFIX`/`REDIS_PREFIX=ffarena:` documentation; weakened `SECURITY_CSP_POLICY="default-src 'self'"` override REMOVED so the complete shipped policy applies (inline JSON-LD/Vite-safe: `script-src/style-src 'self' 'unsafe-inline'`, `object-src 'none'`, `frame-ancestors 'self'`, no wildcards, report-only default) — this also clears `SecurityHeadersCspTest`.

### GAPS-20 — Raw exception messages returned by diagnostics
`Integration\HealthCheckService` echoed `$e->getMessage()` (DSN/path/host detail) into admin health payloads; `GoPaymentGatewayAdapter` returned gateway exception messages to API consumers. **Fix:** details are logged, clients get generic safe strings; the Redis probe now treats ANY `CHANGE_ME_*` password as unconfigured (prefix rule) instead of one magic string.

## LOW / nits

### GAPS-21 — Guest bearer envelope
`EnsureBearerToken` answered the no-header case with an ad-hoc JSON shape; the v1 contract (asserted by `ApiDeviceTokensTest`) is the standard `ApiResponse` envelope with `error.code = unauthenticated`. **Fix:** that branch now uses `ApiResponse::error('unauthenticated', …, 401)`; malformed-credential branches keep their pinned flat contract (`P2BacklogRegressionTest`).

### GAPS-22 — `PAYMENT_CALLBACK_SECRET` documented but never wired
`PaymentCallbackState` reads `services.payments.callback_secret` — the key did not exist in `config/services.php`, so the documented override silently did nothing. **Fix:** wired.

## Test-file repairs (red for non-app reasons)
- `P2BacklogRegressionTest` — fatal: `makeTournament()` signature mismatch (added optional `$overrides`).
- `PaymentWalletLedgerTest` — corrupt trailing fragment removed; the two refund HTTP tests now carry the step-up session (`auth.password_confirmed_at`); `settledPayment()` helper captured the model returned by `verifyManually()` (pre-fix it returned a stale pending instance → 'Only settled payments can be refunded').
- `ApiWebhookTest` — hardcoded dev secret replaced with a config pin (self-contained in CI).
- `ConfigValidationTest` — safe-config fixture extended for the new production gate + a companion test asserting a missing/placeholder `PAYMENT_WEBHOOK_SECRET` IS reported.

## Intentionally NOT changed
`config/services_go_rust.php` `CHANGE_ME_*` placeholders (architecture test requires them; middleware refuses them in production — fail-closed by design); `confirmProviderPayment` duplicate-event semantics (no test demands folding); Gameberry controllers' `getMessage()` catches (established, test-pinned contract — noted as residual hardening); Redis-availability-dependent skips (no Redis/docker/age in this environment).

## Verification
- Targeted: PaymentSecurityTest 5F→0 · ProviderBusinessSecretTest 3E+3F→0 · ApiWebhookTest+WebhookRace+smoke 0 · ReconcileCommandsTest 4→0 · AuditIntegration+DialectParity 3→0 · BackupOffsite(+Drill) 6→0 · PaymentSettlementIdempotency 1→0 · SmokeMatrix(+Api) 1→0 · SecurityHeadersCsp 1→0 · ApiDeviceTokens 1→0 · dup-refund E→0 · ArchitectureIntegrityR9 1→0 · DockerAndHealth 2→0 · PaymentEndpointThrottle 0→0 (contract preserved) · ConfigValidation 1F→0 (+1 new test).
- Full: `phpunit --no-coverage` → **1573 tests, 0 errors, 0 failures, 54 skipped**; `pint` run over every touched file (repo-level style debt outside touched files deliberately untouched).
