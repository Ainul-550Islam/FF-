# Runtime workpackets (R1 / R3 / R9)

Build-and-verify packets for the register rows that need a runtime the
audit sandbox lacks (PHP, Go toolchain, live CI). Each packet states the
verified static baseline, the exact runtime steps, and the close rule. R4
(mobile) and R5 (coverage) need no packet — their procedures are complete
in `docs/TEST_EVIDENCE.md` (first green + pin / publish baseline).

## Packet R1 — maker-checker graduation (code shipped, needs green runs)

Static baseline (verified 2026-10-07, runbook §6 is the record):

| Piece | Location | Verdict |
|---|---|---|
| Refusal check | `app/Services/PayoutService.php:408` `assertDualControl()` | threshold + maker compare + violation audit + 403 |
| Process path | `processInternal():107` (covers `process()` + `processWithOverride()`) | pre-txn pre-check, cannot race (PENDING-only approve) |
| Complete path | `completeManually():191` | pre-txn pre-check; reference required + ≤255; `FOR UPDATE` |
| Maker resolution | `queueMaker():378` | latest `payout.approved` actor; `null` (exempt) for event-less legacy + `distribution_batch` |
| Tests | `tests/Feature/Payments/PayoutDualControlTest.php` (11 tests) | 403 + status-unchanged assertions; fail-without-it holds |
| Gate backing | manifest `GAP10-A4-001…005` | run by the `required-tests` job |

Runtime steps:

1. SQLite: `vendor/bin/phpunit --filter PayoutDualControlTest` and
   `--filter AdminStepUpTest` green.
2. PostgreSQL profile: same two filters with `-c phpunit.pgsql.xml`
   green (against the §2.3 local database).
3. Fail-without-it drill (proves the tests are live, not vacuous):
   comment the two `assertDualControl` call sites, re-run step 1,
   confirm red, restore, confirm green again.
4. Record the evidence (CI job URLs + the drill result) in the release
   ticket and strike the graduation note in runbook §6.

Known leniency (documented, not a defect): payouts with no approval
event at all bypass the check. Backfilling legacy approval events would
close it; that is optional hardening, not graduation criteria.

## Packet R3 — PostgreSQL first green (static items implemented 2026-10-07, needs runs)

Static triage (2026-10-07 — re-run these before blaming the suite):

```bash
# 1. Migration dialect scan (67 files): expect NO hits.
grep -rn -- '->after(\|renameColumn\|->enum(' database/migrations/ || echo CLEAN
# 2. Raw-SQL / datetime-function scan: expect only the jsonb migration.
grep -rln -e 'DB::statement' -e 'DB::unprepared' -e 'strftime' -e 'DATE_FORMAT' database/migrations/ app/
# 3. App dialect-function scan: expect NO hits.
grep -rn -F -e 'RAND(' -e 'RANDOM()' app/ database/seeders/ database/factories/
# 4. PG-strictness candidates (GROUP BY / aggregates): two known-clean spots.
grep -rn -e 'groupByRaw' -e 'havingRaw' -e 'orderByRaw' -e 'selectRaw' app/
```

