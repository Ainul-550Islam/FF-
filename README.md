# 🏆 FF Arena — Free Fire Tournament Platform (Full Laravel System)

[![CI](https://github.com/Ainul-550Islam/FF-/actions/workflows/ci.yml/badge.svg)](https://github.com/Ainul-550Islam/FF-/actions/workflows/ci.yml)

**Bangladesh's Free Fire tournament platform.** Legit, smart, profitable — no hacks, ever.

---

## 📦 সাইজ (MB)

| অংশ | সাইজ |
|---|---|
| নিজের লেখা কোড (app + routes + views + migrations) | ~60 KB (৩০+ ফাইল) |
| Laravel framework + vendor | ~35 MB |
| ডাটাবেজ (SQLite, ডেমো ডেটাসহ) | ~100 KB |
| **মোট** | **~৩৫–৪০ MB** |

হোস্টিং: ৫–১০ GB যেকোনো VPS/shared hosting-এ চলবে। বড় হলে স্ক্রিনশটের জন্য S3 ব্যবহার করবে।

---

## 🔑 ডেমো লগইন

| রোল | ইমেইল | পাসওয়ার্ড |
|---|---|---|
| Admin | `admin@ffarena.test` | `password` |
| Organizer | `organizer@ffarena.test` | `password` |

---

## ✅ যা যা বানানো হয়েছে (কোডে, রিয়েল)

- **Auth** — রেজিস্টার/লগইন/লগআউট, ৩ রোল (admin, organizer, player)
- **Tournament CRUD** — তৈরি, এডিট, publish, close registration
- **Team Registration** — টিম + ক্যাপ্টেন + UID + মেম্বার + স্লট লিমিট (ফুল হলে বন্ধ)
- **bKash পেমেন্ট ফ্লো** — TrxID + ভেরিফিকেশন (ডেমো মোড; লাইভে bKash Checkout API লাগবে)
- **অটো ব্র্যাকেট ইঞ্জিন** — `app/Services/BracketService.php`, single-elimination (8/16/32 টিম)
- **ম্যাচ ম্যানেজমেন্ট** — Room ID + পাসওয়ার্ড, স্কোর সাবমিশন + স্ক্রিনশট প্রুফ
- **উইনার ভেরিফিকেশন** — অর্গানাইজার উইনার ঠিক করে, ব্র্যাকেট অটো-অ্যাডভান্স
- **লিডারবোর্ড** — পয়েন্ট + কিলস + ম্যাচ হিসাব
- **অ্যাডমিন ড্যাশবোর্ড** — স্ট্যাটস, ৮% কমিশন হিসাব, পেমেন্ট approve/reject

---

## 🧱 টেক স্ট্যাক

- **Laravel 12** + PHP 8.4
- **SQLite** (ডেমো/লোকাল) → প্রোডাকশনে **PostgreSQL** (G1 মাইগ্রেশন — `G1_POSTGRESQL_PRODUCTION_MIGRATION_REPORT.md`)
- ব্লেড টেমপ্লেট + ডার্ক গেমিং থিম (ইনলাইন CSS, কোনো npm build লাগে না)

---

## 📁 মূল ফাইল স্ট্রাকচার

```
app/
├── Models/           User, Tournament, Team, TeamMember, GameMatch, Score, Payment
├── Services/BracketService.php      ← ব্র্যাকেট ইঞ্জিন
├── Policies/TournamentPolicy.php    ← অর্গানাইজার-অনলি পারমিশন
└── Http/
    ├── Controllers/  Auth, Home, Tournament, Team, Payment, Match, Admin, Leaderboard
    └── Middleware/EnsureUserIsAdmin.php
database/
├── migrations/       ৭টা টেবিল
└── seeders/DatabaseSeeder.php       ← ডেমো ডেটা
resources/views/      ১৩টা ব্লেড পেজ
routes/web.php        সব রাউট
```

---

## ▶️ লোকালি চালাতে (VS Code)

```bash
# 1. unzip / clone করা ফোল্ডারে ঢুকুন
cd FF-Arena-FINAL-SOURCE

# 2. PHP + JS dependency
composer install
npm install            # (ঐচ্ছিক — CSS/JS আগেই public/css, public/js তে বিল্ট করা আছে)

# 3. Environment — .env ফাইল ZIP-এর ভেতরেই আছে (APP_KEY সহ)
#    না থাকলে: cp .env.example .env && php artisan key:generate

# 4. Database (SQLite — কোনো সার্ভার লাগে না)
touch database/database.sqlite
php artisan migrate --seed

# 5. চালু করুন
php artisan serve          # http://127.0.0.1:8000
```

### ✅ যাচাই (verification)

```bash
php artisan route:list | wc -l                 # 527 লাইন — production ডিফল্ট (web + api + gameberry + health)
APP_ENV=local GAMEBERRY_NUMBERED_SIMULATIONS=true php artisan route:list | wc -l   # 2197 — local rehearsal-এ template clone সহ
php vendor/bin/phpunit                         # SQLite profile — 1527 tests, 5470 assertions, 0 failed, 26 skipped (the profile pins its dialect: F-40)
php vendor/bin/phpunit -c phpunit.pgsql.xml    # PostgreSQL profile (production DB) — 1532 tests, 5559 assertions, 0 failed, 3 skipped
php vendor/bin/phpunit -c phpunit.redis.xml    # Redis profile — 105 tests, 275 assertions, 0 failed, 3 skipped
python3 tools/run_required_tests.py --run --fail-on-skipped --config phpunit.pgsql.xml
                                               # required tests on the production dialect — 36/36 passed (skips are failures)

# Disaster recovery — including the encrypted-backup path (needs pg_dump + age):
php artisan ffarena:backup                     # pg_dump → age → checksum-verified offsite copy
php artisan ffarena:backup:verify --all        # checksum + integrity + offsite presence
php artisan ffarena:backup:restore <name> --identity=/mnt/escrow/backup.key

# Companion services (the same commands CI runs):
(cd services/payment-gateway-go && go vet ./... && go test -race -count=1 ./...)
tools/harden_rust_service.sh --verify          # fmt --check + clippy -D warnings + tests

# The static job's gates, verbatim:
php composer.phar audit --no-interaction        # কম্পোনেন্ট advisories (lock ফাইলের floor test সহ)
tools/harden_dependencies.sh --verify          # একই audit, reproducible script হিসেবে
php vendor/bin/pint --test                     # code style
bash scripts/ci/check-openapi.sh               # spec ↔ routes (84 documented paths)
bash scripts/ci/scan-secrets.sh                # committed secrets
bash scripts/ci/check-flutter.sh               # mobile: generated client + analyze + 86 tests (Flutter SDK লাগবে)
python3 deploy/validate-env.py --env-file .env.example --production --no-process-env   # template অবশ্যই reject হবে
```

> `php artisan test` also works, but it prints one `.env` warning per test (a
> Collision display artifact, exit code still 0). `php vendor/bin/phpunit` is the
> runner every gate and CI job uses — see finding F-13.

The three skips on the PostgreSQL profile are two Docker probes (no Docker daemon
in a plain checkout) and one SQLite-only backup scenario; every required test
runs with zero skips. See findings F-14 … F-17 in
`docs/GAP-10-FINDINGS-REGISTER.md` for what this profile caught.

### 🧹 ZIP-এ কী আছে / কী নেই

| আছে | নেই (ইচ্ছাকৃত) |
|---|---|
| `app/`, `routes/`, `config/`, `database/`, `resources/`, `tests/`, `public/` | `vendor/` (`composer install` চালালেই আসবে) |
| `composer.json` + `composer.lock`, `package.json` + `package-lock.json` | `node_modules/`, `public/build` |
| `.env` (রেডি) + `.env.example` | `.git/`, ক্যাশ, লগ, `storage/framework/*` কম্পাইলড ভিউ |
| `README.md`, `VSCODE_SETUP.md`, `docs/`, `deploy/` | `bin/k6` (65MB প্রি-বিল্ট লোড-টেস্ট বাইনারি — দরকার হলে রি-ডাউনলোড) |

> GitHub-এ পুশ করলে `.gitignore` নিজেই `vendor/`, `node_modules/`, `.env`, ক্যাশ বাদ দিবে।

---

## ⚠️ প্রোডাকশনে যাওয়ার আগে যা লাগবে (শুধু তুমি দিতে পারবে)

1. **bKash মার্চেন্ট অ্যাকাউন্ট + API কী** → `PaymentController@verify`-তে আসল Checkout API কল
2. **SMS গেটওয়ে** (Twilio/BulkSMSBD) → রুম ID/নোটিফিকেশন পাঠাতে
3. **রিয়েল সার্ভার + ডোমেইন + SSL**
4. **PostgreSQL** (SQLite → PostgreSQL কাটওভার — G1 রিপোর্টের §23 রানবুক)
5. **S3/ডিস্ক স্টোরেজ** স্ক্রিনশটের জন্য

---

## 🚫 একদম না

এই সিস্টেমে কোনো হ্যাক/চিট/স্ক্যাম ফিচার নেই এবং থাকবে না। FF Arena আসলে উল্টোটা করে — প্রতারণা ঠেকায়।

---

## 🔐 Phase 01 — Security + Authorization Hardening (সম্পন্ন)

**A–P নিরাপত্তা লক্ষ্য সবগুলো বাস্তবায়িত।** 27টি অটোমেটেড টেস্ট (82 assertions) — সব পাস।

### যা যা শক্ত করা হয়েছে
| এলাকা | কী করা হয়েছে |
|---|---|
| **A. Tournament authorization** | create/edit/update/publish/close/cancel/bracket — সব `TournamentPolicy` দিয়ে অথরাইজড |
| **B. IDOR (ক্রস-টুর্নামেন্ট)** | team/match/payment প্রতিটা রিকোয়েস্টে মূল টুর্নামেন্টের সাথে যাচাই (`belongsToTournament`) |
| **C. Team ownership** | স্কোর/পেমেন্টে `TeamPolicy` + `isCaptain()` চেক |
| **D. Team member security** | `TeamMember` ফিলএবল শুধু `player_name, game_uid`; `team_id` সার্ভার-সেট |
| **E. Registration security** | প্রতি টুর্নামেন্টে এক ক্যাপ্টেন = এক দল; স্লট ফুল চেক; ট্রানজ্যাকশনাল |
| **F. Match authorization** | রুম/উইনার শুধু অর্গানাইজার/অ্যাডমিন (`GameMatchPolicy`) |
| **G. Score submission** | অংশগ্রহণকারী চেক + মালিকানা + ডুপ্লিকেট ব্লক (DB unique constraint) |
| **H. Winner security** | উইনার অবশ্যই অংশগ্রহণকারী দল হতে হবে |
| **I. Payment security** | ভিউ/ভেরিফাই অথরাইজেশন; শুধু অ্যাডমিন ভেরিফাই/রিজেক্ট |
| **J. Admin protection** | `admin` মিডলওয়্যার (সার্ভার-সাইড) সব অ্যাডমিন রাউটে |
| **K. Mass assignment** | সব মডেলে `$fillable` কঠোর; `role`, `organizer_id`, `status`, `slug` ইত্যাদি সার্ভার-সেট |
| **L. Validation** | `role` শুধু player/organizer; সংখ্যা `min:0`; টিম_স্লট 8/16/32 |
| **M. Route model binding** | `{tournament}` slug-ভিত্তিক; নেস্টেড ম্যাচ/টিম/পেমেন্ট চেকড |
| **N. Policies** | 4টি Policy: Tournament, Team, GameMatch, Payment |
| **O. Transactional integrity** | রেজিস্ট্রেশন, পেমেন্ট, উইনার, ভেরিফাই — সব `DB::transaction` |
| **P. Info disclosure** | cross-tournament access → 404 (লুকানো); অননুমোদিত → 403 |

### টেস্ট রান
```bash
php artisan test   # 27 passed (82 assertions)
```

### নিরাপত্তা টেস্ট ফাইল
- `tests/Feature/SecurityAuthorizationTest.php` — 21টি আক্রমণ-পরিস্থিতি টেস্ট
- `tests/Feature/AuthorizedWorkflowTest.php` — 4টি বৈধ ওয়ার্কফ্লো টেস্ট

---

## 🔁 Phase 02 — Tournament Lifecycle + Registration State Machine (সম্পন্ন)

**টুর্নামেন্ট এখন নির্ভরযোগ্য স্টেট মেশিনে চলে।** 55টি টেস্ট (161 assertions) — সব পাস।

### লাইফসাইকেল
```
draft → open → closed → live → finished
  └──────┴─────────┴──→ cancelled   (finished/cancelled = terminal)
```
- `open` = published + রেজিস্ট্রেশন চলছে (একটাই স্টেট)
- transition guard সার্ভার-সাইড (`TournamentLifecycleService`) — arbitrary jump অসম্ভব
- **নতুন:** `live → finished` (Finish Tournament) — সব ম্যাচ completed থাকতে হবে

### কী কী শক্ত হয়েছে
| এলাকা | কাজ |
|---|---|
| State machine | `Tournament::TRANSITIONS` + `TournamentLifecycleService` (publish/close/start/complete/cancel) |
| Publish gate | নাম/মোড/ম্যাপ/ফি/স্লট/টিম-সাইজ + ভবিষ্যৎ `starts_at` যাচাই |
| Registration deadline | `starts_at` পেরিয়ে গেলে রেজিস্ট্রেশন বন্ধ (status primary authority) |
| Capacity (atomic) | `slotsLeft()` এখন pending+confirmed গোনে; atomic slot-claim UPDATE (SQLite-compatible) — 32→33 হয় না |
| Duplicate protection | app চেক + **নতুন DB constraint** `UNIQUE(tournament_id, captain_id)` |
| Payment gate | শুধু registration open থাকলে পেমেন্ট নেওয়া হয় |
| Withdrawal | লাইভ হওয়ার আগে ক্যাপ্টেন/অ্যাডমিন/অর্গানাইজার withdraw করতে পারে; স্লট ফ্রি হয় (রিফান্ড নেই — পরের ফেজ) |
| Visibility | পাবলিক ইনডেক্সে draft/cancelled দেখায় না |

### টেস্ট
```bash
php artisan test   # 55 passed (161 assertions)
```
- নতুন: `tests/Feature/TournamentLifecycleTest.php` — 28টি লাইফসাইকেল টেস্ট
- Phase 01-এর 27টি টেস্ট অক্ষত

### রিপোর্ট
- `PHASE02_LIFECYCLE_REPORT.md` — সম্পূর্ণ অডিট + প্রতিটি পরিবর্তিত ফাইলের পূর্ণ কনটেন্ট

---

## 👥 Phase 03 — Team + Roster Management & Competitive Integrity (সম্পন্ন)

**টিম ও রোস্টার এখন production-grade।** 76টি টেস্ট (232 assertions) — সব পাস।

### যা যোগ হয়েছে
| ফিচার | বর্ণনা |
|---|---|
| Team manage page | `teams.show` — রোস্টার, টিম ইনফো, add/remove member, edit profile |
| Roster size | `tournament.team_size` অনুযায়ী কঠোর লিমিট (atomic slot claim — SQLite-safe) |
| Duplicate prevention | এক টিমে একই UID দুবার নয় (case-insensitive normalize) + DB unique |
| Cross-team abuse | এক টুর্নামেন্টে এক UID দুই টিমে নয় (টুর্নামেন্ট-স্কোপড) + DB unique |
| UID validation | `^[A-Za-z0-9]{4,30}$` + TRIM/uppercase normalize |
| Roster lock | রেজিস্ট্রেশন `open` থাকাকালীন এডিটযোগ্য; `closed/live/finished/cancelled` = লকড (শুধু admin override) |
| Captain-only | শুধু ক্যাপ্টেন নিজের রোস্টার ম্যানেজ করে; অর্গানাইজার রোস্টার এডিট করতে পারে না |
| Cross-tournament | অন্য টুর্নামেন্টের রাউট দিয়ে টিম মডিফাই = 404 |

### নতুন সার্ভিস
- `app/Services/RosterService.php` — UID normalize, size, duplicate, cross-team, lock
- নতুন মাইগ্রেশন: `teams(tournament_id,game_uid)` + `team_members(team_id,game_uid)` unique

### টেস্ট
```bash
php artisan test   # 76 passed (232 assertions)
```
- নতুন: `tests/Feature/TeamRosterTest.php` — 21টি রোস্টার টেস্ট
- Phase 01 (27) + Phase 02 (28) টেস্ট অক্ষত

### রিপোর্ট
- `PHASE03_TEAM_ROSTER_REPORT.md` — সম্পূর্ণ অডিট + প্রতিটি ফাইলের পূর্ণ কনটেন্ট

---

## 🎟 Phase 04 — Registration + Check-in + Waitlist (সম্পন্ন)

**রেজিস্ট্রেশন এখন check-in + waitlist সহ production-grade।** 109টি টেস্ট (338 assertions) — সব পাস।

### যা যোগ হয়েছে
| ফিচার | বর্ণনা |
|---|---|
| Check-in window | `check_in_starts_at` / `check_in_ends_at` (optional) — সেট করলে check-in বাধ্যতামূলক |
| Team check-in | শুধু ক্যাপ্টেন (admin override সহ); idempotent; `checked_in_at` + `checked_in_by` অডিট |
| No-show | check-in বন্ধের পর unchecked-in কনফার্মড টিম → `no_show` (ডিলিট নয়) |
| Waitlist | টুর্নামেন্ট ফুল হলে registration → `waitlisted` (FIFO: `waitlisted_at`) |
| Promotion | অর্গানাইজার "Promote Next" → waitlisted → `pending` → payment; atomic + race-safe |
| Bracket eligibility | শুধু confirmed + checked-in টিম ব্র্যাকেটে; unchecked-in/no-show/waitlisted কখনোই না |
| Start guard | check-in open থাকলে টুর্নামেন্ট start করা যায় না |

### নতুন ফাইল
- `app/Services/TournamentParticipationService.php` — check-in, promotion, no-show
- মাইগ্রেশন: tournaments check-in window + teams `checked_in_at`/`checked_in_by`/`waitlisted_at` + index
- `tests/Feature/CheckInWaitlistTest.php` — 33টি টেস্ট

### টেস্ট
```bash
php artisan test   # 109 passed (338 assertions)
```
Phase 01 (27) + Phase 02 (28) + Phase 03 (21) টেস্ট অক্ষত।

### রিপোর্ট
- `PHASE04_CHECKIN_WAITLIST_REPORT.md` — সম্পূর্ণ অডিট + প্রতিটি ফাইলের পূর্ণ কনটেন্ট

## 🏆 Phase 05 — Advanced Tournament Engine + Brackets (সম্পন্ন)

**সিঙ্গেল + ডাবল এলিমিনেশন ব্র্যাকেট ইঞ্জিন production-grade।** 145টি টেস্ট (484 assertions) — সব পাস।

### যা যোগ হয়েছে
| ফিচার | বর্ণনা |
|---|---|
| Single Elimination | 2–N টিম; power-of-two ব্র্যাকেট + bye; 8→7 ও 16→15 ম্যাচ; deterministic seeding |
| Double Elimination | winners + losers + grand final; winner advance + loser drop; power-of-two ফিল্ড (4/8/16/32) |
| Dependency graph | `next_match_id`/`next_slot` + `loser_next_match_id`/`loser_slot` — আর `ceil(match_no/2)` নেই |
| Match state machine | `pending`/`ready`/`live`/`completed`/`disputed`/`bye`/`cancelled` + controlled transitions |
| Immutable results | completed ম্যাচ শুধু `dispute → resolve` (privileged, audited) দিয়ে বদলানো যায় |
| Format abstraction | শুধু implemented ফরম্যাট সিলেক্টযোগ্য; Round Robin/Swiss/FFA নেই |
| Server-derived winners | ক্লায়েন্ট-supplied team/winner/tournament ID কখনো বিশ্বাস করা হয় না |

### নতুন/পরিবর্তিত ফাইল (মূল)
- `app/Services/BracketService.php` — সম্পূর্ণ রিরাইট (dependency graph + byes + double elim)
- `app/Services/MatchProgressionService.php` — ম্যাচ state machine + advancement
- মাইগ্রেশন `2026_09_04_140000_add_bracket_structure.php` — format/bracket_size + dependency columns
- `app/Models/GameMatch.php`, `app/Models/Tournament.php`, `app/Http/Controllers/MatchController.php`
- `tests/Feature/BracketGenerationTest.php` (21) + `tests/Feature/MatchStateMachineTest.php` (15)

### টেস্ট
```bash
php artisan test   # 145 passed (484 assertions)
```
Phase 01 (27) + Phase 02 (28) + Phase 03 (21) + Phase 04 (33) টেস্ট অক্ষত।

### রিপোর্ট
- `PHASE05_BRACKET_REPORT.md` — সম্পূর্ণ অডিট + প্রতিটি ফাইলের পূর্ণ কনটেন্ট

---

## GAP-10 — closing the remaining gap (2026-10-06)

This section records what the GAP-10 pass changed in the repository, what it
proves, and what it deliberately does **not** claim. Everything here is
re-checkable with the commands at the end.

### What changed

| Area | Change |
| --- | --- |
| Numbered simulations (`core` / `final*` / `stats`) | The 1,375 template-generated clones are hidden behind `GAMEBERRY_NUMBERED_SIMULATIONS` (default **off**) **and** a local/testing environment check, so their routes are never registered in production. A second guard (`numbered.simulation`) refuses any mutating request that still reaches them. |
| Leaked generator fields | `production_ready`, `no_shortening`, `existing_logic_preserved`, `full_file_content`, `zero_files_omitted`, `sequential_output` are gone from the whole tree (**0** occurrences). |
| PRNG | `rand(0, 1)` (**0** left) replaced by `random_int(0, 1)` in 535 places — nothing winnable depends on a seeded PRNG. |
| Wallet locking | `GoldWallet` / `GemWallet` now lock their row with `static::query()->whereKey(...)->lockForUpdate()`; `$this->lockForUpdate()` on an instance was a silent no-op. |
| Payouts | Maker-checker dual control, a bounded reviewed reference for every manual payout, step-up password confirmation on approve/process/override/complete/fail/cancel and admin refunds, and the admin audit row moved inside the money transaction. |
| Webhooks | Correctly signed legacy callbacks are accepted again (optional timestamp, two verification schemes, scheme recorded), with replay protection intact. |
| CSP | Ships report-only with a self-hosted policy matched to the real Blade usage, plus a production gate in `deploy/validate-env.py`. |
| Settlement / reconciliation | Atomic settlement, ledger-integrity auditing, `settlements:reconcile` and `game-sessions:reconcile` (both `--dry-run`, scheduled `onOneServer()->withoutOverlapping()`), and anti-cheat evaluation with escalation into the Phase 10 incident path. |
| Backups | age encryption that fails closed, checksum-verified offsite copies, failed runs on unreachable targets, and a restore/PITR runbook (`docs/GAP-06-DISASTER-RECOVERY-RUNBOOK.md`). |
| Mobile | Release builds **fail** without real signing credentials instead of quietly falling back to the debug key. |
| CI | Eight jobs: lint/config/secrets/OpenAPI/static tests, SQLite suite (PHP 8.3 + 8.4), PostgreSQL 17 + Redis suite, integration + required-test gate, backup toolchain (`pg_dump`, `age`), Go, Rust, Flutter. |

### What is *not* claimed

* Store publication, payment-provider settlement, DNS, TLS, push delivery and
  every other item that needs a credential or a human is **not** verified. All
  27 are listed as `PENDING` in
  `docs/GAP-09-EXTERNAL-VERIFICATION-REGISTER.md`, and a static test fails if an
  unevidenced item is marked verified.
* No production host has run the deploy gate; that needs infrastructure that
  does not exist yet (E01–E03).
* Deferred findings, accepted deviations and the two justified single-test skip
  budgets are recorded in `docs/GAP-10-FINDINGS-REGISTER.md`.

### Keeping this state (read this if something looks reverted)

Edits to files that already existed can be silently rolled back when the
workspace is restored from an earlier revision — created files survive, edits do
not. That is not a test failure and it is not subtle for long: behaviour tests
start failing with "missing" errors.

```bash
# Rebuild the exact-state archive from the tree (after editing a recorded file).
python3 tools/gap10_record_state.py            # refresh; --audit also records uncovered files
python3 tools/gap10_record_state.py --check    # drift report; writes nothing

# Prove it: revert a COPY to upstream and rebuild it, then compare every hash.
python3 tools/drill_gap10_recovery.py          # 0 drift / 0 missing = recoverable

# Verify every changed file is recoverable (fails closed on anything less).
python3 tools/audit_gap10_coverage.py          # 1,566 changed, 1,565 recoverable, 0 UNPROTECTED

# Report which GAP-10 surgical edits are missing, then re-apply them.
python3 tools/gap10_reapply.py --check
python3 tools/gap10_reapply.py

# The A3 marker strip has its own tool (also audit-only by default).
python3 tools/prune_numbered_simulations.py --check
```

`tools/gap10_reapply.py` is idempotent: on a healthy tree it reports
`ok=186 applied=0 failed=0`. Two kinds of edit live in it:

* **mechanical transforms** — an anchor in an existing file is rewritten;
* **exact-state recordings** — files that are *not* a mechanical edit of an
  upstream file (controller changes, `config/*.php`, the route files, the Rust
  modules, `composer.lock`, these docs) are stored byte-for-byte, because a
  restore reverts them and nothing else can put them back. `--check` reports
  any drift from the recorded bytes.

`tools/drill_gap10_recovery.py` reverts a *copy* to upstream and rebuilds it with the
recovery tools, reporting **0 drift, 0 missing across 1,566 changed files**
(findings F-27, F-31…F-34, F-37). The drill itself is one of the 12 proofs below,
so it cannot quietly rot.
If you edit one of the recorded files, re-record it, or `--check` will (correctly)
report it as drifted.

**About the test profiles and `DB_CONNECTION`:** each profile pins the dialect it
tests with `force="true"`, because a profile that can be talked into testing a
different database reports the wrong result: `DB_CONNECTION=pgsql php
vendor/bin/phpunit` used to run the PostgreSQL database while printing "SQLite
green" (23 dialect skips silently became 3). The coordinates stay
environment-overridable — the PG profile still reads host/port/name/user from the
environment — but which dialect is being proven is the profile's to decide.

**About `php artisan test` printing a `.env` warning per test:** that is a
Collision display artifact for a `@`-suppressed read of a missing `.env`, not a
failure — `php vendor/bin/phpunit` (the runner every gate and CI job uses)
reports zero warnings, and `artisan test` still exits 0. Finding F-13 has the
evidence.

### Rebuilding this environment (read this if nothing runs)

This workspace is snapshotted and restored between sessions, and a restore takes
the host with it: PHP, PostgreSQL, Redis, `age`, the `/tmp` upstream copy, the A3
strip and `vendor/autoload.php` do not come back. That has happened four times.
The rebuild is one command, and it exists because doing it by hand is four turns
of archaeology:

```bash
bash tools/hydrate_env.sh            # packages, services, databases, deps, tree, then verify
bash tools/hydrate_env.sh --verify   # read-only health check: floor + --check + markers + audit
bash tools/hydrate_env.sh --no-apt   # skip package installation
```

It is idempotent and fail-closed: every mutating step is guarded, and the script
ends by running this repository's own gates (the static floor, `gap10_reapply.py
--check`, the marker scan and the coverage audit) and exits non-zero if any is
red. It does not invent credentials — the role and databases it creates
(`ffarena`, `ffarena_test`, `ffarena_test_alt`, `ffarena_ops`) are the local
throwaways the test profiles use. What it cannot rebuild is listed at the end of
its output and in §3 of the findings register: the Docker daemon, real provider
credentials, store signing material, a live domain, a device, and E01–E27.

### Evidence of record (run it yourself)

The claims in this section are backed by a re-runnable proof, not by prose:
`tools/prove_gap10.py` runs **13 proofs** as subprocesses and stores a receipt for
each one — the raw log, its SHA-256, the exit code, the wall-clock duration, the
SHA-256 of every artifact the claim depends on, and the tree + environment
fingerprint the run was taken against. `docs/GAP-10-EVIDENCE.md` is the rendered
report.

```bash
python3 tools/prove_gap10.py --list     # the 13 proofs and their exact commands
python3 tools/prove_gap10.py --all      # run them all; exit 0 only if all pass
python3 tools/prove_gap10.py --render   # rebuild the report from the receipts
python3 tools/prove_gap10.py --prune    # keep the 5 most recent run directories
```

Last full run — `docs/evidence/20261007T045217Z`:

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

**13 proven, 0 failed, 0 not run** (exit 0). It includes `tools/live_probe.py`, a
live HTTP probe against `php artisan serve` in production mode, and a red test
that proves the A1 guard test really detects the bypass when the guard is
sabotaged on a scratch copy of the tree. Finding F-38 has the detail, and §5 of
`docs/GAP-10-FINDINGS-REGISTER.md` mirrors this table.

### Re-check the claims

```bash
# Hosting behind a reverse proxy: spoofing blocked, real IP + https honoured.
php vendor/bin/phpunit -c phpunit.pgsql.xml --filter ProductionHostingTest

# The whole suite (expect 0 failures).
php vendor/bin/phpunit

# The tests that MUST run for a release to count.
python3 tools/run_required_tests.py --verify-manifest
python3 tools/run_required_tests.py --run --fail-on-skipped

# Guards, config invariants, CI coverage and external honesty.
python3 -m unittest discover -s tests/Static -p 'test_*.py' -v

# A3: no markers, no predictable PRNG, families accounted for.
python3 tools/prune_numbered_simulations.py --check

# The production environment gate must reject the shipped template.
python3 deploy/validate-env.py --env-file .env.example --production --no-process-env   # exit 1
```
