<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\RegistrationClosedException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\LeaderboardEntryResource;
use App\Http\Resources\Api\V1\LiveEventResource;
use App\Http\Resources\Api\V1\MatchResource;
use App\Http\Resources\Api\V1\TeamResource;
use App\Http\Resources\Api\V1\TournamentResource;
use App\Models\Tournament;
use App\Services\AuditLogService;
use App\Services\FraudRiskService;
use App\Services\LiveEventService;
use App\Services\RegistrationService;
use App\Services\ScoringService;
use App\Services\TournamentParticipationService;
use App\Support\ApiResponse;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 15 — tournament discovery, registration, check-in, waitlist,
 * leaderboard, bracket, live feed. Every business rule lives in the shared
 * services; this controller only translates HTTP.
 */
class TournamentController extends Controller
{
    public function __construct(
        protected RegistrationService $registrations,
        protected TournamentParticipationService $participation,
        protected FraudRiskService $risk,
        protected ScoringService $scoring,
        protected LiveEventService $live,
        protected AuditLogService $audit,
    ) {
    }

    /**
     * GET /api/v1/tournaments
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = $this->perPage($request);

        // Whitelisted sort keys + directions; never concatenated into SQL.
        $sort = $request->query('sort');
        $order = match ($sort) {
            'starts_at' => 'starts_at',
            'prize_pool' => 'prize_pool',
            'entry_fee' => 'entry_fee',
            'name' => 'name',
            default => 'created_at',
        };
        $direction = $request->query('direction') === 'asc' ? 'asc' : 'desc';

        $query = Tournament::query()
            ->with('organizer')
            ->withCount('confirmedTeams')
            ->whereIn('status', Tournament::PUBLIC_STATUSES);

        $status = $request->query('status');
        if ($status !== null && in_array($status, Tournament::PUBLIC_STATUSES, true)) {
            $query->where('status', $status);
        }

        $gameMode = $request->query('game_mode');
        if ($gameMode !== null && in_array($gameMode, ['squad', 'duo', 'solo'], true)) {
            $query->where('game_mode', $gameMode);
        }

        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $query->where('name', 'like', '%' . addcslashes($search, '%_') . '%');
        }

        $tournaments = $query->orderBy($order, $direction)->paginate($perPage);

        return ApiResponse::data(
            TournamentResource::collection($tournaments),
            [
                'pagination' => [
                    'current_page' => $tournaments->currentPage(),
                    'last_page' => $tournaments->lastPage(),
                    'per_page' => $tournaments->perPage(),
                    'total' => $tournaments->total(),
                ],
            ]
        );
    }

    /**
     * GET /api/v1/tournaments/{tournament}
     */
    public function show(Request $request, Tournament $tournament): JsonResponse
    {
        if (! in_array($tournament->status, Tournament::PUBLIC_STATUSES, true)) {
            return ApiResponse::error('not_found', 'Tournament not found.', [], 404);
        }

        $tournament->load('organizer')->loadCount('confirmedTeams');

        return ApiResponse::data(new TournamentResource($tournament));
    }

    /**
     * GET /api/v1/tournaments/{tournament}/matches
     */
    public function matches(Request $request, Tournament $tournament): JsonResponse
    {
        if (! in_array($tournament->status, Tournament::PUBLIC_STATUSES, true)) {
            return ApiResponse::error('not_found', 'Tournament not found.', [], 404);
        }

        $matches = $tournament->matches()
            ->with(['team1', 'team2', 'winner', 'scores.team'])
            ->orderBy('bracket')->orderBy('round')->orderBy('match_no')
            ->get();

        return ApiResponse::data(MatchResource::collection($matches));
    }

    /**
     * GET /api/v1/tournaments/{tournament}/leaderboard
     */
    public function leaderboard(Request $request, Tournament $tournament): JsonResponse
    {
        if (! in_array($tournament->status, Tournament::PUBLIC_STATUSES, true)) {
            return ApiResponse::error('not_found', 'Tournament not found.', [], 404);
        }

        // Standings rows are stdClass (the shared ranking source of truth);
        // they pass through unchanged except for the rank backfill.
        $rows = $this->scoring->standings($tournament)->map(function ($row, int $index) {
            if (is_array($row)) {
                $row['rank'] = $row['rank'] ?? $index + 1;
            } else {
                $row->rank = $row->rank ?? $index + 1;
            }

            return $row;
        });

        return ApiResponse::data(
            LeaderboardEntryResource::collection($rows),
            ['tie_breakers' => $this->scoring->currentRuleSet($tournament)->tieBreakers()]
        );
    }

    /**
     * GET /api/v1/tournaments/{tournament}/bracket
     */
    public function bracket(Request $request, Tournament $tournament): JsonResponse
    {
        if (! in_array($tournament->status, Tournament::PUBLIC_STATUSES, true)) {
            return ApiResponse::error('not_found', 'Tournament not found.', [], 404);
        }

        $matches = $tournament->matches()
            ->with(['team1', 'team2', 'winner'])
            ->orderBy('bracket')->orderBy('round')->orderBy('match_no')
            ->get();

        $byRound = $matches->groupBy(fn ($m) => $m->bracket . ':' . $m->round)
            ->map(fn ($group) => MatchResource::collection($group));

        return ApiResponse::data([
            'format' => $tournament->format,
            'rounds' => $byRound,
        ]);
    }

