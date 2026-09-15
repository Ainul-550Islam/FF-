# PHASE 04 — TOURNAMENT REGISTRATION + CHECK-IN + WAITLIST
**FF Arena (Free Fire Tournament Platform) — Laravel 12 / SQLite**
**Date:** 2026-09-04 · **Scope:** Registration eligibility, check-in, no-show handling, waitlist, bracket eligibility only (Phase 05 NOT implemented)

---

## A. Audit Before Phase 04

### What existed (verified against actual code)
- **Registration:** Phase 02 atomic slot claim in `TeamController@register`; `acceptsRegistration()` = `open` && not started. Full → hard rejection ("All slots are full.").
- **Capacity:** `tournaments.team_slots`; `slotsLeft()`/`isFull()` count `pending + confirmed` (`Team::SLOT_STATUSES`).
- **Check-in:** **did not exist** — no window fields, no check-in state, no timestamp.
- **Waitlist:** **did not exist** — a full tournament simply rejected further registrations.
- **No-show:** **did not exist** — registered-but-absent teams were indistinguishable from attendees.
- **Bracket eligibility:** `BracketService::generate()` took *all* `status='confirmed'` teams.
- **Team states:** pending / confirmed / rejected / withdrawn.

### Why it mattered
1. A confirmed team that never shows up still consumed a competitive slot and entered the bracket — silently degrading the tournament.
2. A full tournament turned players away with no recourse (no waitlist), and a freed slot (withdrawal) could not be back-filled deterministically.
3. There was no check-in gate at all, so "who actually participates" was not enforced before the bracket froze.
4. Bracket seeding accepted every confirmed team — unchecked-in, no-show and (after Phase 04) waitlisted teams could all be placed into competitive matches.

---

## B. Files Changed

**Modified (16):**
| Path | Reason |
|---|---|
| `app/Models/Tournament.php` | check-in window fields, casts, `hasCheckIn()`/`checkInIsOpen()`/`checkInHasClosed()`, `bracketEligibleTeams()`, `waitlistedTeams()` |
| `app/Models/Team.php` | `STATUS_WAITLISTED`/`STATUS_NO_SHOW`, `COMPETING_STATUSES`, casts, `isCheckedIn()`/`isWaitlisted()`/`isNoShow()`/`waitlistPosition()` |
| `app/Services/RosterService.php` | UID-uniqueness now considers waitlisted teams (`COMPETING_STATUSES`) |
| `app/Services/BracketService.php` | uses `bracketEligibleTeams()` — only eligible teams enter the bracket |
| `app/Services/TournamentLifecycleService.php` | `start()` refuses to run while check-in is open; clearer eligibility error |
| `app/Policies/TeamPolicy.php` | new `checkIn` gate (captain or admin) |
| `app/Http/Controllers/TeamController.php` | waitlist placement on full; `checkIn()` action |
| `app/Http/Controllers/TournamentController.php` | check-in fields in store/update; `markNoShows()` + `promoteWaitlisted()` actions; `show()` passes waitlist |
| `routes/web.php` | 3 new routes (check-in, no-shows, waitlist promote) |
| `database/seeders/DatabaseSeeder.php` | demo open tournament gets a check-in window |
| `resources/views/tournaments/show.blade.php` | check-in status/button, waitlist table, no-show & promote controls, checked-in column |
| `resources/views/teams/show.blade.php` | check-in card |
| `resources/views/tournaments/create.blade.php` | optional check-in window inputs |
| `resources/views/tournaments/edit.blade.php` | optional check-in window inputs |
| `resources/views/layouts/app.blade.php` | pill styles for waitlisted / no_show / checked |
| `tests/Feature/TournamentLifecycleTest.php` | capacity test updated to assert the Phase 04 waitlist behaviour (mandated change) |

**New (3):**
| Path | Reason |
|---|---|
| `app/Services/TournamentParticipationService.php` | check-in, waitlist promotion, no-show processing |
| `database/migrations/2026_09_04_130000_add_checkin_waitlist_fields.php` | check-in window + team check-in/waitlist columns + index |
| `tests/Feature/CheckInWaitlistTest.php` | 33 new tests |

---

## C. Database Changes

One new migration: `2026_09_04_130000_add_checkin_waitlist_fields.php`.

- `tournaments.check_in_starts_at` (nullable timestamp)
- `tournaments.check_in_ends_at` (nullable timestamp)
- `teams.checked_in_at` (nullable timestamp)
- `teams.checked_in_by` (nullable FK → users, nullOnDelete) — audit of who checked in
- `teams.waitlisted_at` (nullable timestamp) — FIFO ordering key
- index `teams_tournament_waitlisted_index` on `(tournament_id, waitlisted_at)`

No existing columns were renamed or destroyed. The unique constraints from Phase 02 (`teams_tournament_captain_unique`) and Phase 03 (`teams_tournament_game_uid_unique`, `team_members_team_uid_unique`) are untouched.

---

## D. Registration Changes

- Eligibility unchanged and explicit: tournament must `acceptsRegistration()` (status `open` AND not started), captain must be authenticated, one team per captain (app check + DB unique), UID integrity (Phase 03) enforced.
- **When the tournament is full**, registration no longer hard-fails: the team is created with `status = waitlisted` + `waitlisted_at = now()` (FIFO), and the captain is redirected to the tournament page with a "you are on the waitlist (position N)" flash. Waitlisted teams do **not** occupy a slot and are **not** sent to payment.
- Withdrawn teams still release their slot, captain claim and UID (Phase 02/03 behaviour preserved).
- Slot claiming remains a single atomic conditional `UPDATE` (SQLite-compatible), so two captains racing for the final slot can never both occupy it.

---

## E. Check-in Changes

- **Opening/closing:** window = `[check_in_starts_at, check_in_ends_at]`. Only enforced when BOTH fields are configured — tournaments without a window skip check-in entirely (Phase 02 behaviour preserved, no regression).
- **Authorization:** `TeamPolicy::checkIn` — captain only; admin override allowed (explicit, audited via `checked_in_by`).
- **Eligibility:** only `confirmed` teams may check in; withdrawn, pending, waitlisted, no-show and cross-tournament teams are rejected.
- **Idempotency:** a second check-in returns `already` without touching timestamps/state.
- **No-show handling:** `markNoShowsAndPromote()` (organizer/admin, post-close) marks confirmed-but-unchecked-in teams as `no_show` (explicit state, no deletion), then promotes waitlisted teams into the freed slots.

---

## F. Waitlist Changes

- **Queue ordering:** deterministic FIFO by `waitlisted_at`, then `id`; position exposed via `Team::waitlistPosition()` (computed, never stale).
- **Slot release:** withdrawal frees the slot; no-show marking frees slots.
- **Promotion:** `promoteNext()` — atomic slot claim + FIFO pick inside a transaction; promoted team becomes `pending` (then follows the normal payment flow). Idempotent per team (a promoted team is no longer waitlisted and can never be promoted twice); repeated calls promote the next eligible team.
- **Race safety:** two simultaneous promotions cannot both fill the final slot — the atomic claim UPDATE ensures only one succeeds; the loser gets a clean domain error.
- **Guardrails:** promotion requires registration still open; withdrawn waitlisted teams are skipped; a waitlisted team can never be promoted into a nonexistent slot; promotion bypasses no roster/UID validation (the team was validated at registration).

---

## G. Bracket Eligibility

A team may reach bracket generation only if **all** of the following hold:

1. belongs to the tournament,
2. `status = confirmed` (paid/verified),
3. **checked in** — but only when a check-in window is configured (`hasCheckIn()`); without a window, confirmation alone is sufficient (backward compatible),
4. not withdrawn, rejected, no-show or waitlisted.

