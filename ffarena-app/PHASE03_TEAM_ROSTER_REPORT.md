# PHASE 03 — TEAM + ROSTER MANAGEMENT & COMPETITIVE TEAM INTEGRITY
**FF Arena (Free Fire Tournament Platform) — Laravel 12 / SQLite**
**Date:** 2026-09-04 · **Scope:** Team ownership, roster management, competitive integrity only (Phase 04 NOT implemented)

---

## 1. PHASE 03 AUDIT

### 1.1 Current team architecture (verified against actual code)
| Concept | Current implementation |
|---|---|
| **Team** | `teams` table: `tournament_id`, `captain_id` (nullable FK → users, nullOnDelete), `name`, `captain_name`, `phone`, `game_uid`, `status` (pending/confirmed/rejected/withdrawn), `seed`, timestamps. `$fillable = name, captain_name, phone, game_uid`. |
| **TeamMember** | `team_members` table: `team_id`, `player_name`, `game_uid`, timestamps. `$fillable = player_name, game_uid`. **No user_id link** — it is a manually-entered roster member (Free Fire player), not a platform account. |
| **Captain** | The authenticated user stored in `teams.captain_id` (server-set at registration). `captain_name` is a free-text display string. |
| **Ownership** | `Team::isCaptain(User)`, `Team::belongsToTournament(Tournament)`, `TeamPolicy` (manage/pay/submitScore/view/withdraw). |
| **Roster** | Only collected during registration (`TeamController@register` stores `members[]`). **No add/remove/profile actions existed.** |
| **team_size** | `tournaments.team_size` rendered in the register form (rows = team_size − 1); **no server-side enforcement of member count**. |
| **Registration** | Phase 02 flow: atomic slot claim → one-team-per-captain → team (pending) + members → payment → admin verify → confirmed. |
| **Policy coverage** | `TeamPolicy`: manage, pay, submitScore, view, withdraw. **No roster-specific gates.** |
| **Routes** | teams.register/store/withdraw + payment routes. **No team show / member routes.** |
| **DB constraints** | `teams UNIQUE(tournament_id, captain_id)`; `scores UNIQUE(match_id, team_id)`. **No roster constraints.** |

### 1.2 Exact gaps found
1. **Roster size not enforced** — a client could submit unlimited members (form renders team_size−1 rows, but the server never checked).
2. **No duplicate-player prevention** — same UID could appear twice in one team (case variations too).
3. **No cross-team protection** — the same Free Fire UID could represent two competing teams in the same tournament.
4. **No roster locking** — rosters stayed editable (in principle) after registration closed / tournament started.
5. **No team management page / actions** — captains could not add/remove members or fix team info after registration.
6. **No UID validation** — any string (or empty) accepted for members; captain UID had no format rule.
7. **No DB-level roster integrity** — duplicates only preventable by app logic (which did not exist).

### 1.3 Design decisions (smallest architecture that fits)
- **No invitation or join-request system.** The existing `TeamMember` design is *captain-managed manual roster* (player_name + game_uid, no user identity). Introducing `team_invitations` would require a user-identity system that does not exist and would over-engineer the phase. The captain directly adds/removes members.
- **Free Fire UID = player identity.** UIDs are normalized (`TRIM` + uppercase) and validated (`^[A-Za-z0-9]{4,30}$`) so identity comparison is consistent; case/format bypass is closed.
- **Roster lock = tournament lifecycle.** Roster editable only while the tournament is `open` (registration). Locked on `closed`/`live`/`finished`/`cancelled`. **Admins may override explicitly** (documented, auditable by being a distinct code path); a normal captain cannot.
- **Roster size = `tournament.team_size`** (captain + members). Captain occupies 1 slot; max members = team_size − 1. Full roster is NOT required (preserves existing captain-only workflow).
- **Cross-team rule is tournament-scoped** (same player may play in different tournaments).
- **No captain transfer** (not required; documented gap). **No substitute concept** (not present in schema; documented gap).
- **Service** `RosterService` owns all roster rules (UID normalization, size, duplicates, cross-team, lock); policies own authorization.

---

## 2. FILES TO CHANGE

**Modified (7):**
1. `app/Models/Team.php`
2. `app/Models/TeamMember.php`
3. `app/Policies/TeamPolicy.php`
4. `app/Http/Controllers/TeamController.php`
5. `routes/web.php`
6. `resources/views/tournaments/show.blade.php`
7. `resources/views/teams/show.blade.php` *(new page, listed here as it pairs with the controller)*

