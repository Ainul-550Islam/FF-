# PHASE 02 — TOURNAMENT LIFECYCLE + REGISTRATION STATE MACHINE
**FF Arena (Free Fire Tournament Platform) — Laravel 12 / SQLite**
**Date:** 2026-09-04 · **Scope:** Lifecycle state machine + registration integrity only (Phase 03 NOT implemented)

---

## 1. PHASE 02 AUDIT

### 1.1 Existing state values (from `database/migrations/*` — plain string columns, no ENUMs)
| Table | Status column values in use |
|---|---|
| tournaments | `draft` · `open` · `closed` · `live` · `finished` · `cancelled` |
| teams | `pending` · `confirmed` · `rejected` |
| matches | `pending` · `live` · `completed` · `disputed` |
| payments | `pending` · `verified` · `failed` · `refunded` |

### 1.2 Current transitions (before Phase 02 — scattered in controllers, no guard)
| Action | Route | Old behaviour |
|---|---|---|
| create | `tournaments.store` | → `draft` |
| publish | `tournaments.publish` | `draft → open` (no completeness check) |
| close | `tournaments.close` | `open → closed` |
| bracket | `tournaments.bracket` | `open|closed → live` |
| cancel | `tournaments.cancel` | `draft|open|closed → cancelled` |
| **complete** | *(missing)* | **`live → finished` did not exist — only the seeder set `finished` directly** |

### 1.3 Gaps discovered (exact)
1. **No state machine.** Any status could be reached with no transition guard (client-supplied `status` was already excluded from mass-assignment in Phase 01, but the controller actions themselves had no "is this transition legal?" check).
2. **`live → finished` missing** — a live tournament could never be finished through the app.
3. **Publish had no completeness validation** — a draft with a past `starts_at` (or blank config) could be published.
4. **No registration deadline** — registration was allowed while `open` even after `starts_at` had passed.
5. **Capacity race** — `isFull()` was checked before insert, not atomically; and `slotsLeft()` counted only `confirmed` teams, so `pending` teams did not reserve a slot (32 pending + 32 confirmed possible).
6. **Duplicate / one-team-per-captain was app-only** — no database constraint on `teams`.
7. **Payment accepted after close/finish** — `PaymentController@verify` never checked the tournament was still accepting registration.
8. **No withdrawal** — a team could never leave before the tournament started.
9. **Draft/cancelled tournaments appeared on the public index** (`TournamentController@index` had no status filter).