`Tournament::bracketEligibleTeams()` is the single source of truth, consumed by `BracketService`. Additionally, `TournamentLifecycleService::start()` refuses to start while the check-in window is still open.

---

## H. Security Verification

Attack scenarios tested (and blocked) in `CheckInWaitlistTest`:
guest register · guest check-in · non-captain check-in · check-in before open · check-in after close · withdrawn/pending/waitlisted check-in · check-in not configured · cross-tournament check-in (404) · player promote (403) · player mark-no-shows (403) · promote with no slot / empty waitlist / closed registration · withdrawn waitlisted skip · duplicate check-in (idempotent) · duplicate promotion (impossible) · unchecked-in team into bracket (excluded) · no-show team into bracket (excluded) · start while check-in open (blocked) · mass-assignment of `status`/`checked_in_at`/`waitlisted_at` (ignored).

All authorization remains server-side; no UI-hiding is relied upon.

---

## I. Test Results

```bash
php artisan test
# Tests: 109 passed (338 assertions)
```

| Suite | Tests |
|---|---|
| `Unit\ExampleTest` | 1 |
| `Feature\ExampleTest` | 1 |
| `Feature\AuthorizedWorkflowTest` (P1) | 4 |
| `Feature\SecurityAuthorizationTest` (P1) | 21 |
| `Feature\TournamentLifecycleTest` (P2) | 28 |
| `Feature\TeamRosterTest` (P3) | 21 |
| `Feature\CheckInWaitlistTest` (P4 — NEW) | 33 |
| **Total** | **109** |

Phase 03 baseline (76 tests / 232 assertions) → **all 76 still pass**. Phase 04 added **33 tests / 106 assertions**.

## J. Migration Verification

```bash
php artisan migrate:fresh --seed --force
# 2026_09_04_130000_add_checkin_waitlist_fields ...... DONE
# Seeding database.
# users=2, tournaments=2, teams=16, matches=7
# open_tournament check_in=configured
```

## K. Syntax Verification

`php -l` on all 15 changed/new PHP files → **ALL OK**.

## L. Route Verification

```bash
php artisan route:list   # 41 routes (38 → 41)
```
New routes:
- `POST organizer/tournaments/{tournament}/no-shows` → `tournaments.noshows`
- `POST organizer/tournaments/{tournament}/waitlist/promote` → `tournaments.waitlist.promote`
- `POST tournaments/{tournament}/teams/{team}/check-in` → `teams.checkin`

All inside the `auth` middleware group; check-in is policy-protected; organizer actions use the `update` policy gate.

## M. Regression Result

All Phase 01–03 tests remain passing. One Phase 02 test (`test_tournament_capacity_is_respected`) was updated — not weakened — to assert the new, explicitly-required Phase 04 behaviour: the 9th registration now goes to the waitlist while the slot count stays at exactly `team_slots`. The slot-accounting guarantee it tested is preserved and strengthened.

## N. Remaining Gaps

1. **Automatic promotion on withdrawal** is not wired — promotion is an explicit organizer action (safer, predictable). Auto-promote could be added later.
2. **No-show marking is manual** (organizer button), not a scheduled job (no scheduler/cron in scope).
3. **Admin late check-in override** exists for check-in, but there is no generic "un-do" for a no-show/withdrawn team beyond re-registering.
4. Withdrawal of a confirmed team still does not auto-refund (payment concern, later phase).
5. No audit-log table; `checked_in_by` and `waitlisted_at` provide timestamps/actor for check-in only.

---

## O. Phase Boundary

**PHASE 04 COMPLETE**

**PHASE 05 NOT STARTED**

---

# IMPLEMENTATION — COMPLETE FILES


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
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'check_in_starts_at' => 'datetime',
        'check_in_ends_at' => 'datetime',
        'entry_fee' => 'float',
        'prize_pool' => 'float',
        'team_slots' => 'integer',
        'team_size' => 'integer',
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

