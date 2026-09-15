<?php

namespace App\Services;

use App\Exceptions\RegistrationClosedException;
use App\Models\LiveEvent;
use App\Models\Notification;
use App\Models\RiskEvent;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\Tournament;
use App\Models\User;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Phase 15 — shared team-registration engine.
 *
 * This is the single authoritative registration flow used by BOTH the web
 * controller (Phase 04) and the /api/v1 registration endpoint. Nothing about
 * eligibility, capacity, slots, waitlist position or payment state is taken
 * from the client: the server derives every one of them.
 *
 * The flow (unchanged from Phase 04, extracted verbatim):
 *   1. fraud/risk gate (FraudRiskService::evaluateRegistration),
 *   2. authoritative lifecycle re-check inside a transaction,
 *   3. one-team-per-captain,
 *   4. roster UID availability,
 *   5. atomic slot claim (SQLite-compatible concurrency guard),
 *   6. waitlist branch when full, else pending team,
 *   7. roster member validation + insert,
 *   8. registration-volume risk signal,
 *   9. notifications, live event and audit.
 */
class RegistrationService
{
    public function __construct(
        protected RosterService $roster,
        protected FraudRiskService $risk,
        protected NotificationService $notifications,
        protected LiveEventService $live,
        protected AuditLogService $audit,
    ) {
    }

    /**
     * Register a team for a tournament.
     *
     * @param  array<string, mixed>  $data  already-validated registration payload
     * @return array{team: Team, waitlisted: bool}
     *
     * @throws DomainException             risk gate or roster violation
     * @throws RegistrationClosedException lifecycle refusal
     * @throws QueryException              unique-index backstop
     */
    public function register(Tournament $tournament, User $user, array $data): array
    {
        // Phase 10 — fraud/risk gate (restriction + risk-level enforcement).
        $this->risk->evaluateRegistration($tournament, $user);

        $captainUid = $this->roster->normalizeUid($data['game_uid']);
        $members = is_array($data['members'] ?? null) ? $data['members'] : [];

        $team = null;
        $waitlisted = false;

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

            // Roster integrity (Phase 03): the captain UID must not already
            // belong to another team in this tournament.
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
                // No slot. Re-check under the write lock: if the tournament
                // really is full, the team goes to the waitlist. Otherwise
                // registration is genuinely closed.
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

        // Phase 15 — outbound webhook (best-effort; never rolls back the
        // registration if delivery fails).
        app(WebhookDispatcher::class)->dispatchQuietly('team.registered', [
            'team_id' => $team->id,
            'team_name' => $team->name,
            'tournament_id' => $tournament->id,
            'tournament_name' => $tournament->name,
            'waitlisted' => $waitlisted,
        ]);

        // Phase 16 — the tournament's public availability snapshot (slots/
        // status) is now stale.
        app(CacheInvalidationService::class)->invalidateTournamentAvailability($tournament->id);

        return ['team' => $team, 'waitlisted' => $waitlisted];
    }
}