### 1.4 Chosen state machine (smallest change, keeps existing DB/UI status names)
`open` is the **published + registration-accepting** state (the spec's PUBLISHED and REGISTRATION_OPEN are merged, preserving the existing architecture).

```
draft ──▶ open ──▶ closed ──▶ live ──▶ finished
  │         │          │
  └─────────┴──────────┴──▶ cancelled   (open also → live via bracket, preserved)

finished, cancelled = terminal
```
Allowed edges (encoded in `Tournament::TRANSITIONS`):
- `draft → open, cancelled`
- `open → closed, live, cancelled`
- `closed → live, cancelled`
- `live → finished`
- `finished → (none)`, `cancelled → (none)`

Forbidden examples (all rejected): `completed→draft`, `completed→registration`, `cancelled→live`, `live→open`, `draft→finished`.

### 1.5 Design decisions
- **No new status values** were invented; the existing string values are reused, so no data migration of statuses is needed.
- **`starts_at`** remains the only time authority (it is the existing `starts_at` column); no new `registration_deadline` column was created.
- **Capacity** counts `pending + confirmed` teams (both reserve a slot); withdrawn/rejected teams free their slot.
- **Atomic slot claim**: a single conditional `UPDATE` on the tournament row (works under SQLite's single-writer model) makes the capacity + one-per-captain checks race-safe; a `UNIQUE(tournament_id, captain_id)` index is the database backstop.
- **Authorization vs transition** are separated: policies answer "who may act", `TournamentLifecycleService` answers "is this transition legal from the current state".

---

## 2. FILES TO CHANGE

**Modified (11):**
1. `app/Models/Tournament.php`
2. `app/Models/Team.php`
3. `app/Models/GameMatch.php`
4. `app/Policies/TournamentPolicy.php`
5. `app/Policies/TeamPolicy.php`
6. `app/Http/Controllers/TournamentController.php`
7. `app/Http/Controllers/TeamController.php`
8. `app/Http/Controllers/PaymentController.php`
9. `routes/web.php`
10. `resources/views/tournaments/show.blade.php`
11. `resources/views/layouts/app.blade.php`

**New (4):**
12. `app/Services/TournamentLifecycleService.php`
13. `app/Exceptions/RegistrationClosedException.php`
14. `database/migrations/2026_09_04_110000_add_unique_team_registration_constraint.php`
15. `tests/Feature/TournamentLifecycleTest.php`

---

## 3. IMPLEMENTATION — COMPLETE FILES


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
    ];

    protected $casts = [
        'starts_at' => 'datetime',
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
     * verification) or confirmed. Withdrawn and rejected teams do not
     * occupy a slot.
     */
    public function registeredTeams()
    {
        return $this->hasMany(Team::class)
            ->whereIn('status', [Team::STATUS_PENDING, Team::STATUS_CONFIRMED]);
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

    /**
     * Statuses that occupy a registration slot in a tournament.
     */
    public const SLOT_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_CONFIRMED,
    ];

    /**
     * tournament_id, captain_id, status and seed are server-controlled.
     * Excluded from mass assignment so a client can never attach a team to a
     * foreign tournament or forge ownership/state.
     */
    protected $fillable = [
        'name',
        'captain_name',
        'phone',
        'game_uid',
    ];

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    public function captain()
    {
        return $this->belongsTo(User::class, 'captain_id');
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

    /**
     * Whether this team currently occupies a slot in its tournament.
     */
    public function occupiesSlot(): bool
    {
        return in_array($this->status, self::SLOT_STATUSES, true);
    }
}
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
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_LIVE = 'live';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_DISPUTED = 'disputed';

    /**
     * tournament_id, team1_id, team2_id, winner_team_id, status, round and
     * match_no are server-controlled. Excluded from mass assignment.
     */
    protected $fillable = [
        'room_id',
        'room_pass',
        'scheduled_at',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
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
}
```

### FILE: app/Policies/TournamentPolicy.php
```php
<?php

namespace App\Policies;

use App\Models\Tournament;
use App\Models\User;

class TournamentPolicy
{
    /**
     * Only organizers and admins may create tournaments.
     */
    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->isOrganizer();
    }

    /**
     * Ownership gate shared by every lifecycle action: only the owning
     * organizer or an admin may act on a tournament.
     */
    public function update(User $user, Tournament $tournament): bool
    {
        return $user->isAdmin() || $tournament->organizer_id === $user->id;
    }

    public function publish(User $user, Tournament $tournament): bool
    {
        return $this->update($user, $tournament);
    }

    public function closeRegistration(User $user, Tournament $tournament): bool
    {
        return $this->update($user, $tournament);
    }

    public function start(User $user, Tournament $tournament): bool
    {
        return $this->update($user, $tournament);
    }

    public function complete(User $user, Tournament $tournament): bool
    {
        return $this->update($user, $tournament);
    }

    public function cancel(User $user, Tournament $tournament): bool
    {
        return $this->update($user, $tournament);
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

        return DB::transaction(function () use ($tournament, $bracket) {
            $count = $bracket->generate($tournament);

            if ($count === 0) {
                throw new DomainException(
                    'Bracket generation needs a power-of-two number of confirmed teams (8/16/32).'
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

### FILE: app/Exceptions/RegistrationClosedException.php
```php
<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a team registration is refused for a lifecycle reason:
 * registration closed, tournament already started, capacity reached, or the
 * captain already has a team in this tournament.
 *
 * The message is surfaced to the user as a flash message.
 */
class RegistrationClosedException extends RuntimeException
{
}
```

### FILE: app/Http/Controllers/TournamentController.php
```php
<?php

namespace App\Http\Controllers;

use App\Models\Tournament;
use App\Services\BracketService;
use App\Services\TournamentLifecycleService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TournamentController extends Controller
{
    public function __construct(
        protected TournamentLifecycleService $lifecycle,
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

        return view('tournaments.show', compact('tournament', 'myTeam'));
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
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TeamController extends Controller
{
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

        if ($tournament->isFull()) {
            return back()->with('error', 'All slots are full.');
        }

        $data = $request->validate([
            'name' => 'required|string|max:120',
            'captain_name' => 'required|string|max:120',
            'phone' => 'required|string|max:20',
            'game_uid' => 'required|string|max:30',
            'members' => 'nullable|array',
            'members.*.player_name' => 'nullable|string|max:120',
            'members.*.game_uid' => 'nullable|string|max:30',
        ]);

        $team = null;

        try {
            DB::transaction(function () use ($tournament, $user, $data, &$team) {
                // ATOMIC SLOT CLAIM — SQLite-compatible concurrency guard.
                //
                // A single UPDATE that only succeeds while the tournament is
                // still open, has not started, and has a free slot. In SQLite
                // this statement acquires the write lock, so everything after
                // it in this transaction is race-free: a concurrent
                // registration cannot both pass the capacity check.
                $claimed = DB::table('tournaments')
                    ->where('id', $tournament->id)
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
                    $fresh = Tournament::findOrFail($tournament->id);

                    if ($fresh->hasStarted()) {
                        throw new RegistrationClosedException('Registration is closed — this tournament has already started.');
                    }
                    if ($fresh->status !== Tournament::STATUS_OPEN) {
                        throw new RegistrationClosedException('Registration is closed for this tournament.');
                    }
                    if ($fresh->isFull()) {
                        throw new RegistrationClosedException('All slots are full.');
                    }

                    throw new RegistrationClosedException('Registration is not available for this tournament.');
                }

                // One-team-per-captain, re-checked while holding the write
                // lock. The database unique(tournament_id, captain_id) index
                // is the final backstop.
                if (Team::where('tournament_id', $tournament->id)->where('captain_id', $user->id)->exists()) {
                    throw new RegistrationClosedException('You have already registered a team in this tournament.');
                }

                $team = new Team();
                $team->tournament_id = $tournament->id;
                $team->captain_id = $user->id;
                $team->name = $data['name'];
                $team->captain_name = $data['captain_name'];
                $team->phone = $data['phone'];
                $team->game_uid = $data['game_uid'];
                $team->status = Team::STATUS_PENDING;
                $team->save();

                if (! empty($data['members'])) {
                    foreach ($data['members'] as $member) {
                        if (! empty($member['player_name'])) {
                            $row = new TeamMember();
                            $row->team_id = $team->id;
                            $row->player_name = $member['player_name'];
                            $row->game_uid = $member['game_uid'] ?? '';
                            $row->save();
                        }
                    }
                }
            });
        } catch (RegistrationClosedException $e) {
            return back()->with('error', $e->getMessage());
        } catch (QueryException $e) {
            // Database unique(tournament_id, captain_id) backstop for the
            // one-team-per-captain rule.
            return back()->with('error', 'You have already registered a team in this tournament.');
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
        $team->save();

        return back()->with('success', 'Your team has been withdrawn from the tournament.');
    }
}
```

### FILE: app/Http/Controllers/PaymentController.php
```php
<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\Team;
use App\Models\Tournament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    /**
     * bKash payment page for a team's entry fee.
     */
    public function show(Tournament $tournament, Team $team)
    {
        abort_unless($team->belongsToTournament($tournament), 404);
        $this->authorize('pay', $team);

        return view('payment.show', compact('tournament', 'team'));
    }

    /**
     * Simulated bKash "Send Money" verification.
     * In production this would call bKash's Checkout API / query payment.
     */
    public function verify(Request $request, Tournament $tournament, Team $team)
    {
        abort_unless($team->belongsToTournament($tournament), 404);
        $this->authorize('pay', $team);

        // Lifecycle: payments are only accepted while registration is open.
        if (! $tournament->acceptsRegistration()) {
            return back()->with('error', 'Payment is no longer accepted for this tournament.');
        }

        if ($team->status !== Team::STATUS_PENDING) {
            return back()->with('error', 'This team is not awaiting payment.');
        }

        $data = $request->validate([
            'bkash_number' => 'required|string|min:11|max:15',
            'trx_id' => 'required|string|max:40',
        ]);

        // Amount always derives from the tournament — never from client input.
        $amount = $tournament->entry_fee;

        $payment = null;

        DB::transaction(function () use ($tournament, $team, $amount, $data, &$payment) {
            $payment = new Payment();
            $payment->tournament_id = $tournament->id;
            $payment->team_id = $team->id;
            $payment->amount = $amount;
            $payment->method = 'bkash';
            $payment->trx_id = strtoupper($data['trx_id']);
            $payment->status = 'pending';
            $payment->save();

            // Free-entry tournaments are auto-confirmed (existing demo behaviour preserved).
            if ((float) $amount <= 0) {
                $payment->status = 'verified';
                $payment->save();

                $team->status = Team::STATUS_CONFIRMED;
                $team->save();
            }
        });

        if ($payment->status === 'verified') {
            return redirect()
                ->route('tournaments.show', $tournament)
                ->with('success', 'Registration confirmed! Your team is in.');
        }

        return redirect()->route('payment.pending', [$tournament, $team, $payment]);
    }

    public function pending(Tournament $tournament, Team $team, Payment $payment)
    {
        abort_unless($team->belongsToTournament($tournament), 404);
        abort_unless($payment->belongsToTournament($tournament) && $payment->belongsToTeam($team), 404);
        $this->authorize('view', $payment);

        return view('payment.pending', compact('tournament', 'team', 'payment'));
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
    // Organizer tournament lifecycle
    Route::get('/organizer/tournaments/create', [TournamentController::class, 'create'])->name('tournaments.create');
    Route::post('/organizer/tournaments', [TournamentController::class, 'store'])->name('tournaments.store');
    Route::get('/organizer/tournaments/{tournament}/edit', [TournamentController::class, 'edit'])->name('tournaments.edit');
    Route::put('/organizer/tournaments/{tournament}', [TournamentController::class, 'update'])->name('tournaments.update');
    Route::post('/organizer/tournaments/{tournament}/publish', [TournamentController::class, 'publish'])->name('tournaments.publish');
    Route::post('/organizer/tournaments/{tournament}/close', [TournamentController::class, 'closeRegistration'])->name('tournaments.close');
    Route::post('/organizer/tournaments/{tournament}/bracket', [TournamentController::class, 'start'])->name('tournaments.bracket');
    Route::post('/organizer/tournaments/{tournament}/complete', [TournamentController::class, 'complete'])->name('tournaments.complete');
    Route::post('/organizer/tournaments/{tournament}/cancel', [TournamentController::class, 'cancel'])->name('tournaments.cancel');

    // Team registration + payment
    Route::get('/tournaments/{tournament}/register', [TeamController::class, 'showRegistration'])->name('teams.register');
    Route::post('/tournaments/{tournament}/register', [TeamController::class, 'register'])->name('teams.store');
    Route::post('/tournaments/{tournament}/teams/{team}/withdraw', [TeamController::class, 'withdraw'])->name('teams.withdraw');
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

### FILE: database/migrations/2026_09_04_110000_add_unique_team_registration_constraint.php
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Enforces the one-team-per-captain rule at the database level.
     *
     * A captain may register at most one team per tournament. Because
     * captain_id is nullable (seeded demo teams have no captain), SQLite
     * treats NULLs as distinct, so multiple null-captain rows per
     * tournament remain allowed — exactly the behaviour we want.
     *
     * SQLite does not support ALTER TABLE ADD CONSTRAINT, but Laravel's
     * SQLite grammar compiles a unique index on an existing table as
     * CREATE UNIQUE INDEX, which enforces the same rule.
     */
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->unique(['tournament_id', 'captain_id'], 'teams_tournament_captain_unique');
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropUnique('teams_tournament_captain_unique');
        });
    }
};
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

        $this->actingAs($player)->post(route('teams.store', $tournament), $this->validRegistrationPayload())
            ->assertSessionHas('error');

        $this->assertSame(8, Team::where('tournament_id', $tournament->id)->count());
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
            <span class="pill {{ $tournament->status }}">{{ strtoupper($tournament->status) }}</span>
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
                            <button class="btn btn-primary btn-sm">⚡ Generate Bracket (needs 8/16/32 confirmed teams)</button>
                        </form>
                    @endif
                    @if($tournament->status === 'live')
                        <form method="POST" action="{{ route('tournaments.complete', $tournament) }}">@csrf
                            <button class="btn btn-green btn-sm" onclick="return confirm('Finish this tournament? Make sure all matches are completed.')">🏁 Finish Tournament</button>
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

        @if($myTeam && !$myTeam->isWithdrawn() && in_array($tournament->status, ['draft', 'open', 'closed'], true))
            <div class="card">
                <h3>🎽 Your Team</h3>
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px">
                    <div>
                        <strong>{{ $myTeam->name }}</strong>
                        <span class="pill {{ $myTeam->status }}">{{ strtoupper($myTeam->status) }}</span>
                    </div>
                    <form method="POST" action="{{ route('teams.withdraw', [$tournament, $myTeam]) }}">
                        @csrf
                        <button class="btn btn-sm" style="border-color:var(--red); color:var(--red)" onclick="return confirm('Withdraw your team from this tournament?')">Withdraw Team</button>
                    </form>
                </div>
            </div>
        @endif
    @endauth

    @if($tournament->acceptsRegistration() && !$tournament->isFull())
        <div class="card" style="text-align:center">
            <h3>Ready to fight? 🎯</h3>
            <a href="{{ route('teams.register', $tournament) }}" class="btn btn-primary">Register Your Team</a>
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
                <tr><th>#</th><th>Team</th><th>Captain</th><th>Status</th></tr>
                @foreach($tournament->confirmedTeams as $team)
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td><strong>{{ $team->name }}</strong></td>
                        <td class="muted">{{ $team->captain_name }}</td>
                        <td><span class="pill confirmed">CONFIRMED</span></td>
                    </tr>
                @endforeach
            </table>
        @endif
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
        .pill.confirmed, .pill.verified { background: rgba(52,211,153,.15); color: var(--green); }
        .pill.cancelled, .pill.withdrawn, .pill.rejected, .pill.failed { background: rgba(148,163,184,.15); color: var(--muted); }
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

---

## 4. NEW MIGRATIONS

### FILE: database/migrations/2026_09_04_110000_add_unique_team_registration_constraint.php
(Complete file content shown above in the IMPLEMENTATION section.)

Result of running it:
```
2026_09_04_110000_add_unique_team_registration_constraint ...... DONE
```

---

## 5. VERIFICATION

### 5.1 Automated tests
```bash
php artisan test
# Tests: 55 passed (161 assertions)
```
Breakdown:
| Suite | Tests |
|---|---|
| `Tests\Unit\ExampleTest` | 1 |
| `Tests\Feature\ExampleTest` | 1 |
| `Tests\Feature\AuthorizedWorkflowTest` (Phase 01) | 4 |
| `Tests\Feature\SecurityAuthorizationTest` (Phase 01) | 21 |
| `Tests\Feature\TournamentLifecycleTest` (Phase 02 — NEW) | 28 |
| **Total** | **55** |

Phase 01 baseline (27 tests / 82 assertions) → **all 27 still pass**. Phase 02 added **28 tests / 79 assertions**.

### 5.2 Syntax check
`php -l` on all 15 modified/new files → **ALL OK**.

### 5.3 Migrations + seed
```bash
php artisan migrate --force          # 110000 migration DONE
php artisan migrate:fresh --seed --force
# users=2, tournaments=2, teams=16, matches=7
```

### 5.4 Routes
```bash
php artisan route:list   # 34 routes
# + tournaments.complete, + teams.withdraw (new); all lifecycle routes present
```

### 5.5 HTTP verification (live server :8000)
| Request | Result |
|---|---|
| `GET /` `/tournaments` `/login` `/register` `/up` | **200** |
| organizer login → create tournament | **302** → show page, status = **draft** |
| `POST complete` from **draft** (invalid transition) | **302**, status stays **draft** |
| `POST publish` from draft | status → **open** |
| `POST publish` from **open** (invalid re-publish) | status stays **open** |
| `GET /admin/dashboard` (guest) | **302** |
| `POST publish` (guest, no CSRF) | **419** |

---

## 6. PHASE 02 RESULT

**STATUS: ✅ COMPLETE — lifecycle state machine + registration integrity implemented and verified.**

**Features completed:**
- ✅ Server-side state machine (`TournamentLifecycleService`) with legal-transition enforcement; `finished`/`cancelled` are terminal
- ✅ New `live → finished` ("Finish Tournament") transition with "all matches completed" precondition
- ✅ Publish gating: completeness validation (name/mode/map/fees/slots/team_size/future start) before `draft → open`
- ✅ Registration deadline: `acceptsRegistration()` = `open` AND not started (`starts_at`)
- ✅ Capacity: `slotsLeft()` now counts `pending + confirmed`; atomic slot-claim UPDATE (SQLite-compatible) prevents over-fill
- ✅ Duplicate registration + one-team-per-captain: app checks **plus** `UNIQUE(tournament_id, captain_id)` DB constraint
- ✅ Registration blocked for draft/closed/live/finished/cancelled/started tournaments
- ✅ Payment accepted only while registration is open and team is `pending`
- ✅ Withdrawal (captain/admin/organizer) before the tournament goes live; slot + captain-claim released; no refund invented
- ✅ Public index hides draft/cancelled tournaments
- ✅ Policies hardened: `publish`, `closeRegistration`, `start`, `complete`, `cancel`, `withdraw`
- ✅ All Phase 01 security tests and authorized workflows still pass (nothing over-blocked)

**Tests added:** 28 · **Total tests:** 55 · **Total assertions:** 161
**Files changed:** 11 modified + 4 new = **15** · **Migrations added:** 1

**Remaining gaps (out of scope for Phase 02, documented not hidden):**
1. `withdraw` of a **confirmed** (already-paid) team does not auto-refund — refunds are a payment concern (Phase 03+).
2. Real bKash API, wallet, payouts, dispute system, anti-cheat, notifications — later phases.
3. Match completion/verification UX (score approval) remains as Phase 01 left it — bracket *completion* was deliberately not redesigned.
4. `starts_at` is the only deadline field; no separate `registration_deadline` column was added (not needed — the start time is the deadline).

---

*Phase boundary respected: Phase 03 was NOT started.*
