# 🏆 FF Arena — Free Fire Tournament Platform (Full Laravel System)

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

## ▶️ লোকালি চালাতে

```bash
cd ffarena-app
composer install
cp .env.example .env      # তারপর ডাটাবেজ সেটআপ
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

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