**New (3):**
8. `app/Services/RosterService.php`
9. `database/migrations/2026_09_04_120000_add_roster_integrity_constraints.php`
10. `tests/Feature/TeamRosterTest.php`

---

## 3. IMPLEMENTATION — COMPLETE FILES


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
            ->whereIn('status', Team::SLOT_STATUSES)
            ->whereRaw('UPPER(TRIM(game_uid)) = ?', [$uid])
            ->when($ignoreTeam, fn ($q) => $q->where('id', '!=', $ignoreTeam->id))
            ->exists();

        $memberClash = TeamMember::query()
            ->whereHas('team', fn ($q) => $q
                ->where('tournament_id', $tournament->id)
                ->whereIn('status', Team::SLOT_STATUSES))
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
}
```

### FILE: app/Models/TeamMember.php
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TeamMember extends Model
{
    use HasFactory;

    /**
     * team_id is set server-side from the authenticated team's relationship,
     * never from client input.
     */
    protected $fillable = [
        'player_name',
        'game_uid',
    ];

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function belongsToTeam(Team $team): bool
    {
        return $this->team_id === $team->id;
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
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TeamController extends Controller
{
    public function __construct(
        protected RosterService $roster,
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

        if ($tournament->isFull()) {
            return back()->with('error', 'All slots are full.');
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

        try {
            DB::transaction(function () use ($tournament, $user, $data, $captainUid, $members, &$team) {
                // ATOMIC SLOT CLAIM — SQLite-compatible concurrency guard.
                //
                // A single UPDATE that only succeeds while the tournament is
                // still open, has not started, and has a free slot. In SQLite
                // this statement acquires the write lock, so everything after
                // it in this transaction is race-free.
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

                // Roster integrity (Phase 03): the captain UID must not
                // already belong to another team in this tournament.
                $this->roster->assertUidAvailable($tournament, $captainUid);

                $team = new Team();
                $team->tournament_id = $tournament->id;
                $team->captain_id = $user->id;
                $team->name = $data['name'];
                $team->captain_name = $data['captain_name'];
                $team->phone = $data['phone'];
                $team->game_uid = $captainUid;
                $team->status = Team::STATUS_PENDING;
                $team->save();

                // Validate + persist roster members (size, duplicates,
                // cross-team clashes) — all inside the same transaction.
                $normalized = $this->roster->validateNewMembers($tournament, $team, $members);

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

        return view('teams.show', compact('tournament', 'team', 'locked', 'slotsLeft', 'canEdit'));
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

    // Team registration, payment + roster management
    Route::get('/tournaments/{tournament}/register', [TeamController::class, 'showRegistration'])->name('teams.register');
    Route::post('/tournaments/{tournament}/register', [TeamController::class, 'register'])->name('teams.store');
    Route::get('/tournaments/{tournament}/teams/{team}', [TeamController::class, 'show'])->name('teams.show');
    Route::put('/tournaments/{tournament}/teams/{team}/profile', [TeamController::class, 'updateProfile'])->name('teams.update');
    Route::post('/tournaments/{tournament}/teams/{team}/members', [TeamController::class, 'addMember'])->name('teams.members.store');
    Route::post('/tournaments/{tournament}/teams/{team}/members/{member}/remove', [TeamController::class, 'removeMember'])->name('teams.members.remove');
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

### FILE: database/migrations/2026_09_04_120000_add_roster_integrity_constraints.php
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Roster integrity constraints for Phase 03.
     *
     * 1. teams (tournament_id, game_uid) UNIQUE
     *    — prevents two teams in the SAME tournament sharing a captain UID.
     *
     * 2. team_members (team_id, game_uid) UNIQUE
     *    — prevents the same player UID appearing twice in ONE team.
     *
     * Both are compiled by Laravel's SQLite grammar as CREATE UNIQUE INDEX
     * (SQLite has no ALTER TABLE ADD CONSTRAINT). NULL game_uid values are
     * treated as distinct by SQLite/MySQL, so seeded teams without a UID
     * remain valid.
     */
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->unique(['tournament_id', 'game_uid'], 'teams_tournament_game_uid_unique');
        });

        Schema::table('team_members', function (Blueprint $table) {
            $table->unique(['team_id', 'game_uid'], 'team_members_team_uid_unique');
        });
    }

    public function down(): void
    {
        Schema::table('team_members', function (Blueprint $table) {
            $table->dropUnique('team_members_team_uid_unique');
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->dropUnique('teams_tournament_game_uid_unique');
        });
    }
};
```

