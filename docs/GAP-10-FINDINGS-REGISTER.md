# GAP-10 findings register

What GAP-10 changed, what was found while doing it, and — just as importantly —
**what is still not true**. Every entry is written so a reviewer can re-run the
check themselves.

Read this together with:

* `docs/GAP-09-EXTERNAL-VERIFICATION-REGISTER.md` — the E01–E27 items that need
  a credential, a signature or a human, all still `PENDING`;
* `docs/required-tests/GAP10_BACKEND_TEST_IDS.txt` — the tests that must run for
  a release to count;
* `docs/GAP-06-DISASTER-RECOVERY-RUNBOOK.md` — the restore/PITR half of C.

---

## 1. Status summary

| Area | State | Evidence |
| --- | --- | --- |
| A1 numbered-simulation guard, family-wide | **Done** | `app/Http/Middleware/EnsureNumberedSimulationSafe.php` (family-wide, 403 with a stable message), `bootstrap/app.php` (route files only in local/testing), `config/features.php`; `tests/Feature/Gameberry/NumberedRoutesDisabledTest.php` (8 tests) + `NumberedSimulationProductionGuardTest.php` (4 tests / 49 assertions, manifest `GAP10-A1-001`): the reported core/final9 writes are refused in production with no gold or gem transaction written, proven against a second process's route table, and the same call still settles one gold bet in testing |
| A2 wallet row locks | **Done** | `app/Models/GoldWallet.php`, `app/Models/GemWallet.php` (`static::query()->whereKey(...)->lockForUpdate()`); `tests/Feature/Postgres/VirtualWalletLockTest.php` (5 tests — a second connection really blocks on `FOR UPDATE`, concurrent spends never overdraw) and `tests/Feature/Gameberry/VirtualWalletLockQueryTest.php` (the locking SELECT is issued); manifest `GAP10-A2-001`, `GAP10-A2-002` |
| A3 clone families hidden + markers stripped + CSPRNG | **Done (Option B)** | `tools/prune_numbered_simulations.py`; markers 0, `rand(0, 1)` 0 repo-wide |
| A4 payout defence in depth | **Done** | `PayoutService` (dual control, reviewed reference, PROCESSING event after the lock), `PayoutController`, `EnsureRecentPasswordConfirmation`, `config/payments.php`; 19 tests / 72 assertions |
| A5 webhook ingress hardening | **Done** | `WebhookSignatureService::verifyRequest()`, `WebhookIngressService`, `WebhookSignatureRejected`; **business signature (F-36):** per-provider secrets + optional signed timestamp with a tolerance window, `webhook`/`callback` limits, `ProviderBusinessSecretTest` (10 tests / 20 assertions, manifest `GAP10-A5-005`); webhook clusters green (A5 cluster: 40 tests / 118 assertions). **Rate limits (F-28):** `config/payments.php` (`rate_limits.webhook` 240 / `.callback` 120, env-overridable), `AppServiceProvider` limiters keyed by provider + IP, `throttle:payment-webhook` / `throttle:payment-callback` on both ingress routes, `deploy/validate-env.py` refuses `0` in production; `PaymentEndpointThrottleTest` (5 tests, 26 assertions, manifest `GAP10-A5-004`) |
| A6 CSP report-only + production gate | **Done** | `config/observability.php`, `SecurityHeaders`, `deploy/validate-env.py::check_csp`, `.env.example` |
| A7 notification flags pinned | **Done** | `phpunit.xml` pins `NOTIFICATIONS_EMAIL` / `FEATURE_NOTIFICATION_{EMAIL,PUSH,SMS}` / `FEATURE_MOBILE_PUSH` / `PUSH_{FCM,APNS}_ENABLED` with `force="true"` (the spec's `NotificationOutboxTest` does not exist anywhere; the behaviour lives in `NotificationServiceTest` / `NotificationIntegrationTest`, manifest `GAP10-A7-001`, `GAP10-A7-002`) |
| A8 ten spec items | **Done** | `AntiCheatService`, `AtomicSettlementService`, `LedgerIntegrityService`, `SettlementCompleted` + listener, `ReconcileSettlement`, `ReconcilePendingSettlements`, `ReconcileGameSessions`; 4 new test classes |
| B CI + required-test machinery | **Done** | `.github/workflows/ci.yml` (8 jobs, PostgreSQL profile via `phpunit.pgsql.xml`), `tools/run_required_tests.py` (bare invocation = strict gate), `docs/required-tests/`, `tests/Static/test_gap10_identity_floor.py` |
| Reproducibility of this work | **Done** | `tools/gap10_reapply.py` (F-12, F-27), rebuilt by `tools/gap10_record_state.py` and checked by `tools/audit_gap10_coverage.py`: **1,566 changed/new files, 1,565 recoverable, 0 unprotected** (`transform` 27, `prune (A3)` 1,375, `recorded` 163); `ok=195 applied=0 failed=0` on a healthy tree. Every headline claim below is additionally re-run by `tools/prove_gap10.py` — see §5 and `docs/GAP-10-EVIDENCE.md` (F-38). `tools/drill_gap10_recovery.py` reverts a *copy* to upstream and rebuilds it: **0 drift, 0 missing** (F-31…F-34) |
| PostgreSQL profile (`phpunit.pgsql.xml`) — production database | **Done — green end to end** | `1532 tests, 5559 assertions, 0 failures, 3 skipped` (EXIT 0; E-01 receipt, run `20261007T045217Z`). Fixed 3 dialect defects (F-14), 1 money-core exactly-once defect (F-15), the order-dependent row leak (F-16) and a Redis profile that could not start (F-17). Required-test gate on this profile: `36/36 passed (skips are failures)` (the 36th row is the F-39 regression test) |
| SQLite profile (`phpunit.xml`) | **Done — green end to end** | `1527 tests, 5470 assertions, 0 failures, 26 skipped` (EXIT 0; E-07 receipt, run `20261007T045217Z` — run in a shell that had `DB_CONNECTION=pgsql` exported, which is the point of F-40). The 26 skips are the PostgreSQL-only family, the pg_dump connect-failure scenario, the concurrency assertion, and the three F-39 slot-claim tests — every one of them runs in the PostgreSQL profile; `SettlementExactlyOnceTest` runs on both dialects (schema guard + no connection purge on the in-memory path) |
| Go gateway + Rust security jobs | **Done — both executed locally** | Go 1.24.4: `go vet`, `go test -race`, `go build` clean (26 tests). Rust 1.99: `cargo fmt --check`, `cargo clippy --all-targets -- -D warnings`, `cargo test` (9 tests), `cargo build --release` clean after the F-20 fixes |
| `static` job (Pint, audit, secrets, OpenAPI, env guard) | **Done — 3 defects fixed** | Pint `PASS … 142 files`; `composer audit` clean after F-21; secret scan clean; OpenAPI `84 documented paths` after F-22; `deploy/validate-env.py` correctly rejects the shipped template in production mode; static floor `Ran 59 tests … OK` (includes the `RecoveryFloor` tests from F-32/F-33, the F-39 slot-claim guard, the hostile-environment profile pin from F-40 and the environment-rebuild floor from F-41) |
| `ops-toolchain` job (pg_dump + age) | **Done — 1 defect fixed** | Fresh PostgreSQL 17 migrate + custom-format dump (485,747 bytes, 120 tables) + `pg_restore --list` + age round-trip; the fail-closed assertion step now passes after F-23 |
| `mobile` job (Flutter) | **Done — 1 defect fixed, gate added to CI** | Flutter 3.47.6: `flutter analyze --no-pub` → *No issues found!*; `flutter test --no-pub` → **86 tests, all passed**; generated-client gate now runs in CI after F-24; tag builds still fail closed without signing secrets (verified: exit 1, all five names listed) |
| Redis profile (`phpunit.redis.xml`) | **Done — green end to end** (first time it can start; F-17) | `105 tests, 275 assertions, 0 failures, 3 skipped` (EXIT 0; E-07 receipt, run `20261007T045217Z`); `php8.4-redis 6.2.0` installed here, so the R9 Redis family really executes instead of skipping |
| C backup / offsite / metrics | **Done — DR drill executed** | `BackupService` (age + offsite + fail-closed + **decrypt-on-restore, F-19**), `config/backup.php`, `config/filesystems.php` (**driver selectable, F-18**), `deploy/postgres-init.sh`, `deploy/prometheus-alerts.yml`; live rehearsal: `pg_dump` → `age` → offsite copy → `pg_restore` into a scratch database (120 tables, 67 migrations) Re-applied 2026-10-07 after the F-12 revert: age encryption + offsite mirror re-implemented in `BackupService`, `BackupDrillCommand` + weekly schedule added, `BackupEncryptedRestoreTest` pins the contract. |
| D mobile release fail-closed | **Done** | `mobile/android/app/build.gradle.kts` (release refuses to build unsigned; placeholder host rejected); `scripts/ci/check-flutter.sh`; release runbooks: `docs/MOBILE-ANDROID-RELEASE-RUNBOOK.md`, `docs/MOBILE-IOS-RELEASE-RUNBOOK.md`, kept in step with `docs/MOBILE_RELEASE.md`; signing secrets are never invented — a missing secret fails the job (verified: exit 1, all five names listed) |
| E external items E01–E27 | **PENDING — all 27** | `docs/GAP-09-EXTERNAL-VERIFICATION-REGISTER.md`; enforced by the static floor test |
| A6 deploy gate on the *live* host | **Not performed** | Requires production infrastructure (E01–E03) |
| F-25 hosting behind a reverse proxy | **Done** | `app/Support/TrustedProxyConfig.php`, `app/Providers/TrustedProxyServiceProvider.php` (registered in `bootstrap/providers.php`), `.env.example`; `tests/Feature/Hosting/ProductionHostingTest.php` (17 tests, 48 assertions) |
| F-26 required-test runner `--only` | **Done** | `tools/run_required_tests.py::verify_manifest(entries, a8_pool)`; the A8 floor is evaluated against the unfiltered manifest; covered by `RequiredTestRunnerFloor` |
| F-27 reset-resilience of this pass's output | **Done** | `tools/gap10_reapply.py` (`exact=True` recorded state) + `tools/prune_numbered_simulations.py`, maintained by `tools/gap10_record_state.py`; the numbers below are the ones that matter now — the earlier count in F-27 was under-reported (F-31) and has been superseded: **1,566 changed files, 1,565 recoverable, 0 unprotected, 0 drift** (F-31…F-34, F-38, F-42) |

---

## 2. Findings

Each finding states what was found, what was done, and how to re-check it.

### F-01 — The specification's tracker files are not in this repository (blocking a literal reading, not the work)

`first.md` refers to `docs/required-tests/TRACKER_143_REVIEW.csv` and
`docs/FF-ARENA-FINAL-MASTER-GAP-MATRIX.md`. Neither file exists in the
repository snapshot (nor in the upstream `main` tarball), and
`docs/required-tests/` did not exist at all.

**Done:** the manifest re-states the specification's row numbers verbatim
(`row 018`, `row 035` … `row 056`) next to the test that discharges each one, so
the link survives even though the CSV it points at is absent. The gap is
recorded here rather than hidden.

**Re-check:** `ls docs/required-tests/` → `GAP10_BACKEND_TEST_IDS.txt` is the
manifest; the CSV is still absent.

### F-02 — `$this->lockForUpdate()` was a no-op on the virtual wallets (A2)

On an Eloquent instance, `$this->lockForUpdate()` *builds a query*; it does not
lock the row the instance represents. Two concurrent gold/gem mutations could
therefore interleave and produce a lost update.

**Done:** both wallets now lock explicitly
(`static::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail()`),
behind a `lockRow()` helper so every mutation path goes through it. The static
floor test asserts the pattern is present **and** that the broken call has not
returned (comments are stripped first, because the docblocks explain the bug).

**Re-check:** `grep -n "lockForUpdate" app/Models/GoldWallet.php app/Models/GemWallet.php`.

### F-03 — 1,375 generated clone files carried prompt-scaffolding into production code (A3)

The `core` / `final*` / `stats` families contained the generator's own fields —
`production_ready`, `no_shortening`, `existing_logic_preserved`,
`full_file_content`, `zero_files_omitted`, `sequential_output` — as real payload
keys and Blade rows, plus 535 `rand(0, 1)` calls (predictable, seeded) driving
winnable outcomes.

**Done:** every marker removed repo-wide, every `rand(0, 1)` replaced with
`random_int(0, 1)`, and the family endpoints hidden behind the flag + environment
guard (Option B, the specification's default where the user did not choose
deletion). A tool replaces the ad-hoc scripts:
`tools/prune_numbered_simulations.py --check|--strip|--prune|--restore`.

**Re-check:**
```bash
python3 tools/prune_numbered_simulations.py --check     # 0 marker files, 0 rand() sites
```

### F-04 — The first strip tool destroyed its own source mid-run (process finding)

The original `tools/prune_numbered_simulations.sh` matched its own text while
rewriting files, corrupted ~1,375 files by merging lines, and had to be
abandoned. The recovery came from the upstream tarball, and the replacement is
Python with three defences: the stripper never scans its own source
(`SKIP_FILES`), whitespace classes are `[ \t]` so a removal can never consume a
newline, and every rewritten PHP file is re-linted with `php -l`.

**Why it is recorded:** it is the reason the tool has a `--check` default, a
prune tarball, and a `--restore` path. A mass edit without an oracle is not a
refactor, it is a gamble.

**Re-check:** `python3 tools/prune_numbered_simulations.py --strip` (dry run) →
`would rewrite 0 file(s)`.

### F-05 — Webhooks with correctly signed bodies were being rejected (A5)

The ingress required an `X-Timestamp` header unconditionally. Legacy Phase 08
provider callbacks do not send one, so every correctly signed delivery was
refused with 401 and no payment could settle. The timestamp was also not bound
to the signature.

**Done:** two schemes are verified, and which one matched is recorded in the
event metadata (`signature_scheme`): `timestamped`
(`HMAC-SHA256(secret, "{ts}.{raw_body}")`) and `legacy` (raw-body HMAC). When a
timestamp *is* present it must be inside the tolerance window on both schemes.
A signature failure answers 400 on the legacy surface and 401 on the Phase 15
API surface, each matching its published contract. Legacy bodies are routed as
`payment.callback` instead of being stored as an ignored `unknown` event.

**Re-check:** `php vendor/bin/phpunit tests/Feature/PaymentSecurityTest.php tests/Feature/Concurrency/WebhookRaceTest.php`.

### F-06 — Two pre-existing tests had to learn about step-up authentication (A4)

`AuditIntegrationTest` (payout approval audit) and `PaymentWalletLedgerTest`
(admin refund) drive routes that A4 now puts behind
`EnsureRecentPasswordConfirmation`. Their intent is the audit row and the refund
semantics, not the step-up, so they prime
`auth.password_confirmed_at` in the session. Step-up itself is covered
exhaustively by `AdminStepUpTest` (8 tests).

**This is a deliberate, reviewed change to existing tests** — recorded here
because "the pre-existing tests were edited" is exactly the kind of thing that
must never happen silently.

**Re-check:** `grep -n "auth.password_confirmed_at" tests/Feature/AuditIntegrationTest.php tests/Feature/PaymentWalletLedgerTest.php`.

### F-07 — One backup test is skipped on every profile, on purpose

`BackupTest` contains a SQLite-only scenario (in-memory refusal) and a
PostgreSQL-only scenario (`pg_dump` connect failure). Any single profile
therefore skips exactly one. Rather than weaken the gate to "skips are fine",
the manifest declares a bounded, justified budget (`max_skips=1`) **for that one
entry**, and the runner still fails when the observed skips exceed it. The
static floor test asserts budgets stay rare (≤ 2), ≤ 1 each, name the dialect,
and explain what does run them. `AtomicSettlementTest` carries the same budget
for its two-writer concurrency test.

**Re-check:** `python3 tools/run_required_tests.py --run --fail-on-skipped`.

### F-08 — `ActionValidationService` referenced by the spec does not exist here

`first.md` names `App\Services\Gameberry\ActionValidationService` as existing
machinery. This repository has no such class (it is not in the upstream tarball
either). Rather than invent a service the rest of the tree does not use, the A8
anti-cheat work delivered `App\Services\Gameberry\AntiCheatService` +
`AntiCheatDecision` with the thresholds, rules and escalation paths the spec
describes, and wired escalation through the Phase 10 incident path that *does*
exist. **The deviation is the class name and the shape of the seam, not the
behaviour.**

**Re-check:** `ls app/Services/Gameberry/`; `grep -rn "ActionValidationService" app/ routes/ config/` → no results.

### F-09 — Where the marker names legitimately still appear

The route file's header comment used to explain the generated families by
naming the leaked fields; it now describes the same history without reproducing
the tokens.

Four files still contain the literal marker strings, and each one **must**:
`README.md`, `FILE_AUDIT.md` and this register (they explain what was removed),
plus `tests/Static/test_gap10_identity_floor.py` (a static test cannot assert a
string is absent without containing it). `tools/prune_numbered_simulations.py`
contains them too and excludes itself from its own scan.

The audit therefore reports two numbers, because conflating them would be
dishonest in both directions:

* **application files leaking markers: 0** (`app`, `routes`, `config`,
  `resources`, `bootstrap`, `database`, `tests`)
* **documentation / guard sources naming them: 4** (expected, listed by name)

**Re-check:**
```bash
python3 tools/prune_numbered_simulations.py --check      # leaks: 0, exit 0
grep -rn "no_shortening\|production_ready" app routes config resources bootstrap database   # no results
```

### F-10 — `OpsController::health` returned a nested envelope the admin surface did not expect (A8-adjacent)

`/admin/ops/health` returned `{status, checks:{…}}`, while the admin dashboard
and `AdminOpsTest` read `database` / `cache` at the top level — a 500-shaped
fragility in the ops screen. The admin endpoint now flattens the checks
(`{status, database, cache, filesystem, queue, config}`); the public probe
(`/health/ready`) keeps the nested envelope its own tests assert.

**Re-check:** `php vendor/bin/phpunit tests/Feature/Phase16/AdminOpsTest.php tests/Feature/Phase16/HealthTest.php`.

### F-11 — A clone-route guard missed an `api/`-prefixed path

The first version of the A1 guard matched `gameberry/…` and `v1/gameberry/…`
only. A path presented as `api/v1/gameberry/core/…` (a proxy or rewrite rule
can do that) slipped past the path check. The guard now tolerates an optional
`api/` prefix, and a test pins it.

**Re-check:** `php vendor/bin/phpunit tests/Feature/Gameberry/NumberedRoutesDisabledTest.php`.

### F-12 — The workspace is restored between sessions, and that silently reverted surgical edits

The development workspace is snapshotted per session. Files *created* by a
session survive; **edits to pre-existing files can be rolled back**, and the
rollback is silent: the tree still lints, and no test screams until a behaviour
test fails.

What this cost, and what it produced:

* A first occurrence reverted the A4/A5 service edits, the backup offsite +
  encryption code, the `RestrictionService` / `IdentityVerificationService`
  audit hooks and the two A8 audit verbs. The full suite reported 7 errors +
  24 failures — that signal was the only clue.
* A second occurrence reverted the CI workflow and one of the two step-up
  primings in `PaymentWalletLedgerTest` (the second test then failed on a
  missing validation-error key — the tool had replaced the anchor only once,
  because the same call site appears twice in that file).

**Done:** `tools/gap10_reapply.py` expresses every surgical GAP-10 edit as an
assertion-guarded, idempotent transform with a declared marker:

```bash
python3 tools/gap10_reapply.py --check   # report what is missing, change nothing
python3 tools/gap10_reapply.py           # restore the GAP-10 state
```

Design rules that came out of the failure modes above:

* an anchor that no longer matches is a **loud FAILED**, never a silent skip —
  the file changed underneath the tool and the transform must be updated;
* `repeat=-1` (replace-all) transforms run to a fixpoint, so a half-applied
  file is completed rather than looking done because the marker is present;
* `must_be_absent` catches exactly the half-applied case above: the marker can
  be present while an identical call site elsewhere in the file was never
  touched.

**Re-check:** `python3 tools/gap10_reapply.py --check` → `ok=12 applied=0 failed=0`.

### F-13 — `php artisan test` prints a `.env` warning per test that is NOT a failure

With no `.env` present, `php artisan test` renders one warning per test:

```
  ! test name → file_get_contents(/path/to/.env): Failed to open stream: No such file or directory
```

It is cosmetic, and the evidence is exact:

* the read is `@`-suppressed in `vlucas/phpdotenv`
  (`Option::fromValue(@\file_get_contents($path), false)`), and Laravel calls it
  through `safeLoad()`, which catches the missing-file condition;
* `php vendor/bin/phpunit` — including with `--display-warnings` — reports
  **zero** warnings, because PHPUnit honours the suppression;
* the arithmetic is revealing: exactly one warning per test, because each test
  boots the application and re-runs the environment bootstrap;
* `php artisan test --testsuite Unit,Feature` still **exits 0**.

Collision's printer (which `artisan test` uses) reports suppressed reads as test
warnings; PHPUnit's own printer does not. The canonical, warning-free runner —
and the one every gate and CI job uses — is:

```bash
php vendor/bin/phpunit
php vendor/bin/phpunit -c phpunit.pgsql.xml
```

**Why this is recorded:** "1473 warnings" in a test log is exactly the kind of
output that gets a release blocked on a false alarm, or (worse) gets normalised
so a real warning is ignored later.

**Re-check:**
```bash
php vendor/bin/phpunit --display-warnings | grep -c "file_get_contents"   # 0
php artisan test --testsuite Unit,Feature; echo $?                        # 0
```

### F-14 — Three application bugs were invisible on SQLite and wrong (or fatal) on PostgreSQL

The PostgreSQL profile (`phpunit.pgsql.xml`) had never been run end to end before
this pass. Running it exposed failures that no SQLite run can show, because
SQLite is permissive in three specific ways that PostgreSQL is not:

| SQLite behaviour | Consequence for this codebase |
| --- | --- |
| An unknown **double-quoted** identifier is reinterpreted as a *string literal* (legacy "double-quoted string" misfeature) | `where('created_at', ...)` against a table without that column did not fail — it silently became `'created_at' >= '<timestamp>'`, which is always true. The filter *never applied*. |
| `LIKE` is case-insensitive for ASCII | `?q=bermuda` matched `Bermuda Blitz` locally and matched nothing on production. |
| Column lengths are not enforced | A `User` model coerced into a `varchar(20)` `source` column stored the model's JSON representation happily, and blew up as `SQLSTATE[22001] value too long for type character varying(20)` in production. |

The three defects, all fixed and all now guarded by
`tests/Feature/Regression/DialectParityRegressionTest.php` (required test
`GAP10-PG-001`):

1. **Marketing analytics dashboard (`app/Http/Controllers/MarketingAnalyticsDashboardController.php`).**
   `marketing_attributions` has `first_seen_at` / `last_seen_at`, never
   `created_at`. Five attribution queries filtered by `created_at`, so on
   PostgreSQL the dashboard raised `SQLSTATE[42703]`, the failed statement
   aborted the surrounding transaction (`25P02`), and every later statement in
   that request failed too — the page was a 500 for the whole reporting window.
   The same bug also meant the period filter silently did nothing on SQLite.
   Fixed by filtering on `first_seen_at` (the column that records when the
   touch happened).
2. **Search was case-sensitive on PostgreSQL.** `TournamentController` (public
   tournament listing, the path the spec's row for discovery covers) and
   `AdminAccountController` (admin account search) used `where('x', 'like')`.
   Both now compare `lower(column)` against a lowercased, wildcard-escaped
   needle (`whereRaw('lower(name) like ?')`), which behaves identically on every
   dialect and keeps the existing `%`/`_` escaping intact.
3. **`SecurityController::restrict()` passed its arguments in the wrong order.**
   `restrict(User $user, string $type, string $reason, string $source = 'manual',
   ?User $actor = null, ?Carbon $expiresAt = null)` was called with
   `($user, $type, $reason, $userActor, $expiresAt)`: the acting admin landed in
   `$source` (a `varchar(20)`), and the expiry landed in `$actor`. Every admin
   "restrict user" action was a 500 on PostgreSQL, and the audit row lost its
   actor and recorded `source` as a blob of JSON. Fixed to
   `('admin_manual', $userActor, $expiresAt)`.

**Fail-before / pass-after evidence** (the required shape: red on the upstream
code, green on the fixed code):

```
# app/ code temporarily restored to upstream for the four files above:
php vendor/bin/phpunit -c phpunit.pgsql.xml \
    --filter 'DialectParityRegressionTest|SettlementExactlyOnceTest'
→ Failures: 6 (all three defects red, plus the two money-core tests of F-15)

# with the fixes in place:
→ OK (7 tests, 26 assertions)
```

**Re-check:**
```bash
php vendor/bin/phpunit -c phpunit.pgsql.xml --filter 'DialectParityRegressionTest'
python3 tools/run_required_tests.py --verify-manifest | grep GAP10-PG-001
```

**Why this is recorded:** it is the concrete answer to the specification's
"unverified areas" section. The suite was green on SQLite while the production
database would have served 500s on the marketing dashboard, on any search, and
on the moderation screen — the highest-value defects found in this pass, and
none of them were flagged by the SQLite profile.

---

### F-15 — The money core settled a payment more than once under true concurrent writers

`PaymentService::settleSuccess()` took the "is this payment already settled?"
decision from the **in-memory model the caller had loaded before the
transaction**, and the row was never re-read under a lock. Six concurrent
confirmations of the same payment — the scenario
`tests/Feature/Concurrency/PaymentRaceTest.php` sets up with real forked writers
— therefore all saw `pending`, all passed the idempotency guard and all settled
it:

```
php vendor/bin/phpunit -c phpunit.pgsql.xml --filter PaymentRaceTest
→ Failed asserting that 6 is identical to 1
  (tests/Feature/Concurrency/PaymentRaceTest.php:109 — one `payment.paid`
   event became six, plus six team confirmations and six notification fan-outs)
```

This is the same defect class as A2 (`$this->lockForUpdate()` being a no-op) and
it was invisible on SQLite by construction: the sequential fallback shares one
process, so there is no race to lose.

**Fix** (money core, fail-closed, nothing relaxed):

* `settleSuccess()` now takes the decision under an exclusive row lock — the
  idiom the specification itself mandates:
  `Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail()`
  — re-syncs the caller's instance with the locked row, and returns `bool`
  (`true` = this call settled it).
* `confirmProviderPayment()` records a losing confirmation as an **audited
  duplicate** (`payment.gateway_confirmed` with `metadata.duplicate = true`)
  instead of a settlement.
* `markGatewayFailed()` got the same guard: a stale writer that still believes
  the payment is `pending` can no longer fail a payment that has already been
  paid.

**Evidence:**

```
# upstream code:
php vendor/bin/phpunit -c phpunit.pgsql.xml --filter PaymentRaceTest
→ Tests: 1, Failures: 1 (6 paid events instead of 1)

# after the fix:
php vendor/bin/phpunit -c phpunit.pgsql.xml \
    --filter 'PaymentRaceTest|PaymentWalletLedgerTest|PaymentService'
→ OK (22 tests, 72 assertions)
```

The regression is pinned by `tests/Feature/Payments/SettlementExactlyOnceTest.php`
(required test `GAP10-PG-002`). Its first test is deterministic on every
dialect: it holds a stale `pending` instance across a real settlement and
asserts that `markGatewayFailed()` on it is a no-op. Its second test forks six
real writers when pcntl + PostgreSQL are available and otherwise replays the same
invariant sequentially, so it never needs a skip budget. Both were red on the
upstream code.

**Operational note discovered while verifying this:** two PostgreSQL-profile
runs must never share the test database at the same time — `migrate:fresh` in one
process holds DDL locks the other one needs, and the result is
`SQLSTATE[40P01] deadlock detected` plus a log full of unrelated failures. Run
them one at a time.

**Re-check:**
```bash
php vendor/bin/phpunit -c phpunit.pgsql.xml --filter 'SettlementExactlyOnceTest|PaymentRaceTest'
```

### F-16 — The PostgreSQL profile was order-dependent: fork-based tests leaked committed rows into later classes

Running the profile end to end left 16 failures, all in the marketing family
(`MarketingAttributionTest`, `MarketingConversionTest`, `MarketingTrackingTest`,
`MarketingUtmGovernanceTest`), all of the shape "expected `facebook`, got
`null`" — while every one of those classes passed when run on its own. The
failures were not defects in the application code; they were stale rows.

The five classes that drive **real forked writers**
(`tests/Feature/Concurrency/{PaymentRaceTest,RegistrationRaceTest,WalletRaceTest,WebhookRaceTest}.php`
and this pass's `tests/Feature/Payments/SettlementExactlyOnceTest.php`) cannot
wrap their test in a transaction — the children must be able to commit. They
therefore run `migrate:fresh` in `setUp()` and delete *their own* rows in
`tearDown()`, which leaves behind anything a child wrote to unrelated tables:
marketing attribution touches, `payment_success` events, notifications, webhook
records.

Every later class that reads a marketing table with `->first()` then picks up a
leftover row instead of the row it just created. A temporary probe class running
immediately before the first failure captured it exactly:

```
PROBE attributions=[{"id":1,"anonymous_id":"f92e9059-…","campaign_key":"(direct)","source":null},
                    … 5 rows, all user_id=null, all committed in the same second …]
PROBE events=[{"id":1,"name":"payment_success","anonymous_id":"f92e9059-…","user_id":2}]
PROBE users=0
```

`users=0` with non-empty marketing tables is the tell: the creating class wiped
its own users and left the rest.

**Why it hid so well:** `RefreshDatabase` runs `migrate:fresh` **once per
process**, at the first test that uses it. In a narrow run (`--filter`) that
first test is usually inside or right before the affected class, so the leftover
rows are wiped just in time and everything passes. In a full run the wipe
happened hundreds of tests earlier, and the leftovers were visible.

**Fix:** the five fork-based classes now also `migrate:fresh` in `tearDown()`,
so a class that commits rows hands the next class an empty schema. This is test
hygiene, not an application change — no production code was touched, and the
required tests of every class involved (including `PaymentRaceTest`, which now
proves the F-15 exactly-once fix) still run in full.

**Result:**

```
before: Tests: 1485, Assertions: 5264, Failures: 16, Skipped: 26
after:  Tests: 1485, Assertions: 5293, Skipped: 26        (EXIT 0)
```

(Those two lines were measured before F-17 was found; once the `redis` extension
was installed the same command reports `1485 tests, 5351 assertions, 0 failures,

*(These are the numbers as measured while F-16 was open. The suite has grown
since; §1 and §4 carry the current totals.)*
3 skipped` — see the status table in section 1.)

**Re-check:**
```bash
php vendor/bin/phpunit -c phpunit.pgsql.xml            # 0 failures
php vendor/bin/phpunit -c phpunit.pgsql.xml --filter Marketing   # 0 failures
```

### F-17 — The shipped Redis profile could not start at all

`phpunit.redis.xml` declared two suites, the first of them pointing at
`tests/Feature/G4`:

```xml
<testsuite name="G4">
    <directory>tests/Feature/G4</directory>
</testsuite>
```

That directory does not exist in this repository (the G4 deliverable is a Redis
deployment concern; the Redis *test* coverage lives in `tests/Feature/R9`), and
PHPUnit aborts before running anything when a configured directory is missing:

```
$ php vendor/bin/phpunit -c phpunit.redis.xml
PHPUnit 11.5.56 by Sebastian Bergmann and contributors.
Test directory "/home/user/repo/tests/Feature/G4" not found
```

So the profile — and any job that used it — died with no test executed at all.
Two things had to be true to find this: the profile had to be *invoked* (it never
was, because this environment had no `php redis` extension until this pass), and
`redis` had to be present so the suite was not simply skipped. The extension was
installed here (`php8.4-redis 6.2.0`), which turned the whole R9 Redis family
from "skipped — BLOCKED BY ENVIRONMENT" into 23 tests that really execute.

**Fix:** the phantom `G4` suite is removed (the reason is recorded in the file
itself), and the profile now runs:

```
php vendor/bin/phpunit -c phpunit.redis.xml
→ Tests: 105, Assertions: 275, Skipped: 3        (EXIT 0)
```

The three remaining skips are the two Docker probes (no Docker daemon in this
sandbox) and one dialect-specific backup case — see D-6.

**Re-check:**
```bash
php vendor/bin/phpunit -c phpunit.redis.xml ; echo $?     # 0
php -m | grep -x redis                                    # redis
```

### F-18 — The offsite backup target needed a Composer package that is not in this repository

`config/filesystems.php` shipped the offsite disk as S3-only, and
`composer.json` does not require `league/flysystem-aws-s3-v3`. The documented
setup was therefore "install a package first", and until then every backup with
an offsite target configured failed with:

```
Backup FAILED: Offsite copy to disk [backup-offsite] failed:
Class "League\Flysystem\AwsS3V3\PortableVisibilityConverter" not found
```

That failure is *correct* — the run must never report ok when the offsite copy
did not happen — but it meant the DR path had no dependency-free option, so a
deployment could not prove the offsite copy at all.

**Fix:** the disk's driver is now selectable. The default is `local` with
`BACKUP_OFFSITE_ROOT` (default `storage/app/offsite-backups`), which covers the
common real-world setup — an attached volume, an NFS/CIFS mount, an
rclone/FUSE-mounted bucket — with **zero** extra packages. Object storage stays
first-class via `BACKUP_OFFSITE_DRIVER=s3` plus the adapter, and the S3 keys are
still read only from the environment. The required offsite tests override the
disk, so they exercise both the reachable and the unreachable case unchanged.

### F-19 — An encrypted backup could not be restored: the DR path rejected its own artifact

With `BACKUP_ENCRYPTION_RECIPIENT` set — the production configuration — the dump
is stored as `database.sql.age`, and nothing in the restore path knew that. The
ciphertext went straight to `pg_restore` (or to the SQLite integrity check),
which reported:

```
Restore dry-run FAILED: pg_restore could not read the dump:
pg_restore: error: input file does not appear to be a valid archive
```

An operator following the runbook would have concluded that **the backup was
corrupt**, at the exact moment they most needed it to be good. The artifact was
fine; the tool was wrong.

**Fix** (`BackupService::decryptArtifact()`, `restoreDryRun()`,
`ffarena:backup:restore --identity=`):

* an encrypted backup is decrypted to a temporary file, checked against the
  `plaintext_sha256` and `plaintext_size` the manifest recorded at backup time,
  and only then handed to `pg_restore` / the integrity check;
* the decrypted copy is deleted in a `finally` block — it never survives the
  call, on success, failure or exception;
* without the identity the run fails closed with an actionable message naming
  `--identity` and `BACKUP_DECRYPTION_IDENTITY`, and never blames the archive;
* a wrong identity and a tampered ciphertext both fail closed (age
  authenticates its payload) and leave no partial plaintext behind.

**Verified end to end, for real, in this environment** (`pg_dump` 17.11,
`age` 1.2.1, PostgreSQL 17.11):

```
php artisan ffarena:backup                                    # pg_dump → age → offsite copy
Backup created: ffarena-20261006-140258   sha256 457fa8fe…  size 486,177 bytes
  offsite: {ok: true, disk: backup-offsite, path: backups/ffarena-20261006-140258/database.sql.age}

php artisan ffarena:backup:verify --name=ffarena-20261006-140258
  [✓] exists  [✓] non_empty  [✓] checksum  [✓] offsite_present  [✓] offsite_checksum

php artisan ffarena:backup:restore … (no identity)      → FAILED, exit 1, actionable message
php artisan ffarena:backup:restore … --identity=…       → archive validated, exit 0

# and the drill that matters — restore the OFFSITE copy into a scratch database:
age --decrypt -i key.txt /tmp/dr/offsite/backups/…/database.sql.age | pg_restore -d ffarena_restore_drill
  → pg_restore exit 0; 120 tables, 67 migrations, users/ledger/payout/wallet tables all present
```

Guard: `tests/Feature/Phase16/BackupEncryptedRestoreTest.php` (required test
`GAP10-C-005`). Three of its four tests are red on the pre-fix code.

**Re-check:**
```bash
php artisan ffarena:backup:restore <name>                    # exit 1 for an encrypted backup, with instructions
php artisan ffarena:backup:restore <name> --identity=<key>   # exit 0, "Archive validated"
php vendor/bin/phpunit --filter BackupEncryptedRestoreTest
```

### F-20 — The Go and Rust CI jobs had never been executed, and the Rust one would have failed

Section B of the specification asks for real CI. This pass ran the two service
jobs locally instead of trusting the workflow file:

* **Go payment gateway — passes.** `go vet ./...`, `go test -race -count=1 ./...`
  and `go build ./...` are all clean on Go 1.24.4 (26 tests across the `tests`
  package and subtests, race detector on).
* **Rust security service — would have failed.** On a current stable toolchain
  (1.99.0, from rustup like CI uses) the crate reported:

  ```
  cargo fmt --check        → diffs in ~20 files
  cargo clippy --all-targets -- -D warnings
                           → build failed: 176 warnings turned errors
  ```

  Of those, **three** were real style lints
  (`clippy::empty_line_after_doc_comments` in `crypto/aes.rs` and
  `security/hsts.rs`) and one was `clippy::type_complexity`
  (`observability/mod.rs`). The rest were `dead_code` notes: the crate is
  written as a library (crypto, audit trail, fraud store, worker runtime, header
  helpers) while the binary wires up the evaluation endpoint, middleware and
  health only.

**Fix, in three parts** — no lint is silenced crate-wide:

1. `cargo fmt` + `cargo clippy --fix` (33 mechanical suggestions: `or_default`,
   redundant `map_err`, `Range::contains`, …);
2. the four real lints fixed by hand, with `clippy::type_complexity` resolved by
   introducing `HealthCheck` / `HealthCheckRegistry` type aliases;
3. the staged surface exempted **per module** in `src/main.rs`
   (`#[allow(dead_code)]` on the twelve staged modules) with a comment that
   states exactly what is exempted and why. That keeps `-D warnings` meaningful
   for every other lint and makes the exemption greppable.

The remaining work — wiring the staged modules into the binary or deleting them
— is explicitly **not** done here: it is a product decision, and it is recorded
rather than hidden.

**Tooling:** the mechanical part is reproducible with a new script,
`tools/harden_rust_service.sh` (idempotent; `--verify` mode changes nothing), and
the four hand edits are transforms in `tools/gap10_reapply.py`, so a workspace
restore cannot silently un-fix them.

**Evidence:**

```
services/security-rust$ . "$HOME/.cargo/env"
cargo fmt --check                                   → clean
cargo clippy --all-targets -- -D warnings            → clean
cargo test                                           → 9 passed; 0 failed
cargo build --release                                → Finished (1m 38s)

services/payment-gateway-go$ go vet ./...            → clean
go test -race -count=1 ./...                         → ok (26 tests)
go build ./...                                       → clean
```

**Re-check:**
```bash
tools/harden_rust_service.sh --verify
(cd services/payment-gateway-go && go vet ./... && go test -race -count=1 ./...)
```

### F-21 — The lock file shipped two live security advisories against `league/commonmark`

Running the `static` job's `composer audit` step — never executed before this
pass — failed with:

```
Found 2 security vulnerability advisories affecting 1 package:
+-------------------+-------------------------------------------------+
| Package           | league/commonmark                               |
| Severity          | medium                                          |
| Advisory ID       | PKSA-m2dq-1fhr-29b1                             |
| Title             | DisallowedRawHtml bypassed when a disallowed tag |
|                   | name ends the raw-HTML literal                  |
| URL               | https://github.com/advisories/GHSA-97jj-33gv-5xf9|
| Affected versions | >=1.3.0,<=2.10.1                                |
| Reported at       | 2026-09-30T15:36:33+00:00                       |
+-------------------+-------------------------------------------------+
| Severity          | high                                            |
| Title             | Quadratic-time denial of service in the GitHub  |
|                   | Flavored Markdown Table extension block-start    |
|                   | scan                                            |
| URL               | https://github.com/advisories/GHSA-3q6v-r5mr-hxv8|
| Affected versions | >=2.0.0,<=2.10.1                                |
| Reported at       | 2026-09-30T15:36:13+00:00                       |
+-------------------+-------------------------------------------------+
```

Both were published six days before this pass, and the locked version was
`2.10.0` — i.e. the high-severity DoS was exploitable by anything that feeds
Markdown through the framework (Laravel renders it for mail and for the
Markdown helpers).

**Fix:** `league/commonmark` 2.10.0 → **2.10.3** (plus `symfony/polyfill-php80`
v1.37.0 → v1.43.0). `composer audit` is clean and exits 0. `composer.lock` is a
generated file that nothing else in the tree watches, so two guards now hold it:

* `tools/harden_dependencies.sh` — idempotent: upgrades only if the locked
  version is below 2.10.2, then runs `composer audit` (and `composer validate`
  unless `--verify`);
* `tests/Static` → `DependencyFootprintFloor` — parses `composer.lock` and
  fails if `league/commonmark` ever drops below 2.10.2, naming both advisories.

**Re-check:** `tools/harden_dependencies.sh --verify`

---

### F-22 — The OpenAPI gate was red: 11 routed endpoints were undocumented

`scripts/ci/check-openapi.sh` (a `static` job step) regenerates the spec and
then fails on any routed `/api/v1` endpoint that is missing from it:

```
OPENAPI VALIDATION FAILED:
  - routed but NOT documented: GET /api/v1/go/payments/health
  - routed but NOT documented: POST /api/v1/go/payments
  - routed but NOT documented: GET /api/v1/go/payments/{payment}
  - routed but NOT documented: GET /api/v1/go/payments/methods
  - routed but NOT documented: POST /api/v1/rust/security/evaluate
  - routed but NOT documented: POST /api/v1/rust/security/device
  - routed but NOT documented: POST /api/v1/rust/security/ip
  - routed but NOT documented: POST /api/v1/rust/security/identity
  - routed but NOT documented: POST /api/v1/rust/security/risk-score
  - routed but NOT documented: GET /api/v1/rust/security/providers
  - routed but NOT documented: GET /api/v1/rust/security/health
```

The eleven routes front the two optional companion services (Go payment
gateway, Rust security engine), which is why they were skipped when the spec was
written — but they are routed, they are reachable, and the gate is right to
insist.

**Fix:** all eleven are documented in `tools/gen_openapi.py`, under a new
`Companion Services` tag, and documented *honestly* rather than padded:

* their payloads are the service's own JSON, so the generator gives them a raw
  object schema instead of the business `{data, meta}` envelope
  (`responses_for()` returns only the codes that can occur — 200/201, 401, 429;
  no 422, because nothing is validated, and no 404, because no model is
  resolved);
* each operation's description states what happens when the service is
  disabled: the controller answers locally (`Go payment disabled, use
  /api/v1/payments`, `fallback: true`) instead of proxying;
* `/health` and `/providers` are documented as answering from the application,
  never from the service, which is why they cannot fail when it is down;
* request bodies are documented as optional with the signals they carry,
  because the local fallback ignores them.

`OpenAPI validation OK: 84 documented paths` and the gate exits 0. `tests/Static`
→ `OpenApiGateFloor` fails if any companion path loses its entry, its tag or the
raw-response distinction.

**Re-check:** `bash scripts/ci/check-openapi.sh`

---

### F-23 — The backup-toolchain job asserted a message the code never had

The `ops-toolchain` job greps `app/Services/BackupService.php` to prove the
fail-closed guarantees survived. Executed for the first time, it failed:

```
BackupService no longer refuses to run without the age binary.
```

The check asserted `'age was not found on PATH'`; the service says
**`The age binary was not found on PATH but the backup is encrypted: refusing to
store an unencrypted database dump.`** — the guard had been written against a
message that never existed, so this job could not have passed at any point.

**Fix:** the step now asserts the real literals, and asserts four of them (the
missing-`age` refusal, the plaintext refusal, the unconfigured-offsite refusal,
and the encrypted-restore identity demand from F-19). `tests/Static` →
`OpsJobAssertionFloor` holds the workflow, the service and the list together,
so the next drift fails the fast static job instead of the slow toolchain job.

The rest of that job passes when executed for real: `php artisan migrate:fresh`
against PostgreSQL 17, `pg_dump --format=custom` (485,747 bytes, **120 tables**
in the archive), `pg_restore --list`, and the `age` encrypt/decrypt round-trip
with a throwaway key.

**Re-check:**
```bash
PGPASSWORD=ffarena DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_DATABASE=ffarena_ops \
  DB_USERNAME=ffarena DB_PASSWORD=ffarena php artisan migrate:fresh --force
pg_dump --host=127.0.0.1 --username=ffarena --format=custom --file=/tmp/ops.pgdump ffarena_ops
python3 /tmp/ops_verify.py   # the job's own step, verbatim
```

### F-24 — The generated Dart client could never match its own gate, and the gate was not in CI

The `mobile` job's steps are `flutter pub get`, `flutter analyze`,
`flutter test` — and nothing else. The repository *does* ship a drift gate,
`scripts/ci/check-flutter.sh` ("Regenerates the Dart models/endpoints from the
committed OpenAPI contract and fails if the generated files drift"), but no
workflow step called it, so it had never run. Executed for the first time:

```
==> Generated-code consistency (fails if the generator output drifts)
Wrote …/openapi_models.dart
Wrote …/openapi_endpoints.dart
::error::openapi_endpoints.dart is stale — run: python3 tools/gen_mobile_models.py
```

Three separate defects were behind that one line:

1. **The comparison was impossible to satisfy.** `tools/gen_mobile_models.py`
   emits compact Dart; the committed artifacts are formatter output. Comparing
   raw generator output against formatted committed files fails even when the
   tree is perfectly in sync.
2. **The gate was destructive.** It regenerated the committed files *in place*
   and then exited without restoring them, so a red gate left the working tree
   modified — and the next run compared against its own leftovers.
3. **The formatter's layout depends on where the file is.** `dart format`
   resolves its style from the language version of the surrounding package
   (`mobile/pubspec.yaml` declares `sdk: '>=3.4.0 <4.0.0'`), so formatting a
   copy outside `mobile/` produces a different file from formatting it in place.
   The committed artifacts are in the plan **3.4** layout — verified by
   formatting the raw generator output with `--language-version=3.4`, which
   reproduces the committed `openapi_models.dart` byte-for-byte.

**Fix:**

* the gate now regenerates, canonicalises **both** sides with
  `dart format --language-version=<the floor from pubspec.yaml>`, compares them
  in a temp directory and **restores the committed artifacts** if it fails;
* it is wired into CI: the `mobile` job got a
  `Generated client matches the OpenAPI contract` step running
  `bash ../scripts/ci/check-flutter.sh --generated-only`;
* the mobile job pins the SDK (`flutter-version: '3.47.6'`) — the formatter is
  what defines the canonical layout, so the SDK is part of the contract;
  [Correction 2026-10-07: no `flutter-version` key ever landed in `ci.yml`
  (verified via git history — the key never existed); the job floats on
  `channel: stable` with a pin-after-first-green comment (GAP-R4). The
  `3.47.6` above is the audit environment's SDK. Determinism comes from
  the gate's `--language-version` flag, not from a pin.]
* the committed client was regenerated, because F-22 added the eleven
  companion-service routes to the spec: `openapi_endpoints.dart` now carries
  them (`'Companion Services'`, 11 entries) while `openapi_models.dart` is
  byte-identical to upstream.

**Evidence:**

```
generated-code gate            → "generated client matches the OpenAPI contract", tree byte-identical after the run
flutter analyze --no-pub       → No issues found! (ran in 8.5s)
flutter test --no-pub          → 86 tests, All tests passed!
```

Flutter 3.47.6 / Dart 3.13.5. The SDK lives outside the workspace
(`tools/install_flutter_sdk.sh`, default `/opt/flutter`) and the pub cache must
be disk-backed — a tmpfs `/tmp` produced `OS Error: No space left on device`
while resolving packages. [Correction 2026-10-07: the installer script was
never committed (no git history); the audit SDK was provisioned out-of-band.
Install path: `docs/MOBILE_APP_SETUP.md`.]

**Re-check:**
```bash
export PATH=/opt/flutter/bin:$PATH   # or wherever your SDK lives (see docs/MOBILE_APP_SETUP.md)
(cd mobile && bash ../scripts/ci/check-flutter.sh)
```

### F-25 — Nothing configured the deployment's reverse proxy, and the file the specification names never existed

The specification lists `tests/Feature/Hosting/ProductionHostingTest.php` as an
existing test to update. It is not in the repository — and not in the upstream
history either (F-01/F-08 class of defect): the name is aspirational. The
capability it implies, however, was genuinely missing. `TrustProxies` was
Laravel's default, so behind the real deployment topology (a TLS-terminating
proxy in front of PHP-FPM) **every** request looked like plain HTTP from the
proxy's IP:

* `$request->ip()` returned the proxy address, so per-IP rate limits and the
  audit trail were meaningless and shared by all users;
* `$request->getScheme()` returned `http`, so `URL::forceScheme`-dependent
  redirects and absolute URLs were wrong and secure cookies/HSTS decisions ran
  on false input;
* `X-Forwarded-*` was ignored *entirely*, so no header spoofing was possible —
  the failure mode was the opposite of what one expects: the deployment was
  simply blind to its own edge.

**Fix** — the proxies are now described by the environment, and the default
stays fail-closed:

* `app/Support/TrustedProxyConfig.php` parses `TRUSTED_PROXIES`
  (comma-separated CIDRs/IPs, `*` allowed only when written explicitly and
  reported as such) and `TRUSTED_PROXY_HEADERS` (a `|`-separated list of the
  header names the deployment actually sends; unparseable input yields the safe
  default set instead of a wide-open one);
* `app/Providers/TrustedProxyServiceProvider.php` applies them through
  `TrustProxies::at()` / `withHeaders()` in `register()`. It cannot live in
  `bootstrap/app.php`'s `withMiddleware()`: that callback runs while the HTTP
  kernel is resolved, *before* `LoadConfiguration`, so `config()` there fails
  the boot with `ReflectionException: Class "config" does not exist`;
* with no `TRUSTED_PROXIES` value nothing is registered at all and Laravel keeps
  its default (trust nothing), so a client cannot forge a client IP or scheme;
* `.env.example` documents both variables.

**Evidence** (`tests/Feature/Hosting/ProductionHostingTest.php`, 17 tests /
48 assertions):

```
without configuration, a spoofed X-Forwarded-For / X-Forwarded-Proto / Host is ignored
with a configured proxy, the real client IP and the https scheme are honoured (HSTS present)
a peer outside the configured list is still not trusted; subnet boundaries compute exactly
the header mask maps every documented token, and an unparseable spec fails closed
the shipped .env.example is rejected by the production gate; a fully configured
production environment passes it
```

**Re-check:**
```bash
php vendor/bin/phpunit -c phpunit.pgsql.xml --filter ProductionHostingTest
# 17 tests, 48 assertions — OK
```

---

### F-26 — The required-test runner rejected its own `--only` filter

`tools/run_required_tests.py --only GAP10-F-001` failed with
`only 0 A8 entries are registered`, even though the manifest carried the entry
and the ten A8 rows. The A8 floor (ten spec items must be present) was being
evaluated against the *filtered* list — i.e. against the single entry the caller
had just asked to run — so any `--only` invocation was guaranteed to fail. A
gate that cannot run one test is a gate nobody runs.

**Fix:** `verify_manifest(entries, a8_pool=None)`, with the pool defaulting to the
full manifest and both call sites passing the unfiltered snapshot taken before
the filter is applied. The behaviour is pinned by
`RequiredTestRunnerFloor` (2 tests): `--only GAP10-F-001` exits 0
(`required tests: 1/1 passed`), an unknown ID is rejected, and deleting A8 rows
fails in both modes.

---

### F-27 — A workspace reset silently reverted work the recovery tool did not own

This session's workspace is restored between turns, and the restore is not a
no-op for this pass: files created during the work disappear, and files edited
during it can come back in their upstream state. Early in the pass that cost a
morning of work — `tools/gap10_reapply.py` existed precisely to make the
surgical edits reproducible — but the tool only carried the edits it had been
*told* about. An audit of the tree against the pristine checkout found how large
the gap was:

```
changed/new files vs upstream : 1502        ← under-reported; see F-31 (the corrected total is 1,552)
  mechical A3 pass (markers/rand) : 1395
  carried by the reapply tool     :   30
  UNPROTECTED                     :   77   ← a reset reverts these with no way back
```

The unprotected 77 were controller changes, `config/*.php` blocks, the route
files, the Rust modules, `composer.lock`, `phpunit*.xml` and the documentation.
Three of them had *already* been reverted once this session and silently taken a
defect with them: `config/backup.php` lost the escrowed-identity key (F-19) and
`config/filesystems.php` lost the offsite disk (F-18) — the transforms that
were supposed to cover them had become no-ops (one had identical `old`/`new`
text, the other an anchor that survived its own insertion, so both reported
"ok" forever while putting nothing back).

**Fix:** the reapply tool now supports an *exact-state* transform: the file's
bytes are recorded in the tool, `--check` reports any drift from them, and
`--apply` restores them. All 77 are recorded (78 entries), alongside the 36
mechanical transforms. Two supporting changes fell out of the same audit:

* a pair-based transform may no longer use an empty anchor — `str.replace("", x, 1)`
  splices at the *top* of the file, so an empty anchor silently corrupted the
  target instead of failing;
* the helper-insertion pair for `BackupService` was re-anchored so that it is
  idempotent (its previous anchor survived the insertion, so a second run
  duplicated every helper and PHP died with `Cannot redeclare
  BackupService::encryptArtifact()`).

**Evidence** — a drill that reverts *every* changed file to its upstream
content, restores the three files that do not exist upstream, runs the two
recovery tools and re-hashes the tree:

```
reverting 1483 file(s)
gap10_reapply.py                        → ok=1 applied=112 failed=0
prune_numbered_simulations.py --strip   → 1376 marker files, 535 rand(0,1) files
DRIFT after full recovery: 0 | missing: 0 (of 1483)
```

Also fixed by the same audit: `app/Http/Controllers/HealthController.php`
carried a pre-existing `single_line_empty_body` violation that kept the CI style
gate red (`scripts/ci/check-pint.sh` → `PASS … 142 files` now), and the A4
step-up insertion in `tests/Feature/PaymentWalletLedgerTest.php` had to be
re-indented to Pint's `array_indentation` layout.

**Re-check:**
```bash
python3 tools/gap10_reapply.py --check     # ok=186 applied=0 failed=0
bash scripts/ci/check-pint.sh              # PASS — 142 files
```

---

### F-28 — both payment-ingress endpoints were unthrottled, and no test would have noticed

`POST /webhooks/payments/{provider}` and `GET|POST /payments/callback/{provider}`
are signature-verified, and that is exactly why they had no rate limit: the
signature check rejects forgeries, so the endpoints were treated as safe without
a ceiling. "Rejects forgeries" is not "safe to leave unbounded" — every request
costs a signature comparison, a provider lookup and (on a rejection) an audit
row, and a shared secret makes a brute-force attempt cheap to launch. A flood
also competes with the provider's real retries: the requests that actually
settle money.

Enabled in four places, each one load-bearing:

* `config/payments.php` — `rate_limits.webhook` (240/min) and `.callback`
  (120/min), read from `PAYMENT_WEBHOOK_RATE_LIMIT` /
  `PAYMENT_CALLBACK_RATE_LIMIT` because the right ceiling depends on the
  provider's retry policy. `0` would disable the limit, so
  `deploy/validate-env.py` refuses a non-positive value in production (verified:
  exit 1, naming the endpoint).
* `app/Providers/AppServiceProvider.php` — the named limiters, keyed
  `payment-webhook:{provider}:{ip}` and `payment-callback:{provider}:{ip}`, so a
  provider retrying after its own outage keeps its budget and one abusive source
  exhausts only its own.
* `routes/web.php` — `->middleware('throttle:payment-webhook')` and
  `throttle:payment-callback`, named so a route edit that drops one fails a test.
* `tests/Feature/Payments/PaymentEndpointThrottleTest.php` — **new file**, 5
  tests / 26 assertions: both routes carry the middleware; the (limit+1)th
  request answers **429**; a different provider and a different egress IP
  (`198.51.100.9`) are unaffected; and a correctly signed webhook inside the
  limit still settles the payment exactly once, so the limiter cannot cost a
  legitimate provider a settlement. Registered as `GAP10-A5-004`.

**Re-check:** `php vendor/bin/phpunit -c phpunit.pgsql.xml --filter PaymentEndpointThrottleTest`
→ `OK (5 tests, 26 assertions)`.

---

### F-29 — 25 paths the specification names: what exists, what never did

The specification cites 25 file paths by name. Five of them never existed in the
repository (verified against a fresh checkout of `main`), six more are the same
file under a different spelling, and the rest are present and were worked on.
Nothing here was created to satisfy a name: a file invented to make a checklist
green is worse than a documented absence, because it looks like work.

**Bare names that resolve to existing files** (the intent was real):

| Spec name | Real path |
| --- | --- |
| `PaymentService.php` | `app/Services/PaymentService.php` |
| `PayoutService.php` | `app/Services/PayoutService.php` |
| `features.php` | `config/features.php` |

**Paths that never existed, with where the intent actually lives:**

| Spec path | Where the work lives |
| --- | --- |
| `tests/Static/test_gap08_identity_floor.py` | does not exist; `tests/Static/test_gap10_identity_floor.py` is this pass's floor (59 tests) |
| `NotificationOutboxTest.php` | does not exist anywhere, upstream or here; the A7 work pins the flags in `phpunit.xml` and leaves the notification tests untouched |
| `ActionValidationService.php` | does not exist; validation lives in the form requests and `ScoringService` authorisation |
| `AuthoritativeGameSessionService.php` | does not exist; the session authority is `GameSessionService` + the numbered-family guard |
| `GameSettlementBoundary.php` | does not exist; the settlement boundary is `app/Services/Finance/AtomicSettlementService.php` (A8) |
| `PaymentReconcileCommand.php` | does not exist; reconciliation is `ReconcilePendingSettlements` + `ReconcileSettlement` (A8) |
| `TRACKER_143_REVIEW.csv` | does not exist; the review evidence is in this register |
| `FF-ARENA-FINAL-MASTER-GAP-MATRIX.md` | does not exist; the status matrix is section 1 of this file |
| `.github/workflows/go.yml`, `.github/workflows/rust.yml`, `.github/workflows/mobile.yml`, `.github/workflows/release.yml` | never existed; the brace shorthand `{go,mobile,rust,release}` was not greppable, so each path is named here in full. All four are jobs inside `.github/workflows/ci.yml` (consolidated); a file-existence check for them was the wrong check |
| `app/Services/Organizer/` | directory does not exist; organiser logic lives in `app/Services/OrganizerService.php` |
| `AuthController` | does not exist; auth is five controllers (`LoginController`, `RegisterController`, `AdminAuthController`, `SocialAuthController`, `OtpController`) |

Recorded so that a later reader does not go looking, and so that "we did not
create it" is on the record rather than inferred from silence.

---

### F-30 — section F (unverified areas) closed by execution, not by assertion

Every area the specification lists under F was either executed here or recorded
as environment-bound. Executed and green: Go (`go vet`, `go test ./...` 28 tests,
`go test -race -count=1`, `go build ./cmd/server` → 14.4 MB binary); Rust
(`cargo fmt --check`, `cargo clippy --all-targets -- -D warnings`, `cargo test`
9 passed, `cargo build --release`); Flutter 3.47.6 (`dart analyze .` → *No issues
found*; `flutter test --no-pub` → 86 passed; `scripts/ci/check-flutter.sh`);
`phpunit -c phpunit.pgsql.xml --testsuite Integration` → **`OK (5 tests, 24
assertions)`** — the fifth test tampers with a ledger row's amount *and* rewrites
its `metadata` to say `verified: true`, then asserts the integrity checker fails
closed anyway and clears once the row is restored (integrity is recomputed, not
read from a stored flag); the PostgreSQL-only and static suites. Read and recorded rather
than executed: `AuthController` (absent — five auth controllers were read
instead: login is enumeration-safe, regenerates the session, OTP uses
`max_attempts`, `hash_equals` and single use), `DisputeService` (every state
change is transactional), `ScoringService` (authorised at the edge via
`authorize('submitScore')`), `app/Services/Organizer/` (absent). Not performed,
and not claimed: the Docker build (no daemon here) and everything in E01–E27.

---

### F-31 — the coverage audit certified a state that was not true

The audit written for F-27 reported `UNPROTECTED: 0` while 41 files were in fact
unprotected. The bug was in the audit itself, which makes it worse than a
missing check: it *certified* a state that did not exist.

`diff -rq` reports a file that exists in only one tree in two shapes:

```
Only in /repo/docs: GAP-10-FINDINGS-REGISTER.md     # the directory exists in both trees
Only in /repo: newdir                               # the directory exists in one tree
```

The audit only handled the second. Every file this pass **added to a directory
upstream already had** — this register, the external register, the runbooks, the
A8 deliverables (`ReconcilePendingSettlements`, `ReconcileGameSessions`,
`ReconcileSettlement`, `AntiCheatService`, `AntiCheatDecision`,
`EnsureNumberedSimulationSafe`, `EnsureRecentPasswordConfirmation`,
`ConfirmPasswordController`, `WebhookSignatureRejected`, `MetricsController`,
`PrometheusMetrics`), their tests, `routes/gameberry_numbered.php` and the
recovery tooling itself — was silently excluded from consideration. The one
shape that mattered for new-in-existing-directory work was the shape that was
dropped.

**Fix:** the audit no longer parses an external command's output at all. It walks
both trees and compares content hashes, so a file is found no matter which
directory it lives in and nothing depends on the output format of `diff`. Three
further defects fell out of re-recording, all in the recovery tooling:

* the archive search used `source.index(GUARD, start)`, and several recorded
  files are themselves Python scripts with an `if __name__ == "__main__":`
  guard, so the search landed **inside a payload** and truncated the tool
  mid-string (`SyntaxError: leading zeros in decimal integer literals`). It is
  `rindex` now, and the rebuild asserts the archive sits above the guard;
* the skip list matched path *components*, so
  `services/security-rust/src/storage/mod.rs` — a Rust source file that happens
  to live in a directory called `storage` — was invisible to the audit. Skips
  are anchored prefixes now (`storage/`, `vendor/`, `target/`, …);
* two passes the pruner composes (`strip_markers` ∘ `replace_rand`) were tested
  separately, so 370 files looked uncovered that the pruner recreates exactly.
  The classifier now tries both orders;
* **deletions were not counted at all.** They are now reported (`prune --prune`
  for the numbered families, `LOST` for anything else), because a deletion is a
  change a reset resurrects.

**Lesson recorded:** a checker's verdict is only as good as its file list, and a
clean-looking zero is the most dangerous possible output. The guard against a
repeat is the pairing — the audit counts, the archive verifies content — plus
the drill below, which reverts a copy of the tree and rebuilds it.

**Re-check:**
```bash
python3 tools/audit_gap10_coverage.py        # 1,566 changed, 1,565 recoverable, 0 UNPROTECTED
python3 tools/gap10_record_state.py --check  # 0 drifted recordings, 0 uncovered
python3 tools/gap10_reapply.py --check       # ok=178 applied=0 failed=0
```

---

### F-32 — `--strip` rewrote the re-apply tool and corrupted its own archive

The A3 strip walked every text file in the tree. `tools/gap10_reapply.py` is a
text file, and it legitimately contains the marker names: they are inside its
payloads, because the recorded state of files like
`tools/prune_numbered_simulations.py`, `README.md`, `FILE_AUDIT.md` and this
register *is* text that names them. So every `--strip --apply` run rewrote the
archive that restores the tree, and the recovery drill then reported 16 drifted
files — recordings that no longer matched anything, including the strip tool's
own recorded copy.

This is the "cleaning that destroys the thing being cleaned" failure: the strip
cannot distinguish a leak from a document, and the marker keys are all it looks
for.

**Fix:** the pruner now has an explicit guard list and never rewrites those
paths — it reports them instead:

```
5 guard file(s) name the markers on purpose and were left untouched:
    guard FILE_AUDIT.md
    guard README.md
    guard docs/GAP-10-FINDINGS-REGISTER.md
    guard tests/Static/test_gap10_identity_floor.py
    guard tools/gap10_reapply.py
```

Verified by hashing the archive before and after a strip run
(`md5 3f031b580492c08e` → `3f031b580492c08e`), and pinned by
`RecoveryFloor::test_the_strip_leaves_the_guard_paths_alone`, which fails if the
guard is removed from a path that really does contain markers — including if the
markers stop being present, because a guard that protects nothing is dead code
and the test says so out loud.

---

### F-33 — declared coverage that did not reproduce the file

Three transforms stopped reproducing the file they target while continuing to
look like coverage:

* `tests/Feature/Payments/SettlementExactlyOnceTest.php` and
  `tests/Feature/Hosting/ProductionHostingTest.php` — whole-file payloads that
  had drifted behind the files they store by hundreds of lines and by one byte
  respectively;
* `app/Services/PaymentService.php` — an anchor pair whose anchor
  (`$payment->save();`) no longer exists in any state of the file, so it failed
  loudly on a reverted tree and silently looked "already applied" on the current
  one.

**Fix:** two changes, one for each half of the problem.

* Coverage is now *verified*, not declared: `transform_reproduces()` checks that
  the transform really produces the bytes on disk (whole-file payload equality,
  or anchor pairs applied to the upstream/pruned-upstream copy), and a file that
  fails the check is recorded in the archive instead. The coverage audit and the
  static floor both call this one predicate, so they cannot disagree about what
  "covered" means.
* `Transform.absorbed` exists for the honest case: the file moved on after the
  transform was written (a later pass edited the same method). The transform
  stays as a description of the edit, reports itself as absorbed, and the
  archive owns the bytes. Three transforms are marked absorbed, and both the
  record tool and the floor report them.

**Re-check:** `python3 tools/gap10_record_state.py --check` → `transforms that no
longer reproduce their file: 0`.

---

### F-34 — the archive can bake in a regression, and only the test suite catches that

The recovery drill reverts a *copy* of the tree to upstream, deletes what the
reset would delete, runs the documented recovery
(`prune_numbered_simulations.py --strip --apply` then `gap10_reapply.py`) and
compares every file by SHA-256. It reports **0 drift across 1,566 files**.

That proves reproducibility — and nothing else. During this pass the archive was
refreshed while four A5 edits (`config/payments.php` rate limits,
`AppServiceProvider` limiters, the two throttled routes, the `.env.example`
keys) were absent from the tree, so the recordings captured the missing state.
The drill was perfectly happy: it reproduces the archive faithfully, wherever
the archive happens to be wrong. The **test suite** is what caught it — four
failures in `PaymentEndpointThrottleTest`, on the first full PostgreSQL run
after the refresh — and the four blocks were restored verbatim and re-recorded.

So the two checks are a pair, and neither substitutes for the other:

* the drill answers *"can this tree be rebuilt?"*
* the battery (`phpunit -c phpunit.pgsql.xml`, the required-test gate, the Redis
  and SQLite profiles, the static floor, Pint) answers *"is the tree that gets
  rebuilt the right one?"*

Both run after any refresh of the archive, and both are green in section 4.

---

### F-35 — a re-read of the specification found one test file genuinely missing, and four items under-registered

Every one of the ~83 path lines in the specification was checked against the
tree, one by one, by content rather than by name. Seventeen looked absent at
first pass; fifteen of those are not absences at all, and the two real gaps are
closed here.

**Not absences, verified individually:**

| Spec line | Reality |
| --- | --- |
| `app/Services/Gameberry/Core`, `…/Stats`, `…/Final*` and the sibling controller/model/view directories ("delete the whole directory") | 57 directories, 1,987 files — present, hidden behind the flag (A3 **Option B**, the documented default). The `[DIR] … delete` lines describe Option A; choosing the default is not a missing file. |
| `tests/Feature/Gameberry/Stats` | present (30 files), same reasoning |
| `app/Models/Settlement.php`, `database/migrations/2026_10_06_000000_create_settlements_table.php` | deliberately **not** created: the specification writes `[NEW] row 037: ONLY if the spec requires a distinct model; otherwise map to existing FinancialSettlement and document the decision (avoid two sources of truth)`. `FinancialSettlement` exists and is what the settlement code uses; a second model would be the duplicate the instruction warns against. Recorded in **F-29**. |
| `.github/workflows/go.yml`, `.github/workflows/rust.yml`, `.github/workflows/mobile.yml`, `.github/workflows/release.yml` | never existed upstream; each path is named in full so it is greppable. All four are jobs inside `.github/workflows/ci.yml` (verified: 8 jobs, Go/Rust/Flutter included, release builds inside the `mobile` job). Recorded in **F-29**; a file-existence check for them was the wrong check. |
| `mobile/ios` | present (51 files); the bundle/team settings live in the Xcode project as the line requires |

**Genuinely missing, now created:**

1. `tests/Feature/Gameberry/NumberedSimulationProductionGuardTest.php` — the A1
   test named in the specification. The guard was correct and
   `NumberedRoutesDisabledTest` covered its mechanics, but nothing asserted the
   *reported end-to-end behaviour*: the production request was refused **and no
   gold or gem transaction was written**. **4 tests / 49 assertions.**

   Two things about this file are deliberate and worth recording, because the
   first draft got them wrong:

   * the route-table assertion runs in a **subprocess** (`artisan route:list
     --json` with `APP_ENV=production` in the environment). A booted test process
     cannot un-register a route, so asserting it in-process tested the
     environment variable, not the application. The subprocess also proves the
     gate holds with the feature flag set to **true** — the environment gate is
     what keeps the routes out, not the flag's default.
   * the same request is then exercised in `testing`, where it must succeed and
     settle exactly one gold bet. Without that, a 404/403 caused by a typo would
     satisfy the production assertions and the file would pass for the wrong
     reason.

2. Five required-test manifest rows — the pass had implemented A1, A2 and A7 but
   registered none of them, which is exactly the silent-under-coverage this
   manifest exists to prevent (rule 3 of the specification):

   | Row | Covers |
   | --- | --- |
   | `GAP10-A1-001` | the A1 production guard test above |
   | `GAP10-A2-001` | `VirtualWalletLockTest` — two real connections, the second blocks on `FOR UPDATE`, final balance correct |
   | `GAP10-A2-002` | `VirtualWalletLockQueryTest` — the locking SELECT is issued (SQLite-safe) |
   | `GAP10-A5-005` | the business-signature layer (**F-36**) |
   | `GAP10-A7-001`, `GAP10-A7-002` | the notification suite under the pinned flags |

   The gate runs **36/36 passed (skips are failures)** where it previously ran
   29/29.

**Re-check:**
```bash
python3 tools/run_required_tests.py --config phpunit.pgsql.xml   # required tests: 36/36 passed
php vendor/bin/phpunit -c phpunit.pgsql.xml --filter NumberedSimulationProductionGuardTest
php vendor/bin/phpunit -c phpunit.pgsql.xml --filter ProviderBusinessSecretTest
```

---

### F-36 — the business signature: one shared secret, no timestamp

The inbound webhook layer verifies the provider's transport signature against a
per-provider secret and enforces a timestamp window. The **second** signature
check — the one `PaymentService` performs immediately before the payment state
machine runs, on a separate secret — did not: a single shared
`services.payments.webhook_secret`, raw-body HMAC only, no timestamp.

That left three real gaps behind the layered defence:

* one leaked key signed for every provider (the second layer was only as strong
  as the weakest shared deployment);
* a captured body stayed valid forever, so a replay only had to beat the event-id
  idempotency of the *first* layer;
* the two layers shared a trust root in every deployment that never configured
  per-provider keys, so "two independent checks" was in practice one check
  written twice.

Implemented exactly as the specification describes — per-provider secrets plus a
signed timestamp with a tolerance window, as hardening rather than a breaking
change:

* `config/webhooks.php` — `business.providers.{bkash,nagad,rocket,sslcommerz,card}`
  (each `env(PAYMENT_BUSINESS_*_SECRET)`, empty falls back to the shared secret)
  and `business.timestamp_tolerance` (`PAYMENT_BUSINESS_TIMESTAMP_TOLERANCE`,
  default 300s).
* `PaymentService::verifySignature()` — accepts `{timestamp}.{body}` when a
  timestamp is supplied and the legacy raw-body scheme when it is not. A supplied
  timestamp **outside the window is refused outright**: it does not fall back to
  the legacy scheme, because a stale signature is precisely what the window
  exists to reject. Whitespace around a digest is tolerated (headers are trimmed
  in transit; refusing that would be a false rejection).
* `PaymentService::businessSecretFor()` — the single resolution rule for the
  business secret, used by **both** the verifier and the ingress layer's signer.
  Two implementations of that rule would eventually disagree and produce a
  signature that can never verify: a payments outage dressed as a security fix.
* `WebhookIngressService::processPayment()` — signs with that method and passes
  the already-validated timestamp through, so the business layer re-checks
  freshness instead of trusting the edge's word for it.
* `.env.example` — the five keys and the tolerance documented as empty by
  default, so an existing deployment does not change behaviour without asking.
* `tests/Feature/Payments/ProviderBusinessSecretTest.php` — **10 tests / 20
  assertions**: a provider's own secret wins and the shared secret stops signing
  for it; one provider's secret cannot sign for another; an unconfigured provider
  still falls back; a fresh timestamp is accepted while a stale or future-dated
  one is refused without fallback; a timestampless sender still settles; and
  every refusal is asserted to happen **before** any state change.
  Registered as `GAP10-A5-005`.

**Re-check:** `php vendor/bin/phpunit -c phpunit.pgsql.xml --filter ProviderBusinessSecretTest`
→ `OK (10 tests, 20 assertions)`; the whole A5 cluster (signatures, replay race,
engine, throttle, business signature, exactly-once) → `40 tests, 118 assertions`.

---

### F-37 — the recovery tooling was exercised in anger, and the floor is what caught the loss

Between turns this workspace was snapshotted and restored. It came back with the
A3 marker strip **reverted on 164 files** (`no_shortening`, `production_ready`,
`full_file_content`, `rand(0, 1)`) and with 13 surgical transforms unapplied.
Nothing in the tree said so.

What found it was the static floor — `GeneratedMarkerFloor` failed on the
leaked markers — and then the tools restored the tree: the strip rewrote the 164
files, `gap10_reapply.py` applied the 13 missing edits, and the archive reported
`0 drifted recordings, 0 uncovered files` afterwards, with `gap10_reapply.py
--check` at `ok=186 applied=0 failed=0`.

Two things this proves, and they are the reason it is recorded rather than
quietly fixed:

* **the tooling works as designed** — a reset is recoverable in the documented
  order (`prune --strip --apply`, then the re-apply tool), which is what F-27,
  F-31, F-32 and F-34 were built for;
* **the floor is load-bearing** — the marker test is not decoration. Without it
  the leaked scaffolding fields and the predictable `rand(0, 1)` outcome would
  have shipped silently in a tree that otherwise passed its suite.

It was also caught mid-flight by F-33 during this turn: the A5 edit above moved
`WebhookIngressService.php` beyond its own transform, the floor failed with
`does not reproduce the file`, and the documented remedy — absorb the superseded
transform into the archive — was applied in one step.

**Re-check:**
```bash
python3 -m unittest discover -s tests/Static -p 'test_*.py'   # 59 tests, OK
python3 tools/gap10_record_state.py --check                   # 0 drifted, 0 uncovered
python3 tools/gap10_reapply.py --check                        # ok=186 applied=0 failed=0
```

---

### F-38 — a claim is not evidence: every headline claim now ships a re-runnable proof

The demand was explicit: *the real proof or Live proof / evidence*. Until now the
numbers in this register were real, but a reader had to take them on trust and
reproduce half a dozen commands by hand. Prose, however accurate, is not evidence.

Three tools were added, and none of them is a summary:

* `tools/prove_gap10.py` runs **13 proofs** as subprocesses and writes a receipt
  per proof into `docs/evidence/<run-id>/`: the raw log and its SHA-256, the exit
  code, the wall-clock duration, the SHA-256 of every artifact the claim depends
  on, the markers that had to appear, and the tree + environment fingerprint the
  run was taken against. A proof that cannot produce all of that is `FAILED`, and
  `--all` exits non-zero. `docs/GAP-10-EVIDENCE.md` is the rendered report;
  `--render-only` re-renders it from the receipts without re-running anything.
* `tools/live_probe.py` starts the application on a real socket in production mode
  (`php artisan serve`, `APP_ENV=production`, no `.env`) and speaks HTTP to it —
  the only proof here that exercises the server rather than the test harness. It
  asserts the reported write is refused with no route, a real page is 200,
  `/metrics` is 401 without a token and 200 with one, and an unsigned webhook is
  refused until the limiter trips at request 241 (limit 240).
* `run_red_a1()` sabotages a *copy* of the tree and re-runs the A1 test to prove
  the test detects the bypass. **A red test must sabotage behaviour, not delete a
  class:** deleting `EnsureNumberedSimulationSafe` only yields "class not found",
  while a same-surface pass-through produced the true failure —
  `Expected response status code [403] but received 200`.

Building it found two real defects, both in the probe rather than in the
application, and both are recorded because this is exactly what a live proof is
for (F-42 added a third: the proof had been leaning on a migrated database that
nothing migrated):

* the metrics token must be presented as `Authorization: Bearer <token>`
  (`MetricsController` reads the bearer token); a bespoke header made a correctly
  working endpoint look broken;
* forcing `CACHE_STORE=array` to make the throttle probe "isolated" defeats the
  limiter — its counters *are* the cache, so every request looked like the first
  and the 429 never arrived. The probe deliberately leaves the cache store alone.

The evidence output itself is excluded from the recovery archive, the coverage
audit and the drill, and that is not tidiness: a run that recorded its own
receipts would be protecting the last thing it proved instead of the code that
proved it, and the drill would try to restore a log file in place of a source
file. An earlier recording of the first run's receipts was baked into the archive
before the exclusion existed; the refresh path now *evicts* any recorded path the
current skip rules exclude, so the archive cannot drift back (13 stale receipts
were dropped when the rule landed).

Result for the tree this register describes (`docs/evidence/20261007T045217Z`, tree fingerprint
`f09201eb7710be0b…`, one run): **13 proven, 0 failed, 0 not run**, exit 0 —
E-01…E-10 plus the live HTTP probe (`E-03L`), the A1 red test (`E-03R`) and the F-39 red test (`E-11`). What it
still does not prove is unchanged and listed where it always was: the Docker build
(`docker` is absent in this environment), real provider credentials, store signing
material, a live domain, a real device, and E01–E27.

**Re-check:**
```bash
python3 tools/prove_gap10.py --list      # the 13 proofs and their exact commands
python3 tools/prove_gap10.py --all       # re-run everything; exit 0 = all proven
python3 tools/prove_gap10.py --render    # rebuild docs/GAP-10-EVIDENCE.md
python3 tools/prove_gap10.py --prune     # keep the 5 most recent run directories
```

---

### F-39 — a lock that does not lock, four more times: the slot claim was not race-free on PostgreSQL

**Severity: high (data integrity, money-adjacent).** Found by the sweep, not by
the test suite.

While verifying section B of the specification I added the CI step it asks for —
`tests/Feature/Postgres` and `tests/Feature/Concurrency` run in isolation, with
`--fail-on-skipped --fail-on-risky --fail-on-incomplete` — and ran it by hand
first. It failed:

```
Tests: 22, Assertions: 56, Failures: 1.
FAIL: RegistrationRaceTest::test_concurrent_registration_never_oversubscribes_slots
```

Six captains raced for **one** slot, and two teams ended up pending. Re-running
it eight times gave roughly one failure in four; the full suite has always
passed, because the window is narrow and timing-dependent. This is the same
family as A2 — a guard that looks like a lock and is not one — but in a
different part of the tree, and this time it is not a no-op call: it is a
correct-looking statement that PostgreSQL plans differently from SQLite.

**The mechanism, proven rather than asserted.** All four guards decide capacity
inside the WHERE clause of an UPDATE:

```sql
UPDATE tournaments SET updated_at = now()
WHERE id = ? AND ... 
  AND (SELECT COUNT(*) FROM teams WHERE tournament_id = tournaments.id
       AND status IN ('pending','confirmed')) < team_slots
```

On SQLite this is airtight: the UPDATE takes the database write lock, so every
later statement in the transaction is serialised. On PostgreSQL it is not. Under
READ COMMITTED the scalar subquery is planned as an **InitPlan** — evaluated
once, with the snapshot taken when the statement *started* — and the re-check
PostgreSQL performs when a blocked writer finally gets the row lock does not
refresh it. The waiting writer therefore still sees the free slot, claims it, and
two teams hold one slot.

Two-connection reproduction, deterministic (the waiter is a forked child that is
guaranteed to be blocked on the row lock while the holder commits):

```
T1 claim -> 1
T1 inserted its team and committed
T2 claim after T1 committed -> 1  *** DOUBLE CLAIM: both writers took the only slot ***
```

**The four sites** (the sweep grepped for the shape, it was not one bug):

| File | Parent row | Capacity |
| --- | --- | --- |
| `app/Services/RegistrationService.php` | `tournaments` | team slots |
| `app/Http/Controllers/TeamController.php` | `tournaments` | team slots |
| `app/Services/TournamentParticipationService.php` | `tournaments` | waitlist promotion into a slot |
| `app/Services/RosterService.php` | `teams` | roster size (`team_members`) |

**The fix.** A locking read of the parent row *before* the count is read:

```php
DB::table('tournaments')->where('id', $fresh->id)->lockForUpdate()->first();
```

Every contender waits there, and every statement issued after the lock is
granted runs with a fresh snapshot that can see the winner's committed row. On
SQLite `lockForUpdate()` compiles to nothing (`SQLiteGrammar::compileLock()`
returns `''`), so the dialect that was already safe keeps its behaviour and the
atomic UPDATE keeps its original job — taking the write lock. Nothing in the
money core was weakened; a lock was added, none removed.

**The test fails before and passes after — deterministically.**
`tests/Feature/Concurrency/SlotClaimSerialisationTest.php` holds the parent-row
lock on a dedicated connection and forks a child that is guaranteed to be
waiting on it, so it does not depend on a timing window:

| Run | Registration | Promotion | Roster add |
| --- | --- | --- | --- |
| fix disabled | `{"outcome":"claimed"}` — FAIL | `promoted` — FAIL | `added` — FAIL |
| fix applied | `waitlisted` — OK | `refused: No available slot…` — OK | `refused: …maximum of 2 players…` — OK |

`OK (3 tests, 11 assertions)`. The previously flaky race test then ran **8/8
clean** (it was failing about 3 in 8), and the two directories pass in isolation
with the strict flag set: `OK (25 tests, 70 assertions)`.

Building the harness also produced a lesson worth keeping: a forked child that
closes an inherited PDO sends a TLS `close_notify`/Terminate down the socket its
parent is still using, and the parent's next statement dies with
`SSL error: decryption failed or bad record mac`. The parent's blocking
transaction therefore runs on its own connection, and the child ends with
`SIGKILL` after writing its report so no destructor touches an inherited socket.

**Two guards keep it from coming back:**

* `SlotClaimSerialisationFloor` in the static floor asserts, family-wide, that
  each of the four sites takes a locking read of the right parent row *before*
  the counting subquery — a fifth guard written in this shape fails the build;
* CI now runs `tests/Feature/Postgres` and `tests/Feature/Concurrency` as a named
  step with `--fail-on-skipped --fail-on-risky --fail-on-incomplete`, which is
  the "fail on skipped/incomplete/risky" half of section B that the full-suite
  step does not carry (the full suite has three deliberate, documented skips:
  the SQLite half of one dialect pair and two environment probes).

`TeamController` is the HTTP twin of `RegistrationService` — the same statement
in the same shape inside the same transaction — so it is covered by the pattern
check rather than by a fourth forked fixture. That is a decision, not an
omission.

**Re-check:**
```bash
php vendor/bin/phpunit -c phpunit.pgsql.xml --filter SlotClaimSerialisationTest   # 3 tests, OK
php vendor/bin/phpunit -c phpunit.pgsql.xml tests/Feature/Postgres tests/Feature/Concurrency \
  --fail-on-skipped --fail-on-risky --fail-on-incomplete                          # 25 tests, OK
python3 -m unittest discover -s tests/Static -p 'test_*.py'                       # incl. the F-39 floor
```

---

### F-40 — the test profiles could be talked into testing a different database

**Severity: medium (false green runs).** Found while verifying the F-39 fix.

Running the SQLite profile in a shell that had `DB_CONNECTION=pgsql` exported —
which is how the PostgreSQL profile is run — produced a **PostgreSQL** run that
printed "SQLite green". The tell was arithmetic, not an error: the SQLite profile
has 23 dialect skips (the PostgreSQL-only family), and the run reported 3.

**Why the profiles could be overridden.** PHPUnit's `<env force="true"/>` writes
`$_ENV` and calls `putenv()`; Laravel's `env()` reads `$_SERVER` **first**, and
PHP fills `$_SERVER` from the process environment at startup. So the "forced"
value lost to an exported one:

```
env(DB_CONNECTION)='pgsql'  getenv='sqlite'  $_ENV='sqlite'  $_SERVER='pgsql'  config=pgsql
```

**The fix, and why it is two lines and not one.** Each profile now pins what it
*a is* in `$_SERVER` (the adapter Laravel actually reads) as well as `$_ENV`:

| Profile | Pinned dialect | Pinned coordinates |
| --- | --- | --- |
| `phpunit.xml` | `sqlite` | `DB_DATABASE=:memory:`, `DB_URL=` |
| `phpunit.pgsql.xml` | `pgsql` | — (host/port/name stay environment-overridable, which is how CI points it at the service container) |
| `phpunit.redis.xml` | `sqlite` | `DB_DATABASE=:memory:`, `DB_URL=` |

Pinning only the dialect was **not** enough, and the second half was found the
hard way: with `DB_CONNECTION` pinned to sqlite but `DB_DATABASE` left leaking,
the in-memory profile became a **file** database and the suite wrote
`./ffarena_test` — 1.9 MB of SQLite file in the repository root. Nothing failed
loudly; the coverage audit flagged it as `UNPROTECTED` (an unrecoverable file),
which is how it was caught, and it was deleted. Two tests then failed for the
same reason (`BackupTest::test_backup_fails_honestly_on_in_memory_database` and
`AdminOpsTest::test_admin_backup_trigger_is_audited_even_on_failure`): they assert
that an in-memory database is refused, and a leaked database name had made the
database a real file.

**Evidence it is fixed, not decorated.**
`tests/Feature/ProfilePinningTest.php` asserts from inside the suite that the
resolved connection matches the profile's declared identity, and the static floor
runs that test **in a hostile environment** (`DB_CONNECTION=pgsql` exported) as a
subprocess — so the pin is proven, not merely present in the XML. The floor also
asserts each profile pins its coordinates, family-wide.

Measured on this tree, with `DB_CONNECTION=pgsql` and the PostgreSQL credentials
exported:

```
php vendor/bin/phpunit                     # OK — 1527 tests, 5470 assertions, 0 failures, 26 skipped
php vendor/bin/phpunit -c phpunit.pgsql.xml # OK — 1532 tests, 5559 assertions, 0 failures, 3 skipped
```

The 26 skips are the 23 PostgreSQL-only tests plus the three F-39 tests, every
one of which runs on the PostgreSQL profile. The number is the proof that the
dialect is what the profile says it is.

**Re-check:**
```bash
DB_CONNECTION=pgsql php vendor/bin/phpunit     # must stay SQLite: 26 skips, 0 failures
python3 -m unittest discover -s tests/Static -p 'test_*.py'   # incl. the hostile-environment pin
python3 tools/audit_gap10_coverage.py          # must report 0 UNPROTECTED (no stray database file)
```

---

### F-41 — the re-check of the specification is now a tool, not a memory

The standing instruction is to re-read the whole specification and add what is
genuinely missing. That was done by hand twice and by memory once; it is now two
tools, because a sweep that cannot be re-run cannot be trusted.

**`tools/audit_spec_files.py`** parses every file line of `first.md`
(`path/to/file # [EDIT|NEW|DELETE|DIR] ...`) and resolves it against the tree.
It reports what it *found*, and — more usefully — it is hard to satisfy by
accident:

* `[NEW]` + present = **CREATED**; `[NEW]` + absent = **MISSING**;
* `[EDIT]` + present = **PRESENT**; `[EDIT]` + absent = **MISSING** — unless the
  path never existed upstream either, which is **SPEC-ONLY**, and then the
  findings register has to name it, or the tool calls it
  **SPEC-ONLY-UNDOCUMENTED** and exits non-zero. Fabricating a file that never
  existed is not an option, and neither is quietly ignoring one.

Result on this tree (83 file lines): **26 CREATED, 37 PRESENT, 10 DIR, 4 GLOB,
2 EXEMPT-DOCUMENTED, 4 SPEC-ONLY — 0 unresolved.** The two exemptions are
`app/Models/Settlement.php` and its migration, where the specification itself
says "ONLY if the spec requires a distinct model; otherwise map to existing
FinancialSettlement and document the decision": `FinancialSettlement` exists and
is what the settlement code uses, so the second branch is taken and recorded. The
four SPEC-ONLY lines are the per-language workflow files, which never existed and
which the specification's own CI section consolidates into one workflow.

Running it found one real documentation weakness: the register listed those four
workflows only as a brace shorthand (`.github/workflows/{go,mobile,rust,release}.yml`),
which no grep can find. Each path is now named in full.

**`tools/hydrate_env.sh`** is the other half: the workspace is snapshotted and
restored between sessions, and four restores have each taken the PHP toolchain,
PostgreSQL, Redis, `age`, the `/tmp` upstream copy, the A3 marker strip and
`vendor/autoload.php` with them. Rebuilding that by hand took four turns of
archaeology; it is now one idempotent, fail-closed command that ends by running
this repository's own gates (static floor, `gap10_reapply.py --check`, the marker
scan, the coverage audit) and exits non-zero if any is red. It creates no
credentials — the role and databases it makes (`ffarena`, `ffarena_test`,
`ffarena_test_alt`, `ffarena_ops`) are the local throwaways the profiles use — and
its invariants are pinned by `EnvironmentRebuildFloor` in the static floor.

Writing it produced two of its own bugs, both caught by running it rather than
reading it: `set -e` with `pipefail` aborted the script when `grep` found no
markers (exit 1 is the *healthy* case), and its marker list had drifted from the
floor's — it was missing `existing_logic_preserved`, so a file carrying only that
marker would have passed. The floor test now compares the two lists.

**Re-check:**
```bash
python3 tools/audit_spec_files.py         # 83 lines, 0 unresolved
bash tools/hydrate_env.sh --verify        # floor + --check + markers + audit, exit 0
```

---

### F-42 — "covered by a transform" was checked with a glance, not a rebuild

**Severity: medium (recovery gap + a proof that leaned on hand-built state).**
Both halves were found by re-running the machinery, one turn after writing it.

**The blind spot.** `transform_reproduces()` accepted two proofs, and the weak
one was checked first:

```python
if apply_pairs(current, transform.pairs, transform.repeat) == current:
    return True          # "the pairs change nothing, so it must already be applied"
```

A pair whose `old` text is gone is a **no-op**, and a no-op says nothing about
the content around it. `phpunit.redis.xml` is covered by the F-17 transform,
which rewrites its `<testsuites>` block; the profile pins added to that file later
(F-40) are not something those pairs can express. The pairs therefore changed
nothing, the check returned "reproduces", the coverage audit counted the file as
recovered by a transform, and `gap10_reapply.py --check` printed `ok` — while a
real rebuild produced a file **without the pins**. The recovery drill caught it:

```
DRIFT after full recovery: 1
    DRIFT   phpunit.redis.xml
```

The predicate now requires the rebuild whenever the upstream reference exists:
apply the transform (or its pruned forms) to the upstream copy and compare byte
for byte. The no-op shortcut survives only for files with no upstream
counterpart, where a reset *deletes* the file and the transform must be able to
build it from nothing. Re-running the tightened check over all 195 declared
transforms found exactly one offender — this one — and no false positives.

The file is now covered the way F-33 prescribes for a transform that cannot
rebuild its target: marked `absorbed=True` and stored whole in the recorded
state, which does rebuild it.

**The proof that leaned on hand-built state.** With the tree clean again, the
live HTTP proof (E-03L) came back 3/10:

```
FAIL  GET /health/live answers 200 — status=500
FAIL  GET /metrics with the right token exports metrics — status=500 lines=3
production.ERROR: SQLSTATE[42P01]: Undefined table: relation "cache" does not exist
```

Nothing was wrong with the application: the probe's database (`ffarena_ops`) was
recreated empty by an environment rebuild, the production cache store is the
database, and the probe had been *assuming* a migrated database — its own comment
said "migrated for this probe" while nothing migrated it. Route-level checks still
passed, so the failure looked like a broken endpoint rather than a missing table.

`tools/live_probe.py` now migrates its own database before it starts the server,
from the same environment object the server runs under, and fails the probe
loudly if the migration fails. E-03L is back to **10/10**, and the proof no
longer depends on anything outside the repository.

**Re-check:**
```bash
python3 -m unittest discover -s tests/Static -p 'test_*.py'   # includes the strict transform check
python3 tools/drill_gap10_recovery.py                         # DRIFT 0 / MISSING 0
python3 tools/live_probe.py --expect-production               # LIVE-PROBE: OK (10/10)
```

---

---

## 3. Accepted deviations

| # | Deviation | Why it is acceptable | Residual risk |
| --- | --- | --- | --- |
| D-1 | A3 chose Option B (hide behind a flag) rather than deleting the clone families | It is the specification's default where no preference was given, and it keeps the simulations that development still exercises. The files are inert in production: the routes are never registered there. | The clone code remains in the tree, so a future change must not import it into a product path. `NumberedRoutesDisabledTest` guards the boundary. |
| D-2 | The required-test gate tolerates exactly two justified single-test skips | A class covering both SQLite and PostgreSQL necessarily skips the other dialect's scenario. Both entries run with **zero** skips on the PostgreSQL profile that the release gate uses. | A third budget (or a budget above 1) fails the static floor test. |
| D-3 | `AntiCheatService` is not named `ActionValidationService` | See F-08: the referenced class does not exist in this repo. | A reviewer expecting the literal name must read F-08. |
| D-4 | Markers were stripped from history-bearing comments rather than leaving prose mentions | Keeps one greppable invariant ("marker strings = 0") instead of a rule with exceptions. | History lives in this register, not in the route file. |
| D-9 | The committed Dart client is regenerated in this pass [Correction 2026-10-07: the accompanying pin claim (mobile job pins Flutter 3.47.6) never landed — no `flutter-version` in `ci.yml` per git history; GAP-R4 tracks the pin-after-first-green] | F-22 changed the spec and F-24 requires one canonical formatter; the artifact must match both. Determinism comes from the gate's `--language-version` flag (derived from the pubspec floor), not from a pin. | SDK bumps alone do not drift the layout (the flag freezes it); regenerate the client when the spec changes — the gate says so in its error message. `openapi_models.dart` is unchanged from upstream. |
| D-8 | `composer.lock` and `tools/gen_openapi.py` are changed by this pass, not only `app/` code | F-21/F-22 are dependency-and-spec defects: the fix is the lock file and the generator, and both are covered by a static floor test plus a `--verify`-style script. | A reviewer must run `tools/harden_dependencies.sh --verify` / `bash scripts/ci/check-openapi.sh`; neither touches the money core. |
| D-7 | The Rust service keeps ~130 pieces of staged code under a module-scoped `#[allow(dead_code)]` instead of wiring or deleting them | Deleting a security service's crypto/audit/worker surface to satisfy a lint would be a product change with real risk; wiring it up is a feature, not a fix. The exemption is explicit, module-scoped, commented, and greppable (`#[allow(dead_code)]` in `src/main.rs` plus F-20). | A reviewer must read F-20; the crate still fails `-D warnings` for every non-dead-code lint. **Re-check:** `tools/harden_rust_service.sh --verify` |
| D-6 | 3 tests skip on the PostgreSQL profile (was 26 before the `redis` extension was installed here) | Enumerated, not hand-waved: `Phase16\BackupTest::test_backup_fails_honestly_on_in_memory_database` (SQLite-only scenario, the documented two-dialect budget of `GAP10-C-001`) and `R9\DockerAndHealthTest::{test_docker_available_detection,test_go_available_detection}` (no Docker daemon and no Go toolchain in this sandbox). None of them is in the required set: the release gate runs the required tests with `--fail-on-skipped` and 27/27 pass. | A future skip in a required entry still fails the gate. **Re-check:** `php vendor/bin/phpunit -c phpunit.pgsql.xml --display-skipped` and `python3 tools/run_required_tests.py --run --fail-on-skipped --config phpunit.pgsql.xml` |
| D-5 | A6's deploy gate is code-complete (config + validator + `.env` template + tests) but never executed against a live host | No production infrastructure exists in this environment; that is E01–E03. | Recorded as *not performed* in section 1. |

---

## 4. What a reviewer should run

Nothing below needs this sandbox, production credentials or a network:
every command is deterministic and reads only the repository.

**The battery — correctness.** Run from the repository root, with `.env`
absent so the profiles own their configuration:

```bash
php composer.phar install            # PHP 8.4, PHPUnit 11.5

# 1. the production dialect, twice: the suite, then the strict gate
php vendor/bin/phpunit -c phpunit.pgsql.xml                # 1511 tests, 5457 assertions, 0 failures
python3 tools/run_required_tests.py --config phpunit.pgsql.xml   # 29/29 passed (skips are failures)

# 2. the other two profiles
php vendor/bin/phpunit                                     # SQLite: 1507 tests, 5395 assertions
php vendor/bin/phpunit -c phpunit.redis.xml                # Redis:  105 tests, 276 assertions

# 3. the static floor and the style gate
python3 -m unittest discover -s tests/Static -p 'test_*.py'   # 59 tests, OK
bash scripts/ci/check-pint.sh                                 # PASS 142 files
```

**The recovery — reproducibility.** These answer a different question: can this
tree be rebuilt after a reset? The drill works on a *copy* in `/tmp`; it never
writes to the repository.

```bash
python3 tools/audit_gap10_coverage.py        # 1,566 changed, 1,565 recoverable, 0 UNPROTECTED
python3 tools/gap10_record_state.py --check  # 0 drifted recordings, 0 uncovered
python3 tools/gap10_reapply.py --check       # ok=178 applied=0 failed=0
python3 tools/drill_gap10_recovery.py        # revert a copy, rebuild it: 0 DRIFT, 0 MISSING
```

**The external register — honesty.** `docs/GAP-09-EXTERNAL-VERIFICATION-REGISTER.md`
lists E01–E27 with their evidence paths and their state. Every one of them is
still **PENDING**: none can be executed without production infrastructure,
provider sandbox accounts, store consoles or certificate issuance, and none is
reported as done. The static floor fails if an item claims verification without
an evidence path.

**What is NOT proven here, in one place:** the Docker build (`docker` is absent
in this environment), anything requiring real provider credentials, store
signing material, a live domain or a real device/emulator, and E01–E27. Those
are the F and E sections, and they are recorded as unperformed rather than
assumed.


```bash
# 1. The whole PHP suite (expect 0 failures).
php vendor/bin/phpunit

# 2. The required-test gate, with skips failing the build.
python3 tools/run_required_tests.py --verify-manifest
python3 tools/run_required_tests.py --run --fail-on-skipped

# 3. The static floor: guards, config, CI coverage, external honesty.
python3 -m unittest discover -s tests/Static -p 'test_*.py' -v

# 4. The A3 audit: no markers, no predictable PRNG, families accounted for.
python3 tools/prune_numbered_simulations.py --check

# 5. The environment gate must reject the shipped template.
python3 deploy/validate-env.py --env-file .env.example --production --no-process-env   # expect exit 1
```

Anything in steps 1–5 that does not behave as described above is a regression:
this register claims the repository is in that state.


---

## 5. Evidence of record

`docs/GAP-10-EVIDENCE.md` is generated by `tools/prove_gap10.py`. Every row is a
subprocess run, and every run stores its raw output beside the report, so nothing
here has to be taken on trust. A receipt can be checked by hand:

```bash
# run from the repository root: a receipt stores its log path repo-relative
python3 - <<'PY'
import hashlib, json, pathlib
receipt = json.load(open("docs/evidence/20261007T045217Z/E-01.json"))
log = pathlib.Path(receipt["log"]).read_bytes()
print(receipt["id"], receipt["verdict"], receipt["exit_code"],
      hashlib.sha256(log).hexdigest() == receipt["log_sha256"])
PY
```

Run of record — `docs/evidence/20261007T045217Z` (tree fingerprint `f09201eb7710be0b…`):

| Proof | Verdict | Exit | Duration | Claim | Raw log |
| --- | --- | --- | --- | --- | --- |
| `E-01` | **PROVEN** | 0 | 154.47s | The PostgreSQL profile passes end to end on the production database dialect. | [E-01.log](docs/evidence/20261007T045217Z/E-01.log) |
| `E-02` | **PROVEN** | 0 | 154.25s | Every required test runs and passes on the production dialect — skips are failures. | [E-02.log](docs/evidence/20261007T045217Z/E-02.log) |
| `E-03` | **PROVEN** | 0 | 5.28s | In production the numbered simulation routes do not exist, and the write is refused with no wallet movement. | [E-03.log](docs/evidence/20261007T045217Z/E-03.log) |
| `E-03L` | **PROVEN** | 0 | 10.21s | LIVE OVER HTTP: a running production server answers 404 for the reported write and 200 for a real page. | [E-03L.log](docs/evidence/20261007T045217Z/E-03L.log) |
| `E-03R` | **PROVEN** | 0 | 7.98s | RED TEST: with the guard removed the same A1 test fails — it reproduces the reported production bypass. | [E-03R.log](docs/evidence/20261007T045217Z/E-03R.log) |
| `E-11` | **PROVEN** | 0 | 30.29s | RED TEST: with the parent-row locks removed, the slot-claim test fails — a waiting writer takes a slot that was already taken. | [E-11.log](docs/evidence/20261007T045217Z/E-11.log) |
| `E-04` | **PROVEN** | 0 | 41.05s | Money settles exactly once under concurrent writers, and losing attempts are audited as duplicates. | [E-04.log](docs/evidence/20261007T045217Z/E-04.log) |
| `E-05` | **PROVEN** | 0 | 10.97s | A backup is encrypted with age, copied offsite, checksum-verified, and fails closed when the offsite target is unreachable. | [E-05.log](docs/evidence/20261007T045217Z/E-05.log) |
| `E-06` | **PROVEN** | 0 | 5.07s | The metrics endpoint fails closed without its feature flag and token, and exports the gauges the alerts use. | [E-06.log](docs/evidence/20261007T045217Z/E-06.log) |
| `E-07` | **PROVEN** | 0 | 103.29s | The SQLite and Redis profiles pass, so the suite is not married to one dialect or driver. | [E-07.log](docs/evidence/20261007T045217Z/E-07.log) |
| `E-08` | **PROVEN** | 0 | 5.03s | The static floor and the style gate pass — the invariants are enforced, not documented. | [E-08.log](docs/evidence/20261007T045217Z/E-08.log) |
| `E-09` | **PROVEN** | 0 | 56.47s | A worst-case reset is recoverable: the tree carries its own recorded state. | [E-09.log](docs/evidence/20261007T045217Z/E-09.log) |
| `E-10` | **PROVEN** | 0 | 28.1s | Every file this pass changed is reproducible — none is protected by nothing. | [E-10.log](docs/evidence/20261007T045217Z/E-10.log) |

**13 proven, 0 failed, 0 not run** (exit 0). `--prune` keeps the five most recent
run directories; the receipts currently on disk are listed with
`ls docs/evidence/`.

What this run is *not* evidence for stays in §3 and in
`docs/GAP-09-EXTERNAL-VERIFICATION-REGISTER.md`: E01–E27, real provider
credentials, store signing material, a live domain, a real device, and the Docker
build (no `docker` in this environment). Those are recorded as unperformed, not
as passed.
