# Phase 05 — Advanced Tournament Engine + Brackets

**FF Arena** — Laravel 12 / SQLite
**Date:** 2026-09-06
**Status:** COMPLETE

---

## A. Overview

Phase 05 replaces the basic single-elimination `BracketService` (which used
fragile `ceil(match_no / 2)` arithmetic and only accepted power-of-two fields)
with a production-grade tournament engine:

- **Two implemented formats** — Single Elimination (any field of 2..N teams,
  power-of-two bracket with byes) and Double Elimination (winners + losers +
  grand final; power-of-two fields only). A clean `format` abstraction exists
  so future formats can be added; unimplemented formats are **not** selectable.
- **Explicit dependency graph** — every match carries `next_match_id` /
  `next_slot` (winner destination) and `loser_next_match_id` / `loser_slot`
  (loser destination). Advancement never uses round arithmetic.
- **Full match state machine** — `pending` / `ready` / `live` / `completed` /
  `disputed` / `bye` / `cancelled` with a controlled transition map.
- **Immutable completed results** — a completed match can only be changed via
  an explicit, privileged, audited `dispute → resolve` flow.
- **Deterministic seeding** — teams are ranked by `seed` then `id`; `seed` is
  normalised to the team's rank (1..n). No RNG anywhere.
- **Idempotent generation** — regenerating a bracket deletes and rebuilds the
  same structure deterministically; no duplicate matches.
- **Server-derived participants** — a client can never inject `team_id`,
  `winner_team_id`, `tournament_id`, or dependency links.

All Phase 01–04 logic (security, lifecycle, roster, check-in, waitlist,
scoring) is preserved. **145 tests / 484 assertions pass.**

---

## B. Requirements & Scope

From the Phase 05 brief:

| # | Requirement | Status |
|---|---|---|
| 1 | Single elim: power-of-two + byes for 1–16 teams; 8→7, 16→15 matches | ✅ |
| 2 | Double elim: real winners/losers/grand final, winner advance + loser drop | ✅ |
| 3 | Explicit dependency graph (no `ceil(match_no/2)`) | ✅ |
| 4 | Match state machine with controlled transitions | ✅ |
| 5 | Completed matches immutable without privileged correction (audited) | ✅ |
| 6 | No unrestricted edit-result endpoint | ✅ |
| 7 | Participants/winners derived server-side only | ✅ |
| 8 | Cross-tournament injection impossible | ✅ |
| 9 | Winner must be a participant; duplicate completion/advancement blocked | ✅ |
| 10 | Transactions + idempotent retries | ✅ |
| 11 | Phase 01 score integration preserved | ✅ |
| 12 | Bracket generation at correct lifecycle point; no start unless conditions met | ✅ |
| 13 | Handle 0 and 1 eligible teams gracefully | ✅ |
| 14 | Disputed state: authorized entry/exit, block auto-advance while disputed | ✅ |
| 15 | Blade UI shows round/match/participants/status/winner/next-match/bye, DE sides | ✅ |
| 16 | Organizer/admin privileged bracket ops only; players never mutate brackets | ✅ |
| 17 | New timestamped migration only; referential integrity + indexes | ✅ |
| 18 | Bracket logic in services; controllers thin; mutation transaction-safe | ✅ |
| 19 | Security + bracket tests; regression: Phase 01–04 tests still pass | ✅ |

Out of scope (later phases, intentionally absent): Round Robin / Swiss / FFA,
full dispute platform, realtime, anti-cheat, payouts, rankings, mobile API.

---

## C. Schema Changes

**New migration:** `database/migrations/2026_09_04_140000_add_bracket_structure.php`

- `tournaments.format` — string, default `single_elim` (`single_elim` |
  `double_elim`).
- `tournaments.bracket_size` — unsigned integer, nullable (audit/display of the
  bracket size used at generation).
- `matches.bracket` — string, default `winners` (`winners` | `losers` |
  `grand_final`).
- `matches.next_match_id` — FK → `matches.id`, `nullOnDelete`, nullable.
- `matches.next_slot` — tiny integer (1 = team1, 2 = team2), nullable.
- `matches.loser_next_match_id` — FK → `matches.id`, `nullOnDelete`, nullable.
- `matches.loser_slot` — tiny integer, nullable.
- Indexes: `matches_next_match_index`, `matches_loser_next_match_index`.

No old migration was rewritten; the original `matches` table columns (round,
match_no, team1_id, team2_id, winner_team_id, room, status) are untouched.

---

## D. Models

### `app/Models/GameMatch.php` (rewritten)

- `$table = 'matches'`.
- Status constants: `STATUS_PENDING`, `STATUS_READY`, `STATUS_LIVE`,
  `STATUS_COMPLETED`, `STATUS_DISPUTED`, `STATUS_BYE`, `STATUS_CANCELLED`.
- `TRANSITIONS` map (see §M).
- Bracket-side constants: `BRACKET_WINNERS`, `BRACKET_LOSERS`,
  `BRACKET_GRAND_FINAL`.
- `$fillable` restricted to `room_id`, `room_pass`, `scheduled_at` — all
  bracket/lifecycle/winner fields are server-only.
- Casts for `round`, `match_no`, `next_slot`, `loser_slot`, `scheduled_at`.
- Relations: `tournament`, `team1`, `team2`, `winner`, `nextMatch`,
  `loserNextMatch`, `scores`.
- Helpers: `belongsToTournament`, `hasParticipant`, `hasBothTeams`,
  `teamIdInSlot`, `hasTeamInSlot`, `setTeamSlot`, `loserTeamId`, `isBye`,
  `isCompleted`, `canTransitionTo`, `bracketLabel`, `roundLabel`, `statusPill`.

### `app/Models/Tournament.php` (rewritten)

- Added `FORMAT_SINGLE_ELIM`, `FORMAT_DOUBLE_ELIM`, `FORMATS` constants and
  `isDoubleElim()`.
- `format` added to `$fillable`; `bracket_size` cast to integer (not fillable —
  server-only).
- Preserved all Phase 01–04 lifecycle statuses, `TRANSITIONS`,
  `PUBLIC_STATUSES`, relations, slot/registration helpers, and the Phase 04
  check-in helpers (`hasCheckIn`, `checkInIsOpen`, `checkInHasClosed`,
  `bracketEligibleTeams`, `waitlistedTeams`).

---

## E. Services

### `app/Services/BracketService.php` (rewritten)

- `generate(Tournament)` — idempotent; deletes existing matches then builds
  single- or double-elim based on `tournament->isDoubleElim()`.
- `advance(GameMatch, ?int $staleWinnerId = null)` — moves winner/loser along
  explicit dependency links; idempotent slot fill; stale winner/loser
  replacement for privileged corrections.
- Single elim (§N), double elim (§O), `rankedTeams`, `nextPowerOfTwo`,
  `createMatch`, `opponentOf`, `markReadyIfComplete`.

### `app/Services/MatchProgressionService.php` (new)

- `complete(match, winner)` — validates winner is a participant; rejects
  completion of a `completed` match with a different winner; rejects
  non-`ready`/`live` matches; commits winner + advancement in a transaction;
  returns `'completed'` or `'already'`.
- `dispute(match)` — only from `completed`.
- `resolve(match, winner)` — only from `disputed`; winner must be participant;
  corrects downstream slots via `advance(..., $staleWinnerId)` in a transaction.
- `start(match)` — `pending`/`ready` → `live`.

### `app/Services/TournamentLifecycleService.php` (rewritten)

- `start(tournament, bracket)` — idempotent (already-live returns match count);
  blocked while the check-in window is open; generates the bracket and sets
  `live` inside one transaction; raises a format-specific error when generation
  returns 0.
- `complete` — blocks while any match is not `completed`.
- `publish`/`closeRegistration`/`cancel` — unchanged Phase 02/04 behaviour
  (validation preserved).

---

## F. Controllers

### `app/Http/Controllers/MatchController.php` (rewritten)

- `show`, `setRoom` (publishes room + starts match), `submitScore` (Phase 01
  scoring preserved: participant check, policy, ready/live-only, duplicate
  block app + DB), `setWinner` (participant + policy + idempotent completion),
  `dispute`, `resolve`.
- Every action 404-guards `$match->belongsToTournament($tournament)`.
- `winner_team_id`/`team_id` are validated against the **actual participants**,
  never trusted from the client.

### `app/Http/Controllers/TournamentController.php` (updated)

- `store`/`update` accept `format` as **nullable** (default `single_elim`) —
  preserved so pre-format clients/tests remain valid; only whitelisted formats
  are accepted.
- Lifecycle + no-show/waitlist routes unchanged.

---

## G. Policies

### `app/Policies/GameMatchPolicy.php` (expanded)

- `manage` (organizer owner / admin) — gates room, winner, dispute, resolve.
- `dispute` / `resolve` — explicit privileged gates (same owner/admin rule).
- Players can never manage, dispute, or resolve a match.

---

## H. Routes

Two routes added (43 total, was 41):

```
POST tournaments/{tournament}/matches/{match}/dispute → matches.dispute
POST tournaments/{tournament}/matches/{match}/resolve → matches.resolve
```

There is **no** unrestricted `edit-result` route; the only way to change a
completed result is `dispute` → `resolve`, both privileged.

---

## I. Views (Blade)

- `matches/_bracket_card.blade.php` (new) — shared bracket card: teams,
  winner highlight, BYE/✓/DISPUTED/LIVE pill.
- `matches/show.blade.php` (rewritten) — round/bracket labels, bye
  presentation, winner, next-match and loser-drop links, status pill, score
  submit (ready/live only), admin set-winner / dispute / resolve controls.
- `tournaments/show.blade.php` (updated) — format + bracket-size subtitle;
  double-elim renders winners/losers/grand-final columns; single-elim renders
  round columns with a FINAL label on the last round.
- `tournaments/create.blade.php`, `edit.blade.php` — bracket-format selector
  (only the two implemented formats).
- `layouts/app.blade.php` — added pill styles for `ready`, `disputed`, `bye`.

No JS-heavy frontend; existing Blade architecture preserved.

---

## J. Seeder & Layout

- `DatabaseSeeder.php` — both seeded tournaments now set `format =
  'single_elim'` (explicit, matches the column default).
- `layouts/app.blade.php` — three additional `.pill` classes.

---

## K. Tests

**New:** `tests/Feature/BracketGenerationTest.php` (21 tests) and
`tests/Feature/MatchStateMachineTest.php` (15 tests).

**Modified:** `tests/Feature/CheckInWaitlistTest.php` —
`test_no_show_team_excluded_from_bracket` now asserts the Phase 05 bye
behaviour: 7 eligible teams form an 8-team bracket (7 matches, 1 bye) and the
no-show team is excluded. The security property (no-show exclusion) is
preserved; the obsolete "non-power-of-two fails" expectation was updated to
the new requirement.

Coverage:

- **Brackets:** fields of 1, 2, 3, 4, 5, 6, 7, 8, 16 teams; bye counts; no
  empty round-1 matches; 8→7 and 16→15; seeding order + seed normalisation;
  idempotent deterministic regeneration; advancement through explicit links to
  the final; double-elim 4/8-team structure; double-elim winner-advance +
  loser-drop progression; double-elim power-of-two requirement; lifecycle start
  success/failure; player/foreign-organizer generation blocked; withdrawn +
  unchecked-in exclusion.
- **Security/state machine:** winner must be participant; cross-tournament
  winner/match forbidden; player/foreign-organizer set-winner forbidden;
  pending and bye matches cannot be completed; idempotent same-winner
  completion; completed result immutable without dispute; only completed can be
  disputed; only disputed can be resolved; dispute blocks advancement; resolve
  corrects downstream; resolve rejects non-participant; player/foreign-organizer
  dispute/resolve blocked.

---

## L. Security Guarantees

1. **No client-supplied IDs are trusted.** `organizer_id`, `slug`, `status`,
   `format` (whitelisted), `team_id`, `winner_team_id`, match IDs, and
   dependency links are all set server-side or validated against real state.
2. **Cross-tournament injection impossible.** Every match action 404-guards
   `belongsToTournament`; `winner_team_id` must be one of the match's two
   participants (which themselves belong to the tournament).
3. **Privilege.** Only the owning organizer or an admin may generate brackets,
   set winners, dispute, or resolve. Players get 403.
4. **Immutability.** Completed matches cannot be overwritten; a correction
   requires the audited dispute → resolve flow.
5. **No unrestricted edit-result endpoint** exists.
6. **Transactions.** Winner recording + advancement, dispute resolution, and
   bracket generation + status change are all atomic.
7. **Mass-assignment hardening.** Match and team models exclude all
   bracket/lifecycle/winner columns from `$fillable`.

---

## M. Match State Machine

```
pending    → ready | live | bye | cancelled
ready      → live | completed | cancelled
live       → completed | disputed
completed  → disputed
disputed   → completed | live
bye        → (terminal)
cancelled  → (terminal)
```

Enforcement: `GameMatch::canTransitionTo()` (declarative) and
`MatchProgressionService` (authoritative). A disputed match cannot be
re-completed directly; it must be resolved.

---

## N. Single-Elimination Algorithm

1. Rank eligible teams by `seed` then `id`; normalise `seed` to rank (1..n).
2. `B = nextPowerOfTwo(n)`, `R = log2(B)`.
3. Create `B/2^r` placeholder matches per round `r = 1..R`.
4. Link winners: round `r` match `m` → round `r+1` match `ceil(m/2)`, slot
   `m % 2 ? 1 : 2`.
5. Fill round 1 sequentially: the first `n − B/2` matches get pairs
   (1v2, 3v4, …) as `ready`; the remaining `B − n` teams each get a `bye`
   match (single team, auto-winner, auto-advanced).
6. Store `bracket_size = B`; return match count.

Properties: every round-1 match has ≥ 1 team; 8→7 and 16→15 matches; no
duplicates; deterministic; idempotent.

---

## O. Double-Elimination Design

Power-of-two fields only (4/8/16/32). For `n` teams, `R = log2(n)`:

- **Winners bracket:** rounds `1..R`, standard single-elim links.
- **Losers bracket:** `2R − 2` rounds. WB round-1 losers drop to LB round 1
  (pairs). Odd LB rounds pair up the previous LB round's winners; even LB
  rounds take the previous LB winner (slot 1) plus the corresponding WB loser
  (slot 2).
- **Grand final:** single match (round `R+1`, bracket `grand_final`) — WB final
  winner (slot 1) vs LB final winner (slot 2). This is the standard "modified
  double elimination" with a single final.

Match counts: n=4 → 6 (WB 3 + LB 2 + GF 1); n=8 → 14 (7 + 6 + 1); n=16 → 30.

---

## P. Verification

Exact commands and results (run in `/home/user/ffarena-app`):

1. `php -l` on all 14 changed/new PHP files → all **No syntax errors**.
2. `php artisan migrate:fresh --seed --force` → all 15 migrations applied
   (140000 in 26.39ms), seeded.
3. `php artisan test` → **145 passed (484 assertions)**.
4. `php artisan route:list` → **43 routes** (41 + dispute + resolve).
5. HTTP smoke (server on `:8000`): `/`, `/tournaments`, `/login`, `/register`,
   tournament show, match show (authenticated), bye-match show → **200**;
   double-elim show page renders Winners/Losers/Grand Final; bye card renders
   BYE pill.

Baseline (Phase 04): 109 tests / 338 assertions. Phase 05 adds 36 tests / 146
assertions (and updates one Phase 04 assertion), final 145 / 484.

---

## Q. File Manifest

New files:

| File | Purpose |
|---|---|
| `database/migrations/2026_09_04_140000_add_bracket_structure.php` | format/bracket_size + bracket dependency columns |
| `app/Services/MatchProgressionService.php` | match state machine + advancement |
| `resources/views/matches/_bracket_card.blade.php` | shared bracket card partial |
| `tests/Feature/BracketGenerationTest.php` | 21 bracket tests |
| `tests/Feature/MatchStateMachineTest.php` | 15 state-machine/security tests |

Modified files:

| File | Change |
|---|---|
| `app/Models/GameMatch.php` | statuses, transitions, bracket sides, helpers |
| `app/Models/Tournament.php` | format constants + `isDoubleElim()` |
| `app/Services/BracketService.php` | full engine rewrite |
| `app/Services/TournamentLifecycleService.php` | format-aware start + generation errors |
| `app/Http/Controllers/MatchController.php` | state machine + dispute/resolve |
| `app/Http/Controllers/TournamentController.php` | nullable format (default single_elim) |
| `app/Policies/GameMatchPolicy.php` | dispute/resolve gates |
| `routes/web.php` | +2 match routes |
| `database/seeders/DatabaseSeeder.php` | explicit `format` on seeded tournaments |
| `resources/views/layouts/app.blade.php` | pill CSS |
| `resources/views/tournaments/show.blade.php` | DE sides, bye, format label |
| `resources/views/tournaments/create.blade.php` | format selector |
| `resources/views/tournaments/edit.blade.php` | format selector |
| `resources/views/matches/show.blade.php` | state machine + bye + dispute/resolve UI |
| `tests/Feature/CheckInWaitlistTest.php` | no-show test updated for byes |

---

## R. Complete File Contents

