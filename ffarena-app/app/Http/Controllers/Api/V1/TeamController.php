<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\TeamMemberResource;
use App\Http\Resources\Api\V1\TeamResource;
use App\Models\Team;
use App\Models\TeamMember;
use App\Services\AuditLogService;
use App\Services\RosterService;
use App\Support\ApiResponse;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 15 — team & roster APIs. Authorization goes through TeamPolicy and
 * roster integrity through RosterService; no direct DB manipulation.
 */
class TeamController extends Controller
{
    public function __construct(
        protected RosterService $roster,
        protected AuditLogService $audit,
    ) {
    }

    /**
     * GET /api/v1/me/teams — the caller's own teams.
     */
    public function index(Request $request): JsonResponse
    {
        $teams = Team::where('captain_id', $request->user()->id)
            ->with(['tournament', 'members'])
            ->orderByDesc('id')
            ->get();

        return ApiResponse::data(TeamResource::collection($teams));
    }

    /**
     * GET /api/v1/teams/{team}
     */
    public function show(Request $request, Team $team): JsonResponse
    {
        $this->authorize('view', $team);

        $team->load(['captain', 'members', 'tournament']);

        return ApiResponse::data(new TeamResource($team));
    }

    /**
     * PATCH /api/v1/teams/{team}
     */
    public function update(Request $request, Team $team): JsonResponse
    {
        $this->authorize('updateProfile', $team);

        $tournament = $team->tournament;

        $data = $request->validate([
            'name' => 'required|string|max:120',
            'captain_name' => 'required|string|max:120',
            'phone' => 'required|string|max:20',
            'game_uid' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9]{4,30}$/'],
        ]);

        try {
            $this->roster->updateProfile($team, $tournament, $data, $request->user()->isAdmin());
        } catch (DomainException $e) {
            return ApiResponse::error('roster_conflict', $e->getMessage(), [], 422);
        } catch (QueryException $e) {
            return ApiResponse::error('uid_conflict', 'This Free Fire UID is already used in this tournament.', [], 409);
        }

        $this->audit->recordQuietly($request->user(), 'team.updated', 'team', $team->id, [
            'tournament_id' => $tournament->id,
        ]);

        return ApiResponse::data(new TeamResource($team->fresh()->load('members')));
    }

    /**
     * GET /api/v1/teams/{team}/roster
     */
    public function roster(Request $request, Team $team): JsonResponse
    {
        $this->authorize('view', $team);

        return ApiResponse::data(TeamMemberResource::collection($team->members()->orderBy('id')->get()));
    }

    /**
     * POST /api/v1/teams/{team}/roster
     */
    public function addMember(Request $request, Team $team): JsonResponse
    {
        $this->authorize('addMember', $team);

        $tournament = $team->tournament;

        $data = $request->validate([
            'player_name' => 'required|string|max:120',
            'game_uid' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9]{4,30}$/'],
        ]);

        try {
            $member = $this->roster->addMember($team, $tournament, $data, $request->user()->isAdmin());
        } catch (DomainException $e) {
            return ApiResponse::error('roster_conflict', $e->getMessage(), [], 422);
        } catch (QueryException $e) {
            return ApiResponse::error('duplicate_member', 'This player is already in the team.', [], 409);
        }

        $this->audit->recordQuietly($request->user(), 'team.member_added', 'team', $team->id, [
            'tournament_id' => $tournament->id,
            'metadata' => ['player_name' => $member->player_name],
        ]);

        return ApiResponse::created(new TeamMemberResource($member));
    }

    /**
     * DELETE /api/v1/teams/{team}/roster/{member}
     */
    public function removeMember(Request $request, Team $team, TeamMember $member): JsonResponse
    {
        if ($member->team_id !== $team->id) {
            return ApiResponse::error('not_found', 'Member not found in this team.', [], 404);
        }

        $this->authorize('removeMember', $team);

        try {
            $this->roster->removeMember($team, $member, $request->user()->isAdmin());
        } catch (DomainException $e) {
            return ApiResponse::error('roster_conflict', $e->getMessage(), [], 422);
        }

        $this->audit->recordQuietly($request->user(), 'team.member_removed', 'team', $team->id, [
            'tournament_id' => $team->tournament_id,
            'metadata' => ['player_name' => $member->player_name],
        ]);

        return ApiResponse::noContent();
    }

    /**
     * POST /api/v1/teams/{team}/withdraw
     */
    public function withdraw(Request $request, Team $team): JsonResponse
    {
        $this->authorize('withdraw', $team);

        $tournament = $team->tournament;

        if (in_array($tournament->status, [
            \App\Models\Tournament::STATUS_LIVE,
            \App\Models\Tournament::STATUS_FINISHED,
            \App\Models\Tournament::STATUS_CANCELLED,
        ], true)) {
            return ApiResponse::error('withdraw_refused', 'Teams can no longer withdraw from this tournament.', [], 409);
        }

        if ($team->status === Team::STATUS_WITHDRAWN) {
            return ApiResponse::error('withdraw_refused', 'This team has already been withdrawn.', [], 409);
        }

        // Phase 10 — repeated-withdrawal signal (non-blocking observation).
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
            app(\App\Services\FraudRiskService::class)->recordSignal(
                $request->user(),
                \App\Models\RiskEvent::TYPE_WITHDRAWAL_REPEAT,
                \App\Models\RiskEvent::SEVERITY_LOW,
                'registration',
                ['withdrawal_count' => $withdrawals],
                $tournament
            );
        }

        // Phase 11 — notify the organizer.
        $organizer = $tournament->organizer;

        if ($organizer !== null) {
            app(\App\Services\NotificationService::class)->send(
                $organizer,
                \App\Models\Notification::TYPE_TEAM_WITHDRAWN,
                'Team withdrew',
                'Team ' . $team->name . ' withdrew from ' . $tournament->name . '.',
                \App\Services\NotificationService::link('tournaments.show', [$tournament]),
                ['team_id' => $team->id, 'tournament_id' => $tournament->id],
            );
        }

        // Phase 12 — live event (best-effort).
        app(\App\Services\LiveEventService::class)->recordQuietly($tournament, $request->user(), \App\Models\LiveEvent::TYPE_TEAM_WITHDRAWN, [
            'team' => $team->name,
        ]);

        // Phase 13 — central audit (best-effort).
        $this->audit->recordQuietly($request->user(), 'team.withdrawn', 'team', $team->id, [
            'tournament_id' => $tournament->id,
            'metadata' => ['team' => $team->name],
        ]);

        return ApiResponse::data(new TeamResource($team->fresh()));
    }
}
