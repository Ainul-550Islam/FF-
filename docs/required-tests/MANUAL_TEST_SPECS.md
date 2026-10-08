# Required manual test specs

GAP-R6 row, closed 2026-10-07. The automated half of the release gate is
`GAP10_BACKEND_TEST_IDS.txt` (36 entries, executed by
`tools/run_required_tests.py` in the `required-tests` CI job). This file is
the other half: human-executed verification for paths that need a person —
money movement authorization, real-provider behavior, key custody, store
submission, and deploy-day witnessing.

How to use: copy the specs triggered by a release into the release ticket,
execute in order, attach each spec's evidence artifact, and sign. A release
that skips a triggered spec without a written waiver from the release owner
is not a clean release.

`[OPERATOR DECISION]` marks choices the organization must make (roles,
sample rates, retention) — the spec defines the shape, not the policy.

## MT-01 — Maker-checker payout procedure (every payout at/above threshold)

Objective: execute the human half of the control `config/payments.php:89-110`
promises (GAP-R1 closed 2026-10-07 — `PayoutService::assertDualControl()`
now refuses a maker-actor with a 403 backstop): the administrator who
approved a payout must not be the one who disburses it.

Trigger: every payout whose amount is at/above
`PAYOUT_DUAL_CONTROL_THRESHOLD_MINOR` (minor units — poisha; default `0`
means every payout that went through the payout queue). Exempt: payouts
created already-approved as part of a batch prize-distribution approval —
the distribution approval is the separately audited maker action (see
`PayoutService::queueMaker()`).

Prerequisites: two distinct admin accounts with payout rights; both
operators able to pass step-up (recent password confirmation).

Steps:

1. MAKER: confirm the payout is in scope (amount at/above threshold, not a
   batch-distribution pre-approval). Pass step-up, then approve via
   `POST /payouts/{payout}/approve` (`payouts.approve`, `routes/web.php`).
   Expected: approval recorded with the maker's admin ID in the audit
   trail; the payout is not yet disbursed.
2. CHECKER (a different admin — verify the maker's ID in the audit trail
   is not your own): re-verify amount, payee, and provider against the
   source request. Pass step-up, then complete via
   `POST /payouts/{payout}/complete` (`payouts.complete`).
   Expected: completion recorded with the checker's admin ID; the two IDs
   differ.
3. CHECKER: record the reviewed external provider reference on the manual
   completion (max 255 characters; the full value is also kept in the
   payout event metadata). Expected: reference visible on the payout and
   in the audit trail; no truncation surprises.

Pass criteria: approve and complete carry distinct admin IDs; the reference
is present; the audit trail shows the full maker → checker chain. Spot-check quarterly on staging that a same-admin
completion is refused with a 403 (the code backstop must never go quiet).

Evidence: audit-log export for the payout (both entries).

Companion automation: `tests/Feature/Payments/PayoutDualControlTest.php`
(fixture-level), `tests/Feature/Payments/AdminStepUpTest.php` (the step-up
gate), T10 transition atomicity (audit-row-or-nothing — a refused attempt
leaves no partial trail).

## MT-02 — Provider sandbox E2E (per release touching payments)

Objective: prove every enabled provider still completes a real (sandbox)
money round-trip through this build. Procedure: `docs/PROVIDER_PILOT.md`
§§0–2 (pre-flight with no credentials, sandbox E2E per hosted provider —
bKash, Nagad, Card, SSLCommerz — then manual methods: Rocket, bank
transfer).

Trigger: any release touching `app/Services/*Gateway*`, payment routes,
webhook ingress, or provider config; plus the scheduled pre-release run.