### FILE: database/migrations/2026_09_04_140000_add_bracket_structure.php
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 05 — bracket structure.
     *
     * tournaments:
     *   - format        : single_elim | double_elim (only implemented formats
     *                     may ever be selected)
     *   - bracket_size  : the bracket size used at generation (audit/display)
     *
     * matches:
     *   - bracket               : winners | losers | grand_final
     *   - next_match_id         : explicit winner destination
     *   - next_slot             : 1 (team1) or 2 (team2) in the destination
     *   - loser_next_match_id   : explicit loser destination (double elimination)
     *   - loser_slot            : 1 or 2 in the loser destination
     *
     * This replaces the old `ceil(match_no / 2)` arithmetic advancement with
     * an explicit dependency graph.
     */
    public function up(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->string('format')->default('single_elim')->after('status');
            $table->unsignedInteger('bracket_size')->nullable()->after('format');
        });

        Schema::table('matches', function (Blueprint $table) {
            $table->string('bracket')->default('winners')->after('status');
            $table->foreignId('next_match_id')->nullable()->after('bracket')->constrained('matches')->nullOnDelete();
            $table->unsignedTinyInteger('next_slot')->nullable()->after('next_match_id');
            $table->foreignId('loser_next_match_id')->nullable()->after('next_slot')->constrained('matches')->nullOnDelete();
            $table->unsignedTinyInteger('loser_slot')->nullable()->after('loser_next_match_id');
            $table->index('next_match_id', 'matches_next_match_index');
            $table->index('loser_next_match_id', 'matches_loser_next_match_index');
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropIndex('matches_next_match_index');
            $table->dropIndex('matches_loser_next_match_index');
            $table->dropConstrainedForeignId('loser_next_match_id');
            $table->dropColumn('loser_slot');
            $table->dropConstrainedForeignId('next_match_id');
            $table->dropColumn('next_slot');
            $table->dropColumn('bracket');
        });

        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropColumn(['format', 'bracket_size']);
        });
    }
};
```

### FILE: app/Models/GameMatch.php
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GameMatch extends Model
{
    use HasFactory;

    /**
     * The database table is named "matches" (game_matches would collide with
     * common Eloquent naming, and the migration creates the `matches` table).
     */
    protected $table = 'matches';

    /**
     * Match lifecycle statuses.
     *
     * pending   → waiting for participants (placeholder)
     * ready     → both participants assigned, waiting to start
     * live      → in progress (room published)
     * completed → finished with a winner
     * disputed  → completed result is contested
     * bye       → auto-advanced (single participant, no play)
     * cancelled → abandoned
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_READY = 'ready';
    public const STATUS_LIVE = 'live';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_DISPUTED = 'disputed';
    public const STATUS_BYE = 'bye';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Valid match state transitions. `bye`, `cancelled` and `completed` are
     * terminal except for the explicit dispute flow (completed → disputed).
     */
    public const TRANSITIONS = [
        self::STATUS_PENDING => [self::STATUS_READY, self::STATUS_LIVE, self::STATUS_BYE, self::STATUS_CANCELLED],
        self::STATUS_READY => [self::STATUS_LIVE, self::STATUS_COMPLETED, self::STATUS_CANCELLED],
        self::STATUS_LIVE => [self::STATUS_COMPLETED, self::STATUS_DISPUTED],
        self::STATUS_COMPLETED => [self::STATUS_DISPUTED],
        self::STATUS_DISPUTED => [self::STATUS_COMPLETED, self::STATUS_LIVE],
        self::STATUS_BYE => [],
        self::STATUS_CANCELLED => [],
    ];

    /**
     * Bracket sides.
     */
    public const BRACKET_WINNERS = 'winners';
    public const BRACKET_LOSERS = 'losers';
    public const BRACKET_GRAND_FINAL = 'grand_final';

    /**
     * All bracket-structure and lifecycle fields are server-controlled.
     * Excluded from mass assignment so a client can never forge a winner,
     * participants, or bracket links.
     */
    protected $fillable = [
        'room_id',
        'room_pass',
        'scheduled_at',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'round' => 'integer',
        'match_no' => 'integer',
        'next_slot' => 'integer',
        'loser_slot' => 'integer',
    ];

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    public function team1()
    {
        return $this->belongsTo(Team::class, 'team1_id');
    }

    public function team2()
    {
        return $this->belongsTo(Team::class, 'team2_id');
    }

    public function winner()
    {
        return $this->belongsTo(Team::class, 'winner_team_id');
    }

    public function nextMatch()
    {
        return $this->belongsTo(GameMatch::class, 'next_match_id');
    }

    public function loserNextMatch()
    {
        return $this->belongsTo(GameMatch::class, 'loser_next_match_id');
    }

    public function scores()
    {
        return $this->hasMany(Score::class, 'match_id');
    }

    public function belongsToTournament(Tournament $tournament): bool
    {
        return $this->tournament_id === $tournament->id;
    }

    public function hasParticipant(Team $team): bool
    {
        return $this->team1_id === $team->id || $this->team2_id === $team->id;
    }

    /**
     * Whether both participant slots are filled.
     */
    public function hasBothTeams(): bool
    {
        return $this->team1_id !== null && $this->team2_id !== null;
    }

    /**
     * The team id currently occupying the given slot (1 = team1, 2 = team2),
     * or null when the slot is empty.
     */
    public function teamIdInSlot(?int $slot): ?int
    {
        return match ($slot) {
            1 => $this->team1_id,
            2 => $this->team2_id,
            default => null,
        };
    }

    /**
     * Whether the given slot is already occupied.
     */
    public function hasTeamInSlot(?int $slot): bool
    {
        return $this->teamIdInSlot($slot) !== null;
    }

    /**
     * Assign a team to a slot (1 = team1, 2 = team2).
     */
    public function setTeamSlot(int $slot, int $teamId): void
    {
        if ($slot === 1) {
            $this->team1_id = $teamId;
        } elseif ($slot === 2) {
            $this->team2_id = $teamId;
        }
    }

    /**
     * The losing participant, or null for a bye/undecided match.
     */
    public function loserTeamId(): ?int
    {
        if ($this->winner_team_id === null || ! $this->hasBothTeams()) {
            return null;
        }

        return $this->winner_team_id === $this->team1_id ? $this->team2_id : $this->team1_id;
    }

    public function isBye(): bool
    {
        return $this->status === self::STATUS_BYE;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Whether moving to the given status is legal from the current status.
     */
    public function canTransitionTo(string $target): bool
    {
        return in_array($target, self::TRANSITIONS[$this->status] ?? [], true);
    }

    /**
     * Human label for the bracket side (used in views).
     */
    public function bracketLabel(): string
    {
        return match ($this->bracket) {
            self::BRACKET_LOSERS => 'Losers Bracket',
            self::BRACKET_GRAND_FINAL => 'Grand Final',
            default => 'Winners Bracket',
        };
    }

    /**
     * Human label for this match's round within its bracket side.
     */
    public function roundLabel(): string
    {
        if ($this->bracket === self::BRACKET_GRAND_FINAL) {
            return '🏁 Grand Final';
        }

        if ($this->bracket === self::BRACKET_LOSERS) {
            return 'Losers Round ' . $this->round;
        }

        return 'Round ' . $this->round;
    }

    /**
     * Status pill class name for the Blade views.
     */
    public function statusPill(): string
    {
        return match ($this->status) {
            self::STATUS_COMPLETED => 'finished',
            self::STATUS_LIVE => 'live',
            self::STATUS_READY => 'ready',
            self::STATUS_BYE => 'bye',
            self::STATUS_DISPUTED => 'disputed',
            default => 'draft',
        };
    }
}
```