Verdicts on record: no `after`/`renameColumn`/`enum` migrations;
multi-`dropColumn` is PG-native; `2026_09_12_000000_convert_json_to_jsonb_on_postgres.php`
is explicitly PG-aware; `WalletService.php:128-129` (pure aggregates, no
`GROUP BY`) and `AnalyticsService.php:189-190` (grouped column + aggregate
only) are PG-valid; `config/database.php` pgsql block is standard.
Search-operator triage: the web tournament search uses `lower()+like`
(PG-safe, and the parity suite exercises exactly this path). The API
V1 plain-`LIKE` divergence is FIXED (code round 2026-10-07):
`Api/V1/TournamentController.php` now mirrors the web block
(`lower(name) like ?`, name-only, wildcards escaped, needle
byte-verified against the web controller).
Route names (CORRECTED 2026-10-07 — the earlier "verified collision
set" was group-blind grep and is VOID where noted): the api group
DOES carry a name prefix (`routes/api.php:37` `name('api.v1.')`,
closes :207), so the four claimed api/web twins are VOID —
`tournament.index/.show/.live/.bracket` are `api.v1.*` on the api
side and bare/`admin.`-prefixed on the web side (no web name starts
with `api.` — verified). The claimed web-internal
`.create/.store/.edit/.update` re-registration is likewise
prefix-separated (`admin.*` :529-532 vs bare :693-697). A rigorous
prefix-stack parse of all 8 route files (504 named routes) then found
the REAL duplicates — three, all FIXED the same day with caller
audits: (1) `teams.register` — first fixed by renaming the live GET
to `teams.register.show`, but a runtime re-proof the same day showed
that rename broke the live route (POST slug `teams.register` was
file-present but absent at runtime, and the renamed GET left
`teams.register` unresolvable): CORRECTED — the live GET
`tournaments/{tournament}/register` keeps `teams.register`, the
live POST keeps `teams.store`, and the dead slug twins are deleted
(proven via full `route:list` + `getByName()` tinker probes);
(2) `admin.ops.dashboard` (:569 + :728,
middleware/prefix/URI/handler-identical) → :569 copy deleted;
(3) `health.live` / `health.ready` (pre-Phase-16 web.php closures
shadowed the canonical `routes/health.php` controller probes for
requests — web.php loads first — while the names resolved to
health.php; `HealthTest` requires the controller shapes) → closures
deleted. A fourth dead pair fell in the same runtime pass:
`admin.payouts` / `admin.settlements` registrations (proven
unresolvable at runtime while the `.index` winners serve the same
URIs; zero route-name callers) → deleted with an audit comment.
Sqlite runtime partial-proof (2026-10-07, static PHP 8.5.8): the
targeted 27-test battery went from route-failure to route-clean
after the correction (Pint clean on all touched PHP files); the 2
remaining failures reproduce identically on pristine `2d99255`
(`DialectParityRegressionTest` bounded-source AuditLog,
`tiersEditable()` wallet/settlement pages) — pre-existing, not
regressions, and not dialect signal for the PG runner.
Related non-collisions (verified, no action): `analytics.*`,
`support.*` short-dupes are all prefix-separated. Leftover
observation (no name collision, no test signal — held for a
runtime-guarded pass): web.php `/health` still URI-shadows
health.php's `health.index`, and `/up` coexists with the `health:
'/up'` default.
Boolean where-clauses and integer-bound booleans scan clean.

Runtime steps:

1. Local first green per runbook §2.3 (PG16 container, `migrate:fresh
   --seed`, full `phpunit.pgsql.xml`, then the strict lock suites).
2. CI evidence: three consecutive green `tests-postgres` runs (artifacts
   `junit-pgsql.xml` + `junit-locks.xml`).
3. Flip per `docs/TEST_EVIDENCE.md` (remove `continue-on-error`, log it).

On-red playbook: a dialect error is fixed in the query (both drivers
must stay green — never PG-only SQL without a guard); a lock flake is
re-run 3× before it is believed, then investigated (never deleted); a
seed failure is checked against PG sequences and hardcoded-ID factories
first.

## Packet R9 — companion-daemon auth wiring (IMPLEMENTED + sandbox-PROVEN 2026-10-07, needs green CI jobs to flip)

Pre-fix static baseline (verified 2026-10-07 — found by reading every
layer; the first reading under-scoped this twice before the full
picture held). All rows below describe the code BEFORE the
implementation round of the same day:

| Piece | Location | Behaviour |
|---|---|---|
| Go `ValidateStrength()` | `services/payment-gateway-go/internal/config/secrets.go:21` | rejects set-but-short (<32) JWT/HMAC; empty passes; webhook uncovered |
| Go `ValidateSecretStrength()` | `secrets.go:75` | 32-min + 5-word exact-match screen — DEAD (no callers) |
| Go server wiring | `cmd/server/main.go:40` | calls `ValidateStrength()` at boot — `change-me-*` fallbacks fail loudly ✓ |
| Go worker/migrate | `cmd/worker/main.go:4`, `cmd/migrate/main.go:4` | call `config.Load()` but NOT `ValidateStrength()` ✗ |
| Go middleware chain | `cmd/server/main.go:216-228` | recovery/logging/rate-limit only — NO auth middleware mounted |
| Go `BearerAuth` | `internal/middleware/bearer_auth.go:15` | `len(token) < 10` → 401, else pass-through; never verifies |
| Go `HMACVerify` | `internal/middleware/hmac_verify.go` | correct webhook-path HMAC check — runs ONLY in `tests/webhook_test.go:3` |
| Go `VerifyJWT` | `internal/security/jwt.go:9` | real HS256 verification — ZERO callers |
| Go money routes | `cmd/server/main.go:170-203` | payments/wallets/payouts/webhooks — served to the container network freely |
| Go provider webhooks | `internal/webhooks/service.go:142` | DOES call `provider.VerifyWebhook` ✓ (per-provider secrets) |
| Go provider Secret rule | `internal/providers/base.go:71` | requires `BaseURL` when enabled — `Secret` NOT required ✗ (bkash:409, manual:64, rocket:133 verify HMAC with it; nagad:513 uses public-key instead) |
| Rust boot secrets | `src/config/mod.rs:77` + `src/main.rs:61` | fail-closed: empty rejected in production; 32-min + 16-word + sequential + entropy ✓ (the one enforced piece) |
| Rust `with_auth` family | `src/middleware/auth.rs:33,158,172` | correct JWT verification (`verify_jwt` + `user_id > 0`) — NEVER applied to routes |
| Rust evaluate routes | `src/handlers/evaluate.rs:32` | fraud evaluate/overall — no auth filter in the chain |
| Rust route composition | `src/handlers/mod.rs`, `src/main.rs:87` | logging only |
| Network posture | root `docker-compose.yml:154,192` | FIX-18 expose-only, no published ports (mitigation, not auth) |
| Daemon env default | `docker-compose.yml:144` | `APP_ENV: production` — strict-by-default once rules exist ✓ |
| Laravel direction | `app/Http/Middleware/EnsureServiceHmac.php` | Laravel verifies INBOUND daemon calls — the reverse direction is the gap |

Implemented 2026-10-07 — every spec step landed in code (this
patch). Proof RAN in-sandbox the same day (Go + Rust toolchains were
provisioned after this packet was first written): `go vet ./...`
clean and `go test ./...` green (3 packages, exit 0) on go1.27.1 AND
CI-exact go1.22.12, `cargo test` 19 passed / 0 failed on rustc 1.99.0
— the `implemented-needs-toolchain-run` markers were reworded to
`Proven 2026-10-07 (audit packet R9)` headers, but the `go`/`rust` CI
jobs still flip to required only after a green CI run (step 8).
Static self-review substituted for the compiler BEFORE the toolchain
arrived: paren/brace balance on every touched Go file,
import-use checks, cross-file signer/secret consistency, and a
deterministic-test audit — three real defects caught and fixed in
review (a stale `time.Now` captured outside the Go test helper; a
stray `)` in four generated mux wrappers; promoted-field composite
literals in the provider test). Deltas from the spec are marked
DELTA and justified; each was forced by evidence found while
implementing.

1. Go `ValidateStrength()`: rewritten production-aware
   (`internal/config/secrets.go`) — empty JWT/HMAC/webhook secrets
   refuse in production via `Config.IsProduction()` (which counts
   payment-env production too — kept deliberately: the right
   strictness for payment secrets), set-but-short (<32) refuses
   everywhere, plus a case-insensitive weak-substring screen
   (`secret`, `password`, `123456`, `test`, `default`, `changeme`,
   `change-me`, `placeholder`). DELTA: substring, not the dead 5-word
   exact-match — an exact word shorter than 32 chars can never fire
   after a 32-minimum length check, so the exact screen was
   untestable theater; the new screen fires on realistic
   placeholders. The spec's reviewer check is done: webhook handlers
   DID accept unsigned traffic with empty secrets, so empty-in-prod
   refuses AND the webhook path fails closed (step 3c).
2. Worker/migrate call `ValidateStrength()` (fatal in production,
   warn in dev) — `cmd/worker/main.go`, `cmd/migrate/main.go`.
   Server main additionally fatals on a missing
   `TOKEN_ENCRYPTION_KEY` in production — `cmd/server/main.go`.
3. Go request auth: (a) NEW `internal/middleware/service_auth.go` —
   `ServiceAuth(hmacSecret)` verifies the Laravel canonical envelope
   (`X-Service-*` headers over `METHOD:PATH:BODY:TIMESTAMP:NONCE`,
   hex HMAC, 300 s tolerance, dev-open empty secret, fail-closed
   otherwise), mounted on all money/operator routes (8 handler routes
   + 4 inline closures: payments, wallets, payouts, webhooks-ops,
   providers, reconciliation, dead-letter, bulkhead;
   health/metrics/webhook-inbound stay open by design).
   DELTA vs spec: the spec said mount `HMACVerify` (the webhook-path
   checker); the new `ServiceAuth` verifies the same Laravel signer
   (`ServiceAuthenticator`) but over the API envelope rather than a
   webhook payload — the correct primitive for API routes.
   (b) `BearerAuth` rewritten to call `security.VerifyJWT` (HS256,
   expiry/issuer) — verified-correct but intentionally UNMOUNTED
   (the daemon is service-to-service; HMAC is the mounted auth;
   Bearer stays available for a future user-JWT path).
   (c) Webhook inbound fails closed on missing signatures whenever a
   provider is known or a global secret is set
   (`internal/webhooks/service.go`; dev-open only when no
   verification is configured at all).
4. Rust request auth: strict `verify_service_signature` (canonical
   envelope, hex HMAC, 300 s tolerance, required-present service id)
   + raw-body `with_service_auth` filter applied to evaluate AND
   overall, with `handle_auth_rejection` recovery → JSON 401s
   (`src/middleware/auth.rs`, `src/handlers/evaluate.rs`);
   `src/security/hmac.rs` rewritten (hex generate/verify,
   constant-time compare, dead lines removed). DELTA vs spec: the
   spec said apply `with_auth(jwt_secret)`; the real Laravel caller
   signs HMAC (`RustFraudServiceAdapter` via `ServiceAuthenticator`)
   and mints no JWT for the daemon, so JWT-apply would 401 the
   legitimate caller — service-HMAC matches the actual signer
   (`with_auth` stays for a future JWT path). Laravel side: adapter
   paths corrected to `/api/v1/fraud/*`
   (`app/Services/RustFraudServiceAdapter.php` — dead-code
   correctness: the provider is currently unwired).
5. Provider secrets: `Secret` required for bkash/rocket when enabled
   and unconditionally for manual (always-on fallback — its flag is
   ignored by design); nagad's key-presence checks already existed
   (merchant/private/public/base — `nagad.go` `ValidateConfig`). The
   dead 5-word `ValidateSecretStrength` is left untouched —
   superseded by step 1's screen.
6. Tests: `internal/config/secrets_test.go` (strength table incl.
   payment-env-counts-as-production),
   `internal/providers/validate_secret_test.go` (Secret-required
   incl. manual-always-on), `tests/service_auth_test.go` (sign/verify
   round-trip through the REAL middleware:
   valid/tampered-stale/missing/dev-open), `tests/webhook_signature_
   test.go` (fail-closed matrix), `tests/webhook_replay_test.go`
   updated to the signed contract (replay/timestamp/malformed intents
   preserved — signatures added so auth no longer masks them). Rust:
   `#[cfg(test)]` signature suite in `auth.rs`
   (round-trip/tamper/stale/missing/dev-open on a fixed clock).
7. Compose: the six `:-change-me-*` fallbacks dropped to bare refs in
   the SAME patch as the enabling code (steps 1–4 make unset fail
   loud — the spec's ordering guard is satisfied structurally, not
   sequentially), matching the root file.
   `services/docker-compose.yml`. `.env.example` documents the new
   daemon secrets (`PAYMENT_BKASH/ROCKET/MANUAL_SECRET`,
   `TOKEN_ENCRYPTION_KEY`).
8. Flip (NOT done — needs green CI runs, sandbox proof is not the
   flip): first green `go` + `rust` jobs with the new tests → remove
   `continue-on-error` → close GAP-R9.

Explicit non-goals: mirroring Rust's sequential/entropy screens in Go
(optional hardening); touching `config.go Validate()` (port +
payment-env stay as is); production compose changes (the daemons do not
run there); changing the FIX-18 expose-only posture (it stays).