### FILE: app/Models/Team.php
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Team extends Model
{
    use HasFactory;

    /**
     * Team lifecycle statuses.
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_WITHDRAWN = 'withdrawn';
    public const STATUS_WAITLISTED = 'waitlisted';
    public const STATUS_NO_SHOW = 'no_show';

    /**
     * Statuses that occupy a registration slot in a tournament.
     */
    public const SLOT_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_CONFIRMED,
    ];

    /**
     * Statuses that hold a competitive identity (Free Fire UID) in a
     * tournament. Waitlisted teams reserve their UID too, so a player cannot
     * appear on two teams (including a waitlisted one) in the same
     * tournament. Withdrawn teams release their UID and are excluded.
     */
    public const COMPETING_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_CONFIRMED,
        self::STATUS_WAITLISTED,
    ];

    /**
     * tournament_id, captain_id, status, seed, checked_in_at, checked_in_by
     * and waitlisted_at are server-controlled. Excluded from mass assignment
     * so a client can never forge participation/check-in/waitlist state.
     */
    protected $fillable = [
        'name',
        'captain_name',
        'phone',
        'game_uid',
    ];

    protected $casts = [
        'checked_in_at' => 'datetime',
        'waitlisted_at' => 'datetime',
    ];

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    public function captain()
    {
        return $this->belongsTo(User::class, 'captain_id');
    }

    public function checkedInBy()
    {
        return $this->belongsTo(User::class, 'checked_in_by');
    }

    public function members()
    {
        return $this->hasMany(TeamMember::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function latestPayment()
    {
        return $this->hasOne(Payment::class)->latestOfMany();
    }

    public function isCaptain(User $user): bool
    {
        return $this->captain_id !== null && $this->captain_id === $user->id;
    }

    public function belongsToTournament(Tournament $tournament): bool
    {
        return $this->tournament_id === $tournament->id;
    }

    public function isWithdrawn(): bool
    {
        return $this->status === self::STATUS_WITHDRAWN;
    }

    public function isConfirmed(): bool
    {
        return $this->status === self::STATUS_CONFIRMED;
    }

    public function isWaitlisted(): bool
    {
        return $this->status === self::STATUS_WAITLISTED;
    }

    public function isNoShow(): bool
    {
        return $this->status === self::STATUS_NO_SHOW;
    }

    public function isCheckedIn(): bool
    {
        return $this->checked_in_at !== null;
    }

    /**
     * Whether this team currently occupies a slot in its tournament.
     */
    public function occupiesSlot(): bool
    {
        return in_array($this->status, self::SLOT_STATUSES, true);
    }

    /**
     * Whether a member with the given (case-insensitive, trimmed) Free Fire
     * UID already exists on this team.
     */
    public function hasMemberWithUid(string $uid): bool
    {
        return $this->members()
            ->whereRaw('UPPER(TRIM(game_uid)) = ?', [strtoupper(trim($uid))])
            ->exists();
    }

    /**
     * Total roster size: the captain plus all members.
     */
    public function rosterSize(): int
    {
        return 1 + $this->members()->count();
    }

    /**
     * 1-based position on the tournament waitlist, or null when not
     * waitlisted. Deterministic FIFO ordering: waitlisted_at, then id.
     */
    public function waitlistPosition(): ?int
    {
        if (! $this->isWaitlisted()) {
            return null;
        }

        return Team::query()
            ->where('tournament_id', $this->tournament_id)
            ->where('status', self::STATUS_WAITLISTED)
            ->where(function ($q) {
                $q->where('waitlisted_at', '<', $this->waitlisted_at)
                    ->orWhere(function ($q2) {
                        $q2->where('waitlisted_at', $this->waitlisted_at)
                            ->where('id', '<', $this->id);
                    });
            })
            ->count() + 1;
    }
}
```

### FILE: app/Services/TournamentParticipationService.php
```php
<?php

namespace App\Services;

use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Phase 04 — registration eligibility, check-in, waitlist and no-show
 * handling.
 *
 * This service owns the *participation* state machine that sits on top of the
 * Phase 02 tournament lifecycle and the Phase 03 roster rules:
 *
 *   - check-in (window enforcement, idempotency, admin override)
 *   - waitlist FIFO ordering and race-safe promotion
 *   - no-show marking once the check-in window closes
 *
 * Authorization lives in policies; this service answers
 * "is this participation change legal right now?" and performs it.
 */
class TournamentParticipationService
{
    /**
     * Check a team in. Idempotent — checking in twice returns 'already' and
     * does not alter slot accounting or timestamps.
     *
     * @return string 'checked_in' | 'already'
     */
    public function checkIn(Tournament $tournament, Team $team, User $user, bool $override = false): string
    {
        if (! $team->belongsToTournament($tournament)) {
            throw new DomainException('This team does not belong to this tournament.');
        }

        if ($team->status !== Team::STATUS_CONFIRMED) {
            throw new DomainException('Only confirmed teams can check in.');
        }

        if (! $tournament->hasCheckIn()) {
            throw new DomainException('Check-in is not configured for this tournament.');
        }

        if ($team->checked_in_at !== null) {
            return 'already';
        }

        if (! $override && ! $tournament->checkInIsOpen()) {
            if ($tournament->checkInHasClosed()) {
                throw new DomainException('The check-in window has closed.');
            }

            throw new DomainException('Check-in has not opened yet.');
        }

        $team->checked_in_at = now();
        $team->checked_in_by = $user->id;
        $team->save();

        return 'checked_in';
    }

    /**
     * Promote the next waitlisted team into a free slot.
     *
     * Deterministic (FIFO by waitlisted_at, then id), transaction-safe and
     * idempotent per team: once promoted, a team is no longer waitlisted and
     * can never be selected again. The atomic slot claim guarantees that two
     * simultaneous promotions can never both fill the same final slot.
     */
    public function promoteNext(Tournament $tournament): Team
    {
        return DB::transaction(function () use ($tournament) {
            $fresh = Tournament::findOrFail($tournament->id);

            if (! $fresh->acceptsRegistration()) {
                throw new DomainException('Registration is closed — waitlisted teams can no longer be promoted.');
            }

            // ATOMIC SLOT CLAIM — only succeeds while a slot is actually free.
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
                throw new DomainException('No available slot to promote a waitlisted team into.');
            }

            $next = Team::query()
                ->where('tournament_id', $fresh->id)
                ->where('status', Team::STATUS_WAITLISTED)
                ->orderBy('waitlisted_at')
                ->orderBy('id')
                ->first();

            if ($next === null) {
                throw new DomainException('The waitlist is empty.');
            }

            $next->status = Team::STATUS_PENDING;
            $next->waitlisted_at = null;
            $next->save();

            return $next;
        });
    }

    /**
     * Mark confirmed-but-unchecked-in teams as no-shows (only once the
     * check-in window has closed), then promote waitlisted teams into the
     * freed slots.
     *
     * @return array{no_shows: int, promoted: int}
     */
    public function markNoShowsAndPromote(Tournament $tournament): array
    {
        $noShows = 0;

        DB::transaction(function () use ($tournament, &$noShows) {
            $fresh = Tournament::findOrFail($tournament->id);

            if (! $fresh->hasCheckIn()) {
                throw new DomainException('Check-in is not configured for this tournament.');
            }

            if (! $fresh->checkInHasClosed()) {
                throw new DomainException('The check-in window has not closed yet.');
            }

            $missing = Team::query()
                ->where('tournament_id', $fresh->id)
                ->where('status', Team::STATUS_CONFIRMED)
                ->whereNull('checked_in_at')
                ->get();

            $noShows = $missing->count();

            foreach ($missing as $team) {
                $team->status = Team::STATUS_NO_SHOW;
                $team->save();
            }
        });

        // Promote as many waitlisted teams as freed slots allow. Each
        // promotion is its own atomic transaction.
        $promoted = 0;
        while (true) {
            try {
                $this->promoteNext($tournament);
                $promoted++;
            } catch (DomainException $e) {
                break;
            }
        }

        return ['no_shows' => $noShows, 'promoted' => $promoted];
    }
}
```

### FILE: app/Services/RosterService.php
```php
<?php

namespace App\Services;

use App\Models\Team;
use App\Models\TeamMember;
use App\Models\Tournament;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Roster + competitive team integrity service.
 *
 * Owns all roster rules for Phase 03:
 *   - Free Fire UID normalization (consistent identity)
 *   - roster size enforcement against tournament.team_size
 *   - duplicate-player prevention (within a team)
 *   - cross-team player duplication prevention (within a tournament)
 *   - roster locking (editable only while registration is open)
 *   - member add / remove / team profile update
 *
 * Authorization ("is this user allowed?") lives in policies; this service
 * answers "is this roster change legal right now?" and performs it.
 */
class RosterService
{
    /**
     * Normalize a Free Fire UID for consistent identity comparison and
     * storage. UIDs are trimmed and uppercased.
     */
    public function normalizeUid(?string $uid): string
    {
        return strtoupper(trim((string) $uid));
    }

    /**
     * Whether the team's roster is currently locked.
     * Rosters are editable while the tournament is open for registration.
     * Once registration closes (or the tournament starts/ends), the roster
     * locks. Admins may override (handled explicitly at the controller).
     */
    public function isLocked(Team $team): bool
    {
        return $team->tournament->status !== Tournament::STATUS_OPEN;
    }

    /**
     * Throw unless the roster is editable.
     */
    public function assertEditable(Team $team): void
    {
        if ($this->isLocked($team)) {
            throw new DomainException('This team roster is locked because registration has closed.');
        }
    }

    /**
     * Maximum number of members (excluding the captain) for this team.
     */
    public function maxMembers(Team $team): int
    {
        return max(0, (int) $team->tournament->team_size - 1);
    }

    /**
     * Throw unless $count members can still be added to the team.
     */
    public function assertCanAddMembers(Team $team, int $count = 1, ?int $ignoreMemberId = null): void
    {
        $current = $team->members()
            ->when($ignoreMemberId, fn ($q) => $q->where('id', '!=', $ignoreMemberId))
            ->count();

        if ($current + $count > $this->maxMembers($team)) {
            throw new DomainException("This tournament allows a maximum of {$team->tournament->team_size} players per team (captain + members).");
        }
    }

    /**
     * Ensure the given UID is not already used by any captain or member of
     * another team in this tournament. Optionally ignores one team (both its
     * captain row and its members) and/or one specific member row.
     */
    public function assertUidAvailable(Tournament $tournament, string $uid, ?Team $ignoreTeam = null, ?int $ignoreMemberId = null): void
    {
        $uid = $this->normalizeUid($uid);

        $captainClash = Team::query()
            ->where('tournament_id', $tournament->id)
            ->whereIn('status', Team::COMPETING_STATUSES)
            ->whereRaw('UPPER(TRIM(game_uid)) = ?', [$uid])
            ->when($ignoreTeam, fn ($q) => $q->where('id', '!=', $ignoreTeam->id))
            ->exists();

        $memberClash = TeamMember::query()
            ->whereHas('team', fn ($q) => $q
                ->where('tournament_id', $tournament->id)
                ->whereIn('status', Team::COMPETING_STATUSES))
            ->whereRaw('UPPER(TRIM(game_uid)) = ?', [$uid])
            ->when($ignoreTeam, fn ($q) => $q->where('team_id', '!=', $ignoreTeam->id))
            ->when($ignoreMemberId, fn ($q) => $q->where('id', '!=', $ignoreMemberId))
            ->exists();

        if ($captainClash || $memberClash) {
            throw new DomainException("Player UID {$uid} is already registered to another team in this tournament.");
        }
    }