    /**
     * POST /api/v1/tournaments/{tournament}/registrations
     */
    public function register(Request $request, Tournament $tournament): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'captain_name' => 'required|string|max:120',
            'phone' => 'required|string|max:20',
            'game_uid' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9]{4,30}$/'],
            'members' => 'nullable|array',
            'members.*.player_name' => 'nullable|string|max:120',
            'members.*.game_uid' => 'nullable|string|max:30',
        ]);

        try {
            $result = $this->registrations->register($tournament, $request->user(), $data);
        } catch (RegistrationClosedException $e) {
            return ApiResponse::error('registration_closed', $e->getMessage(), [], 409);
        } catch (DomainException $e) {
            return ApiResponse::error('registration_refused', $e->getMessage(), [], 422);
        } catch (QueryException $e) {
            return ApiResponse::error('duplicate_detected', 'A duplicate team or player was detected. Registration was not saved.', [], 409);
        }

        $team = $result['team'];
        $waitlisted = $result['waitlisted'];

        return ApiResponse::created([
            'team' => new TeamResource($team->load('members')),
            'waitlisted' => $waitlisted,
            'waitlist_position' => $waitlisted ? $team->waitlistPosition() : null,
            'next_step' => $waitlisted ? 'waitlist' : 'payment',
        ]);
    }

    /**
     * POST /api/v1/tournaments/{tournament}/check-in
     */
    public function checkIn(Request $request, Tournament $tournament): JsonResponse
    {
        $data = $request->validate([
            'team_id' => 'required|integer|exists:teams,id',
        ]);

        $team = \App\Models\Team::find($data['team_id']);

        if ($team === null || ! $team->belongsToTournament($tournament)) {
            return ApiResponse::error('not_found', 'Team not found in this tournament.', [], 404);
        }

        $this->authorize('checkIn', $team);

        // Phase 10 — fraud/risk gate for check-in.
        try {
            $this->risk->gate($request->user(), 'checkin', $tournament);
        } catch (DomainException $e) {
            return ApiResponse::error('checkin_refused', $e->getMessage(), [], 422);
        }

        try {
            $result = $this->participation->checkIn($tournament, $team, $request->user(), $request->user()->isAdmin());
        } catch (DomainException $e) {
            return ApiResponse::error('checkin_refused', $e->getMessage(), [], 422);
        }

        $this->audit->recordQuietly($request->user(), 'team.checked_in', 'team', $team->id, [
            'tournament_id' => $tournament->id,
        ]);

        return ApiResponse::data([
            'status' => $result === 'already' ? 'already_checked_in' : 'checked_in',
            'team' => new TeamResource($team->fresh()),
        ]);
    }

    /**
     * GET /api/v1/tournaments/{tournament}/waitlist — positions only; promotion
     * is server/admin controlled, never client-submitted.
     */
    public function waitlist(Request $request, Tournament $tournament): JsonResponse
    {
        if (! in_array($tournament->status, Tournament::PUBLIC_STATUSES, true)) {
            return ApiResponse::error('not_found', 'Tournament not found.', [], 404);
        }

        $waitlisted = $tournament->waitlistedTeams()
            ->with('captain')
            ->orderBy('waitlisted_at')
            ->orderBy('id')
            ->get();

        $rows = $waitlisted->map(fn ($team) => [
            'team_id' => $team->id,
            'name' => $team->name,
            'waitlist_position' => $team->waitlistPosition(),
            'waitlisted_at' => $team->waitlisted_at?->toISOString(),
        ]);

        return ApiResponse::data(['teams' => $rows, 'count' => $rows->count()]);
    }

    /**
     * GET /api/v1/tournaments/{tournament}/live?since=N — visibility-gated
     * cursor feed (Phase 12).
     */
    public function live(Request $request, Tournament $tournament): JsonResponse
    {
        if (! in_array($tournament->status, Tournament::PUBLIC_STATUSES, true)) {
            return ApiResponse::error('not_found', 'Tournament not found.', [], 404);
        }

        $since = (int) $request->query('since', 0);
        $limit = min(100, max(1, (int) $request->query('limit', 50)));

        $events = $this->live->since($since, $tournament, $request->user(), $limit);

        return ApiResponse::data([
            'events' => LiveEventResource::collection($events),
            'latest_cursor' => $this->live->latestCursor(),
        ]);
    }

    protected function perPage(Request $request): int
    {
        $max = (int) config('api.pagination.max_per_page', 100);
        $default = (int) config('api.pagination.default_per_page', 15);

        $perPage = (int) $request->query('per_page', $default);

        return min($max, max(1, $perPage));
    }
}
