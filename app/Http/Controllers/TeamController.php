<?php

namespace App\Http\Controllers;

use App\Exceptions\RegistrationClosedException;
use App\Models\LiveEvent;
use App\Models\Notification;
use App\Models\RiskEvent;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\Tournament;
use App\Services\AuditLogService;
use App\Services\FraudRiskService;
use App\Services\LiveEventService;
use App\Services\NotificationService;
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
        protected FraudRiskService $risk,
        protected NotificationService $notifications,
        protected LiveEventService $live,
        protected AuditLogService $audit,
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

        // Phase 10 — fraud/risk gate (restriction + risk-level enforcement).
        try {
            $this->risk->evaluateRegistration($tournament, $user);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

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

        // Phase 10 — registration-volume signal (non-blocking observation).
        $teamCount = Team::where('captain_id', $user->id)->count();
        $maxTeams = (int) config('antifraud.registration.max_teams', 5);

        if ($teamCount >= $maxTeams) {
            $this->risk->recordSignal($user, RiskEvent::TYPE_REGISTRATION_VOLUME, RiskEvent::SEVERITY_MEDIUM, 'registration', [
                'team_count' => $teamCount,
            ], $tournament);
        }

        // Phase 11 — notify the captain and the organizer.
        $teamLink = NotificationService::link('teams.show', [$tournament, $team]);

        $this->notifications->send(
            $user,
            Notification::TYPE_TEAM_REGISTERED,
            'Team registered',
            'Your team ' . $team->name . ' was registered for ' . $tournament->name . '.',
            $teamLink,
            ['team_id' => $team->id, 'tournament_id' => $tournament->id],
        );

        $organizer = $tournament->organizer;

        if ($organizer !== null) {
            $this->notifications->send(
                $organizer,
                Notification::TYPE_TEAM_REGISTERED,
                'New team registration',
                'Team ' . $team->name . ' registered for ' . $tournament->name . '.',
                $teamLink,
                ['team_id' => $team->id, 'tournament_id' => $tournament->id],
            );
        }

        // Phase 12 — live event (best-effort).
        $this->live->recordQuietly($tournament, $user, LiveEvent::TYPE_TEAM_REGISTERED, [
            'team' => $team->name,
            'waitlisted' => $waitlisted,
        ]);

        // Phase 13 — central audit (best-effort).
        $this->audit->recordQuietly($user, 'team.registered', 'team', $team->id, [
            'tournament_id' => $tournament->id,
            'metadata' => ['team' => $team->name, 'waitlisted' => $waitlisted],
        ]);

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

        // Phase 10 — repeated-withdrawal signal (non-blocking observation).
        // Count prior withdrawals before releasing the captain claim.
        $priorWithdrawals = Team::where('captain_id', $request->user()->id)
            ->where('status', Team::STATUS_WITHDRAWN)
            ->count();

        $team->status = Team::STATUS_WITHDRAWN;
        $team->captain_id = null; // release the captain's claim so they may re-register
        $team->game_uid = null;   // release the captain UID so it can be re-used
        $team->save();

        $withdrawals = $priorWithdrawals + 1;
        $threshold = (int) config('antifraud.withdrawal.repeat_threshold', 3);

        if ($withdrawals >= $threshold) {
            $this->risk->recordSignal($request->user(), RiskEvent::TYPE_WITHDRAWAL_REPEAT, RiskEvent::SEVERITY_LOW, 'registration', [
                'withdrawal_count' => $withdrawals,
            ], $tournament);
        }

        // Phase 11 — notify the organizer that a team withdrew.
        $organizer = $tournament->organizer;

        if ($organizer !== null) {
            $this->notifications->send(
                $organizer,
                Notification::TYPE_TEAM_WITHDRAWN,
                'Team withdrew',
                'Team ' . $team->name . ' withdrew from ' . $tournament->name . '.',
                NotificationService::link('tournaments.show', [$tournament]),
                ['team_id' => $team->id, 'tournament_id' => $tournament->id],
            );
        }

        // Phase 12 — live event (best-effort).
        $this->live->recordQuietly($tournament, $request->user(), LiveEvent::TYPE_TEAM_WITHDRAWN, [
            'team' => $team->name,
        ]);

        // Phase 13 — central audit (best-effort).
        $this->audit->recordQuietly($request->user(), 'team.withdrawn', 'team', $team->id, [
            'tournament_id' => $tournament->id,
            'metadata' => ['team' => $team->name],
        ]);

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

        $this->audit->recordQuietly($request->user(), 'team.member_added', 'team', $team->id, [
            'tournament_id' => $tournament->id,
            'metadata' => ['player_name' => $member->player_name],
        ]);

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

        $this->audit->recordQuietly($request->user(), 'team.member_removed', 'team', $team->id, [
            'tournament_id' => $tournament->id,
            'metadata' => ['player_name' => $member->player_name],
        ]);

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

        $this->audit->recordQuietly($request->user(), 'team.updated', 'team', $team->id, [
            'tournament_id' => $tournament->id,
        ]);

        return back()->with('success', 'Team profile updated.');
    }

    /**
     * Team check-in (captain or admin). Idempotent.
     */
    public function checkIn(Request $request, Tournament $tournament, Team $team)
    {
        abort_unless($team->belongsToTournament($tournament), 404);
        $this->authorize('checkIn', $team);

        // Phase 10 — fraud/risk gate for check-in.
        try {
            $this->risk->gate($request->user(), 'checkin', $tournament);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        try {
            $result = $this->participation->checkIn($tournament, $team, $request->user(), $request->user()->isAdmin());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($result === 'already') {
            return back()->with('success', 'Your team is already checked in.');
        }

        $this->audit->recordQuietly($request->user(), 'team.checked_in', 'team', $team->id, [
            'tournament_id' => $tournament->id,
        ]);

        return back()->with('success', 'Check-in successful! Your team is confirmed for the bracket.');
    }

    /**
     * Registration form (legacy route name `teams.register.form`). The
     * canonical entry point is {@see showRegistration()} — the same form with
     * the lifecycle checks applied.
     */
    public function showRegisterForm(Tournament $tournament)
    {
        return $this->showRegistration($tournament);
    }
}