    /**
     * Validate and normalize a batch of roster rows (from the registration
     * form). Blank rows are skipped. Throws on invalid UID, within-team
     * duplicates, size overflow or cross-team clashes.
     *
     * @return array<int, array{player_name: string, game_uid: string}>
     */
    public function validateNewMembers(Tournament $tournament, Team $team, array $members): array
    {
        $normalized = [];
        $seen = [];

        foreach ($members as $member) {
            $name = trim((string) ($member['player_name'] ?? ''));
            if ($name === '') {
                continue; // blank rows are skipped (matches the register form)
            }

            $uid = $this->normalizeUid($member['game_uid'] ?? '');
            if ($uid === '' || ! preg_match('/^[A-Za-z0-9]{4,30}$/', $uid)) {
                throw new DomainException("Invalid Free Fire UID for member \"{$name}\" — use 4–30 letters or numbers.");
            }

            if ($uid === $this->normalizeUid($team->game_uid)) {
                throw new DomainException("Player UID {$uid} already belongs to the team captain.");
            }

            if (in_array($uid, $seen, true)) {
                throw new DomainException("Player UID {$uid} appears more than once in this team.");
            }

            $seen[] = $uid;
            $normalized[] = ['player_name' => $name, 'game_uid' => $uid];
        }

        if (count($normalized) > 0) {
            $this->assertCanAddMembers($team, count($normalized));
        }

        foreach ($seen as $uid) {
            $this->assertUidAvailable($tournament, $uid, $team);
        }

        return $normalized;
    }

    /**
     * Add a member to a team. $bypassLock is true only for admins.
     */
    public function addMember(Team $team, Tournament $tournament, array $data, bool $bypassLock = false): TeamMember
    {
        if (! $bypassLock) {
            $this->assertEditable($team);
        }

        $name = trim($data['player_name']);
        $uid = $this->normalizeUid($data['game_uid']);

        $member = null;

        DB::transaction(function () use ($team, $tournament, $name, $uid, &$member) {
            // Atomic size claim (SQLite-compatible): only succeeds while a
            // roster slot remains. This UPDATE takes the write lock, so the
            // reads and insert below cannot race with a concurrent add.
            $claimed = DB::table('teams')
                ->where('id', $team->id)
                ->whereRaw(
                    '(SELECT COUNT(*) FROM team_members WHERE team_id = teams.id) < (? - 1)',
                    [(int) $team->tournament->team_size]
                )
                ->update(['updated_at' => now()]);

            if ($claimed !== 1) {
                throw new DomainException("This tournament allows a maximum of {$team->tournament->team_size} players per team (captain + members).");
            }

            // Under the write lock, re-validate identity rules.
            if ($uid === $this->normalizeUid($team->game_uid)) {
                throw new DomainException("Player UID {$uid} already belongs to the team captain.");
            }

            if ($team->hasMemberWithUid($uid)) {
                throw new DomainException("Player UID {$uid} is already in this team.");
            }

            $this->assertUidAvailable($tournament, $uid, $team);

            $member = new TeamMember();
            $member->team_id = $team->id;
            $member->player_name = $name;
            $member->game_uid = $uid;
            $member->save();
        });

        return $member;
    }

    /**
     * Remove a member from a team.
     */
    public function removeMember(Team $team, TeamMember $member, bool $bypassLock = false): void
    {
        if (! $member->belongsToTeam($team)) {
            throw new DomainException('This member does not belong to this team.');
        }

        if (! $bypassLock) {
            $this->assertEditable($team);
        }

        $member->delete();
    }