Prerequisites: sandbox credentials per provider; seeded test data;
providers in sandbox mode (MT-03 governs production credentials — never
mix the two).
When a companion daemon is enabled, exercise the daemon path: the Rust
Until GAP-R9 closes, enable daemons ONLY on trusted container networks:
request auth is unmounted on both (Go serves money endpoints freely;
Rust's `with_auth` is unapplied), and Go boots silently on EMPTY
secrets — never rely on unset variables with the root compose file.
Confirm real secrets are injected before trusting daemon traffic.

Steps:

1. Pre-flight: `php artisan payments:probe` (add `--ping` for TLS
   routability). Expected: no structural misconfiguration; per-provider
   readiness reported.
2. Hosted providers: execute a sandbox payment + callback + status query
   per enabled provider. Expected: `payment.success`/`payment.pending`
   per the provider's real semantics — manual-method returns land on
   `payment.pending`, never a 500, never a fabricated refund.
3. Manual methods: walk a Rocket/bank return. Expected: pending state,
   operator-visible next step, no exception.

Pass criteria: all enabled providers green; any disabled provider stays
disabled for a stated reason.

Evidence: `payments:probe` output + per-provider sandbox receipts/IDs.

Companion automation: `PaymentGatewayContractTest` (registry honesty),
`PaymentEndpointThrottleTest` (ingress limits), the required-gate A5 rows.

## MT-03 — Live-pilot promotion (before a provider goes live)

Objective: bound the blast radius of real money. Procedure:
`docs/PROVIDER_PILOT.md` §§3–5 (bounded live pilot, rollback, promotion
criteria as launch-gate evidence).

Trigger: first production enablement of a provider, or re-enablement after
a provider incident.

Prerequisites: MT-02 green on the exact build being promoted; rollback
plan rehearsed (§4); pilot caps configured [OPERATOR DECISION: amounts,
volume, duration].

Steps:

1. Enable the provider for pilot traffic only. Expected: pilot payments
   settle; non-pilot traffic unaffected.
2. Reconcile every pilot payment (provider record vs ledger). Expected:
   zero unexplained mismatches — G1 reconciliation rules apply.
3. Meet the §5 promotion criteria, or roll back per §4. Expected: a
   written go/no-go with evidence attached.

Pass criteria: promotion criteria met with evidence, or a clean rollback
with a post-mortem ticket.

Evidence: pilot ledger reconciliation + signed go/no-go.

Companion automation: settlement idempotency tests
(`PaymentSettlementIdempotencyTest`), reconciliation commands
(`reconcile:pending-settlements`).

## MT-04 — Backup restore drill witness (scheduled + quarterly key drill)

Objective: prove backups restore — including the encrypted production
shape whose key is NOT on the host. Procedure: `GAP-06` DR runbook §3
(daily verification) plus the full drill command.

Trigger: every scheduled drill output review; full witnessed drill at
least quarterly; key drill (decrypt with the escrowed identity) at least
quarterly.

Prerequisites: backup storage access; for the key drill, the escrowed age
identity (never stored on the web host).

Steps:

1. Daily: review the newest backup end to end (checksum, size, integrity,
   offsite presence). A non-zero exit or `ok=false` is a page, not a
   ticket. Expected: all green.
2. Full drill: `php artisan ffarena:backup:drill`. Expected: restore to
   scratch proven, plaintext SHA verified, scratch always cleaned.
3. Key drill (encrypted backups): supply the escrowed identity and prove
   decryption; also prove the fail-closed half (no identity → clean
   refusal, `blob-only` mode where applicable). Expected: decrypts with
   the identity, refuses without it. Nothing here ever writes to the live
   database.

Pass criteria: drill green; key drill green within the quarter; offsite
copy verified with per-file checksums.

Evidence: drill command output (archived per retention policy);
`ffarena:backup:verify --all` output.

Companion automation: `BackupOffsiteAndDrillTest` (6 tests: mirror,
required/optional fail-closed, retention, full drill, blob-only),
`BackupEncryptedRestoreTest`.

## MT-05 — Mobile release verification (per app release)

Objective: ship a signed, versioned, server-compatible app. Procedures:
`docs/MOBILE-ANDROID-RELEASE-RUNBOOK.md` and
`docs/MOBILE-IOS-RELEASE-RUNBOOK.md` (fail-closed, end to end).

Trigger: every store submission (both platforms have independent
checklists — a combined release runs both).

Prerequisites: release signing credentials (release keystore / Mac with
provisioning); server release notes URL live.

Steps (each platform):

1. Version: Android `versionCode` monotonically increasing (Play rejects
   duplicates); iOS build number bumped for every upload. Expected:
   versions accepted by the stores, release notes reviewed with the
   change.
2. Compatibility: enabled against the server's minimum supported version
   (`MOBILE_MIN_APP_VERSION`) and deep-link scheme. Expected: no forced
   update on day one unless intended (`MOBILE_UPDATE_REQUIRED`).
3. CI mobile job green on the release commit (drift gate REQUIRED;
   analyze+test advisory until graduation). Expected: generated client
   matches the server contract.

Pass criteria: store acceptance + server-compatibility confirmed; release
notes published.

Evidence: store receipts, version record, CI mobile log.

Companion automation: `bash scripts/ci/check-flutter.sh` (drift gate +
analyze + tests), checkout widget tests, OpenAPI↔Dart parity gate.

## MT-06 — Sentry delivery check (post-activation + per release)

Objective: prove errors actually arrive in Sentry (a silent error
pipeline is worse than none). Procedure: runtime runbook §4 (activation:
`composer require sentry/sentry-laravel` plus driver + DSN; the reporter
stays honestly log-only until all three exist).

Trigger: once after activation; then once per release (cheap).

Prerequisites: Sentry project access; a non-production environment for
the test event (never fire test events at production Sentry).

Steps:

1. Confirm the reporter state: log-only without driver+SDK+DSN, Sentry
   path only with all three. Expected: no half-configured state.
2. Fire a test event in staging and observe it in the Sentry project
   with the expected release/environment tags. Expected: event visible
   within minutes, alert rules (if any) evaluated.

Pass criteria: test event observed; log-only fallback still proven by
unsetting the DSN in a scratch environment.

Evidence: Sentry event link (or screenshot) in the release ticket.

Companion automation: `ErrorReporterManager` selection tests; the
log-only default is covered by the suite.

## MT-07 — k6 pre-release smoke (per release)

Objective: catch performance and limiter regressions before users do.
Procedure: `docs/LOAD_TEST_RUNBOOK.md`; scenarios in `tests/load/`
(`k6-smoke.js`, `k6-baseline.js`, `k6-api-mixed.js`, `k6-registration.js`,
`k6-payment.js`, `k6-leaderboard.js`, `k6-spike.js`, `k6-stress.js`;
`bin/k6` is committed — `chmod +x` only if the exec bit was lost).

Trigger: every release; full scenario set on releases touching API,
auth/OTP, payments, or rate limiting.

Prerequisites: a dedicated test server for any run with raised READ-path
limits (`API_RATE_LIMIT_API`, `API_RATE_LIMIT_API_ANON`) — never
production-adjacent hosts; seeded test data with providers in sandbox
mode for `k6-payment.js`.

Safety rules (non-negotiable, from `config/api.php` + the load runbook):

1. READ-path limits may be raised only against the dedicated rig.
2. Auth/OTP/payment write limits stay hardcoded — never tuned for load.
3. `k6-payment.js` exercises money paths: seeded data, sandbox mode.

Steps:

1. `bin/k6 run tests/load/k6-smoke.js`. Expected: green within the
   runbook's thresholds.
2.Triggered scenarios per the touch surface above. Expected: no
   regression vs the last release's summary.

Pass criteria: smoke green; triggered scenarios within threshold;
write-limit integrity untouched (spot-check one 429 path).

Evidence: k6 summary output attached to the release.

Companion automation: none — this spec IS the gate (runtime runbook §5
checklist row 7).

## MT-08 — Production deploy witness (per production deploy)

Objective: a second pair of eyes on the only step that touches real
users and real money. Procedures: `docs/DEPLOYMENT.md`,
`docs/DEPLOYMENT_GATE.md` (manual checklist — no artisan command ships
it), `deploy/deploy.sh`.

Trigger: every `deploy.sh production` run.

Prerequisites: production env file validated locally first
(`python3 deploy/validate-env.py --env-file .env.production
--production`); rollback version known (`.last_successful_version` or
`ROLLBACK_VERSION`).

Steps:

1. Run the deploy; capture the full log. Expected: the fatal env gate
   passes before anything builds; image builds, pushes, and deploys;
   `/health/live` green (else automatic rollback runs — let it finish,
   then diagnose from the log, never by re-pushing blind).
2. Walk the `DEPLOYMENT_GATE.md` checklist (migrations, config cache,
   connectivity, boot, storage, assets). Console assists:
   `ffarena:health`, `ffarena:queue:health`, `payments:probe`.
   Expected: every item checked or waived in writing.
3. Record the version (`.last_successful_version`) and the gate output
   with the release. Expected: rollback target unambiguous.

Pass criteria: live + ready green, checklist walked, version recorded.

Evidence: deploy log (with gate output) + version record.

Companion automation: the deploy-time env gate itself (fatal), CI
`tests`/`coverage` green on the deployed commit, POST full-suite
evidence in the release ticket.

---

## Spec index

| ID | Title | Trigger | Evidence |
|---|---|---|---|
| MT-01 | Maker-checker payout procedure | every in-scope payout | audit-log export |
| MT-02 | Provider sandbox E2E | payment-touching releases + pre-release | probe output + sandbox receipts |
| MT-03 | Live-pilot promotion | provider go-live / re-enable | reconciliation + signed go/no-go |
| MT-04 | Backup restore drill witness | scheduled + quarterly key drill | drill + verify output |
| MT-05 | Mobile release verification | every store submission | store receipts + CI mobile log |
| MT-06 | Sentry delivery check | post-activation + per release | Sentry event link |
| MT-07 | k6 pre-release smoke | every release | k6 summary |
| MT-08 | Production deploy witness | every production deploy | deploy log + version record |