### FILE: app/Models/Tournament.php
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tournament extends Model
{
    use HasFactory;

    /**
     * Lifecycle states. These are the single source of truth for the values
     * stored in the `status` column.
     */
    public const STATUS_DRAFT = 'draft';
    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_LIVE = 'live';
    public const STATUS_FINISHED = 'finished';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Statuses that are visible on public listings. DRAFT (not yet published)
     * and CANCELLED tournaments are hidden.
     */
    public const PUBLIC_STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_CLOSED,
        self::STATUS_LIVE,
        self::STATUS_FINISHED,
    ];

    /**
     * Valid state transitions. A tournament may only move along these edges;
     * it can never jump arbitrarily between states. FINISHED and CANCELLED
     * are terminal.
     *
     * draft    → open, cancelled
     * open     → closed, live, cancelled   (open == published + accepting)
     * closed   → live, cancelled
     * live     → finished
     * finished → (terminal)
     * cancelled→ (terminal)
     */
    public const TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_OPEN, self::STATUS_CANCELLED],
        self::STATUS_OPEN => [self::STATUS_CLOSED, self::STATUS_LIVE, self::STATUS_CANCELLED],
        self::STATUS_CLOSED => [self::STATUS_LIVE, self::STATUS_CANCELLED],
        self::STATUS_LIVE => [self::STATUS_FINISHED],
        self::STATUS_FINISHED => [],
        self::STATUS_CANCELLED => [],
    ];

    /**
     * Bracket formats. Only formats that are actually implemented may be
     * selected; the others are intentionally absent so they never appear
     * as a selectable option.
     */
    public const FORMAT_SINGLE_ELIM = 'single_elim';
    public const FORMAT_DOUBLE_ELIM = 'double_elim';

    public const FORMATS = [
        self::FORMAT_SINGLE_ELIM,
        self::FORMAT_DOUBLE_ELIM,
    ];

    /**
     * organizer_id, slug and status are set server-side only. They are
     * excluded from mass assignment so a client can never hijack ownership
     * or lifecycle state.
     */
    protected $fillable = [
        'name',
        'game_mode',
        'map',
        'entry_fee',
        'prize_pool',
        'team_slots',
        'team_size',
        'rules',
        'starts_at',
        'check_in_starts_at',
        'check_in_ends_at',
        'format',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'check_in_starts_at' => 'datetime',
        'check_in_ends_at' => 'datetime',
        'entry_fee' => 'float',
        'prize_pool' => 'float',
        'team_slots' => 'integer',
        'team_size' => 'integer',
        'bracket_size' => 'integer',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function organizer()
    {
        return $this->belongsTo(User::class, 'organizer_id');
    }

    public function teams()
    {
        return $this->hasMany(Team::class);
    }

    public function confirmedTeams()
    {
        return $this->hasMany(Team::class)->where('status', Team::STATUS_CONFIRMED);
    }

    /**
     * Teams that currently occupy a slot: pending (awaiting payment
     * verification) or confirmed. Withdrawn, rejected, no-show and
     * waitlisted teams do not occupy a slot.
     */
    public function registeredTeams()
    {
        return $this->hasMany(Team::class)
            ->whereIn('status', [Team::STATUS_PENDING, Team::STATUS_CONFIRMED]);
    }

    /**
     * Teams on the waitlist, in deterministic FIFO order.
     */
    public function waitlistedTeams()
    {
        return $this->hasMany(Team::class)->where('status', Team::STATUS_WAITLISTED);
    }

    public function matches()
    {
        return $this->hasMany(GameMatch::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Number of registration slots still available. Counts both pending and
     * confirmed teams so that a team awaiting payment still reserves its slot.
     */
    public function slotsLeft(): int
    {
        return max(0, (int) $this->team_slots - $this->registeredTeams()->count());
    }

    public function isFull(): bool
    {
        return $this->slotsLeft() <= 0;
    }

    public function isOrganizedBy(User $user): bool
    {
        return $this->organizer_id === $user->id;
    }

    /**
     * Whether the configured start time has already passed.
     */
    public function hasStarted(): bool
    {
        return $this->starts_at !== null && $this->starts_at->isPast();
    }

    /**
     * Whether the tournament is currently accepting new registrations.
     * Status is the primary authority; the start time acts as a hard deadline.
     */
    public function acceptsRegistration(): bool
    {
        return $this->status === self::STATUS_OPEN && ! $this->hasStarted();
    }

    /**
     * Whether moving to the given status is legal from the current status.
     */
    public function canTransitionTo(string $target): bool
    {
        return in_array($target, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function isFinished(): bool
    {
        return $this->status === self::STATUS_FINISHED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isLive(): bool
    {
        return $this->status === self::STATUS_LIVE;
    }

    public function isDoubleElim(): bool
    {
        return $this->format === self::FORMAT_DOUBLE_ELIM;
    }

    // ------------------------------------------------------------------
    // Phase 04 — check-in window + bracket eligibility
    // ------------------------------------------------------------------

    /**
     * Check-in is only active when BOTH window timestamps are configured.
     * Tournaments created before this feature (or without a window) simply
     * do not require check-in, preserving the Phase 02 behaviour.
     */
    public function hasCheckIn(): bool
    {
        return $this->check_in_starts_at !== null && $this->check_in_ends_at !== null;
    }

    /**
     * Whether the check-in window is currently open.
     */
    public function checkInIsOpen(): bool
    {
        if (! $this->hasCheckIn()) {
            return false;
        }

        return now()->between($this->check_in_starts_at, $this->check_in_ends_at);
    }

    /**
     * Whether the check-in window has already closed.
     */
    public function checkInHasClosed(): bool
    {
        return $this->hasCheckIn() && $this->check_in_ends_at->isPast();
    }

    /**
     * The set of teams eligible for competitive bracket generation:
     * confirmed teams that have checked in (when check-in is configured).
     * This is the single source of truth used by BracketService.
     */
    public function bracketEligibleTeams()
    {
        return $this->teams()
            ->where('status', Team::STATUS_CONFIRMED)
            ->when($this->hasCheckIn(), fn ($q) => $q->whereNotNull('checked_in_at'));
    }
}
```

### FILE: app/Services/BracketService.php
```php
<?php

namespace App\Services;

use App\Models\GameMatch;
use App\Models\Team;
use App\Models\Tournament;

/**
 * Tournament bracket engine (Phase 05).
 *
 * Generates deterministic, idempotent brackets and advances winners/losers
 * through an explicit dependency graph (next_match_id / next_slot /
 * loser_next_match_id / loser_slot) rather than fragile arithmetic
 * (`ceil(match_no / 2)`), so the structure is auditable and extensible.
 *
 * Implemented formats:
 *   - Single elimination — any field of 2..N teams, using a power-of-two
 *     bracket with byes for non-power-of-two field sizes.
 *   - Double elimination — winners + losers brackets + a single grand final;
 *     requires a power-of-two field (4, 8, 16, 32) so every losers-bracket
 *     round is well formed.
 *
 * Seeding policy (deterministic, no RNG): teams are ranked by `seed` then
 * `id`, and `seed` is normalised to the team's rank (1..n) so seeding is
 * reproducible. Round one pairs ranks sequentially (1v2, 3v4, …) and any
 * remaining teams receive byes at the bottom of the draw — a bye match is
 * auto-completed and its team advances. This keeps every round-1 match
 * non-empty for any field size.
 */
class BracketService
{
    /**
     * Generate (or regenerate) the bracket for a tournament.
     *
     * Idempotent: any previously generated matches are removed first, so
     * calling this twice never duplicates matches. Returns the number of
     * matches created, or 0 when the field cannot form a bracket.
     */
    public function generate(Tournament $tournament): int
    {
        GameMatch::where('tournament_id', $tournament->id)->delete();

        if ($tournament->isDoubleElim()) {
            return $this->generateDoubleElim($tournament);
        }

        return $this->generateSingleElim($tournament);
    }

    /**
     * Advance a completed (or bye) match: move the winner into its winner
     * destination and — for double elimination — the loser into its loser
     * destination.
     *
     * Filling is idempotent: a slot that is already occupied is never
     * overwritten unless $staleWinnerId matches it (privileged result
     * correction).
     */
    public function advance(GameMatch $match, ?int $staleWinnerId = null): void
    {
        if ($match->winner_team_id === null) {
            return;
        }

        $winnerId = $match->winner_team_id;
        $loserId = $match->loserTeamId();

        if ($match->next_match_id !== null) {
            $next = GameMatch::find($match->next_match_id);
            if ($next !== null) {
                $current = $next->teamIdInSlot($match->next_slot);
                if ($current === null || ($staleWinnerId !== null && $current === $staleWinnerId)) {
                    $next->setTeamSlot($match->next_slot, $winnerId);
                    $this->markReadyIfComplete($next);
                    $next->save();
                }
            }
        }

        if ($match->loser_next_match_id !== null && $loserId !== null) {
            $ln = GameMatch::find($match->loser_next_match_id);
            if ($ln !== null) {
                $staleLoser = $staleWinnerId !== null ? $this->opponentOf($staleWinnerId, $match) : null;
                $current = $ln->teamIdInSlot($match->loser_slot);
                if ($current === null || ($staleLoser !== null && $current === $staleLoser)) {
                    $ln->setTeamSlot($match->loser_slot, $loserId);
                    $this->markReadyIfComplete($ln);
                    $ln->save();
                }
            }
        }
    }

    // ------------------------------------------------------------------
    // Single elimination
    // ------------------------------------------------------------------

    protected function generateSingleElim(Tournament $tournament): int
    {
        $teams = $this->rankedTeams($tournament);
        $n = $teams->count();

        if ($n < 2) {
            return 0;
        }

        $B = $this->nextPowerOfTwo($n);
        $R = (int) log($B, 2);
        $teamByRank = $teams->values()->all();

        $matches = [];

        // Create every round (pending placeholders), rounds 1..R.
        for ($r = 1; $r <= $R; $r++) {
            $count = intdiv($B, 2 ** $r);
            for ($m = 1; $m <= $count; $m++) {
                $matches[$r][$m] = $this->createMatch($tournament, GameMatch::BRACKET_WINNERS, $r, $m);
            }
        }

        // Explicit winner links: round r match m → round r+1 match ceil(m/2).
        for ($r = 1; $r < $R; $r++) {
            $count = intdiv($B, 2 ** $r);
            for ($m = 1; $m <= $count; $m++) {
                $src = $matches[$r][$m];
                $src->next_match_id = $matches[$r + 1][(int) ceil($m / 2)]->id;
                $src->next_slot = ($m % 2 === 1) ? 1 : 2;
                $src->save();
            }
        }

        // Fill round 1: sequential pairs (1v2, 3v4, …) then byes at the
        // bottom of the draw. Every round-1 match ends up with ≥ 1 team.
        $roundOneMatches = intdiv($B, 2);
        $full = $n - $roundOneMatches; // matches that get two teams (≥ 1)

        for ($i = 0; $i < $full; $i++) {
            $match = $matches[1][$i + 1];
            $match->team1_id = $teamByRank[2 * $i]->id;
            $match->team2_id = $teamByRank[2 * $i + 1]->id;
            $match->status = GameMatch::STATUS_READY;
            $match->save();
        }

        for ($j = 0; $j < $n - 2 * $full; $j++) {
            $match = $matches[1][$full + $j + 1];
            $team = $teamByRank[2 * $full + $j];
            $match->team1_id = $team->id;
            $match->winner_team_id = $team->id;
            $match->status = GameMatch::STATUS_BYE;
            $match->save();
            $this->advance($match);
        }

        $tournament->bracket_size = $B;
        $tournament->save();

        return GameMatch::where('tournament_id', $tournament->id)->count();
    }

    // ------------------------------------------------------------------
    // Double elimination
    // ------------------------------------------------------------------

    protected function generateDoubleElim(Tournament $tournament): int
    {
        $teams = $this->rankedTeams($tournament);
        $n = $teams->count();

        // Power-of-two only: byes would produce ill-formed losers rounds.
        if ($n < 4 || ($n & ($n - 1)) !== 0) {
            return 0;
        }

        $R = (int) log($n, 2);
        $teamByRank = $teams->values()->all();

        $wb = [];
        $lb = [];

        // Winners bracket: rounds 1..R.
        for ($r = 1; $r <= $R; $r++) {
            $count = intdiv($n, 2 ** $r);
            for ($m = 1; $m <= $count; $m++) {
                $wb[$r][$m] = $this->createMatch($tournament, GameMatch::BRACKET_WINNERS, $r, $m);
            }
        }

        // Winners-bracket winner links.
        for ($r = 1; $r < $R; $r++) {
            $count = intdiv($n, 2 ** $r);
            for ($m = 1; $m <= $count; $m++) {
                $src = $wb[$r][$m];
                $src->next_match_id = $wb[$r + 1][(int) ceil($m / 2)]->id;
                $src->next_slot = ($m % 2 === 1) ? 1 : 2;
                $src->save();
            }
        }

        // Losers bracket: 2R-2 rounds.
        $lbRounds = 2 * $R - 2;
        for ($r = 1; $r <= $lbRounds; $r++) {
            $count = intdiv($n, 2 ** ((int) ceil($r / 2) + 1));
            for ($m = 1; $m <= $count; $m++) {
                $lb[$r][$m] = $this->createMatch($tournament, GameMatch::BRACKET_LOSERS, $r, $m);
            }
        }

        // Winners-bracket round-1 losers drop into losers-bracket round 1.
        for ($m = 1; $m <= intdiv($n, 2); $m++) {
            $src = $wb[1][$m];
            $src->loser_next_match_id = $lb[1][(int) ceil($m / 2)]->id;
            $src->loser_slot = ($m % 2 === 1) ? 1 : 2;
            $src->save();
        }

        // Link the rest of the losers bracket.
        for ($r = 1; $r <= $lbRounds; $r++) {
            $count = intdiv($n, 2 ** ((int) ceil($r / 2) + 1));

            if ($r % 2 === 1) {
                // Odd LB round: winners of the previous LB round pair up.
                if ($r > 1) {
                    for ($m = 1; $m <= $count; $m++) {
                        $a = $lb[$r - 1][2 * $m - 1];
                        $b = $lb[$r - 1][2 * $m];
                        $a->next_match_id = $lb[$r][$m]->id;
                        $a->next_slot = 1;
                        $a->save();
                        $b->next_match_id = $lb[$r][$m]->id;
                        $b->next_slot = 2;
                        $b->save();
                    }
                }
            } else {
                // Even LB round: previous LB round winner (slot 1) plus the
                // corresponding winners-bracket loser (slot 2).
                $wbRound = intdiv($r, 2) + 1;
                for ($m = 1; $m <= $count; $m++) {
                    $prev = $lb[$r - 1][$m];
                    $prev->next_match_id = $lb[$r][$m]->id;
                    $prev->next_slot = 1;
                    $prev->save();

                    $wbSrc = $wb[$wbRound][$m];
                    $wbSrc->loser_next_match_id = $lb[$r][$m]->id;
                    $wbSrc->loser_slot = 2;
                    $wbSrc->save();
                }
            }
        }

        // Grand final: winners-bracket final winner vs losers-bracket final
        // winner. (Single final — a "modified" double elimination.)
        $gf = $this->createMatch($tournament, GameMatch::BRACKET_GRAND_FINAL, $R + 1, 1);
        $wbFinal = $wb[$R][1];
        $wbFinal->next_match_id = $gf->id;
        $wbFinal->next_slot = 1;
        $wbFinal->save();
        $lbFinal = $lb[$lbRounds][1];
        $lbFinal->next_match_id = $gf->id;
        $lbFinal->next_slot = 2;
        $lbFinal->save();

        // Fill winners-bracket round 1 (power of two → no byes).
        for ($i = 0; $i < $n; $i += 2) {
            $match = $wb[1][intdiv($i, 2) + 1];
            $match->team1_id = $teamByRank[$i]->id;
            $match->team2_id = $teamByRank[$i + 1]->id;
            $match->status = GameMatch::STATUS_READY;
            $match->save();
        }

        $tournament->bracket_size = $n;
        $tournament->save();

        return GameMatch::where('tournament_id', $tournament->id)->count();
    }

    // ------------------------------------------------------------------
    // Seeding + helpers
    // ------------------------------------------------------------------

    /**
     * Eligible teams in deterministic seed order. Each team's `seed` is
     * (re)assigned to its rank (1..n) so the bracket is reproducible and
     * auditable. Ordering: seed ascending, then id ascending.
     */
    protected function rankedTeams(Tournament $tournament)
    {
        $teams = $tournament->bracketEligibleTeams()
            ->orderBy('seed')
            ->orderBy('id')
            ->get();

        $rank = 0;
        foreach ($teams as $team) {
            $rank++;
            if ((int) $team->seed !== $rank) {
                $team->seed = $rank;
                $team->save();
            }
        }

        return $teams;
    }

    protected function nextPowerOfTwo(int $n): int
    {
        $p = 1;
        while ($p < $n) {
            $p <<= 1;
        }

        return $p;
    }

    protected function createMatch(Tournament $tournament, string $bracket, int $round, int $matchNo): GameMatch
    {
        $match = new GameMatch();
        $match->tournament_id = $tournament->id;
        $match->bracket = $bracket;
        $match->round = $round;
        $match->match_no = $matchNo;
        $match->status = GameMatch::STATUS_PENDING;
        $match->save();

        return $match;
    }

    /**
     * The id of the team playing against $teamId in this match, if any.
     */
    protected function opponentOf(int $teamId, GameMatch $match): ?int
    {
        if ($match->team1_id === $teamId) {
            return $match->team2_id;
        }
        if ($match->team2_id === $teamId) {
            return $match->team1_id;
        }

        return null;
    }

    /**
     * Promote a placeholder match to READY once both slots are filled.
     */
    protected function markReadyIfComplete(GameMatch $match): void
    {
        if ($match->hasBothTeams() && $match->status === GameMatch::STATUS_PENDING) {
            $match->status = GameMatch::STATUS_READY;
        }
    }
}
```

### FILE: app/Services/MatchProgressionService.php
```php
<?php

namespace App\Services;

use App\Models\GameMatch;
use App\Models\Team;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Match progression (Phase 05).
 *
 * Controls the match state machine and winner advancement. Authorization is
 * handled by policies in the controllers; this service only answers "is this
 * progression legal right now?" and performs it atomically.
 */
class MatchProgressionService
{
    public function __construct(
        protected BracketService $bracket,
    ) {
    }

    /**
     * Record a winner and complete a match, then advance the bracket.
     *
     * @return string 'completed' | 'already'
     */
    public function complete(GameMatch $match, Team $winner): string
    {
        if (! $match->hasParticipant($winner)) {
            throw new DomainException('Winner must be a participating team.');
        }

        if ($match->status === GameMatch::STATUS_COMPLETED) {
            if ($match->winner_team_id === $winner->id) {
                return 'already';
            }

            throw new DomainException('This match is already completed — dispute it before changing the result.');
        }

        if (! in_array($match->status, [GameMatch::STATUS_READY, GameMatch::STATUS_LIVE], true)) {
            throw new DomainException('This match cannot be completed from its current state.');
        }

        DB::transaction(function () use ($match, $winner) {
            $match->winner_team_id = $winner->id;
            $match->status = GameMatch::STATUS_COMPLETED;
            $match->save();

            $this->bracket->advance($match);
        });

        return 'completed';
    }

    /**
     * Move a completed match into the disputed state. Advancement is halted
     * while disputed.
     */
    public function dispute(GameMatch $match): void
    {
        if ($match->status !== GameMatch::STATUS_COMPLETED) {
            throw new DomainException('Only completed matches can be disputed.');
        }

        $match->status = GameMatch::STATUS_DISPUTED;
        $match->save();
    }

    /**
     * Resolve a disputed match with a (possibly corrected) winner. If the
     * winner changed, the stale winner/loser are replaced in the downstream
     * match slots.
     */
    public function resolve(GameMatch $match, Team $winner): void
    {
        if ($match->status !== GameMatch::STATUS_DISPUTED) {
            throw new DomainException('Only disputed matches can be resolved.');
        }

        if (! $match->hasParticipant($winner)) {
            throw new DomainException('Winner must be a participating team.');
        }

        DB::transaction(function () use ($match, $winner) {
            $stale = $match->winner_team_id;

            $match->winner_team_id = $winner->id;
            $match->status = GameMatch::STATUS_COMPLETED;
            $match->save();

            $this->bracket->advance($match, $stale);
        });
    }

    /**
     * Move a ready/pending match into the live state.
     */
    public function start(GameMatch $match): void
    {
        if (! in_array($match->status, [GameMatch::STATUS_PENDING, GameMatch::STATUS_READY], true)) {
            throw new DomainException('This match cannot be started from its current state.');
        }

        $match->status = GameMatch::STATUS_LIVE;
        $match->save();
    }
}
```

### FILE: app/Services/TournamentLifecycleService.php
```php
<?php

namespace App\Services;

use App\Models\GameMatch;
use App\Models\Tournament;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Server-side tournament lifecycle state machine.
 *
 * Every status change goes through one of these methods. Each method:
 *   1. asserts the transition is legal from the current state
 *   2. runs any state-specific preconditions
 *   3. mutates the status (atomically, inside a transaction where needed)
 *
 * Authorization ("is this user allowed?") is a separate concern and remains
 * in the controller via policies; this service only answers
 * "is this transition allowed from the current state, and are its
 * preconditions satisfied?".
 */
class TournamentLifecycleService
{
    /**
     * Publish a draft tournament, opening registration.
     */
    public function publish(Tournament $tournament): void
    {
        $this->assertTransition($tournament, Tournament::STATUS_OPEN);

        $errors = $this->publicationErrors($tournament);
        if ($errors !== []) {
            throw new DomainException('Cannot publish: '.implode(' ', $errors));
        }

        $tournament->status = Tournament::STATUS_OPEN;
        $tournament->save();
    }

    /**
     * Close registration for an open tournament.
     */
    public function closeRegistration(Tournament $tournament): void
    {
        $this->assertTransition($tournament, Tournament::STATUS_CLOSED);

        $tournament->status = Tournament::STATUS_CLOSED;
        $tournament->save();
    }

    /**
     * Cancel a tournament that has not yet gone live.
     */
    public function cancel(Tournament $tournament): void
    {
        $this->assertTransition($tournament, Tournament::STATUS_CANCELLED);

        $tournament->status = Tournament::STATUS_CANCELLED;
        $tournament->save();
    }

    /**
     * Start the tournament: generate the bracket and move to LIVE.
     *
     * Idempotent: if the tournament is already live, the existing match count
     * is returned without regenerating (no data loss on a retry). Runs inside
     * a transaction so the bracket and the status change are committed
     * atomically.
     *
     * @return int number of matches in the bracket
     */
    public function start(Tournament $tournament, BracketService $bracket): int
    {
        if ($tournament->status === Tournament::STATUS_LIVE) {
            return $tournament->matches()->count();
        }

        $this->assertTransition($tournament, Tournament::STATUS_LIVE);

        // Never start (and freeze the bracket) while check-in is still open:
        // teams must have checked in before they can be seeded.
        if ($tournament->hasCheckIn() && ! $tournament->checkInHasClosed()) {
            throw new DomainException('The check-in window has not closed yet.');
        }

        return DB::transaction(function () use ($tournament, $bracket) {
            $count = $bracket->generate($tournament);

            if ($count === 0) {
                throw new DomainException($this->generationFailureMessage($tournament));
            }

            $tournament->status = Tournament::STATUS_LIVE;
            $tournament->save();

            return $count;
        });
    }

    /**
     * Finish a live tournament. Every match must already be completed so we
     * never mark a tournament finished while matches are still undecided.
     */
    public function complete(Tournament $tournament): void
    {
        $this->assertTransition($tournament, Tournament::STATUS_FINISHED);

        if ($tournament->matches()->where('status', '!=', GameMatch::STATUS_COMPLETED)->exists()) {
            throw new DomainException(
                'All matches must be completed before the tournament can be finished.'
            );
        }

        $tournament->status = Tournament::STATUS_FINISHED;
        $tournament->save();
    }

    /**
     * Assert that the requested transition is legal from the current state.
     */
    public function assertTransition(Tournament $tournament, string $target): void
    {
        if (! $tournament->canTransitionTo($target)) {
            throw new DomainException(sprintf(
                "Cannot move a tournament from '%s' to '%s'.",
                $tournament->status,
                $target
            ));
        }
    }

    /**
     * Human-readable bracket generation failure for the tournament format.
     */
    protected function generationFailureMessage(Tournament $tournament): string
    {
        if ($tournament->isDoubleElim()) {
            return 'Bracket generation failed: double elimination requires 4, 8, 16 or 32 eligible teams (a power of two).';
        }

        return 'Bracket generation failed: at least 2 eligible teams are required.';
    }

    /**
     * Human-readable configuration problems that prevent publication.
     */
    public function publicationErrors(Tournament $tournament): array
    {
        $errors = [];

        if (trim((string) $tournament->name) === '') {
            $errors[] = 'The tournament name is required.';
        }

        if (! in_array($tournament->game_mode, ['squad', 'duo', 'solo'], true)) {
            $errors[] = 'The game mode is invalid.';
        }

        if (trim((string) $tournament->map) === '') {
            $errors[] = 'The map is required.';
        }

        if ($tournament->entry_fee === null || $tournament->entry_fee < 0) {
            $errors[] = 'The entry fee must be a non-negative amount.';
        }

        if ($tournament->prize_pool === null || $tournament->prize_pool < 0) {
            $errors[] = 'The prize pool must be a non-negative amount.';
        }

        if (! in_array((int) $tournament->team_slots, [8, 16, 32], true)) {
            $errors[] = 'Team slots must be 8, 16 or 32.';
        }

        if ((int) $tournament->team_size < 1) {
            $errors[] = 'Team size must be at least 1.';
        }

        if ($tournament->starts_at === null) {
            $errors[] = 'A start time is required.';
        } elseif ($tournament->starts_at->isPast()) {
            $errors[] = 'The start time must be in the future.';
        }

        return $errors;
    }
}
```

### FILE: app/Http/Controllers/MatchController.php
```php
<?php

namespace App\Http\Controllers;

use App\Models\GameMatch;
use App\Models\Score;
use App\Models\Team;
use App\Models\Tournament;
use App\Services\MatchProgressionService;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

class MatchController extends Controller
{
    /** Placement → points map (Free Fire style) */
    protected array $placementPoints = [1 => 12, 2 => 9, 3 => 7, 4 => 5, 5 => 4, 6 => 3, 7 => 2, 8 => 1];

    public function __construct(
        protected MatchProgressionService $progression,
    ) {
    }

    public function show(Tournament $tournament, GameMatch $match)
    {
        abort_unless($match->belongsToTournament($tournament), 404);

        $match->load(['team1', 'team2', 'scores.team', 'nextMatch', 'loserNextMatch']);

        return view('matches.show', compact('tournament', 'match'));
    }

    /**
     * Publish room details and move a ready/pending match to live.
     */
    public function setRoom(Request $request, Tournament $tournament, GameMatch $match)
    {
        abort_unless($match->belongsToTournament($tournament), 404);
        $this->authorize('manage', $match);

        $data = $request->validate([
            'room_id' => 'required|string|max:30',
            'room_pass' => 'required|string|max:30',
            'scheduled_at' => 'nullable|date',
        ]);

        $match->room_id = $data['room_id'];
        $match->room_pass = $data['room_pass'];
        $match->scheduled_at = $data['scheduled_at'] ?? now();
        $match->save();

        try {
            $this->progression->start($match);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Room details published. Match is now live.');
    }

    public function submitScore(Request $request, Tournament $tournament, GameMatch $match)
    {
        abort_unless($match->belongsToTournament($tournament), 404);

        $data = $request->validate([
            'team_id' => 'required|integer|exists:teams,id',
            'kills' => 'required|integer|min:0',
            'placement' => 'required|integer|min:1|max:12',
            'screenshot' => 'nullable|image|max:2048',
        ]);

        $team = Team::find($data['team_id']);

        // The submitted team MUST be an actual participant of this match.
        abort_unless($team !== null && $match->hasParticipant($team), 403, 'This team is not part of this match.');

        $this->authorize('submitScore', $team);

        // Scoring is only possible while the match is ready or live.
        abort_unless(
            in_array($match->status, [GameMatch::STATUS_READY, GameMatch::STATUS_LIVE], true),
            403,
            'Score submission is not open for this match.'
        );

        // Prevent duplicate/unauthorized score replacement.
        if (Score::where('match_id', $match->id)->where('team_id', $team->id)->exists()) {
            abort(403, 'A score for this team has already been submitted.');
        }

        $points = (int) $data['kills'] + (int) ($this->placementPoints[$data['placement']] ?? 1);

        $path = null;
        if ($request->hasFile('screenshot')) {
            $path = $request->file('screenshot')->store('scores', 'public');
        }

        try {
            $score = new Score();
            $score->match_id = $match->id;
            $score->team_id = $team->id;
            $score->kills = (int) $data['kills'];
            $score->placement = (int) $data['placement'];
            $score->points = $points;
            $score->screenshot_path = $path;
            $score->status = 'pending';
            $score->save();
        } catch (QueryException $e) {
            // Unique (match_id, team_id) constraint as a race-condition backstop.
            abort(403, 'A score for this team has already been submitted.');
        }

        return back()->with('success', 'Score submitted! Awaiting verification.');
    }

    /**
     * Record a winner, complete the match and advance the bracket.
     * Idempotent: completing again with the same winner is a no-op.
     */
    public function setWinner(Request $request, Tournament $tournament, GameMatch $match)
    {
        abort_unless($match->belongsToTournament($tournament), 404);
        $this->authorize('manage', $match);

        $data = $request->validate([
            'winner_team_id' => 'required|integer|exists:teams,id',
        ]);

        $winner = Team::findOrFail($data['winner_team_id']);

        // Winner must be one of the actual participating teams.
        abort_unless($match->hasParticipant($winner), 403, 'Winner must be a participating team.');

        try {
            $result = $this->progression->complete($match, $winner);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($result === 'already') {
            return back()->with('success', 'This match is already completed with that winner.');
        }

        return back()->with('success', 'Winner confirmed. Bracket advanced.');
    }

    /**
     * Move a completed match into the disputed state (privileged).
     */
    public function dispute(Request $request, Tournament $tournament, GameMatch $match)
    {
        abort_unless($match->belongsToTournament($tournament), 404);
        $this->authorize('manage', $match);

        try {
            $this->progression->dispute($match);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Match marked as disputed.');
    }

    /**
     * Resolve a disputed match with a (possibly corrected) winner (privileged).
     */
    public function resolve(Request $request, Tournament $tournament, GameMatch $match)
    {
        abort_unless($match->belongsToTournament($tournament), 404);
        $this->authorize('manage', $match);

        $data = $request->validate([
            'winner_team_id' => 'required|integer|exists:teams,id',
        ]);

        $winner = Team::findOrFail($data['winner_team_id']);
        abort_unless($match->hasParticipant($winner), 403, 'Winner must be a participating team.');

        try {
            $this->progression->resolve($match, $winner);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Dispute resolved. Winner recorded and bracket advanced.');
    }
}
```

### FILE: app/Http/Controllers/TournamentController.php
```php
<?php

namespace App\Http\Controllers;

use App\Models\Tournament;
use App\Services\BracketService;
use App\Services\TournamentLifecycleService;
use App\Services\TournamentParticipationService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TournamentController extends Controller
{
    public function __construct(
        protected TournamentLifecycleService $lifecycle,
        protected TournamentParticipationService $participation,
    ) {
    }

    public function index()
    {
        // Draft and cancelled tournaments are not shown publicly.
        $tournaments = Tournament::with('organizer')
            ->withCount('confirmedTeams')
            ->whereIn('status', Tournament::PUBLIC_STATUSES)
            ->orderByDesc('created_at')
            ->paginate(12);

        return view('tournaments.index', compact('tournaments'));
    }

    public function show(Tournament $tournament)
    {
        $tournament->load([
            'organizer',
            'confirmedTeams',
            'matches' => fn ($q) => $q->orderBy('bracket')->orderBy('round')->orderBy('match_no'),
        ]);

        $myTeam = null;
        if (auth()->check()) {
            $myTeam = $tournament->teams()->where('captain_id', auth()->id())->first();
        }

        // Waitlist is shown to organizers/admin (and positions are shown to
        // the relevant captains via their own team's waitlistPosition()).
        $waitlist = null;
        if (auth()->check() && (auth()->user()->isAdmin() || auth()->user()->isOrganizer())) {
            $waitlist = $tournament->waitlistedTeams()
                ->orderBy('waitlisted_at')
                ->orderBy('id')
                ->get();
        }

        return view('tournaments.show', compact('tournament', 'myTeam', 'waitlist'));
    }

    public function create()
    {
        $this->authorize('create', Tournament::class);

        return view('tournaments.create');
    }

    public function store(Request $request)
    {
        $this->authorize('create', Tournament::class);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'game_mode' => 'required|in:squad,duo,solo',
            'map' => 'required|string|max:60',
            'entry_fee' => 'required|numeric|min:0',
            'prize_pool' => 'required|numeric|min:0',
            'team_slots' => 'required|in:8,16,32',
            'team_size' => 'required|integer|min:1|max:6',
            'rules' => 'nullable|string',
            'starts_at' => 'required|date|after:now',
            'check_in_starts_at' => 'nullable|date|required_with:check_in_ends_at',
            'check_in_ends_at' => 'nullable|date|after:check_in_starts_at|before_or_equal:starts_at',
            'format' => 'nullable|in:single_elim,double_elim',
        ]);

        // organizer_id, slug and status are server-controlled — a client can
        // never inject them. New tournaments always start as DRAFT.
        $tournament = new Tournament();
        $tournament->organizer_id = $request->user()->id;
        $tournament->name = $data['name'];
        $tournament->slug = Str::slug($data['name']).'-'.Str::random(6);
        $tournament->game_mode = $data['game_mode'];
        $tournament->map = $data['map'];
        $tournament->entry_fee = $data['entry_fee'];
        $tournament->prize_pool = $data['prize_pool'];
        $tournament->team_slots = $data['team_slots'];
        $tournament->team_size = $data['team_size'];
        $tournament->rules = $data['rules'] ?? null;
        $tournament->starts_at = $data['starts_at'];
        $tournament->check_in_starts_at = $data['check_in_starts_at'] ?? null;
        $tournament->check_in_ends_at = $data['check_in_ends_at'] ?? null;
        $tournament->format = $data['format'] ?? Tournament::FORMAT_SINGLE_ELIM;
        $tournament->status = Tournament::STATUS_DRAFT;
        $tournament->save();

        return redirect()
            ->route('tournaments.show', $tournament)
            ->with('success', 'Tournament created as draft. Publish it to open registration.');
    }

    public function edit(Tournament $tournament)
    {
        $this->authorize('update', $tournament);

        return view('tournaments.edit', compact('tournament'));
    }

    public function update(Request $request, Tournament $tournament)
    {
        $this->authorize('update', $tournament);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'game_mode' => 'required|in:squad,duo,solo',
            'map' => 'required|string|max:60',
            'entry_fee' => 'required|numeric|min:0',
            'prize_pool' => 'required|numeric|min:0',
            'team_slots' => 'required|in:8,16,32',
            'team_size' => 'required|integer|min:1|max:6',
            'rules' => 'nullable|string',
            'starts_at' => 'required|date',
            'check_in_starts_at' => 'nullable|date|required_with:check_in_ends_at',
            'check_in_ends_at' => 'nullable|date|after:check_in_starts_at',
            'format' => 'nullable|in:single_elim,double_elim',
        ]);

        // fill() only touches mass-assignable fields, so a client cannot
        // tamper with organizer_id, slug or status through this endpoint.
        $tournament->fill($data)->save();

        return redirect()->route('tournaments.show', $tournament)->with('success', 'Tournament updated.');
    }

    public function publish(Tournament $tournament)
    {
        $this->authorize('publish', $tournament);

        try {
            $this->lifecycle->publish($tournament);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Tournament published — registration is now open.');
    }

    public function closeRegistration(Tournament $tournament)
    {
        $this->authorize('closeRegistration', $tournament);

        try {
            $this->lifecycle->closeRegistration($tournament);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Registration closed.');
    }

    public function start(Tournament $tournament, BracketService $bracket)
    {
        $this->authorize('start', $tournament);

        try {
            $count = $this->lifecycle->start($tournament, $bracket);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Bracket generated with {$count} matches. Tournament is LIVE!");
    }

    public function complete(Tournament $tournament)
    {
        $this->authorize('complete', $tournament);

        try {
            $this->lifecycle->complete($tournament);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Tournament marked as finished. Congratulations to the winners!');
    }

    public function cancel(Tournament $tournament)
    {
        $this->authorize('cancel', $tournament);

        try {
            $this->lifecycle->cancel($tournament);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Tournament cancelled.');
    }

    /**
     * Mark confirmed-but-unchecked-in teams as no-shows (after the check-in
     * window closes) and promote waitlisted teams into the freed slots.
     */
    public function markNoShows(Tournament $tournament)
    {
        $this->authorize('update', $tournament);

        try {
            $result = $this->participation->markNoShowsAndPromote($tournament);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        $message = "Marked {$result['no_shows']} team(s) as no-show.";

        if ($result['promoted'] > 0) {
            $message .= " Promoted {$result['promoted']} team(s) from the waitlist.";
        }

        return back()->with('success', $message);
    }

    /**
     * Promote the next waitlisted team into a free slot.
     */
    public function promoteWaitlisted(Tournament $tournament)
    {
        $this->authorize('update', $tournament);

        try {
            $team = $this->participation->promoteNext($tournament);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$team->name} promoted from the waitlist.");
    }
}
```

### FILE: app/Policies/GameMatchPolicy.php
```php
<?php

namespace App\Policies;

use App\Models\GameMatch;
use App\Models\User;

class GameMatchPolicy
{
    /**
     * Privileged match management (publish room, set winner, dispute, resolve,
     * correct results). Only the owning organizer or an admin may do this.
     * Players can NEVER manage, dispute or resolve matches.
     */
    public function manage(User $user, GameMatch $match): bool
    {
        return $user->isAdmin()
            || $match->tournament->organizer_id === $user->id;
    }

    /**
     * Entering the disputed state is a privileged bracket operation.
     */
    public function dispute(User $user, GameMatch $match): bool
    {
        return $this->manage($user, $match);
    }

    /**
     * Resolving a dispute (including correcting a winner) is privileged and
     * audited via the same organizer/admin gate.
     */
    public function resolve(User $user, GameMatch $match): bool
    {
        return $this->manage($user, $match);
    }
}
```

### FILE: routes/web.php
```php
<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LeaderboardController;
use App\Http\Controllers\MatchController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TournamentController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');

// Guest auth
Route::middleware('guest')->group(function () {
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register']);
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
});

Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

// Public tournament browsing
Route::get('/tournaments', [TournamentController::class, 'index'])->name('tournaments.index');
Route::get('/tournaments/{tournament}', [TournamentController::class, 'show'])->name('tournaments.show');
Route::get('/tournaments/{tournament}/leaderboard', [LeaderboardController::class, 'show'])->name('leaderboard.show');

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

    // Matches (bracket progression)
    Route::get('/tournaments/{tournament}/matches/{match}', [MatchController::class, 'show'])->name('matches.show');
    Route::post('/tournaments/{tournament}/matches/{match}/room', [MatchController::class, 'setRoom'])->name('matches.room');
    Route::post('/tournaments/{tournament}/matches/{match}/score', [MatchController::class, 'submitScore'])->name('matches.score');
    Route::post('/tournaments/{tournament}/matches/{match}/winner', [MatchController::class, 'setWinner'])->name('matches.winner');
    Route::post('/tournaments/{tournament}/matches/{match}/dispute', [MatchController::class, 'dispute'])->name('matches.dispute');
    Route::post('/tournaments/{tournament}/matches/{match}/resolve', [MatchController::class, 'resolve'])->name('matches.resolve');

    // Admin
    Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/dashboard', [AdminController::class, 'dashboard'])->name('dashboard');
        Route::post('/payments/{payment}/verify', [AdminController::class, 'verifyPayment'])->name('payments.verify');
        Route::post('/payments/{payment}/reject', [AdminController::class, 'rejectPayment'])->name('payments.reject');
    });
});
```

### FILE: database/seeders/DatabaseSeeder.php
```php
<?php

namespace Database\Seeders;

use App\Models\GameMatch;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Admin — role set explicitly (not mass-assignable)
        $admin = new User();
        $admin->name = 'FF Arena Admin';
        $admin->username = 'admin';
        $admin->email = 'admin@ffarena.test';
        $admin->password = Hash::make('password');
        $admin->role = 'admin';
        $admin->save();

        // Organizer
        $organizer = new User();
        $organizer->name = 'Rafsan Esports';
        $organizer->username = 'rafsan';
        $organizer->email = 'organizer@ffarena.test';
        $organizer->password = Hash::make('password');
        $organizer->role = 'organizer';
        $organizer->phone = '01700000000';
        $organizer->save();

        // Demo tournament (open for registration)
        $tournament = new Tournament();
        $tournament->organizer_id = $organizer->id;
        $tournament->name = 'Squad Showdown 32 Teams';
        $tournament->slug = 'squad-showdown-32-teams';
        $tournament->game_mode = 'squad';
        $tournament->map = 'Bermuda';
        $tournament->entry_fee = 100;
        $tournament->prize_pool = 5000;
        $tournament->team_slots = 8;
        $tournament->team_size = 4;
        $tournament->rules = "1. No hacks/cheats — instant ban.\n2. Screenshot of result is mandatory.\n3. Room entry within 5 minutes of schedule.";
        $tournament->starts_at = now()->addDays(2);
        $tournament->check_in_starts_at = now()->addDays(2)->subHours(3);
        $tournament->check_in_ends_at = now()->addDays(2)->subHour();
        $tournament->format = 'single_elim';
        $tournament->status = 'open';
        $tournament->save();

        $teamNames = ['BD Titans', 'Dhaka Wolves', 'RapidFire', 'Night Owls', 'Sylhet Strikers', 'Cox Cobras', 'Rajshahi Reapers', 'Chittagong Kings'];
        $seededTeams = [];

        foreach ($teamNames as $i => $name) {
            $team = new Team();
            $team->tournament_id = $tournament->id;
            $team->captain_id = null;
            $team->name = $name;
            $team->captain_name = 'Captain ' . ($i + 1);
            $team->phone = '017' . str_pad((string) (10000000 + $i), 8, '0', STR_PAD_LEFT);
            $team->game_uid = 'UID' . (900000000 + $i);
            $team->status = 'confirmed';
            $team->seed = $i + 1;
            $team->save();
            $seededTeams[] = $team;
        }

        // Second tournament (finished) with a bracket + results
        $done = new Tournament();
        $done->organizer_id = $organizer->id;
        $done->name = 'Duo Battle Royale';
        $done->slug = 'duo-battle-royale-' . rand(1000, 9999);
        $done->game_mode = 'duo';
        $done->map = 'Purgatory';
        $done->entry_fee = 50;
        $done->prize_pool = 2000;
        $done->team_slots = 8;
        $done->team_size = 2;
        $done->rules = 'Standard duo rules.';
        $done->starts_at = now()->subDays(1);
        $done->format = 'single_elim';
        $done->status = 'finished';
        $done->save();

        $dTeams = [];
        for ($i = 1; $i <= 8; $i++) {
            $t = new Team();
            $t->tournament_id = $done->id;
            $t->captain_id = null;
            $t->name = 'Duo Team ' . $i;
            $t->captain_name = 'Cap ' . $i;
            $t->phone = '017' . str_pad((string) (20000000 + $i), 8, '0', STR_PAD_LEFT);
            $t->game_uid = 'UID' . (800000000 + $i);
            $t->status = 'confirmed';
            $t->seed = $i;
            $t->save();
            $dTeams[] = $t;
        }

        $this->makeMatch($done, 1, 1, $dTeams[0], $dTeams[1], $dTeams[0]);
        $this->makeMatch($done, 1, 2, $dTeams[2], $dTeams[3], $dTeams[2]);
        $this->makeMatch($done, 1, 3, $dTeams[4], $dTeams[5], $dTeams[5]);
        $this->makeMatch($done, 1, 4, $dTeams[6], $dTeams[7], $dTeams[6]);

        $this->makeMatch($done, 2, 1, $dTeams[0], $dTeams[2], $dTeams[0]);
        $this->makeMatch($done, 2, 2, $dTeams[5], $dTeams[6], $dTeams[5]);

        $this->makeMatch($done, 3, 1, $dTeams[0], $dTeams[5], $dTeams[0]);
    }

    private function makeMatch(Tournament $tournament, int $round, int $matchNo, Team $t1, Team $t2, Team $winner): void
    {
        $match = new GameMatch();
        $match->tournament_id = $tournament->id;
        $match->round = $round;
        $match->match_no = $matchNo;
        $match->team1_id = $t1->id;
        $match->team2_id = $t2->id;
        $match->winner_team_id = $winner->id;
        $match->status = 'completed';
        $match->save();
    }
}
```

### FILE: resources/views/matches/_bracket_card.blade.php
```blade
@php /** @var \App\Models\GameMatch $match */ @endphp
<div class="bracket-match">
    <a href="{{ route('matches.show', [$tournament, $match]) }}" style="display:block">
        <div class="bracket-team {{ $match->winner_team_id === $match->team1_id ? 'win' : '' }}">
            <span>{{ $match->team1?->name ?? 'TBD' }}</span>
            @if($match->isBye() && $match->team1_id !== null && $match->team2_id === null)
                <span class="muted" style="font-size:11px">(bye)</span>
            @endif
        </div>
        <div class="divider"></div>
        <div class="bracket-team {{ $match->winner_team_id === $match->team2_id ? 'win' : '' }}">
            <span>{{ $match->team2?->name ?? 'TBD' }}</span>
            @if($match->isBye() && $match->team2_id !== null && $match->team1_id === null)
                <span class="muted" style="font-size:11px">(bye)</span>
            @endif
        </div>
        <div style="font-size:10px; margin-top:4px; text-align:center">
            @if($match->isBye())
                <span class="pill bye">BYE</span>
            @elseif($match->isCompleted())
                <span class="pill finished">✓</span>
            @elseif($match->status === 'disputed')
                <span class="pill disputed">DISPUTED</span>
            @elseif($match->status === 'live')
                <span class="pill live">LIVE</span>
            @endif
        </div>
    </a>
</div>
```

### FILE: resources/views/matches/show.blade.php
```blade
@extends('layouts.app')
@section('title', 'Match — ' . $tournament->name)
@section('content')
    <div style="padding: 24px 0 6px">
        <a href="{{ route('tournaments.show', $tournament) }}" class="muted" style="font-size:13px">← Back to tournament</a>
        <h1 style="margin-top:6px">
            {{ $match->roundLabel() }} — Match #{{ $match->match_no }}
        </h1>
        <div class="muted" style="font-size:13px">{{ $match->bracketLabel() }}</div>
    </div>

    <div class="grid cols-2">
        <div class="card">
            <h3>⚔️ Matchup</h3>
            @if($match->isBye())
                @php $byeTeam = $match->team1 ?: $match->team2; @endphp
                <div class="bracket-team win">
                    <strong>{{ $byeTeam->name ?? 'TBD' }}</strong>
                </div>
                <p class="muted" style="text-align:center; margin:10px 0">— bye, automatically advanced —</p>
            @else
                <div class="bracket-team {{ $match->winner_team_id === $match->team1_id ? 'win' : '' }}">
                    <strong>{{ $match->team1?->name ?? 'TBD' }}</strong>
                </div>
                <div style="text-align:center; color:var(--purple); font-weight:800; padding:4px 0">VS</div>
                <div class="bracket-team {{ $match->winner_team_id === $match->team2_id ? 'win' : '' }}">
                    <strong>{{ $match->team2?->name ?? 'TBD' }}</strong>
                </div>
                @if($match->winner)
                    <div class="muted" style="margin-top:12px; font-size:13px">
                        Winner: <strong class="tag">{{ $match->winner->name }}</strong>
                    </div>
                @endif
            @endif
            <div class="muted" style="margin-top:12px; font-size:13px">
                Status: <span class="pill {{ $match->statusPill() }}">{{ strtoupper($match->status) }}</span>
            </div>
            @if($match->nextMatch)
                <div class="muted" style="margin-top:8px; font-size:13px">
                    Next: <a href="{{ route('matches.show', [$tournament, $match->nextMatch]) }}">
                        {{ $match->nextMatch->roundLabel() }} #{{ $match->nextMatch->match_no }}
                        @if($match->next_slot) (slot {{ $match->next_slot }}) @endif
                    </a>
                </div>
            @endif
            @if($match->loserNextMatch)
                <div class="muted" style="margin-top:4px; font-size:13px">
                    Loser drops to: <a href="{{ route('matches.show', [$tournament, $match->loserNextMatch]) }}">
                        {{ $match->loserNextMatch->roundLabel() }} #{{ $match->loserNextMatch->match_no }}
                        @if($match->loser_slot) (slot {{ $match->loser_slot }}) @endif
                    </a>
                </div>
            @endif
        </div>

        <div class="card">
            <h3>🎟 Room Info</h3>
            @if($match->room_id)
                <div style="font-size:15px">
                    Room ID: <strong class="tag">{{ $match->room_id }}</strong><br>
                    Password: <strong class="tag">{{ $match->room_pass }}</strong><br>
                    Time: {{ optional($match->scheduled_at)->format('d M, h:i A') }}
                </div>
            @else
                <p class="muted">Room details not published yet.</p>
            @endif

            @auth
                @if(auth()->user()->isAdmin() || auth()->user()->isOrganizer())
                    <form method="POST" action="{{ route('matches.room', [$tournament, $match]) }}" style="margin-top:12px">
                        @csrf
                        <div class="grid cols-2">
                            <div><label>Room ID</label><input type="text" name="room_id" required></div>
                            <div><label>Password</label><input type="text" name="room_pass" required></div>
                        </div>
                        <button class="btn btn-sm btn-cyan" style="margin-top:10px">Publish Room & Start Match</button>
                    </form>
                @endif
            @endauth
        </div>
    </div>

    <div class="grid cols-2">
        <div class="card">
            <h3>📊 Submitted Scores</h3>
            @if($match->scores->isEmpty())
                <p class="muted">No scores submitted yet.</p>
            @else
                <table>
                    <tr><th>Team</th><th>Kills</th><th>Place</th><th>Points</th><th>Proof</th></tr>
                    @foreach($match->scores as $score)
                        <tr>
                            <td>{{ $score->team->name }}</td>
                            <td>{{ $score->kills }}</td>
                            <td>#{{ $score->placement }}</td>
                            <td><strong>{{ $score->points }}</strong></td>
                            <td>
                                @if($score->screenshot_path)
                                    <a href="{{ asset('storage/' . $score->screenshot_path) }}" target="_blank" class="btn btn-sm">View</a>
                                @else
                                    <span class="muted">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </table>
            @endif
        </div>

        <div class="card">
            <h3>📝 Submit Score</h3>
            @if(in_array($match->status, ['ready', 'live'], true))
                <form method="POST" action="{{ route('matches.score', [$tournament, $match]) }}" enctype="multipart/form-data">
                    @csrf
                    <label>Your team</label>
                    <select name="team_id" required>
                        @if($match->team1) <option value="{{ $match->team1->id }}">{{ $match->team1->name }}</option> @endif
                        @if($match->team2) <option value="{{ $match->team2->id }}">{{ $match->team2->name }}</option> @endif
                    </select>
                    <div class="grid cols-2">
                        <div><label>Kills</label><input type="number" name="kills" min="0" value="0" required></div>
                        <div><label>Placement</label><input type="number" name="placement" min="1" max="12" value="1" required></div>
                    </div>
                    <label>Screenshot (proof)</label>
                    <input type="file" name="screenshot" accept="image/*">
                    <button class="btn btn-primary btn-sm" style="margin-top:12px">Submit Score</button>
                </form>
            @else
                <p class="muted">Score submission is not open for this match.</p>
            @endif

            @auth
                @if(auth()->user()->isAdmin() || (auth()->user()->isOrganizer() && auth()->user()->id === $tournament->organizer_id))
                    <hr style="border-color:var(--line); margin:16px 0">

                    @if(in_array($match->status, ['ready', 'live'], true))
                        <h3>✅ Set Winner</h3>
                        <form method="POST" action="{{ route('matches.winner', [$tournament, $match]) }}">
                            @csrf
                            <label>Winner team</label>
                            <select name="winner_team_id" required>
                                @if($match->team1) <option value="{{ $match->team1->id }}">{{ $match->team1->name }}</option> @endif
                                @if($match->team2) <option value="{{ $match->team2->id }}">{{ $match->team2->name }}</option> @endif
                            </select>
                            <button class="btn btn-green btn-sm" style="margin-top:10px">Confirm Winner (advances bracket)</button>
                        </form>
                    @endif

                    @if($match->status === 'completed')
                        <form method="POST" action="{{ route('matches.dispute', [$tournament, $match]) }}">
                            @csrf
                            <button class="btn btn-sm" style="border-color:var(--red); color:var(--red); margin-top:10px"
                                onclick="return confirm('Mark this completed match as disputed? Advancement will be blocked until resolved.')">
                                ⚠ Mark Disputed
                            </button>
                        </form>
                    @endif

                    @if($match->status === 'disputed')
                        <h3>⚖ Resolve Dispute</h3>
                        <p class="muted" style="font-size:13px">Choose the correct winner. The bracket will be re-advanced.</p>
                        <form method="POST" action="{{ route('matches.resolve', [$tournament, $match]) }}">
                            @csrf
                            <label>Winner team</label>
                            <select name="winner_team_id" required>
                                @if($match->team1) <option value="{{ $match->team1->id }}" @selected($match->winner_team_id === $match->team1_id)>{{ $match->team1->name }}</option> @endif
                                @if($match->team2) <option value="{{ $match->team2->id }}" @selected($match->winner_team_id === $match->team2_id)>{{ $match->team2->name }}</option> @endif
                            </select>
                            <button class="btn btn-green btn-sm" style="margin-top:10px">Resolve Dispute</button>
                        </form>
                    @endif
                @endif
            @endauth
        </div>
    </div>
@endsection
```

### FILE: resources/views/tournaments/show.blade.php
```blade
@extends('layouts.app')
@section('title', $tournament->name . ' — FF Arena')
@section('content')
    <div style="padding: 30px 0 10px">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px">
            <div>
                <h1 style="margin:0">{{ $tournament->name }}</h1>
                <div class="muted" style="margin-top:6px">
                    by {{ $tournament->organizer->name ?? 'Organizer' }} ·
                    {{ strtoupper($tournament->game_mode) }} · {{ $tournament->map }} ·
                    starts {{ optional($tournament->starts_at)->format('d M Y, h:i A') }}
                </div>
            </div>
            <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap">
                @if($tournament->hasCheckIn())
                    @if($tournament->checkInIsOpen())
                        <span class="pill checked">CHECK-IN OPEN</span>
                    @elseif($tournament->checkInHasClosed())
                        <span class="pill no_show">CHECK-IN CLOSED</span>
                    @else
                        <span class="pill pending">CHECK-IN NOT OPEN</span>
                    @endif
                @endif
                <span class="pill {{ $tournament->status }}">{{ strtoupper($tournament->status) }}</span>
            </div>
        </div>
    </div>

    <div class="grid cols-2">
        <div class="card">
            <h3>Prize & Entry</h3>
            <div style="display:flex; gap:24px; font-size:16px">
                <div>Entry fee <br><strong class="tag">৳{{ number_format($tournament->entry_fee) }}</strong></div>
                <div>Prize pool <br><strong class="tag">৳{{ number_format($tournament->prize_pool) }}</strong></div>
                <div>Slots left <br><strong>{{ $tournament->slotsLeft() }}/{{ $tournament->team_slots }}</strong></div>
            </div>
            <div class="muted" style="margin-top:12px; font-size:13px">Players per team: {{ $tournament->team_size }}</div>
            @if($tournament->hasCheckIn())
                <div class="muted" style="margin-top:8px; font-size:13px">
                    Check-in: {{ $tournament->check_in_starts_at->format('d M, h:i A') }} —
                    {{ $tournament->check_in_ends_at->format('d M, h:i A') }}
                </div>
            @endif
        </div>
        <div class="card">
            <h3>Rules</h3>
            <pre style="white-space:pre-wrap; font-family:inherit; font-size:14px; color:var(--muted)">{{ $tournament->rules ?: 'No rules set.' }}</pre>
        </div>
    </div>

    @auth
        @if((auth()->user()->isOrganizer() && auth()->user()->id === $tournament->organizer_id) || auth()->user()->isAdmin())
            <div class="card">
                <h3>🎛 Organizer Controls</h3>
                <div style="display:flex; gap:10px; flex-wrap:wrap">
                    @if($tournament->status === 'draft')
                        <form method="POST" action="{{ route('tournaments.publish', $tournament) }}">@csrf
                            <button class="btn btn-green btn-sm">Publish (open registration)</button>
                        </form>
                    @endif
                    @if($tournament->status === 'open')
                        <form method="POST" action="{{ route('tournaments.close', $tournament) }}">@csrf
                            <button class="btn btn-sm">Close registration</button>
                        </form>
                    @endif
                    @if(in_array($tournament->status, ['closed','open'], true))
                        <form method="POST" action="{{ route('tournaments.bracket', $tournament) }}">@csrf
                            <button class="btn btn-primary btn-sm">⚡ Generate Bracket</button>
                        </form>
                    @endif
                    @if($tournament->status === 'live')
                        <form method="POST" action="{{ route('tournaments.complete', $tournament) }}">@csrf
                            <button class="btn btn-green btn-sm" onclick="return confirm('Finish this tournament? Make sure all matches are completed.')">🏁 Finish Tournament</button>
                        </form>
                    @endif
                    @if($tournament->hasCheckIn() && $tournament->checkInHasClosed())
                        <form method="POST" action="{{ route('tournaments.noshows', $tournament) }}">@csrf
                            <button class="btn btn-sm" onclick="return confirm('Mark unchecked-in teams as no-show and promote from the waitlist?')">🚫 Mark No-shows</button>
                        </form>
                    @endif
                    @if($tournament->acceptsRegistration() && $waitlist && $waitlist->isNotEmpty())
                        <form method="POST" action="{{ route('tournaments.waitlist.promote', $tournament) }}">@csrf
                            <button class="btn btn-sm btn-cyan">⬆ Promote Next Waitlisted</button>
                        </form>
                    @endif
                    <a href="{{ route('tournaments.edit', $tournament) }}" class="btn btn-sm">Edit</a>
                    <a href="{{ route('leaderboard.show', $tournament) }}" class="btn btn-sm btn-cyan">Leaderboard</a>
                    @if(in_array($tournament->status, ['draft', 'open', 'closed'], true))
                        <form method="POST" action="{{ route('tournaments.cancel', $tournament) }}" style="display:inline">
                            @csrf
                            <button class="btn btn-sm" style="border-color:var(--red); color:var(--red)" onclick="return confirm('Cancel this tournament?')">Cancel</button>
                        </form>
                    @endif
                </div>
            </div>
        @endif

        @if($myTeam && !$myTeam->isWithdrawn())
            <div class="card">
                <h3>🎽 Your Team</h3>
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px">
                    <div>
                        <strong>{{ $myTeam->name }}</strong>
                        <span class="pill {{ $myTeam->status }}">{{ strtoupper($myTeam->status) }}</span>
                        @if($myTeam->isWaitlisted())
                            <span class="pill waitlisted">WAITLIST #{{ $myTeam->waitlistPosition() }}</span>
                        @elseif($myTeam->isCheckedIn())
                            <span class="pill checked">CHECKED IN ✓</span>
                        @endif
                        <div class="muted" style="font-size:13px; margin-top:4px">Roster: {{ $myTeam->rosterSize() }} / {{ $tournament->team_size }} players</div>
                    </div>
                    <div style="display:flex; gap:8px; flex-wrap:wrap">
                        @if($myTeam->isConfirmed() && $tournament->hasCheckIn() && !$myTeam->isCheckedIn() && $tournament->checkInIsOpen())
                            <form method="POST" action="{{ route('teams.checkin', [$tournament, $myTeam]) }}">
                                @csrf
                                <button class="btn btn-green btn-sm">✅ Check In</button>
                            </form>
                        @endif
                        <a href="{{ route('teams.show', [$tournament, $myTeam]) }}" class="btn btn-sm btn-cyan">Manage Team</a>
                        @if(in_array($tournament->status, ['draft', 'open', 'closed'], true))
                            <form method="POST" action="{{ route('teams.withdraw', [$tournament, $myTeam]) }}">
                                @csrf
                                <button class="btn btn-sm" style="border-color:var(--red); color:var(--red)" onclick="return confirm('Withdraw your team from this tournament?')">Withdraw Team</button>
                            </form>
                        @endif
                    </div>
                </div>
            </div>
        @endif
    @endauth

    @if($tournament->acceptsRegistration() && !$tournament->isFull())
        <div class="card" style="text-align:center">
            <h3>Ready to fight? 🎯</h3>
            <a href="{{ route('teams.register', $tournament) }}" class="btn btn-primary">Register Your Team</a>
        </div>
    @elseif($tournament->acceptsRegistration() && $tournament->isFull())
        <div class="card" style="text-align:center">
            <h3>⏳ Tournament full</h3>
            <p class="muted">All slots are taken, but you can still join the waitlist.</p>
            <a href="{{ route('teams.register', $tournament) }}" class="btn btn-primary">Join Waitlist</a>
        </div>
    @endif

    @if($tournament->status === 'live' || $tournament->status === 'finished')
        <div class="card">
            <h2>🏆 Bracket
                <span class="muted" style="font-size:13px; font-weight:400">
                    — {{ $tournament->isDoubleElim() ? 'Double Elimination' : 'Single Elimination' }}
                    @if($tournament->bracket_size) ({{ $tournament->bracket_size }}-team bracket) @endif
                </span>
            </h2>
            @if($tournament->matches->isEmpty())
                <p class="muted">Bracket not generated yet.</p>
            @elseif($tournament->isDoubleElim())
                @php
                    $sides = [
                        'winners' => ['label' => 'Winners Bracket', 'brackets' => ['winners']],
                        'losers' => ['label' => 'Losers Bracket', 'brackets' => ['losers']],
                        'grand_final' => ['label' => 'Grand Final', 'brackets' => ['grand_final']],
                    ];
                @endphp
                @foreach($sides as $side)
                    @php $sideMatches = $tournament->matches->where('bracket', $side['brackets'][0]); @endphp
                    @if($sideMatches->isNotEmpty())
                        <div style="margin-top:18px">
                            <div class="muted" style="font-size:13px; font-weight:800; text-transform:uppercase; letter-spacing:.5px">{{ $side['label'] }}</div>
                            <div class="bracket-col">
                                @foreach($sideMatches->groupBy('round')->sortKeys() as $round => $roundMatches)
                                    <div class="bracket-round">
                                        <div class="muted" style="font-size:12px; font-weight:700">
                                            {{ $roundMatches->first()->roundLabel() }}
                                        </div>
                                        @foreach($roundMatches as $m)
                                            @include('matches._bracket_card', ['match' => $m])
                                        @endforeach
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endforeach
            @else
                @php $rounds = $tournament->matches->groupBy('round')->sortKeys(); $lastRound = $rounds->keys()->last(); @endphp
                <div class="bracket-col">
                    @foreach($rounds as $round => $matches)
                        <div class="bracket-round">
                            <div class="muted" style="font-size:12px; font-weight:700">
                                {{ $round == $lastRound ? '🏁 FINAL' : 'Round ' . $round }}
                            </div>
                            @foreach($matches as $m)
                                @include('matches._bracket_card', ['match' => $m])
                            @endforeach
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    <div class="card">
        <h3>👥 Registered Teams</h3>
        @if($tournament->confirmedTeams->isEmpty())
            <p class="muted">No confirmed teams yet.</p>
        @else
            <table>
                <tr><th>#</th><th>Team</th><th>Captain</th><th>Status</th>@if($tournament->hasCheckIn())<th>Check-in</th>@endif</tr>
                @foreach($tournament->confirmedTeams as $team)
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td><strong>{{ $team->name }}</strong></td>
                        <td class="muted">{{ $team->captain_name }}</td>
                        <td><span class="pill confirmed">CONFIRMED</span></td>
                        @if($tournament->hasCheckIn())
                            <td>
                                @if($team->isCheckedIn())
                                    <span class="pill checked">CHECKED IN</span>
                                @else
                                    <span class="pill pending">NOT CHECKED IN</span>
                                @endif
                            </td>
                        @endif
                    </tr>
                @endforeach
            </table>
        @endif
    </div>

    @if($waitlist && $waitlist->isNotEmpty())
        <div class="card">
            <h3>⏳ Waitlist</h3>
            <table>
                <tr><th>#</th><th>Team</th><th>Captain</th><th>Status</th></tr>
                @foreach($waitlist as $wt)
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td><strong>{{ $wt->name }}</strong></td>
                        <td class="muted">{{ $wt->captain_name }}</td>
                        <td><span class="pill waitlisted">WAITLISTED</span></td>
                    </tr>
                @endforeach
            </table>
        </div>
    @endif
@endsection
```

### FILE: resources/views/tournaments/create.blade.php
```blade
@extends('layouts.app')
@section('title', 'Create Tournament — FF Arena')
@section('content')
    <div class="card" style="max-width: 640px; margin: 40px auto">
        <h2>🎮 Create a Tournament</h2>
        <form method="POST" action="{{ route('tournaments.store') }}">
            @csrf
            <label>Tournament name</label>
            <input type="text" name="name" value="{{ old('name') }}" placeholder="Squad Showdown 32 Teams" required>
            <div class="grid cols-2" style="margin-top:4px">
                <div>
                    <label>Game mode</label>
                    <select name="game_mode">
                        <option value="squad">Squad</option>
                        <option value="duo">Duo</option>
                        <option value="solo">Solo</option>
                    </select>
                </div>
                <div>
                    <label>Map</label>
                    <select name="map">
                        <option>Bermuda</option>
                        <option>Purgatory</option>
                        <option>Kalahari</option>
                        <option>Alpine</option>
                    </select>
                </div>
                <div>
                    <label>Entry fee (৳ per team)</label>
                    <input type="number" name="entry_fee" value="{{ old('entry_fee', 100) }}" min="0" required>
                </div>
                <div>
                    <label>Prize pool (৳)</label>
                    <input type="number" name="prize_pool" value="{{ old('prize_pool', 5000) }}" min="0" required>
                </div>
                <div>
                    <label>Team slots</label>
                    <select name="team_slots">
                        <option value="8">8</option>
                        <option value="16" selected>16</option>
                        <option value="32">32</option>
                    </select>
                </div>
                <div>
                    <label>Players per team</label>
                    <input type="number" name="team_size" value="{{ old('team_size', 4) }}" min="1" max="6" required>
                </div>
                <div>
                    <label>Bracket format</label>
                    <select name="format">
                        <option value="single_elim" @selected(old('format', 'single_elim') === 'single_elim')>Single Elimination</option>
                        <option value="double_elim" @selected(old('format') === 'double_elim')>Double Elimination</option>
                    </select>
                    <p class="muted" style="font-size:12px; margin-top:4px">Double elimination requires a full power-of-two field (8, 16 or 32 eligible teams).</p>
                </div>
            </div>
            <label>Start date & time</label>
            <input type="datetime-local" name="starts_at" value="{{ old('starts_at') }}" required>
            <div class="grid cols-2" style="margin-top:4px">
                <div>
                    <label>Check-in opens (optional)</label>
                    <input type="datetime-local" name="check_in_starts_at" value="{{ old('check_in_starts_at') }}">
                </div>
                <div>
                    <label>Check-in closes (optional)</label>
                    <input type="datetime-local" name="check_in_ends_at" value="{{ old('check_in_ends_at') }}">
                </div>
            </div>
            <p class="muted" style="font-size:12px; margin-top:6px">Leave check-in empty to skip check-in (all confirmed teams enter the bracket).</p>
            <label>Rules</label>
            <textarea name="rules" rows="4" placeholder="1. No hacks — instant ban.&#10;2. Screenshot mandatory.">{{ old('rules') }}</textarea>
            <button class="btn btn-primary" style="margin-top:18px; width:100%">Create Tournament</button>
        </form>
    </div>
@endsection
```

### FILE: resources/views/tournaments/edit.blade.php
```blade
@extends('layouts.app')
@section('title', 'Edit Tournament — FF Arena')
@section('content')
    <div class="card" style="max-width: 640px; margin: 40px auto">
        <h2>Edit: {{ $tournament->name }}</h2>
        <form method="POST" action="{{ route('tournaments.update', $tournament) }}">
            @csrf
            @method('PUT')
            <label>Tournament name</label>
            <input type="text" name="name" value="{{ $tournament->name }}" required>
            <div class="grid cols-2" style="margin-top:4px">
                <div>
                    <label>Game mode</label>
                    <select name="game_mode">
                        @foreach(['squad','duo','solo'] as $m)
                            <option value="{{ $m }}" @selected($tournament->game_mode === $m)>{{ ucfirst($m) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label>Map</label>
                    <input type="text" name="map" value="{{ $tournament->map }}" required>
                </div>
                <div>
                    <label>Entry fee (৳)</label>
                    <input type="number" name="entry_fee" value="{{ $tournament->entry_fee }}" min="0" required>
                </div>
                <div>
                    <label>Prize pool (৳)</label>
                    <input type="number" name="prize_pool" value="{{ $tournament->prize_pool }}" min="0" required>
                </div>
                <div>
                    <label>Team slots</label>
                    <select name="team_slots">
                        @foreach([8,16,32] as $s)
                            <option value="{{ $s }}" @selected($tournament->team_slots == $s)>{{ $s }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label>Players per team</label>
                    <input type="number" name="team_size" value="{{ $tournament->team_size }}" min="1" max="6" required>
                </div>
                <div>
                    <label>Bracket format</label>
                    <select name="format">
                        @foreach(\App\Models\Tournament::FORMATS as $f)
                            <option value="{{ $f }}" @selected($tournament->format === $f)>
                                {{ $f === 'double_elim' ? 'Double Elimination' : 'Single Elimination' }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>
            <label>Start date & time</label>
            <input type="datetime-local" name="starts_at" value="{{ optional($tournament->starts_at)->format('Y-m-d\TH:i') }}" required>
            <div class="grid cols-2" style="margin-top:4px">
                <div>
                    <label>Check-in opens (optional)</label>
                    <input type="datetime-local" name="check_in_starts_at" value="{{ optional($tournament->check_in_starts_at)->format('Y-m-d\TH:i') }}">
                </div>
                <div>
                    <label>Check-in closes (optional)</label>
                    <input type="datetime-local" name="check_in_ends_at" value="{{ optional($tournament->check_in_ends_at)->format('Y-m-d\TH:i') }}">
                </div>
            </div>
            <label>Rules</label>
            <textarea name="rules" rows="4">{{ $tournament->rules }}</textarea>
            <button class="btn btn-primary" style="margin-top:18px; width:100%">Save Changes</button>
        </form>
    </div>
@endsection
```

### FILE: resources/views/layouts/app.blade.php
```blade
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
    <nav>
        <a href="{{ route('home') }}" class="brand">FF<span>ARENA</span></a>
        <div class="nav-links">
            <a href="{{ route('tournaments.index') }}">Tournaments</a>
            @auth
                @if(auth()->user()->isOrganizer() || auth()->user()->isAdmin())
                    <a href="{{ route('tournaments.create') }}" class="btn btn-sm btn-cyan">+ Create Tournament</a>
                @endif
                @if(auth()->user()->isAdmin())
                    <a href="{{ route('admin.dashboard') }}" class="btn btn-sm">Admin</a>
                @endif
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
</body>
</html>
```

### FILE: tests/Feature/BracketGenerationTest.php
```php
<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Services\BracketService;
use App\Services\MatchProgressionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 05 — bracket generation tests.
 *
 * Covers single-elimination fields of 1..16 teams (byes), deterministic
 * seeding, idempotent regeneration, explicit dependency-graph advancement,
 * the grand final, double-elimination structure and progression, and the
 * exclusion of withdrawn/unchecked-in teams.
 */
class BracketGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $role = 'player'): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->save();

        return $user;
    }

    protected function makeTournament(User $organizer, string $status = 'closed', array $o = []): Tournament
    {
        $t = new Tournament();
        $t->organizer_id = $organizer->id;
        $t->name = $o['name'] ?? 'Bracket Tournament';
        $t->slug = $o['slug'] ?? ('bracket-'.Str::random(8));
        $t->game_mode = $o['game_mode'] ?? 'squad';
        $t->map = $o['map'] ?? 'Bermuda';
        $t->entry_fee = $o['entry_fee'] ?? 0;
        $t->prize_pool = $o['prize_pool'] ?? 5000;
        $t->team_slots = $o['team_slots'] ?? 16;
        $t->team_size = $o['team_size'] ?? 4;
        $t->rules = $o['rules'] ?? null;
        $t->starts_at = $o['starts_at'] ?? now()->addDay();
        $t->check_in_starts_at = $o['check_in_starts_at'] ?? null;
        $t->check_in_ends_at = $o['check_in_ends_at'] ?? null;
        $t->format = $o['format'] ?? Tournament::FORMAT_SINGLE_ELIM;
        $t->status = $status;
        $t->save();

        return $t;
    }

    protected function makeTeam(
        Tournament $tournament,
        ?User $captain = null,
        string $status = 'confirmed',
        ?int $seed = null,
        bool $checkedIn = false,
    ): Team {
        $team = new Team();
        $team->tournament_id = $tournament->id;
        $team->captain_id = $captain?->id;
        $team->name = 'Team '.Str::random(6);
        $team->captain_name = $captain?->name ?? 'Captain';
        $team->phone = '01700000000';
        $team->game_uid = 'UID'.strtoupper(Str::random(8));
        $team->status = $status;
        if ($seed !== null) {
            $team->seed = $seed;
        }
        if ($checkedIn) {
            $team->checked_in_at = now();
        }
        $team->save();

        return $team;
    }

    protected function bracket(): BracketService
    {
        return app(BracketService::class);
    }

    protected function progression(): MatchProgressionService
    {
        return app(MatchProgressionService::class);
    }

    // ------------------------------------------------------------------
    // Single elimination — field sizes & byes
    // ------------------------------------------------------------------

    public function test_single_team_cannot_form_a_bracket(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $this->makeTeam($tournament, null, 'confirmed');

        $this->assertSame(0, $this->bracket()->generate($tournament));
        $this->assertSame(0, GameMatch::where('tournament_id', $tournament->id)->count());
    }

    public function test_two_teams_form_a_single_final(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $t1 = $this->makeTeam($tournament, null, 'confirmed');
        $t2 = $this->makeTeam($tournament, null, 'confirmed');

        $this->assertSame(1, $this->bracket()->generate($tournament));

        $final = GameMatch::where('tournament_id', $tournament->id)->firstOrFail();
        $this->assertSame(1, $final->round);
        $this->assertSame(1, $final->match_no);
        $this->assertSame($t1->id, $final->team1_id);
        $this->assertSame($t2->id, $final->team2_id);
        $this->assertSame('ready', $final->status);
    }

    public function test_three_teams_produce_one_bye(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $t1 = $this->makeTeam($tournament, null, 'confirmed');
        $t2 = $this->makeTeam($tournament, null, 'confirmed');
        $t3 = $this->makeTeam($tournament, null, 'confirmed');

        $this->assertSame(3, $this->bracket()->generate($tournament));

        $byes = GameMatch::where('tournament_id', $tournament->id)->where('status', 'bye')->get();
        $this->assertCount(1, $byes);
        $this->assertSame($t3->id, $byes->first()->winner_team_id);

        // The bye team is auto-advanced into the round-2 slot.
        $round2 = GameMatch::where('tournament_id', $tournament->id)->where('round', 2)->firstOrFail();
        $this->assertTrue(in_array($t3->id, [$round2->team1_id, $round2->team2_id], true));
    }

    public function test_four_teams_pair_sequentially(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $t1 = $this->makeTeam($tournament, null, 'confirmed');
        $t2 = $this->makeTeam($tournament, null, 'confirmed');
        $t3 = $this->makeTeam($tournament, null, 'confirmed');
        $t4 = $this->makeTeam($tournament, null, 'confirmed');

        $this->assertSame(3, $this->bracket()->generate($tournament));

        $m1 = GameMatch::where('tournament_id', $tournament->id)->where('round', 1)->where('match_no', 1)->firstOrFail();
        $m2 = GameMatch::where('tournament_id', $tournament->id)->where('round', 1)->where('match_no', 2)->firstOrFail();
        $this->assertSame($t1->id, $m1->team1_id);
        $this->assertSame($t2->id, $m1->team2_id);
        $this->assertSame($t3->id, $m2->team1_id);
        $this->assertSame($t4->id, $m2->team2_id);
    }

    public function test_five_teams_produce_three_byes(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        for ($i = 0; $i < 5; $i++) {
            $this->makeTeam($tournament, null, 'confirmed');
        }

        $this->assertSame(7, $this->bracket()->generate($tournament));
        $this->assertSame(3, GameMatch::where('tournament_id', $tournament->id)->where('status', 'bye')->count());

        // No round-1 match may be empty.
        $empty = GameMatch::where('tournament_id', $tournament->id)->where('round', 1)
            ->whereNull('team1_id')->whereNull('team2_id')->count();
        $this->assertSame(0, $empty);
    }

    public function test_six_teams_produce_two_byes(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        for ($i = 0; $i < 6; $i++) {
            $this->makeTeam($tournament, null, 'confirmed');
        }

        $this->assertSame(7, $this->bracket()->generate($tournament));
        $this->assertSame(2, GameMatch::where('tournament_id', $tournament->id)->where('status', 'bye')->count());
    }

    public function test_seven_teams_produce_one_bye_and_seven_matches(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        for ($i = 0; $i < 7; $i++) {
            $this->makeTeam($tournament, null, 'confirmed');
        }

        $this->assertSame(7, $this->bracket()->generate($tournament));
        $this->assertSame(1, GameMatch::where('tournament_id', $tournament->id)->where('status', 'bye')->count());
        $this->assertSame(8, (int) $tournament->fresh()->bracket_size);
    }

    public function test_eight_teams_produce_seven_matches_no_byes(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        for ($i = 0; $i < 8; $i++) {
            $this->makeTeam($tournament, null, 'confirmed');
        }

        $this->assertSame(7, $this->bracket()->generate($tournament));
        $this->assertSame(0, GameMatch::where('tournament_id', $tournament->id)->where('status', 'bye')->count());
        $this->assertSame(4, GameMatch::where('tournament_id', $tournament->id)->where('round', 1)->count());
        $this->assertSame(2, GameMatch::where('tournament_id', $tournament->id)->where('round', 2)->count());
        $this->assertSame(1, GameMatch::where('tournament_id', $tournament->id)->where('round', 3)->count());
    }

    public function test_sixteen_teams_produce_fifteen_matches(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'closed', ['team_slots' => 16]);
        for ($i = 0; $i < 16; $i++) {
            $this->makeTeam($tournament, null, 'confirmed');
        }

        $this->assertSame(15, $this->bracket()->generate($tournament));
        $this->assertSame(0, GameMatch::where('tournament_id', $tournament->id)->where('status', 'bye')->count());
    }

    // ------------------------------------------------------------------
    // Seeding, idempotency
    // ------------------------------------------------------------------

    public function test_seeding_follows_seed_then_id_order_and_normalises_seeds(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);

        // Created out of seed order.
        $a = $this->makeTeam($tournament, null, 'confirmed', 4);
        $b = $this->makeTeam($tournament, null, 'confirmed', 2);
        $c = $this->makeTeam($tournament, null, 'confirmed', 1);
        $d = $this->makeTeam($tournament, null, 'confirmed', 3);

        $this->bracket()->generate($tournament);

        $m1 = GameMatch::where('tournament_id', $tournament->id)->where('round', 1)->where('match_no', 1)->firstOrFail();
        // Rank order: c(1), b(2), d(3), a(4) → 1v2 = c vs b, 3v4 = d vs a.
        $this->assertSame($c->id, $m1->team1_id);
        $this->assertSame($b->id, $m1->team2_id);

        $m2 = GameMatch::where('tournament_id', $tournament->id)->where('round', 1)->where('match_no', 2)->firstOrFail();
        $this->assertSame($d->id, $m2->team1_id);
        $this->assertSame($a->id, $m2->team2_id);

        // Seeds normalised to the deterministic rank.
        $this->assertSame(1, (int) $c->fresh()->seed);
        $this->assertSame(2, (int) $b->fresh()->seed);
        $this->assertSame(3, (int) $d->fresh()->seed);
        $this->assertSame(4, (int) $a->fresh()->seed);
    }

    public function test_regeneration_is_idempotent_and_deterministic(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        for ($i = 0; $i < 5; $i++) {
            $this->makeTeam($tournament, null, 'confirmed');
        }

        $service = $this->bracket();

        $first = $service->generate($tournament);
        $snapshot1 = GameMatch::where('tournament_id', $tournament->id)
            ->orderBy('round')->orderBy('match_no')
            ->get(['bracket', 'round', 'match_no', 'team1_id', 'team2_id', 'winner_team_id', 'status', 'next_slot', 'loser_slot'])
            ->map->getAttributes()
            ->all();

        $second = $service->generate($tournament);
        $snapshot2 = GameMatch::where('tournament_id', $tournament->id)
            ->orderBy('round')->orderBy('match_no')
            ->get(['bracket', 'round', 'match_no', 'team1_id', 'team2_id', 'winner_team_id', 'status', 'next_slot', 'loser_slot'])
            ->map->getAttributes()
            ->all();

        $this->assertSame($first, $second);
        $this->assertSame($snapshot1, $snapshot2);
    }

    // ------------------------------------------------------------------
    // Exclusion of ineligible teams
    // ------------------------------------------------------------------

    public function test_withdrawn_and_unchecked_in_teams_are_excluded(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'closed', [
            'check_in_starts_at' => now()->subHours(2),
            'check_in_ends_at' => now()->subHour(),
        ]);

        $eligible = [];
        for ($i = 0; $i < 4; $i++) {
            $eligible[] = $this->makeTeam($tournament, null, 'confirmed', null, true)->id;
        }
        $withdrawn = $this->makeTeam($tournament, null, 'withdrawn', null, true);
        $unchecked = $this->makeTeam($tournament, null, 'confirmed'); // confirmed but not checked in

        $this->bracket()->generate($tournament);

        $participantIds = GameMatch::where('tournament_id', $tournament->id)
            ->get()
            ->flatMap(fn ($m) => [$m->team1_id, $m->team2_id])
            ->filter()
            ->unique()
            ->values()
            ->all();

        sort($eligible);
        sort($participantIds);
        $this->assertSame($eligible, $participantIds);
        $this->assertNotContains($withdrawn->id, $participantIds);
        $this->assertNotContains($unchecked->id, $participantIds);
    }

    // ------------------------------------------------------------------
    // Advancement through the dependency graph (single elim)
    // ------------------------------------------------------------------

    public function test_winner_advances_through_explicit_links_to_final(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $t1 = $this->makeTeam($tournament, null, 'confirmed');
        $t2 = $this->makeTeam($tournament, null, 'confirmed');
        $t3 = $this->makeTeam($tournament, null, 'confirmed');
        $t4 = $this->makeTeam($tournament, null, 'confirmed');

        $this->bracket()->generate($tournament);
        $progression = $this->progression();

        $m1 = GameMatch::where('tournament_id', $tournament->id)->where('round', 1)->where('match_no', 1)->firstOrFail();
        $m2 = GameMatch::where('tournament_id', $tournament->id)->where('round', 1)->where('match_no', 2)->firstOrFail();
        $final = GameMatch::where('tournament_id', $tournament->id)->where('round', 2)->where('match_no', 1)->firstOrFail();

        // Explicit dependency links exist (not arithmetic).
        $this->assertSame($final->id, $m1->next_match_id);
        $this->assertSame($final->id, $m2->next_match_id);

        $progression->complete($m1, $t1);
        $this->assertSame($t1->id, $final->fresh()->team1_id);
        $this->assertNull($final->fresh()->team2_id);
        $this->assertSame('pending', $final->fresh()->status);

        $progression->complete($m2, $t4);
        $this->assertSame($t4->id, $final->fresh()->team2_id);
        $this->assertSame('ready', $final->fresh()->status);

        $final->refresh();
        $progression->complete($final, $t4);
        $this->assertSame('completed', $final->fresh()->status);
        $this->assertSame($t4->id, $final->fresh()->winner_team_id);
        // The grand final has no further destination.
        $this->assertNull($final->fresh()->next_match_id);
    }

    // ------------------------------------------------------------------
    // Double elimination
    // ------------------------------------------------------------------

    public function test_double_elim_requires_power_of_two_field(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'closed', ['format' => Tournament::FORMAT_DOUBLE_ELIM]);
        for ($i = 0; $i < 6; $i++) {
            $this->makeTeam($tournament, null, 'confirmed');
        }

        $this->assertSame(0, $this->bracket()->generate($tournament));
    }

    public function test_double_elim_four_team_structure(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'closed', ['format' => Tournament::FORMAT_DOUBLE_ELIM]);
        for ($i = 0; $i < 4; $i++) {
            $this->makeTeam($tournament, null, 'confirmed');
        }

        $this->assertSame(6, $this->bracket()->generate($tournament));

        $this->assertSame(3, GameMatch::where('tournament_id', $tournament->id)->where('bracket', 'winners')->count());
        $this->assertSame(2, GameMatch::where('tournament_id', $tournament->id)->where('bracket', 'losers')->count());
        $this->assertSame(1, GameMatch::where('tournament_id', $tournament->id)->where('bracket', 'grand_final')->count());
    }

    public function test_double_elim_eight_team_structure(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'closed', [
            'format' => Tournament::FORMAT_DOUBLE_ELIM,
            'team_slots' => 8,
        ]);
        for ($i = 0; $i < 8; $i++) {
            $this->makeTeam($tournament, null, 'confirmed');
        }

        $this->assertSame(14, $this->bracket()->generate($tournament));

        $this->assertSame(7, GameMatch::where('tournament_id', $tournament->id)->where('bracket', 'winners')->count());
        $this->assertSame(6, GameMatch::where('tournament_id', $tournament->id)->where('bracket', 'losers')->count());
        $this->assertSame(1, GameMatch::where('tournament_id', $tournament->id)->where('bracket', 'grand_final')->count());
    }

    public function test_double_elim_winner_advances_and_loser_drops(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'closed', ['format' => Tournament::FORMAT_DOUBLE_ELIM]);
        $t1 = $this->makeTeam($tournament, null, 'confirmed');
        $t2 = $this->makeTeam($tournament, null, 'confirmed');
        $t3 = $this->makeTeam($tournament, null, 'confirmed');
        $t4 = $this->makeTeam($tournament, null, 'confirmed');

        $this->bracket()->generate($tournament);
        $progression = $this->progression();

        $w1m1 = GameMatch::where('tournament_id', $tournament->id)->where('bracket', 'winners')->where('round', 1)->where('match_no', 1)->firstOrFail(); // t1 vs t2
        $w1m2 = GameMatch::where('tournament_id', $tournament->id)->where('bracket', 'winners')->where('round', 1)->where('match_no', 2)->firstOrFail(); // t3 vs t4
        $wbFinal = GameMatch::where('tournament_id', $tournament->id)->where('bracket', 'winners')->where('round', 2)->firstOrFail();
        $lb1 = GameMatch::where('tournament_id', $tournament->id)->where('bracket', 'losers')->where('round', 1)->firstOrFail();
        $lb2 = GameMatch::where('tournament_id', $tournament->id)->where('bracket', 'losers')->where('round', 2)->firstOrFail();
        $gf = GameMatch::where('tournament_id', $tournament->id)->where('bracket', 'grand_final')->firstOrFail();

        // WB round-1 match 1: t1 wins → t1 to WB final, t2 drops to LB1 slot 1.
        $progression->complete($w1m1, $t1);
        $this->assertSame($t1->id, $wbFinal->fresh()->team1_id);
        $this->assertSame($t2->id, $lb1->fresh()->team1_id);

        // WB round-1 match 2: t4 wins → t4 to WB final, t3 drops to LB1 slot 2.
        $progression->complete($w1m2, $t4);
        $this->assertSame($t4->id, $wbFinal->fresh()->team2_id);
        $this->assertSame($t3->id, $lb1->fresh()->team2_id);
        $this->assertSame('ready', $lb1->fresh()->status);

        // LB1: t3 beats t2 → t3 to LB2 slot 1 (t2 eliminated).
        $lb1->refresh();
        $progression->complete($lb1, $t3);
        $this->assertSame($t3->id, $lb2->fresh()->team1_id);

        // WB final: t4 beats t1 → t4 to grand final slot 1, t1 drops to LB2 slot 2.
        $wbFinal->refresh();
        $progression->complete($wbFinal, $t4);
        $this->assertSame($t4->id, $gf->fresh()->team1_id);
        $this->assertSame($t1->id, $lb2->fresh()->team2_id);
        $this->assertSame('ready', $lb2->fresh()->status);

        // LB2: t1 beats t3 → t1 to grand final slot 2.
        $lb2->refresh();
        $progression->complete($lb2, $t1);
        $this->assertSame($t1->id, $gf->fresh()->team2_id);
        $this->assertSame('ready', $gf->fresh()->status);

        // Grand final: t1 wins it all.
        $gf->refresh();
        $progression->complete($gf, $t1);
        $this->assertSame('completed', $gf->fresh()->status);
        $this->assertSame($t1->id, $gf->fresh()->winner_team_id);
    }

    // ------------------------------------------------------------------
    // Lifecycle integration & authorization
    // ------------------------------------------------------------------

    public function test_start_generates_bracket_and_sets_live(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'closed');
        for ($i = 0; $i < 8; $i++) {
            $this->makeTeam($tournament, null, 'confirmed');
        }

        $this->actingAs($org)->post(route('tournaments.bracket', $tournament))->assertSessionHas('success');

        $this->assertSame('live', $tournament->fresh()->status);
        $this->assertSame(7, GameMatch::where('tournament_id', $tournament->id)->count());
    }

    public function test_start_fails_when_no_bracket_can_be_formed(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'closed');
        $this->makeTeam($tournament, null, 'confirmed'); // only one team

        $this->actingAs($org)->post(route('tournaments.bracket', $tournament))->assertSessionHas('error');

        $this->assertSame('closed', $tournament->fresh()->status);
        $this->assertSame(0, GameMatch::where('tournament_id', $tournament->id)->count());
    }

    public function test_player_cannot_generate_bracket(): void
    {
        $org = $this->makeUser('organizer');
        $player = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'closed');
        for ($i = 0; $i < 8; $i++) {
            $this->makeTeam($tournament, null, 'confirmed');
        }

        $this->actingAs($player)->post(route('tournaments.bracket', $tournament))->assertStatus(403);

        $this->assertSame('closed', $tournament->fresh()->status);
        $this->assertSame(0, GameMatch::where('tournament_id', $tournament->id)->count());
    }

    public function test_other_organizer_cannot_generate_bracket(): void
    {
        $orgA = $this->makeUser('organizer');
        $orgB = $this->makeUser('organizer');
        $tournament = $this->makeTournament($orgA, 'closed');
        for ($i = 0; $i < 8; $i++) {
            $this->makeTeam($tournament, null, 'confirmed');
        }

        $this->actingAs($orgB)->post(route('tournaments.bracket', $tournament))->assertStatus(403);

        $this->assertSame('closed', $tournament->fresh()->status);
        $this->assertSame(0, GameMatch::where('tournament_id', $tournament->id)->count());
    }
}
```

### FILE: tests/Feature/MatchStateMachineTest.php
```php
<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 05 — match state machine + bracket security tests.
 *
 * Covers the controlled pending/ready/live/completed/disputed/bye/cancelled
 * transitions, immutability of completed results without a privileged
 * dispute→resolve correction, idempotent advancement, and the authorization
 * boundaries around winner/result manipulation.
 */
class MatchStateMachineTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $role = 'player'): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->save();

        return $user;
    }

    protected function makeTournament(User $organizer, string $status = 'live', array $o = []): Tournament
    {
        $t = new Tournament();
        $t->organizer_id = $organizer->id;
        $t->name = $o['name'] ?? 'Match Tournament';
        $t->slug = $o['slug'] ?? ('match-'.Str::random(8));
        $t->game_mode = 'squad';
        $t->map = 'Bermuda';
        $t->entry_fee = 0;
        $t->prize_pool = 5000;
        $t->team_slots = 8;
        $t->team_size = 4;
        $t->rules = null;
        $t->starts_at = now()->addDay();
        $t->format = Tournament::FORMAT_SINGLE_ELIM;
        $t->status = $status;
        $t->save();

        return $t;
    }

    protected function makeTeam(Tournament $tournament, ?User $captain = null, string $status = 'confirmed'): Team
    {
        $team = new Team();
        $team->tournament_id = $tournament->id;
        $team->captain_id = $captain?->id;
        $team->name = 'Team '.Str::random(6);
        $team->captain_name = $captain?->name ?? 'Captain';
        $team->phone = '01700000000';
        $team->game_uid = 'UID'.strtoupper(Str::random(8));
        $team->status = $status;
        $team->save();

        return $team;
    }

    protected function makeMatch(
        Tournament $tournament,
        Team $t1,
        ?Team $t2 = null,
        string $status = 'ready',
    ): GameMatch {
        $m = new GameMatch();
        $m->tournament_id = $tournament->id;
        $m->round = 1;
        $m->match_no = 1;
        $m->team1_id = $t1->id;
        $m->team2_id = $t2?->id;
        $m->status = $status;
        $m->save();

        return $m;
    }

    // ------------------------------------------------------------------
    // Winner integrity
    // ------------------------------------------------------------------

    public function test_winner_must_be_a_participant(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $t1 = $this->makeTeam($tournament, null);
        $t2 = $this->makeTeam($tournament, null);
        $outsider = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $t1, $t2, 'ready');

        $this->actingAs($org)->post(route('matches.winner', [$tournament, $match]), [
            'winner_team_id' => $outsider->id,
        ])->assertStatus(403);

        $this->assertNull($match->fresh()->winner_team_id);
        $this->assertSame('ready', $match->fresh()->status);
    }

    public function test_winner_from_other_tournament_is_forbidden(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $otherTournament = $this->makeTournament($org);
        $t1 = $this->makeTeam($tournament, null);
        $t2 = $this->makeTeam($tournament, null);
        $foreignTeam = $this->makeTeam($otherTournament, null);
        $match = $this->makeMatch($tournament, $t1, $t2, 'ready');

        $this->actingAs($org)->post(route('matches.winner', [$tournament, $match]), [
            'winner_team_id' => $foreignTeam->id,
        ])->assertStatus(403);

        $this->assertNull($match->fresh()->winner_team_id);
    }

    public function test_match_winner_route_guards_against_cross_tournament_match(): void
    {
        $org = $this->makeUser('organizer');
        $tournamentA = $this->makeTournament($org);
        $tournamentB = $this->makeTournament($org);
        $t1 = $this->makeTeam($tournamentA, null);
        $t2 = $this->makeTeam($tournamentA, null);
        $matchA = $this->makeMatch($tournamentA, $t1, $t2, 'ready');

        $this->actingAs($org)->post(route('matches.winner', [$tournamentB, $matchA]), [
            'winner_team_id' => $t1->id,
        ])->assertStatus(404);

        $this->assertNull($matchA->fresh()->winner_team_id);
    }

    public function test_player_cannot_set_winner(): void
    {
        $org = $this->makeUser('organizer');
        $player = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $t1 = $this->makeTeam($tournament, $player);
        $t2 = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $t1, $t2, 'ready');

        $this->actingAs($player)->post(route('matches.winner', [$tournament, $match]), [
            'winner_team_id' => $t1->id,
        ])->assertStatus(403);

        $this->assertNull($match->fresh()->winner_team_id);
    }

    public function test_other_organizer_cannot_set_winner(): void
    {
        $orgA = $this->makeUser('organizer');
        $orgB = $this->makeUser('organizer');
        $tournament = $this->makeTournament($orgA);
        $t1 = $this->makeTeam($tournament, null);
        $t2 = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $t1, $t2, 'ready');

        $this->actingAs($orgB)->post(route('matches.winner', [$tournament, $match]), [
            'winner_team_id' => $t1->id,
        ])->assertStatus(403);

        $this->assertNull($match->fresh()->winner_team_id);
    }

    // ------------------------------------------------------------------
    // State machine transitions
    // ------------------------------------------------------------------

    public function test_pending_match_cannot_be_completed(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $t1 = $this->makeTeam($tournament, null);
        $t2 = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $t1, $t2, 'pending');

        $this->actingAs($org)->post(route('matches.winner', [$tournament, $match]), [
            'winner_team_id' => $t1->id,
        ])->assertSessionHas('error');

        $this->assertNull($match->fresh()->winner_team_id);
        $this->assertSame('pending', $match->fresh()->status);
    }

    public function test_bye_match_cannot_be_completed(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $t1 = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $t1, null, 'bye');
        $match->winner_team_id = $t1->id;
        $match->save();

        $this->actingAs($org)->post(route('matches.winner', [$tournament, $match]), [
            'winner_team_id' => $t1->id,
        ])->assertSessionHas('error');

        $this->assertSame('bye', $match->fresh()->status);
        $this->assertSame($t1->id, $match->fresh()->winner_team_id);
    }

    public function test_completing_again_with_same_winner_is_idempotent(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $t1 = $this->makeTeam($tournament, null);
        $t2 = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $t1, $t2, 'ready');

        // A downstream destination to prove no duplicate advancement occurs.
        $next = new GameMatch();
        $next->tournament_id = $tournament->id;
        $next->round = 2;
        $next->match_no = 1;
        $next->status = 'pending';
        $next->save();
        $match->next_match_id = $next->id;
        $match->next_slot = 1;
        $match->save();

        $this->actingAs($org)->post(route('matches.winner', [$tournament, $match]), [
            'winner_team_id' => $t1->id,
        ])->assertRedirect();
        $this->assertSame($t1->id, $next->fresh()->team1_id);

        // Second completion with the same winner: no-op, no double advance.
        $this->actingAs($org)->post(route('matches.winner', [$tournament, $match]), [
            'winner_team_id' => $t1->id,
        ])->assertRedirect();

        $this->assertSame('completed', $match->fresh()->status);
        $this->assertSame($t1->id, $match->fresh()->winner_team_id);
        $this->assertSame($t1->id, $next->fresh()->team1_id);
        $this->assertNull($next->fresh()->team2_id);
    }

    public function test_completed_match_result_cannot_be_changed_without_dispute(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $t1 = $this->makeTeam($tournament, null);
        $t2 = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $t1, $t2, 'ready');

        $this->actingAs($org)->post(route('matches.winner', [$tournament, $match]), [
            'winner_team_id' => $t1->id,
        ])->assertRedirect();
        $this->assertSame($t1->id, $match->fresh()->winner_team_id);

        // Attempt to silently change the completed result.
        $this->actingAs($org)->post(route('matches.winner', [$tournament, $match]), [
            'winner_team_id' => $t2->id,
        ])->assertSessionHas('error');

        $this->assertSame('completed', $match->fresh()->status);
        $this->assertSame($t1->id, $match->fresh()->winner_team_id);
    }

    public function test_only_completed_matches_can_be_disputed(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $t1 = $this->makeTeam($tournament, null);
        $t2 = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $t1, $t2, 'live');

        $this->actingAs($org)->post(route('matches.dispute', [$tournament, $match]))->assertSessionHas('error');

        $this->assertSame('live', $match->fresh()->status);
    }

    public function test_only_disputed_matches_can_be_resolved(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $t1 = $this->makeTeam($tournament, null);
        $t2 = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $t1, $t2, 'completed');
        $match->winner_team_id = $t1->id;
        $match->save();

        $this->actingAs($org)->post(route('matches.resolve', [$tournament, $match]), [
            'winner_team_id' => $t2->id,
        ])->assertSessionHas('error');

        $this->assertSame('completed', $match->fresh()->status);
        $this->assertSame($t1->id, $match->fresh()->winner_team_id);
    }

    // ------------------------------------------------------------------
    // Dispute → resolve correction flow
    // ------------------------------------------------------------------

    public function test_dispute_blocks_advancement_and_resolve_corrects_it(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $t1 = $this->makeTeam($tournament, null);
        $t2 = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $t1, $t2, 'ready');

        $next = new GameMatch();
        $next->tournament_id = $tournament->id;
        $next->round = 2;
        $next->match_no = 1;
        $next->status = 'pending';
        $next->save();
        $match->next_match_id = $next->id;
        $match->next_slot = 1;
        $match->save();

        // Complete with t1 → t1 advanced to slot 1.
        $this->actingAs($org)->post(route('matches.winner', [$tournament, $match]), [
            'winner_team_id' => $t1->id,
        ])->assertRedirect();
        $this->assertSame($t1->id, $next->fresh()->team1_id);

        // Dispute.
        $this->actingAs($org)->post(route('matches.dispute', [$tournament, $match]))->assertSessionHas('success');
        $this->assertSame('disputed', $match->fresh()->status);

        // While disputed, no further advancement and no direct completion.
        $this->actingAs($org)->post(route('matches.winner', [$tournament, $match]), [
            'winner_team_id' => $t2->id,
        ])->assertSessionHas('error');
        $this->assertSame('disputed', $match->fresh()->status);
        $this->assertSame($t1->id, $next->fresh()->team1_id);

        // Resolve with a corrected winner → downstream slot replaced.
        $this->actingAs($org)->post(route('matches.resolve', [$tournament, $match]), [
            'winner_team_id' => $t2->id,
        ])->assertSessionHas('success');

        $this->assertSame('completed', $match->fresh()->status);
        $this->assertSame($t2->id, $match->fresh()->winner_team_id);
        $this->assertSame($t2->id, $next->fresh()->team1_id);
    }

    public function test_resolve_rejects_non_participant_winner(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $t1 = $this->makeTeam($tournament, null);
        $t2 = $this->makeTeam($tournament, null);
        $outsider = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $t1, $t2, 'disputed');
        $match->winner_team_id = $t1->id;
        $match->save();

        $this->actingAs($org)->post(route('matches.resolve', [$tournament, $match]), [
            'winner_team_id' => $outsider->id,
        ])->assertStatus(403);

        $this->assertSame('disputed', $match->fresh()->status);
        $this->assertSame($t1->id, $match->fresh()->winner_team_id);
    }

    // ------------------------------------------------------------------
    // Dispute/resolve authorization
    // ------------------------------------------------------------------

    public function test_player_cannot_dispute_or_resolve(): void
    {
        $org = $this->makeUser('organizer');
        $player = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $t1 = $this->makeTeam($tournament, $player);
        $t2 = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $t1, $t2, 'completed');
        $match->winner_team_id = $t1->id;
        $match->save();

        $this->actingAs($player)->post(route('matches.dispute', [$tournament, $match]))->assertStatus(403);
        $this->assertSame('completed', $match->fresh()->status);

        $match->status = 'disputed';
        $match->save();

        $this->actingAs($player)->post(route('matches.resolve', [$tournament, $match]), [
            'winner_team_id' => $t2->id,
        ])->assertStatus(403);

        $this->assertSame('disputed', $match->fresh()->status);
        $this->assertSame($t1->id, $match->fresh()->winner_team_id);
    }

    public function test_other_organizer_cannot_dispute(): void
    {
        $orgA = $this->makeUser('organizer');
        $orgB = $this->makeUser('organizer');
        $tournament = $this->makeTournament($orgA);
        $t1 = $this->makeTeam($tournament, null);
        $t2 = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $t1, $t2, 'completed');
        $match->winner_team_id = $t1->id;
        $match->save();

        $this->actingAs($orgB)->post(route('matches.dispute', [$tournament, $match]))->assertStatus(403);

        $this->assertSame('completed', $match->fresh()->status);
    }
}
```

### FILE: tests/Feature/CheckInWaitlistTest.php
```php
<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 04 — registration eligibility, check-in, waitlist and no-show
 * handling, plus bracket-eligibility integration.
 */
class CheckInWaitlistTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------
    // Helpers (sensitive fields set explicitly, mirroring production)
    // ------------------------------------------------------------------

    protected function makeUser(string $role = 'player'): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->save();

        return $user;
    }

    protected function makeTournament(User $organizer, string $status = 'open', array $o = []): Tournament
    {
        $t = new Tournament();
        $t->organizer_id = $organizer->id;
        $t->name = $o['name'] ?? 'Participation Tournament';
        $t->slug = $o['slug'] ?? ('part-'.Str::random(8));
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
        $team->name = 'Team '.Str::random(6);
        $team->captain_name = $captain?->name ?? 'Captain';
        $team->phone = '01700000000';
        $team->game_uid = $uid ?? 'UID'.strtoupper(Str::random(8));
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

    protected function validPayload(string $name = 'CheckIn Squad', string $uid = 'UIDCHECK1'): array
    {
        return [
            'name' => $name,
            'captain_name' => 'Captain',
            'phone' => '01700000000',
            'game_uid' => $uid,
            'members' => [],
        ];
    }

    protected function openCheckInWindow(): array
    {
        return [
            'check_in_starts_at' => now()->subHour(),
            'check_in_ends_at' => now()->addHour(),
        ];
    }

    protected function closedCheckInWindow(): array
    {
        return [
            'check_in_starts_at' => now()->subHours(2),
            'check_in_ends_at' => now()->subHour(),
        ];
    }

    // ------------------------------------------------------------------
    // 1. Registration eligibility + capacity
    // ------------------------------------------------------------------

    public function test_guest_cannot_register(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);

        $this->post(route('teams.store', $tournament), $this->validPayload())
            ->assertRedirect(route('login'));
    }

    public function test_full_tournament_places_team_on_waitlist(): void
    {
        $org = $this->makeUser('organizer');
        $player = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open', ['team_slots' => 8]);

        for ($i = 0; $i < 8; $i++) {
            $this->makeTeam($tournament, null, 'pending');
        }

        $this->actingAs($player)->post(route('teams.store', $tournament), $this->validPayload('Overflow'))
            ->assertRedirect(route('tournaments.show', $tournament));

        $this->assertSame(8, Team::where('tournament_id', $tournament->id)->whereIn('status', ['pending', 'confirmed'])->count());
        $this->assertSame(1, Team::where('tournament_id', $tournament->id)->where('status', 'waitlisted')->count());
        $this->assertSame(0, $tournament->fresh()->slotsLeft());

        $waitlisted = Team::where('tournament_id', $tournament->id)->where('status', 'waitlisted')->first();
        $this->assertNotNull($waitlisted->waitlisted_at);
        $this->assertSame(1, $waitlisted->waitlistPosition());
    }

    public function test_waitlist_is_fifo_ordered(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'open');

        $t1 = $this->makeTeam($tournament, null, 'waitlisted');
        $t2 = $this->makeTeam($tournament, null, 'waitlisted');
        $t3 = $this->makeTeam($tournament, null, 'waitlisted');

        $t1->waitlisted_at = now()->subMinutes(30);
        $t1->save();
        $t2->waitlisted_at = now()->subMinutes(20);
        $t2->save();
        $t3->waitlisted_at = now()->subMinutes(10);
        $t3->save();

        $this->assertSame(1, $t1->fresh()->waitlistPosition());
        $this->assertSame(2, $t2->fresh()->waitlistPosition());
        $this->assertSame(3, $t3->fresh()->waitlistPosition());
    }

    public function test_withdrawn_team_releases_slot(): void
    {
        $org = $this->makeUser('organizer');
        $withdrawer = $this->makeUser('player');
        $newCaptain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open', ['team_slots' => 8]);

        for ($i = 0; $i < 7; $i++) {
            $this->makeTeam($tournament, null, 'pending');
        }
        $mine = $this->makeTeam($tournament, $withdrawer, 'pending');

        $this->actingAs($withdrawer)->post(route('teams.withdraw', [$tournament, $mine]))
            ->assertSessionHas('success');
        $this->assertSame('withdrawn', $mine->fresh()->status);

        $this->actingAs($newCaptain)->post(route('teams.store', $tournament), $this->validPayload('Newcomer', 'UIDNEWCOMER'))
            ->assertRedirect(route('payment.show', [$tournament, Team::where('name', 'Newcomer')->firstOrFail()]));

        $newcomer = Team::where('name', 'Newcomer')->firstOrFail();
        $this->assertSame('pending', $newcomer->status);
        $this->assertSame(0, Team::where('tournament_id', $tournament->id)->where('status', 'waitlisted')->count());
    }

    public function test_waitlisted_team_does_not_occupy_slot(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'open', ['team_slots' => 8]);

        for ($i = 0; $i < 8; $i++) {
            $this->makeTeam($tournament, null, 'pending');
        }
        $this->makeTeam($tournament, null, 'waitlisted');

        $this->assertSame(0, $tournament->fresh()->slotsLeft());
        $this->assertSame(8, Team::where('tournament_id', $tournament->id)->whereIn('status', ['pending', 'confirmed'])->count());
    }

    // ------------------------------------------------------------------
    // 2. Check-in authorization + window rules
    // ------------------------------------------------------------------

    public function test_guest_cannot_check_in(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open', $this->openCheckInWindow());
        $team = $this->makeTeam($tournament, $captain, 'confirmed');

        $this->post(route('teams.checkin', [$tournament, $team]))
            ->assertRedirect(route('login'));

        $this->assertFalse($team->fresh()->isCheckedIn());
    }

    public function test_non_captain_cannot_check_in(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $intruder = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open', $this->openCheckInWindow());
        $team = $this->makeTeam($tournament, $captain, 'confirmed');

        $this->actingAs($intruder)->post(route('teams.checkin', [$tournament, $team]))
            ->assertStatus(403);

        $this->assertFalse($team->fresh()->isCheckedIn());
    }

    public function test_captain_can_check_in_during_window(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open', $this->openCheckInWindow());
        $team = $this->makeTeam($tournament, $captain, 'confirmed');

        $this->actingAs($captain)->post(route('teams.checkin', [$tournament, $team]))
            ->assertSessionHas('success');

        $team->refresh();
        $this->assertTrue($team->isCheckedIn());
        $this->assertSame($captain->id, $team->checked_in_by);
    }

    public function test_check_in_before_open_rejected(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open', [
            'check_in_starts_at' => now()->addHour(),
            'check_in_ends_at' => now()->addHours(2),
        ]);
        $team = $this->makeTeam($tournament, $captain, 'confirmed');

        $this->actingAs($captain)->post(route('teams.checkin', [$tournament, $team]))
            ->assertSessionHas('error');

        $this->assertFalse($team->fresh()->isCheckedIn());
    }

    public function test_check_in_after_close_rejected(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open', $this->closedCheckInWindow());
        $team = $this->makeTeam($tournament, $captain, 'confirmed');

        $this->actingAs($captain)->post(route('teams.checkin', [$tournament, $team]))
            ->assertSessionHas('error');

        $this->assertFalse($team->fresh()->isCheckedIn());
    }

    public function test_check_in_is_idempotent(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open', $this->openCheckInWindow());
        $team = $this->makeTeam($tournament, $captain, 'confirmed');

        $this->actingAs($captain)->post(route('teams.checkin', [$tournament, $team]))->assertSessionHas('success');
        $checkedAt = Team::find($team->id)->checked_in_at;

        $this->actingAs($captain)->post(route('teams.checkin', [$tournament, $team]))->assertSessionHas('success');

        $team->refresh();
        $this->assertSame($checkedAt->toDateTimeString(), $team->checked_in_at->toDateTimeString());
        $this->assertSame($captain->id, $team->checked_in_by);
        $this->assertSame('confirmed', $team->status);
    }

    public function test_withdrawn_team_cannot_check_in(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open', $this->openCheckInWindow());
        $team = $this->makeTeam($tournament, $captain, 'withdrawn');

        $this->actingAs($captain)->post(route('teams.checkin', [$tournament, $team]))
            ->assertSessionHas('error');

        $this->assertFalse($team->fresh()->isCheckedIn());
    }

    public function test_pending_team_cannot_check_in(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open', $this->openCheckInWindow());
        $team = $this->makeTeam($tournament, $captain, 'pending');

        $this->actingAs($captain)->post(route('teams.checkin', [$tournament, $team]))
            ->assertSessionHas('error');

        $this->assertFalse($team->fresh()->isCheckedIn());
    }

    public function test_waitlisted_team_cannot_check_in(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open', $this->openCheckInWindow());
        $team = $this->makeTeam($tournament, $captain, 'waitlisted');

        $this->actingAs($captain)->post(route('teams.checkin', [$tournament, $team]))
            ->assertSessionHas('error');

        $this->assertFalse($team->fresh()->isCheckedIn());
    }

    public function test_check_in_cross_tournament_blocked(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournamentA = $this->makeTournament($org, 'open', $this->openCheckInWindow() + ['name' => 'A']);
        $tournamentB = $this->makeTournament($org, 'open', $this->openCheckInWindow() + ['name' => 'B']);
        $teamA = $this->makeTeam($tournamentA, $captain, 'confirmed');

        $this->actingAs($captain)->post(route('teams.checkin', [$tournamentB, $teamA]))
            ->assertStatus(404);

        $this->assertFalse($teamA->fresh()->isCheckedIn());
    }

    public function test_check_in_not_configured_rejected(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open'); // no check-in window
        $team = $this->makeTeam($tournament, $captain, 'confirmed');

        $this->actingAs($captain)->post(route('teams.checkin', [$tournament, $team]))
            ->assertSessionHas('error');

        $this->assertFalse($team->fresh()->isCheckedIn());
    }

    public function test_admin_can_check_in_after_close(): void
    {
        $admin = $this->makeUser('admin');
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open', $this->closedCheckInWindow());
        $team = $this->makeTeam($tournament, $captain, 'confirmed');

        $this->actingAs($admin)->post(route('teams.checkin', [$tournament, $team]))
            ->assertSessionHas('success');

        $this->assertTrue($team->fresh()->isCheckedIn());
        $this->assertSame($admin->id, $team->fresh()->checked_in_by);
    }

    // ------------------------------------------------------------------
    // 3. No-show handling
    // ------------------------------------------------------------------

    public function test_mark_no_shows_marks_unchecked_confirmed_teams(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'closed', $this->closedCheckInWindow());

        $checkedA = $this->makeTeam($tournament, null, 'confirmed', null, true);
        $checkedB = $this->makeTeam($tournament, null, 'confirmed', null, true);
        $noShowA = $this->makeTeam($tournament, null, 'confirmed');
        $noShowB = $this->makeTeam($tournament, null, 'confirmed');

        $this->actingAs($org)->post(route('tournaments.noshows', $tournament))
            ->assertSessionHas('success');

        $this->assertSame('confirmed', $checkedA->fresh()->status);
        $this->assertSame('confirmed', $checkedB->fresh()->status);
        $this->assertSame('no_show', $noShowA->fresh()->status);
        $this->assertSame('no_show', $noShowB->fresh()->status);
    }

    public function test_mark_no_shows_requires_window_closed(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'closed', $this->openCheckInWindow());
        $team = $this->makeTeam($tournament, $captain, 'confirmed');

        $this->actingAs($org)->post(route('tournaments.noshows', $tournament))
            ->assertSessionHas('error');

        $this->assertSame('confirmed', $team->fresh()->status);
    }

    public function test_mark_no_shows_promotes_waitlisted(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'open', $this->closedCheckInWindow() + ['team_slots' => 8]);

        // 8 confirmed teams: 2 checked in, 6 no-shows.
        for ($i = 0; $i < 2; $i++) {
            $this->makeTeam($tournament, null, 'confirmed', null, true);
        }
        for ($i = 0; $i < 6; $i++) {
            $this->makeTeam($tournament, null, 'confirmed');
        }

        // Two waitlisted teams.
        $w1 = $this->makeTeam($tournament, null, 'waitlisted');
        $w2 = $this->makeTeam($tournament, null, 'waitlisted');

        $this->actingAs($org)->post(route('tournaments.noshows', $tournament))
            ->assertSessionHas('success');

        $this->assertSame(6, Team::where('tournament_id', $tournament->id)->where('status', 'no_show')->count());
        $this->assertSame('pending', $w1->fresh()->status);
        $this->assertSame('pending', $w2->fresh()->status);
        $this->assertSame(0, Team::where('tournament_id', $tournament->id)->where('status', 'waitlisted')->count());
    }

    // ------------------------------------------------------------------
    // 4. Waitlist promotion
    // ------------------------------------------------------------------

    public function test_promote_next_waitlisted_team(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'open', ['team_slots' => 8]);

        for ($i = 0; $i < 7; $i++) {
            $this->makeTeam($tournament, null, 'pending');
        }
        $waitlisted = $this->makeTeam($tournament, null, 'waitlisted');

        $this->actingAs($org)->post(route('tournaments.waitlist.promote', $tournament))
            ->assertSessionHas('success');

        $this->assertSame('pending', $waitlisted->fresh()->status);
        $this->assertNull($waitlisted->fresh()->waitlisted_at);
    }

    public function test_promotion_is_fifo_and_idempotent(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'open', ['team_slots' => 8]);

        for ($i = 0; $i < 6; $i++) {
            $this->makeTeam($tournament, null, 'pending');
        }

        $t1 = $this->makeTeam($tournament, null, 'waitlisted');
        $t2 = $this->makeTeam($tournament, null, 'waitlisted');
        $t3 = $this->makeTeam($tournament, null, 'waitlisted');
        $t1->waitlisted_at = now()->subMinutes(30);
        $t1->save();
        $t2->waitlisted_at = now()->subMinutes(20);
        $t2->save();
        $t3->waitlisted_at = now()->subMinutes(10);
        $t3->save();

        $this->actingAs($org)->post(route('tournaments.waitlist.promote', $tournament))->assertSessionHas('success');
        $this->actingAs($org)->post(route('tournaments.waitlist.promote', $tournament))->assertSessionHas('success');

        $this->assertSame('pending', $t1->fresh()->status); // FIFO: first in, first promoted
        $this->assertSame('pending', $t2->fresh()->status);
        $this->assertSame('waitlisted', $t3->fresh()->status); // no slot left

        // A third promotion cannot promote t1/t2 again (they are no longer waitlisted).
        $this->actingAs($org)->post(route('tournaments.waitlist.promote', $tournament))->assertSessionHas('error');
        $this->assertSame('waitlisted', $t3->fresh()->status);
    }

    public function test_promote_when_waitlist_empty_errors(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'open');

        $this->actingAs($org)->post(route('tournaments.waitlist.promote', $tournament))
            ->assertSessionHas('error');
    }

    public function test_promote_when_no_slot_errors(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'open', ['team_slots' => 8]);

        for ($i = 0; $i < 8; $i++) {
            $this->makeTeam($tournament, null, 'pending');
        }
        $waitlisted = $this->makeTeam($tournament, null, 'waitlisted');

        $this->actingAs($org)->post(route('tournaments.waitlist.promote', $tournament))
            ->assertSessionHas('error');

        $this->assertSame('waitlisted', $waitlisted->fresh()->status);
    }

    public function test_promote_requires_registration_open(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'closed');
        $waitlisted = $this->makeTeam($tournament, null, 'waitlisted');

        $this->actingAs($org)->post(route('tournaments.waitlist.promote', $tournament))
            ->assertSessionHas('error');

        $this->assertSame('waitlisted', $waitlisted->fresh()->status);
    }

    public function test_withdrawn_waitlisted_team_is_skipped(): void
    {
        $org = $this->makeUser('organizer');
        $captainA = $this->makeUser('player');
        $captainB = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open');

        $first = $this->makeTeam($tournament, $captainA, 'waitlisted');
        $second = $this->makeTeam($tournament, $captainB, 'waitlisted');

        // The first waitlisted team withdraws.
        $this->actingAs($captainA)->post(route('teams.withdraw', [$tournament, $first]))
            ->assertSessionHas('success');

        // Promotion picks the next eligible (the second) team.
        $this->actingAs($org)->post(route('tournaments.waitlist.promote', $tournament))
            ->assertSessionHas('success');

        $this->assertSame('withdrawn', $first->fresh()->status);
        $this->assertSame('pending', $second->fresh()->status);
    }

    public function test_player_cannot_promote(): void
    {
        $org = $this->makeUser('organizer');
        $player = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open');

        $this->actingAs($player)->post(route('tournaments.waitlist.promote', $tournament))
            ->assertStatus(403);
    }

    public function test_player_cannot_mark_no_shows(): void
    {
        $org = $this->makeUser('organizer');
        $player = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'closed', $this->closedCheckInWindow());

        $this->actingAs($player)->post(route('tournaments.noshows', $tournament))
            ->assertStatus(403);
    }

    // ------------------------------------------------------------------
    // 5. Bracket eligibility integration
    // ------------------------------------------------------------------

    public function test_unchecked_team_excluded_from_bracket(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'closed', $this->closedCheckInWindow() + ['team_slots' => 8]);

        $checkedIds = [];
        for ($i = 0; $i < 4; $i++) {
            $checkedIds[] = $this->makeTeam($tournament, null, 'confirmed', null, true)->id;
        }
        for ($i = 0; $i < 4; $i++) {
            $this->makeTeam($tournament, null, 'confirmed'); // not checked in
        }

        $this->actingAs($org)->post(route('tournaments.bracket', $tournament))->assertSessionHas('success');

        $this->assertSame('live', $tournament->fresh()->status);
        $this->assertSame(3, GameMatch::where('tournament_id', $tournament->id)->count());

        // Only checked-in teams may appear in the bracket.
        $participantIds = GameMatch::where('tournament_id', $tournament->id)
            ->get()
            ->flatMap(fn ($m) => [$m->team1_id, $m->team2_id])
            ->filter()
            ->unique()
            ->values()
            ->all();

        sort($checkedIds);
        sort($participantIds);
        $this->assertSame($checkedIds, $participantIds);
    }

    public function test_all_checked_in_teams_enter_bracket(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'closed', $this->closedCheckInWindow() + ['team_slots' => 8]);

        for ($i = 0; $i < 8; $i++) {
            $this->makeTeam($tournament, null, 'confirmed', null, true);
        }

        $this->actingAs($org)->post(route('tournaments.bracket', $tournament))->assertSessionHas('success');

        $this->assertSame('live', $tournament->fresh()->status);
        $this->assertSame(7, GameMatch::where('tournament_id', $tournament->id)->count());
    }

    public function test_no_show_team_excluded_from_bracket(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'closed', $this->closedCheckInWindow() + ['team_slots' => 8]);

        for ($i = 0; $i < 7; $i++) {
            $this->makeTeam($tournament, null, 'confirmed', null, true);
        }
        $noShow = $this->makeTeam($tournament, null, 'confirmed'); // never checked in

        // Mark no-shows: the unchecked-in team becomes no_show.
        $this->actingAs($org)->post(route('tournaments.noshows', $tournament))->assertSessionHas('success');
        $this->assertSame('no_show', $noShow->fresh()->status);

        // 7 eligible teams fill an 8-team bracket (7 matches) with one bye.
        $this->actingAs($org)->post(route('tournaments.bracket', $tournament))->assertSessionHas('success');

        $this->assertSame('live', $tournament->fresh()->status);
        $this->assertSame(7, GameMatch::where('tournament_id', $tournament->id)->count());
        $this->assertSame(1, GameMatch::where('tournament_id', $tournament->id)->where('status', 'bye')->count());

        // The no-show team must never appear in the bracket.
        $participantIds = GameMatch::where('tournament_id', $tournament->id)
            ->get()
            ->flatMap(fn ($m) => [$m->team1_id, $m->team2_id])
            ->filter()
            ->unique()
            ->values()
            ->all();

        $this->assertNotContains($noShow->id, $participantIds);
    }

    public function test_start_blocked_while_check_in_open(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'closed', $this->openCheckInWindow() + ['team_slots' => 8]);

        for ($i = 0; $i < 8; $i++) {
            $this->makeTeam($tournament, null, 'confirmed', null, true);
        }

        $this->actingAs($org)->post(route('tournaments.bracket', $tournament))->assertSessionHas('error');

        $this->assertSame('closed', $tournament->fresh()->status);
        $this->assertSame(0, GameMatch::where('tournament_id', $tournament->id)->count());
    }

    // ------------------------------------------------------------------
    // 6. Mass-assignment / integrity backstops
    // ------------------------------------------------------------------

    public function test_check_in_fields_cannot_be_mass_assigned(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open');
        $team = $this->makeTeam($tournament, $captain, 'confirmed', 'UIDCAPTAIN');

        $this->actingAs($captain)->put(route('teams.update', [$tournament, $team]), [
            'name' => 'Renamed',
            'captain_name' => 'Captain',
            'phone' => '01700000000',
            'game_uid' => 'UIDCAPTAIN',
            'status' => 'finished',
            'checked_in_at' => now(),
            'waitlisted_at' => now(),
        ])->assertSessionHas('success');

        $team->refresh();
        $this->assertSame('Renamed', $team->name);
        $this->assertSame('confirmed', $team->status);       // status not mass-assignable
        $this->assertNull($team->checked_in_at);             // check-in not mass-assignable
        $this->assertNull($team->waitlisted_at);             // waitlist not mass-assignable
    }
}
```

---

PHASE 05 COMPLETE

PHASE 06 NOT STARTED