    /**
     * Update team profile fields. Changing the captain UID re-runs the
     * availability checks.
     */
    public function updateProfile(Team $team, Tournament $tournament, array $data, bool $bypassLock = false): void
    {
        if (! $bypassLock) {
            $this->assertEditable($team);
        }

        $uid = $this->normalizeUid($data['game_uid']);

        if ($uid !== $this->normalizeUid($team->game_uid)) {
            if ($team->hasMemberWithUid($uid)) {
                throw new DomainException("Player UID {$uid} already belongs to a member of this team.");
            }

            $this->assertUidAvailable($tournament, $uid, $team);
        }

        $team->fill([
            'name' => trim($data['name']),
            'captain_name' => trim($data['captain_name']),
            'phone' => trim($data['phone']),
            'game_uid' => $uid,
        ])->save();
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

class BracketService
{
    /**
     * Generate a single-elimination knockout bracket.
     * Requires the number of confirmed teams to be a power of two (8/16/32...).
     *
     * @return int number of matches created
     */
    public function generate(Tournament $tournament): int
    {
        // Clear any existing matches first (idempotent)
        GameMatch::where('tournament_id', $tournament->id)->delete();

        // Only bracket-eligible teams may enter: confirmed, and — when a
        // check-in window is configured — checked in. Unchecked-in, no-show,
        // withdrawn, rejected and waitlisted teams can never enter a bracket.
        $teams = $tournament->bracketEligibleTeams()
            ->orderBy('seed')
            ->orderBy('id')
            ->get();

        $n = $teams->count();

        if ($n < 2 || ($n & ($n - 1)) !== 0) {
            // not a power of two
            return 0;
        }

        $rounds = (int) log($n, 2);
        $created = 0;

        // Round 1 matches — all fields set explicitly (server-controlled).
        $round = 1;
        $matchNo = 1;
        for ($i = 0; $i < $n; $i += 2) {
            $match = new GameMatch();
            $match->tournament_id = $tournament->id;
            $match->round = $round;
            $match->match_no = $matchNo++;
            $match->team1_id = $teams[$i]->id;
            $match->team2_id = $teams[$i + 1]->id;
            $match->status = 'pending';
            $match->save();
            $created++;
        }

        // Later rounds: placeholders, teams filled as winners advance
        $slotCount = $n / 2;
        for ($round = 2; $round <= $rounds; $round++) {
            $slotCount = intdiv($slotCount, 2);
            for ($m = 1; $m <= $slotCount; $m++) {
                $match = new GameMatch();
                $match->tournament_id = $tournament->id;
                $match->round = $round;
                $match->match_no = $m;
                $match->team1_id = null;
                $match->team2_id = null;
                $match->status = 'pending';
                $match->save();
                $created++;
            }
        }

        return $created;
    }

    /**
     * Advance the winner of a completed match into the next round.
     */
    public function advance(GameMatch $match): void
    {
        if (! $match->winner_team_id) {
            return;
        }

        $nextRound = $match->round + 1;
        // The next match for this position: match_no of next round = ceil(current match_no / 2)
        $nextMatchNo = (int) ceil($match->match_no / 2);

        $next = GameMatch::where('tournament_id', $match->tournament_id)
            ->where('round', $nextRound)
            ->where('match_no', $nextMatchNo)
            ->first();

        if (! $next) {
            return;
        }

        // Fill first empty slot
        if (! $next->team1_id) {
            $next->team1_id = $match->winner_team_id;
        } elseif (! $next->team2_id) {
            $next->team2_id = $match->winner_team_id;
        }
        $next->save();
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
     * Runs inside a transaction so the bracket and the status change are
     * committed atomically.
     *
     * @return int number of matches generated
     */
    public function start(Tournament $tournament, BracketService $bracket): int
    {
        $this->assertTransition($tournament, Tournament::STATUS_LIVE);

        // Never start (and freeze the bracket) while check-in is still open:
        // teams must have checked in before they can be seeded.
        if ($tournament->hasCheckIn() && ! $tournament->checkInHasClosed()) {
            throw new DomainException('The check-in window has not closed yet.');
        }

        return DB::transaction(function () use ($tournament, $bracket) {
            $count = $bracket->generate($tournament);

            if ($count === 0) {
                throw new DomainException(
                    'Bracket generation needs a power-of-two number of eligible teams (8/16/32). Teams that did not check in are excluded.'
                );
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

### FILE: app/Policies/TeamPolicy.php
```php
<?php

namespace App\Policies;

use App\Models\Team;
use App\Models\User;

class TeamPolicy
{
    /**
     * A user can act on a team when they are an admin, the tournament
     * organizer, or the team captain.
     */
    public function manage(User $user, Team $team): bool
    {
        return $user->isAdmin()
            || $team->tournament->organizer_id === $user->id
            || $team->isCaptain($user);
    }

    /**
     * Only an authorized actor may view/pay for a team's entry fee.
     */
    public function pay(User $user, Team $team): bool
    {
        return $this->manage($user, $team);
    }

    /**
     * Only an authorized actor may submit a score on behalf of a team.
     */
    public function submitScore(User $user, Team $team): bool
    {
        return $this->manage($user, $team);
    }

    /**
     * Only an authorized actor may view team-level details.
     */
    public function view(User $user, Team $team): bool
    {
        return $this->manage($user, $team);
    }

    /**
     * Only an authorized actor may withdraw a team. The lifecycle rule that
     * forbids withdrawal after the tournament goes live is enforced in the
     * controller/service — not here — because it is a state concern.
     */
    public function withdraw(User $user, Team $team): bool
    {
        return $this->manage($user, $team);
    }

    /**
     * Roster editing (add/remove members, edit team profile) is restricted
     * to the team captain and admins. Tournament organizers manage the
     * tournament, not individual team rosters — this keeps competitive
     * integrity (an organizer cannot silently alter a roster).
     */
    public function manageRoster(User $user, Team $team): bool
    {
        return $user->isAdmin() || $team->isCaptain($user);
    }

    public function addMember(User $user, Team $team): bool
    {
        return $this->manageRoster($user, $team);
    }

    /**
     * Only the team captain (or an admin) may check the team in.
     */
    public function checkIn(User $user, Team $team): bool
    {
        return $user->isAdmin() || $team->isCaptain($user);
    }

    public function removeMember(User $user, Team $team): bool
    {
        return $this->manageRoster($user, $team);
    }

    public function updateProfile(User $user, Team $team): bool
    {
        return $this->manageRoster($user, $team);
    }
}
```

### FILE: app/Http/Controllers/TeamController.php
```php
<?php

namespace App\Http\Controllers;

use App\Exceptions\RegistrationClosedException;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\Tournament;
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
        // checks run again inside the atomic claim below.
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

        $captainUid = $this->roster->normalizeUid($data['game_uid']);
        $members = is_array($data['members'] ?? null) ? $data['members'] : [];

        $team = null;
        $waitlisted = false;

        try {
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

                // Roster integrity (Phase 03): the captain UID must not
                // already belong to another team in this tournament.
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
                    // No slot. Re-check under the write lock: if the
                    // tournament really is full, the team goes to the
                    // waitlist. Otherwise registration is genuinely closed.
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

        $team->status = Team::STATUS_WITHDRAWN;
        $team->captain_id = null; // release the captain's claim so they may re-register
        $team->game_uid = null;   // release the captain UID so it can be re-used
        $team->save();

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

        return back()->with('success', 'Team profile updated.');
    }

    /**
     * Team check-in (captain or admin). Idempotent.
     */
    public function checkIn(Request $request, Tournament $tournament, Team $team)
    {
        abort_unless($team->belongsToTournament($tournament), 404);
        $this->authorize('checkIn', $team);

        try {
            $result = $this->participation->checkIn($tournament, $team, $request->user(), $request->user()->isAdmin());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($result === 'already') {
            return back()->with('success', 'Your team is already checked in.');
        }

        return back()->with('success', 'Check-in successful! Your team is confirmed for the bracket.');
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
            'matches' => fn ($q) => $q->orderBy('round')->orderBy('match_no'),
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

    // Matches
    Route::get('/tournaments/{tournament}/matches/{match}', [MatchController::class, 'show'])->name('matches.show');
    Route::post('/tournaments/{tournament}/matches/{match}/room', [MatchController::class, 'setRoom'])->name('matches.room');
    Route::post('/tournaments/{tournament}/matches/{match}/score', [MatchController::class, 'submitScore'])->name('matches.score');
    Route::post('/tournaments/{tournament}/matches/{match}/winner', [MatchController::class, 'setWinner'])->name('matches.winner');

    // Admin
    Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/dashboard', [AdminController::class, 'dashboard'])->name('dashboard');
        Route::post('/payments/{payment}/verify', [AdminController::class, 'verifyPayment'])->name('payments.verify');
        Route::post('/payments/{payment}/reject', [AdminController::class, 'rejectPayment'])->name('payments.reject');
    });
});
```

### FILE: database/migrations/2026_09_04_130000_add_checkin_waitlist_fields.php
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 04 — check-in + waitlist fields.
     *
     * tournaments:
     *   - check_in_starts_at / check_in_ends_at  (optional check-in window)
     *
     * teams:
     *   - checked_in_at  (check-in timestamp; NULL = not checked in)
     *   - checked_in_by  (who performed check-in, for audit)
     *   - waitlisted_at  (FIFO ordering key; NULL = not waitlisted)
     *   - index on (tournament_id, waitlisted_at) for fast FIFO promotion
     *
     * A team's waitlist / check-in state is kept separate from its
     * registration status (pending/confirmed) so no single column is
     * overloaded with unrelated meanings.
     */
    public function up(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->timestamp('check_in_starts_at')->nullable()->after('starts_at');
            $table->timestamp('check_in_ends_at')->nullable()->after('check_in_starts_at');
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->timestamp('checked_in_at')->nullable()->after('status');
            $table->foreignId('checked_in_by')->nullable()->after('checked_in_at')->constrained('users')->nullOnDelete();
            $table->timestamp('waitlisted_at')->nullable()->after('checked_in_by');
            $table->index(['tournament_id', 'waitlisted_at'], 'teams_tournament_waitlisted_index');
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropIndex('teams_tournament_waitlisted_index');
            $table->dropConstrainedForeignId('checked_in_by');
            $table->dropColumn(['checked_in_at', 'waitlisted_at']);
        });

        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropColumn(['check_in_starts_at', 'check_in_ends_at']);
        });
    }
};
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

        // 7 eligible teams is not a power of two → bracket generation fails.
        $this->actingAs($org)->post(route('tournaments.bracket', $tournament))->assertSessionHas('error');
        $this->assertSame('closed', $tournament->fresh()->status);
        $this->assertSame(0, GameMatch::where('tournament_id', $tournament->id)->count());
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

### FILE: tests/Feature/TournamentLifecycleTest.php
```php
<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\Payment;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 02 — Tournament lifecycle + registration state machine.
 * Covers every lifecycle rule from the Phase 02 specification.
 */
class TournamentLifecycleTest extends TestCase
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

    protected function makeTournament(User $organizer, string $status = 'open', array $overrides = []): Tournament
    {
        $t = new Tournament();
        $t->organizer_id = $organizer->id;
        $t->name = $overrides['name'] ?? 'Lifecycle Tournament';
        $t->slug = $overrides['slug'] ?? ('lifecycle-' . Str::random(8));
        $t->game_mode = $overrides['game_mode'] ?? 'squad';
        $t->map = $overrides['map'] ?? 'Bermuda';
        $t->entry_fee = $overrides['entry_fee'] ?? 100;
        $t->prize_pool = $overrides['prize_pool'] ?? 5000;
        $t->team_slots = $overrides['team_slots'] ?? 8;
        $t->team_size = $overrides['team_size'] ?? 4;
        $t->rules = $overrides['rules'] ?? null;
        $t->starts_at = $overrides['starts_at'] ?? now()->addDay();
        $t->status = $status;
        $t->save();

        return $t;
    }

    protected function makeTeam(Tournament $tournament, ?User $captain = null, string $status = 'pending'): Team
    {
        $t = new Team();
        $t->tournament_id = $tournament->id;
        $t->captain_id = $captain?->id;
        $t->name = 'Team ' . Str::random(6);
        $t->captain_name = $captain?->name ?? 'Captain';
        $t->phone = '01700000000';
        $t->game_uid = 'UID' . rand(100000, 999999);
        $t->status = $status;
        $t->save();

        return $t;
    }

    protected function makeMatch(Tournament $tournament, Team $t1, ?Team $t2 = null, string $status = 'pending'): GameMatch
    {
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

    protected function validRegistrationPayload(string $name = 'Test Squad'): array
    {
        return [
            'name' => $name,
            'captain_name' => 'Captain',
            'phone' => '01700000000',
            'game_uid' => 'UID123456',
            'members' => [],
        ];
    }

    // ------------------------------------------------------------------
    // 1. Who may change tournament state
    // ------------------------------------------------------------------

    public function test_guest_cannot_change_tournament_state(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'draft');

        $this->post(route('tournaments.publish', $tournament))
            ->assertRedirect(route('login'));

        $this->assertSame('draft', $tournament->fresh()->status);
    }

    public function test_player_cannot_change_tournament_state(): void
    {
        $org = $this->makeUser('organizer');
        $player = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'draft');

        $this->actingAs($player)->post(route('tournaments.publish', $tournament))->assertStatus(403);

        $this->assertSame('draft', $tournament->fresh()->status);
    }

    public function test_organizer_can_publish_own_draft_tournament(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'draft');

        $this->actingAs($org)->post(route('tournaments.publish', $tournament))
            ->assertSessionHas('success');

        $this->assertSame('open', $tournament->fresh()->status);
    }

    public function test_organizer_cannot_publish_another_organizers_tournament(): void
    {
        $orgA = $this->makeUser('organizer');
        $orgB = $this->makeUser('organizer');
        $tournament = $this->makeTournament($orgA, 'draft');

        $this->actingAs($orgB)->post(route('tournaments.publish', $tournament))->assertStatus(403);

        $this->assertSame('draft', $tournament->fresh()->status);
    }

    public function test_admin_can_manage_lifecycle(): void
    {
        $admin = $this->makeUser('admin');
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'draft');

        $this->actingAs($admin)->post(route('tournaments.publish', $tournament))->assertSessionHas('success');
        $this->assertSame('open', $tournament->fresh()->status);

        $this->actingAs($admin)->post(route('tournaments.cancel', $tournament))->assertSessionHas('success');
        $this->assertSame('cancelled', $tournament->fresh()->status);
    }

    // ------------------------------------------------------------------
    // 2. Transition validity
    // ------------------------------------------------------------------

    public function test_invalid_transition_is_rejected(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'draft');

        // draft → finished is not a legal edge.
        $this->actingAs($org)->post(route('tournaments.complete', $tournament))
            ->assertSessionHas('error');

        $this->assertSame('draft', $tournament->fresh()->status);
    }

    public function test_completed_tournament_cannot_return_to_registration(): void
    {
        $org = $this->makeUser('organizer');
        $player = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'finished');

        // finished → open is illegal.
        $this->actingAs($org)->post(route('tournaments.publish', $tournament))->assertSessionHas('error');
        $this->assertSame('finished', $tournament->fresh()->status);

        // Registration is refused.
        $this->actingAs($player)->post(route('teams.store', $tournament), $this->validRegistrationPayload())
            ->assertSessionHas('error');
        $this->assertSame(0, Team::where('tournament_id', $tournament->id)->count());
    }

    public function test_cancelled_tournament_cannot_reopen(): void
    {
        $org = $this->makeUser('organizer');
        $player = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'cancelled');

        // cancelled → open is illegal.
        $this->actingAs($org)->post(route('tournaments.publish', $tournament))->assertSessionHas('error');
        $this->assertSame('cancelled', $tournament->fresh()->status);

        $this->actingAs($player)->post(route('teams.store', $tournament), $this->validRegistrationPayload())
            ->assertSessionHas('error');
        $this->assertSame(0, Team::where('tournament_id', $tournament->id)->count());
    }

    public function test_organizer_can_complete_live_tournament(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'live');
        $t1 = $this->makeTeam($tournament, null, 'confirmed');
        $t2 = $this->makeTeam($tournament, null, 'confirmed');
        $this->makeMatch($tournament, $t1, $t2, 'completed');

        $this->actingAs($org)->post(route('tournaments.complete', $tournament))
            ->assertSessionHas('success');

        $this->assertSame('finished', $tournament->fresh()->status);
    }

    public function test_complete_blocked_when_matches_still_pending(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'live');
        $t1 = $this->makeTeam($tournament, null, 'confirmed');
        $t2 = $this->makeTeam($tournament, null, 'confirmed');
        $this->makeMatch($tournament, $t1, $t2, 'pending');

        $this->actingAs($org)->post(route('tournaments.complete', $tournament))
            ->assertSessionHas('error');

        $this->assertSame('live', $tournament->fresh()->status);
    }

    public function test_publish_blocked_when_start_time_is_in_the_past(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'draft', ['starts_at' => now()->subHour()]);

        $this->actingAs($org)->post(route('tournaments.publish', $tournament))
            ->assertSessionHas('error');

        $this->assertSame('draft', $tournament->fresh()->status);
    }

    public function test_unauthorized_user_cannot_manipulate_registration_state(): void
    {
        $orgA = $this->makeUser('organizer');
        $orgB = $this->makeUser('organizer');
        $player = $this->makeUser('player');
        $tournament = $this->makeTournament($orgA, 'open');

        $this->actingAs($orgB)->post(route('tournaments.close', $tournament))->assertStatus(403);
        $this->assertSame('open', $tournament->fresh()->status);

        $this->actingAs($player)->post(route('tournaments.cancel', $tournament))->assertStatus(403);
        $this->assertSame('open', $tournament->fresh()->status);
    }

    // ------------------------------------------------------------------
    // 3. Registration rules
    // ------------------------------------------------------------------

    public function test_registration_blocked_when_closed(): void
    {
        $org = $this->makeUser('organizer');
        $player = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'closed');

        $this->actingAs($player)->post(route('teams.store', $tournament), $this->validRegistrationPayload())
            ->assertSessionHas('error');

        $this->assertSame(0, Team::where('tournament_id', $tournament->id)->count());
    }

    public function test_registration_blocked_when_completed(): void
    {
        $org = $this->makeUser('organizer');
        $player = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'finished');

        $this->actingAs($player)->post(route('teams.store', $tournament), $this->validRegistrationPayload())
            ->assertSessionHas('error');

        $this->assertSame(0, Team::where('tournament_id', $tournament->id)->count());
    }

    public function test_registration_blocked_when_cancelled(): void
    {
        $org = $this->makeUser('organizer');
        $player = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'cancelled');

        $this->actingAs($player)->post(route('teams.store', $tournament), $this->validRegistrationPayload())
            ->assertSessionHas('error');

        $this->assertSame(0, Team::where('tournament_id', $tournament->id)->count());
    }

    public function test_registration_blocked_after_tournament_start(): void
    {
        $org = $this->makeUser('organizer');
        $player = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open', ['starts_at' => now()->subHour()]);

        $this->actingAs($player)->post(route('teams.store', $tournament), $this->validRegistrationPayload())
            ->assertSessionHas('error');

        $this->assertSame(0, Team::where('tournament_id', $tournament->id)->count());
    }

    public function test_tournament_capacity_is_respected(): void
    {
        $org = $this->makeUser('organizer');
        $player = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open', ['team_slots' => 8]);

        // Fill all 8 slots with pending teams (pending teams occupy slots too).
        for ($i = 0; $i < 8; $i++) {
            $this->makeTeam($tournament, null, 'pending');
        }

        // Phase 04: the 9th registration goes to the WAITLIST instead of
        // being rejected, but it must NOT occupy a competitive slot.
        $this->actingAs($player)->post(route('teams.store', $tournament), $this->validRegistrationPayload())
            ->assertRedirect(route('tournaments.show', $tournament));

        // Exactly 8 teams occupy slots (pending/confirmed)…
        $this->assertSame(
            8,
            Team::where('tournament_id', $tournament->id)->whereIn('status', ['pending', 'confirmed'])->count()
        );

        // …and exactly 1 team sits on the waitlist.
        $this->assertSame(
            1,
            Team::where('tournament_id', $tournament->id)->where('status', 'waitlisted')->count()
        );

        $this->assertSame(0, $tournament->fresh()->slotsLeft());
    }

    public function test_duplicate_team_registration_is_blocked(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open');

        $this->actingAs($captain)->post(route('teams.store', $tournament), $this->validRegistrationPayload('First Team'))
            ->assertRedirect();

        $this->actingAs($captain)->post(route('teams.store', $tournament), $this->validRegistrationPayload('First Team'))
            ->assertSessionHas('error');

        $this->assertSame(1, Team::where('tournament_id', $tournament->id)->where('captain_id', $captain->id)->count());
    }

    public function test_same_captain_cannot_register_two_teams(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open');

        $this->actingAs($captain)->post(route('teams.store', $tournament), $this->validRegistrationPayload('Team A'))
            ->assertRedirect();

        $this->actingAs($captain)->post(route('teams.store', $tournament), $this->validRegistrationPayload('Team B'))
            ->assertSessionHas('error');

        $this->assertSame(1, Team::where('tournament_id', $tournament->id)->where('captain_id', $captain->id)->count());
    }

    public function test_database_enforces_one_team_per_captain(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open');

        $this->makeTeam($tournament, $captain, 'pending');

        $duplicate = new Team();
        $duplicate->tournament_id = $tournament->id;
        $duplicate->captain_id = $captain->id;
        $duplicate->name = 'Duplicate Team';
        $duplicate->captain_name = $captain->name;
        $duplicate->phone = '01700000000';
        $duplicate->game_uid = 'UID999';
        $duplicate->status = 'pending';

        $threw = false;
        try {
            $duplicate->save();
        } catch (QueryException $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Expected the teams_tournament_captain_unique constraint to reject a duplicate captain.');
    }

    public function test_authorized_captain_can_register_a_valid_team(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open');

        $this->actingAs($captain)->post(route('teams.store', $tournament), $this->validRegistrationPayload('Champions'))
            ->assertRedirect(route('payment.show', [$tournament, Team::where('name', 'Champions')->firstOrFail()]));

        $team = Team::where('name', 'Champions')->firstOrFail();
        $this->assertSame('pending', $team->status);
        $this->assertSame($captain->id, $team->captain_id);
    }

    // ------------------------------------------------------------------
    // 4. Cross-tournament integrity
    // ------------------------------------------------------------------

    public function test_foreign_team_cannot_withdraw_from_other_tournament(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournamentA = $this->makeTournament($org, 'open');
        $tournamentB = $this->makeTournament($org, 'open');
        $teamA = $this->makeTeam($tournamentA, $captain, 'pending');

        $this->actingAs($captain)->post(route('teams.withdraw', [$tournamentB, $teamA]))
            ->assertStatus(404);

        $this->assertSame('pending', $teamA->fresh()->status);
    }

    // ------------------------------------------------------------------
    // 5. Withdrawal
    // ------------------------------------------------------------------

    public function test_captain_can_withdraw_and_slot_is_released(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open');

        $this->actingAs($captain)->post(route('teams.store', $tournament), $this->validRegistrationPayload('Withdraw Me'))
            ->assertRedirect();

        $team = Team::where('name', 'Withdraw Me')->firstOrFail();

        $this->actingAs($captain)->post(route('teams.withdraw', [$tournament, $team]))
            ->assertSessionHas('success');

        $this->assertSame('withdrawn', $team->fresh()->status);
        $this->assertNull($team->fresh()->captain_id);
        $this->assertSame($tournament->team_slots, $tournament->fresh()->slotsLeft());

        // The captain may register again after withdrawing.
        $this->actingAs($captain)->post(route('teams.store', $tournament), $this->validRegistrationPayload('Back Again'))
            ->assertRedirect();
        $this->assertSame(1, Team::where('tournament_id', $tournament->id)->where('captain_id', $captain->id)->where('status', 'pending')->count());
    }

    public function test_cannot_withdraw_after_tournament_is_live(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'live');
        $team = $this->makeTeam($tournament, $captain, 'confirmed');

        $this->actingAs($captain)->post(route('teams.withdraw', [$tournament, $team]))
            ->assertSessionHas('error');

        $this->assertSame('confirmed', $team->fresh()->status);
    }

    // ------------------------------------------------------------------
    // 6. Existing workflows preserved (regression)
    // ------------------------------------------------------------------

    public function test_existing_payment_workflow_remains_functional(): void
    {
        $admin = $this->makeUser('admin');
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open', ['entry_fee' => 100]);

        $this->actingAs($captain)->post(route('teams.store', $tournament), $this->validRegistrationPayload('Paying Team'))
            ->assertRedirect();

        $team = Team::where('name', 'Paying Team')->firstOrFail();

        $this->actingAs($captain)->post(route('payment.verify', [$tournament, $team]), [
            'bkash_number' => '01700000000',
            'trx_id' => 'BTRX123',
        ])->assertRedirect();

        $payment = Payment::where('team_id', $team->id)->firstOrFail();
        $this->assertSame('pending', $payment->status);

        $this->actingAs($admin)->post(route('admin.payments.verify', $payment))->assertRedirect();
        $this->assertSame('verified', $payment->fresh()->status);
        $this->assertSame('confirmed', $team->fresh()->status);
    }

    public function test_payment_blocked_when_registration_is_closed(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'closed', ['entry_fee' => 100]);
        $team = $this->makeTeam($tournament, $captain, 'pending');

        $this->actingAs($captain)->post(route('payment.verify', [$tournament, $team]), [
            'bkash_number' => '01700000000',
            'trx_id' => 'BTRX123',
        ])->assertSessionHas('error');

        $this->assertSame(0, Payment::where('team_id', $team->id)->count());
    }

    public function test_existing_bracket_workflow_remains_functional(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'open');

        for ($i = 0; $i < 8; $i++) {
            $this->makeTeam($tournament, null, 'confirmed');
        }

        $this->actingAs($org)->post(route('tournaments.bracket', $tournament))->assertSessionHas('success');

        $this->assertSame('live', $tournament->fresh()->status);
        $this->assertSame(7, GameMatch::where('tournament_id', $tournament->id)->count());
    }

    public function test_existing_leaderboard_remains_functional(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'live', ['entry_fee' => 0]);

        $teamA = $this->makeTeam($tournament, $captain, 'confirmed');
        $teamB = $this->makeTeam($tournament, null, 'confirmed');
        $match = $this->makeMatch($tournament, $teamA, $teamB, 'live');

        $this->actingAs($captain)->post(route('matches.score', [$tournament, $match]), [
            'team_id' => $teamA->id,
            'kills' => 6,
            'placement' => 1,
        ])->assertRedirect();

        $this->actingAs($captain)->get(route('leaderboard.show', $tournament))
            ->assertOk()
            ->assertSee($teamA->name);
    }
}
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
                            <button class="btn btn-primary btn-sm">⚡ Generate Bracket (needs 8/16/32 eligible teams)</button>
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
            <h2>🏆 Bracket</h2>
            @php $rounds = $tournament->matches->groupBy('round')->sortKeys(); @endphp
            @if($rounds->isEmpty())
                <p class="muted">Bracket not generated yet.</p>
            @else
                <div class="bracket-col">
                    @foreach($rounds as $round => $matches)
                        <div class="bracket-round">
                            <div class="muted" style="font-size:12px; font-weight:700">
                                {{ $round == $rounds->keys()->last() ? '🏁 FINAL' : 'Round ' . $round }}
                            </div>
                            @foreach($matches as $m)
                                <div class="bracket-match">
                                    <a href="{{ route('matches.show', [$tournament, $m]) }}" style="display:block">
                                        <div class="bracket-team {{ $m->winner_team_id === $m->team1_id ? 'win' : '' }}">
                                            <span>{{ $m->team1?->name ?? 'TBD' }}</span>
                                        </div>
                                        <div class="divider"></div>
                                        <div class="bracket-team {{ $m->winner_team_id === $m->team2_id ? 'win' : '' }}">
                                            <span>{{ $m->team2?->name ?? 'TBD' }}</span>
                                        </div>
                                    </a>
                                </div>
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

### FILE: resources/views/teams/show.blade.php
```blade
@extends('layouts.app')
@section('title', $team->name . ' — FF Arena')
@section('content')
    <div style="padding: 24px 0 6px">
        <a href="{{ route('tournaments.show', $tournament) }}" class="muted" style="font-size:13px">← Back to tournament</a>
        <h1 style="margin-top:6px">{{ $team->name }}</h1>
        <div class="muted" style="margin-top:6px">
            {{ $tournament->name }} ·
            <span class="pill {{ $team->status }}">{{ strtoupper($team->status) }}</span>
            @if($locked)
                <span class="pill withdrawn">ROSTER LOCKED</span>
            @endif
            @if($team->isWaitlisted())
                <span class="pill waitlisted">WAITLIST #{{ $team->waitlistPosition() }}</span>
            @elseif($team->isCheckedIn())
                <span class="pill checked">CHECKED IN ✓</span>
            @endif
        </div>
    </div>

    @if($tournament->hasCheckIn())
        <div class="card">
            <h3>📋 Check-in</h3>
            @if($team->isCheckedIn())
                <p style="color:var(--green)"><strong>✓ Checked in</strong>
                    <span class="muted">at {{ $team->checked_in_at->format('d M, h:i A') }}</span></p>
            @elseif($team->isWaitlisted())
                <p class="muted">Waitlisted teams check in after they are promoted and confirmed.</p>
            @elseif(!$team->isConfirmed())
                <p class="muted">Only confirmed teams can check in. Complete your payment first.</p>
            @elseif($tournament->checkInIsOpen())
                <form method="POST" action="{{ route('teams.checkin', [$tournament, $team]) }}">
                    @csrf
                    <button class="btn btn-green">✅ Check In Now</button>
                </form>
            @elseif($tournament->checkInHasClosed())
                <p class="muted" style="color:var(--red)">The check-in window has closed.</p>
            @else
                <p class="muted">Check-in opens {{ $tournament->check_in_starts_at->format('d M, h:i A') }}.</p>
            @endif
        </div>
    @endif

    <div class="grid cols-2">
        <div class="card">
            <h3>🪪 Team Info</h3>
            <table>
                <tr><th>Captain</th><td>{{ $team->captain_name }}</td></tr>
                <tr><th>Captain UID</th><td><strong class="tag">{{ $team->game_uid }}</strong></td></tr>
                <tr><th>Phone</th><td>{{ $team->phone }}</td></tr>
                <tr><th>Roster size</th><td>{{ $team->rosterSize() }} / {{ $tournament->team_size }} players</td></tr>
                <tr><th>Status</th><td>{{ ucfirst($team->status) }}</td></tr>
            </table>
        </div>

        <div class="card">
            <h3>👥 Roster</h3>
            @if($team->members->isEmpty())
                <p class="muted">No members added yet — the captain is the only player.</p>
            @else
                <table>
                    <tr><th>#</th><th>Player</th><th>Free Fire UID</th>@if($canEdit)<th></th>@endif</tr>
                    @foreach($team->members as $m)
                        <tr>
                            <td>{{ $loop->iteration + 1 }}</td>
                            <td>{{ $m->player_name }}</td>
                            <td><strong class="tag">{{ $m->game_uid }}</strong></td>
                            @if($canEdit)
                                <td>
                                    <form method="POST" action="{{ route('teams.members.remove', [$tournament, $team, $m]) }}" style="display:inline">
                                        @csrf
                                        <button class="btn btn-sm" style="border-color:var(--red); color:var(--red)" onclick="return confirm('Remove {{ $m->player_name }} from the roster?')">Remove</button>
                                    </form>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </table>
            @endif
        </div>
    </div>

    @if($canEdit)
        <div class="grid cols-2">
            <div class="card">
                <h3>➕ Add Member</h3>
                @if($slotsLeft > 0)
                    <p class="muted" style="font-size:13px; margin-bottom:8px">{{ $slotsLeft }} roster slot(s) remaining.</p>
                    <form method="POST" action="{{ route('teams.members.store', [$tournament, $team]) }}">
                        @csrf
                        <label>Player name</label>
                        <input type="text" name="player_name" required placeholder="Player name">
                        <label>Free Fire UID</label>
                        <input type="text" name="game_uid" required placeholder="e.g. 1234567890">
                        <button class="btn btn-primary btn-sm" style="margin-top:12px">Add to Roster</button>
                    </form>
                @else
                    <p class="muted">Roster is full ({{ $tournament->team_size }} players maximum).</p>
                @endif
            </div>

            <div class="card">
                <h3>✏️ Edit Team Info</h3>
                <form method="POST" action="{{ route('teams.update', [$tournament, $team]) }}">
                    @csrf
                    @method('PUT')
                    <label>Team name</label>
                    <input type="text" name="name" value="{{ $team->name }}" required>
                    <label>Captain name</label>
                    <input type="text" name="captain_name" value="{{ $team->captain_name }}" required>
                    <label>Phone (bKash)</label>
                    <input type="text" name="phone" value="{{ $team->phone }}" required>
                    <label>Captain Free Fire UID</label>
                    <input type="text" name="game_uid" value="{{ $team->game_uid }}" required>
                    <button class="btn btn-primary btn-sm" style="margin-top:12px">Save Changes</button>
                </form>
            </div>
        </div>
    @elseif($locked && !$canEdit)
        <div class="card">
            <p class="muted">🔒 This roster is locked. Registration has closed, so the roster can no longer be changed.</p>
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
        .pill.closed, .pill.finished { background: rgba(248,113,113,.15); color: var(--red); }
        .pill.draft { background: rgba(251,191,36,.15); color: var(--amber); }
        .pill.pending { background: rgba(251,191,36,.15); color: var(--amber); }
        .pill.confirmed, .pill.verified, .pill.checked { background: rgba(52,211,153,.15); color: var(--green); }
        .pill.waitlisted { background: rgba(251,191,36,.15); color: var(--amber); }
        .pill.cancelled, .pill.withdrawn, .pill.rejected, .pill.failed, .pill.no_show { background: rgba(148,163,184,.15); color: var(--muted); }
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