### FILE: tests/Feature/TeamRosterTest.php
```php
<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\TeamMember;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 03 — Team + roster management & competitive team integrity.
 */
class TeamRosterTest extends TestCase
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
        $t->name = $overrides['name'] ?? 'Roster Tournament';
        $t->slug = $overrides['slug'] ?? ('roster-' . Str::random(8));
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

    protected function makeTeam(Tournament $tournament, ?User $captain = null, string $status = 'pending', string $uid = 'UIDCAPTAIN'): Team
    {
        $t = new Team();
        $t->tournament_id = $tournament->id;
        $t->captain_id = $captain?->id;
        $t->name = 'Team ' . Str::random(6);
        $t->captain_name = $captain?->name ?? 'Captain';
        $t->phone = '01700000000';
        $t->game_uid = $uid;
        $t->status = $status;
        $t->save();

        return $t;
    }

    protected function makeMember(Team $team, string $name = 'Player', string $uid = 'UIDMEMBER'): TeamMember
    {
        $m = new TeamMember();
        $m->team_id = $team->id;
        $m->player_name = $name;
        $m->game_uid = $uid;
        $m->save();

        return $m;
    }

    protected function validRegistrationPayload(string $name = 'Test Squad', string $uid = 'UID123456'): array
    {
        return [
            'name' => $name,
            'captain_name' => 'Captain',
            'phone' => '01700000000',
            'game_uid' => $uid,
            'members' => [],
        ];
    }

    // ------------------------------------------------------------------
    // 1. Who may manage a team
    // ------------------------------------------------------------------

    public function test_guest_cannot_manage_a_team(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $team = $this->makeTeam($tournament, $captain);

        $this->get(route('teams.show', [$tournament, $team]))->assertRedirect(route('login'));

        $this->post(route('teams.members.store', [$tournament, $team]), [
            'player_name' => 'Hacker', 'game_uid' => 'UIDHACK',
        ])->assertRedirect(route('login'));
    }

    public function test_player_cannot_manage_another_users_team(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $intruder = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $team = $this->makeTeam($tournament, $captain);

        $this->actingAs($intruder)->get(route('teams.show', [$tournament, $team]))->assertStatus(403);

        $this->actingAs($intruder)->post(route('teams.members.store', [$tournament, $team]), [
            'player_name' => 'Sneak', 'game_uid' => 'UIDSNEAK',
        ])->assertStatus(403);

        $this->assertSame(0, $team->members()->count());
    }

    public function test_captain_can_manage_own_team(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $team = $this->makeTeam($tournament, $captain);

        $this->actingAs($captain)->post(route('teams.members.store', [$tournament, $team]), [
            'player_name' => 'SquadMate',
            'game_uid' => 'uidmate99', // lowercase on purpose — must be normalized
        ])->assertSessionHas('success');

        $this->assertSame(1, $team->members()->count());
        $this->assertSame('UIDMATE99', $team->members()->first()->game_uid);
    }

    public function test_admin_can_perform_authorized_team_management(): void
    {
        $admin = $this->makeUser('admin');
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $team = $this->makeTeam($tournament, $captain);

        $this->actingAs($admin)->post(route('teams.members.store', [$tournament, $team]), [
            'player_name' => 'Admin Add',
            'game_uid' => 'UIDADMIN',
        ])->assertSessionHas('success');
        $this->assertSame(1, $team->members()->count());

        $member = $team->members()->first();
        $this->actingAs($admin)->post(route('teams.members.remove', [$tournament, $team, $member]))
            ->assertSessionHas('success');
        $this->assertSame(0, $team->members()->count());
    }

    public function test_organizer_permissions_remain_compatible(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $team = $this->makeTeam($tournament, $captain);

        // Organizers can view a team (they manage the tournament)...
        $this->actingAs($org)->get(route('teams.show', [$tournament, $team]))->assertOk();

        // ...but cannot edit its roster (competitive integrity).
        $this->actingAs($org)->post(route('teams.members.store', [$tournament, $team]), [
            'player_name' => 'Organizer Add', 'game_uid' => 'UIDORG',
        ])->assertStatus(403);

        $this->assertSame(0, $team->members()->count());
    }

    // ------------------------------------------------------------------
    // 2. Roster size
    // ------------------------------------------------------------------

    public function test_team_cannot_exceed_tournament_team_size(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open', ['team_size' => 4]); // captain + 3 members
        $team = $this->makeTeam($tournament, $captain);

        foreach (['UIDA001', 'UIDA002', 'UIDA003'] as $i => $uid) {
            $this->actingAs($captain)->post(route('teams.members.store', [$tournament, $team]), [
                'player_name' => 'Player ' . $i,
                'game_uid' => $uid,
            ])->assertSessionHas('success');
        }

        $this->assertSame(3, $team->members()->count());

        // 4th member must be rejected.
        $this->actingAs($captain)->post(route('teams.members.store', [$tournament, $team]), [
            'player_name' => 'Overflow',
            'game_uid' => 'UIDOVER',
        ])->assertSessionHas('error');

        $this->assertSame(3, $team->members()->count());
    }

    // ------------------------------------------------------------------
    // 3. Duplicate player prevention
    // ------------------------------------------------------------------

    public function test_duplicate_member_cannot_be_added(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $team = $this->makeTeam($tournament, $captain);

        $this->actingAs($captain)->post(route('teams.members.store', [$tournament, $team]), [
            'player_name' => 'Dup One',
            'game_uid' => 'uiddup01',
        ])->assertSessionHas('success');

        // Same UID, different case + different name → rejected.
        $this->actingAs($captain)->post(route('teams.members.store', [$tournament, $team]), [
            'player_name' => 'Dup Two',
            'game_uid' => 'UIDDUP01',
        ])->assertSessionHas('error');

        $this->assertSame(1, $team->members()->count());
    }

    public function test_member_cannot_share_captain_uid(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $team = $this->makeTeam($tournament, $captain, 'pending', 'UIDCAPTAIN');

        $this->actingAs($captain)->post(route('teams.members.store', [$tournament, $team]), [
            'player_name' => 'Clone',
            'game_uid' => 'uidcaptain',
        ])->assertSessionHas('error');

        $this->assertSame(0, $team->members()->count());
    }

    public function test_database_enforces_unique_member_per_team(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $team = $this->makeTeam($tournament, $captain);

        $this->makeMember($team, 'First', 'UIDDUPDB');

        $dup = new TeamMember();
        $dup->team_id = $team->id;
        $dup->player_name = 'Second';
        $dup->game_uid = 'UIDDUPDB';

        $threw = false;
        try {
            $dup->save();
        } catch (QueryException $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Expected the team_members_team_uid_unique constraint to reject a duplicate member.');
    }

    // ------------------------------------------------------------------
    // 4. Unauthorized mutations
    // ------------------------------------------------------------------

    public function test_unauthorized_user_cannot_add_member(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $stranger = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $team = $this->makeTeam($tournament, $captain);

        $this->actingAs($stranger)->post(route('teams.members.store', [$tournament, $team]), [
            'player_name' => 'Stranger',
            'game_uid' => 'UIDSTRA',
        ])->assertStatus(403);

        $this->assertSame(0, $team->members()->count());
    }

    public function test_unauthorized_user_cannot_remove_member(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $stranger = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $team = $this->makeTeam($tournament, $captain);
        $member = $this->makeMember($team, 'Keep Me', 'UIDKEEP');

        $this->actingAs($stranger)->post(route('teams.members.remove', [$tournament, $team, $member]))
            ->assertStatus(403);

        $this->assertSame(1, $team->members()->count());
    }

    // ------------------------------------------------------------------
    // 5. Cross-tournament protection
    // ------------------------------------------------------------------

    public function test_team_from_tournament_a_cannot_be_modified_via_tournament_b(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournamentA = $this->makeTournament($org, 'open', ['name' => 'Tournament A']);
        $tournamentB = $this->makeTournament($org, 'open', ['name' => 'Tournament B']);
        $teamA = $this->makeTeam($tournamentA, $captain);

        $this->actingAs($captain)->get(route('teams.show', [$tournamentB, $teamA]))->assertStatus(404);

        $this->actingAs($captain)->post(route('teams.members.store', [$tournamentB, $teamA]), [
            'player_name' => 'X', 'game_uid' => 'UIDXXXX',
        ])->assertStatus(404);

        $this->assertSame(0, $teamA->members()->count());
    }

    // ------------------------------------------------------------------
    // 6. Cross-team roster abuse (same tournament)
    // ------------------------------------------------------------------

    public function test_player_cannot_join_two_teams_in_same_tournament(): void
    {
        $org = $this->makeUser('organizer');
        $captain1 = $this->makeUser('player');
        $captain2 = $this->makeUser('player');
        $tournament = $this->makeTournament($org);

        // Team One: captain1 with UID UIDAAAA.
        $team1 = $this->makeTeam($tournament, $captain1, 'pending', 'UIDAAAA');
        // Team Two: captain2 with UID UIDBBBB.
        $team2 = $this->makeTeam($tournament, $captain2, 'pending', 'UIDBBBB');

        // captain2 tries to add UIDAAAA (already team one's captain) → rejected.
        $this->actingAs($captain2)->post(route('teams.members.store', [$tournament, $team2]), [
            'player_name' => 'Ring In',
            'game_uid' => 'UIDAAAA',
        ])->assertSessionHas('error');

        $this->assertSame(0, $team2->members()->count());

        // Registration path: a new captain registering a team whose member UID
        // already belongs to team one must also be rejected.
        $captain3 = $this->makeUser('player');
        $this->actingAs($captain3)->post(route('teams.store', $tournament), [
            'name' => 'Team Three',
            'captain_name' => 'Cap3',
            'phone' => '01700000000',
            'game_uid' => 'UIDCCCC',
            'members' => [['player_name' => 'Clash', 'game_uid' => 'UIDAAAA']],
        ])->assertSessionHas('error');

        $this->assertSame(0, Team::where('name', 'Team Three')->count());
    }

    public function test_player_can_participate_in_different_tournaments(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournamentA = $this->makeTournament($org, 'open', ['name' => 'Tournament A']);
        $tournamentB = $this->makeTournament($org, 'open', ['name' => 'Tournament B']);

        $this->actingAs($captain)->post(route('teams.store', $tournamentA), [
            'name' => 'Team A', 'captain_name' => 'Cap', 'phone' => '01700000000',
            'game_uid' => 'UIDAAAA',
            'members' => [['player_name' => 'Shared', 'game_uid' => 'UIDSHARED']],
        ])->assertRedirect();

        $this->actingAs($captain)->post(route('teams.store', $tournamentB), [
            'name' => 'Team B', 'captain_name' => 'Cap', 'phone' => '01700000000',
            'game_uid' => 'UIDBBBB',
            'members' => [['player_name' => 'Shared', 'game_uid' => 'UIDSHARED']],
        ])->assertRedirect();

        $this->assertSame(1, Team::where('name', 'Team A')->count());
        $this->assertSame(1, Team::where('name', 'Team B')->count());
        $this->assertSame(1, Team::where('name', 'Team A')->first()->members()->count());
        $this->assertSame(1, Team::where('name', 'Team B')->first()->members()->count());
    }

    // ------------------------------------------------------------------
    // 7. Roster lock
    // ------------------------------------------------------------------

    public function test_roster_cannot_be_modified_after_lock(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'closed'); // registration closed → locked
        $team = $this->makeTeam($tournament, $captain);

        $this->actingAs($captain)->post(route('teams.members.store', [$tournament, $team]), [
            'player_name' => 'Late',
            'game_uid' => 'UIDLATE',
        ])->assertSessionHas('error');

        $this->assertSame(0, $team->members()->count());
    }

    public function test_captain_cannot_bypass_roster_lock(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'closed');
        $team = $this->makeTeam($tournament, $captain);
        $member = $this->makeMember($team, 'Locked In', 'UIDLOCK');

        $this->actingAs($captain)->post(route('teams.members.remove', [$tournament, $team, $member]))
            ->assertSessionHas('error');

        $this->assertSame(1, $team->members()->count());
    }

    public function test_profile_update_blocked_after_lock(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'closed');
        $team = $this->makeTeam($tournament, $captain);

        $this->actingAs($captain)->put(route('teams.update', [$tournament, $team]), [
            'name' => 'Renamed',
            'captain_name' => 'Captain',
            'phone' => '01700000000',
            'game_uid' => 'UIDCAPTAIN',
        ])->assertSessionHas('error');

        $this->assertNotSame('Renamed', $team->fresh()->name);
    }

    // ------------------------------------------------------------------
    // 8. UID validation
    // ------------------------------------------------------------------

    public function test_invalid_free_fire_uid_is_rejected(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $team = $this->makeTeam($tournament, $captain);

        // Invalid format in addMember.
        $this->actingAs($captain)->post(route('teams.members.store', [$tournament, $team]), [
            'player_name' => 'Bad UID',
            'game_uid' => 'bad uid!',
        ])->assertSessionHasErrors('game_uid');

        // Too short at registration (captain UID).
        $this->actingAs($captain)->post(route('teams.store', $tournament), $this->validRegistrationPayload('Bad UID Team', 'abc'))
            ->assertSessionHasErrors('game_uid');

        $this->assertSame(0, $team->members()->count());
    }

    // ------------------------------------------------------------------
    // 9. Registration integrity
    // ------------------------------------------------------------------

    public function test_valid_roster_can_be_submitted(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open', ['team_size' => 4]);

        $this->actingAs($captain)->post(route('teams.store', $tournament), [
            'name' => 'Full Squad',
            'captain_name' => 'Captain',
            'phone' => '01700000000',
            'game_uid' => 'uidcap777',
            'members' => [
                ['player_name' => 'Mate 1', 'game_uid' => 'uidm1111'],
                ['player_name' => 'Mate 2', 'game_uid' => 'uidm2222'],
            ],
        ])->assertRedirect(route('payment.show', [$tournament, Team::where('name', 'Full Squad')->firstOrFail()]));

        $team = Team::where('name', 'Full Squad')->firstOrFail();
        $this->assertSame('pending', $team->status);
        $this->assertSame('UIDCAP777', $team->game_uid); // normalized
        $this->assertSame(2, $team->members()->count());
        $this->assertTrue($team->members()->pluck('game_uid')->contains('UIDM1111'));
        $this->assertTrue($team->members()->pluck('game_uid')->contains('UIDM2222'));
    }

    public function test_registration_with_captain_only_is_allowed(): void
    {
        // Full roster is NOT required by existing rules — a captain may
        // register alone and complete the roster later (while open).
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open', ['team_size' => 4]);

        $this->actingAs($captain)->post(route('teams.store', $tournament), $this->validRegistrationPayload('Solo Entry', 'UIDSOLO1'))
            ->assertRedirect();

        $team = Team::where('name', 'Solo Entry')->firstOrFail();
        $this->assertSame(0, $team->members()->count());
        $this->assertSame(1, $team->rosterSize());
    }

    public function test_captain_can_update_team_profile(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $team = $this->makeTeam($tournament, $captain, 'pending', 'UIDCAPTAIN');

        $this->actingAs($captain)->put(route('teams.update', [$tournament, $team]), [
            'name' => 'Renamed Squad',
            'captain_name' => 'New Captain Name',
            'phone' => '01800000000',
            'game_uid' => 'UIDCAPTAIN',
        ])->assertSessionHas('success');

        $this->assertSame('Renamed Squad', $team->fresh()->name);
        $this->assertSame('New Captain Name', $team->fresh()->captain_name);
        $this->assertSame('01800000000', $team->fresh()->phone);
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

        @if($myTeam && !$myTeam->isWithdrawn())
            <div class="card">
                <h3>🎽 Your Team</h3>
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px">
                    <div>
                        <strong>{{ $myTeam->name }}</strong>
                        <span class="pill {{ $myTeam->status }}">{{ strtoupper($myTeam->status) }}</span>
                        <div class="muted" style="font-size:13px; margin-top:4px">Roster: {{ $myTeam->rosterSize() }} / {{ $tournament->team_size }} players</div>
                    </div>
                    <div style="display:flex; gap:8px; flex-wrap:wrap">
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
        </div>
    </div>

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

---

## 4. NEW MIGRATIONS

### FILE: database/migrations/2026_09_04_120000_add_roster_integrity_constraints.php
(Complete file content shown above in the IMPLEMENTATION section.)

Result of running it:
```
2026_09_04_120000_add_roster_integrity_constraints ...... DONE
```
Adds two SQLite-compatible unique indexes:
- `teams (tournament_id, game_uid) UNIQUE` — no two teams in the same tournament share a captain UID.
- `team_members (team_id, game_uid) UNIQUE` — no duplicate player UID inside one team.

---

## 5. TEST FILES

### FILE: tests/Feature/TeamRosterTest.php
(Complete file content shown above in the IMPLEMENTATION section — 21 tests.)

---

## 6. VERIFICATION

### 6.1 Automated tests
```bash
php artisan test
# Tests: 76 passed (232 assertions)
```
| Suite | Tests |
|---|---|
| `Tests\Unit\ExampleTest` | 1 |
| `Tests\Feature\ExampleTest` | 1 |
| `Tests\Feature\AuthorizedWorkflowTest` (Phase 01) | 4 |
| `Tests\Feature\SecurityAuthorizationTest` (Phase 01) | 21 |
| `Tests\Feature\TournamentLifecycleTest` (Phase 02) | 28 |
| `Tests\Feature\TeamRosterTest` (Phase 03 — NEW) | 21 |
| **Total** | **76** |

Phase 02 baseline (55 tests / 161 assertions) → **all 55 still pass**. Phase 03 added **21 tests / 71 assertions**.

### 6.2 Syntax check
`php -l` on all 10 modified/new PHP files → **ALL OK**.

### 6.3 Migrations + seed
```bash
php artisan migrate --force
php artisan migrate:fresh --seed --force
# users=2, tournaments=2, teams=16, team_members=0, matches=7
```

### 6.4 Routes
```bash
php artisan route:list   # 38 routes
# + teams.show, teams.update, teams.members.store, teams.members.remove (new)
```

### 6.5 HTTP verification (live server :8000)
| Request | Result |
|---|---|
| `GET /` `/tournaments` `/login` `/register` | **200** |
| admin login → `GET /tournaments/{slug}/teams/{team}` | **200** (Team Info + Roster render) |
| `GET /tournaments/{slug}/teams/{team}` (guest) | **302** → login |
| player registration (register form) | **302** + user created (role=player) |
| player → `GET /organizer/tournaments/create` | **403** |

---

## 7. PHASE 03 RESULT

**STATUS: ✅ COMPLETE — team + roster management & competitive integrity implemented and verified.**

**Features completed:**
- ✅ Team management page (`teams.show`) with roster, team info, add/remove member, edit profile
- ✅ Captain-only roster management (+ explicit admin override); organizers cannot edit rosters
- ✅ Roster size enforced against `tournament.team_size` (atomic SQLite-compatible slot claim)
- ✅ Duplicate-player prevention within a team (case-insensitive, normalized UID) + `UNIQUE(team_id, game_uid)`
- ✅ Cross-team abuse prevention per tournament (app-level) + `UNIQUE(tournament_id, game_uid)` on captains
- ✅ Free Fire UID validation (`^[A-Za-z0-9]{4,30}$`) + normalization (trim/uppercase)
- ✅ Roster locking by Phase 02 lifecycle (editable only while `open`; locked at closed/live/finished/cancelled)
- ✅ Cross-tournament protection (404 for team from another tournament)
- ✅ Registration integrity (size, duplicates, cross-team, captain UID) integrated with Phase 02 flow
- ✅ All Phase 01 security + Phase 02 lifecycle tests still pass; all existing business logic preserved

**Vulnerabilities fixed:** roster overflow (unlimited members), duplicate/forged UIDs, cross-team multi-team players, roster tampering after registration close, unauthorized roster mutation, cross-tournament IDOR on team routes.

**Tests added:** 21 · **Total tests:** 76 · **Total assertions:** 232
**Files changed:** 7 modified + 3 new = **10** · **Migrations added:** 1

**Remaining gaps (documented, out of scope for Phase 03):**
1. **Invitations / join requests** — not built (captain-managed roster is the smallest fit; a user-identity system would be needed first).
2. **Captain transfer** — not built; requires explicit authorization design.
3. **Substitutes (starter vs substitute)** — not built; current schema has no substitute concept.
4. **Roster history / audit log** — no audit table exists; roster lock prevents *rewriting* history but a full audit trail is deferred.
5. **DB-level cross-team UID constraint** — not feasible without a schema redesign (members live in a different table without a tournament column); enforced app-level under the SQLite write lock.
6. Withdrawal of a **confirmed** team still does not auto-refund (payment concern — Phase 04+).

---

*Phase boundary respected: Phase 04 was NOT started.*
