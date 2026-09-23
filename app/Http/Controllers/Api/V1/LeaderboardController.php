<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\LeaderboardEntryResource;
use App\Models\Tournament;
use App\Models\User;
use App\Services\ScoringService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 15 — leaderboards: the list of ranked tournaments, a tournament's
 * standings, and a player's ranks. Every ranking comes from
 * ScoringService::standings (Phase 12) — no duplicated ranking math.
 */
class LeaderboardController extends Controller
{
    public function __construct(
        protected ScoringService $scoring,
    ) {}

    /**
     * GET /api/v1/leaderboards — tournaments that have standings.
     */
    public function index(Request $request): JsonResponse
    {
        $tournaments = Tournament::query()
            ->whereIn('status', Tournament::PUBLIC_STATUSES)
            ->whereHas('matches.scores')
            ->withCount(['matches as completed_matches' => fn ($q) => $q->whereIn('status', ['completed', 'disputed'])])
            ->orderByDesc('starts_at')
            ->paginate((int) config('api.pagination.default_per_page', 15));

        $rows = $tournaments->map(fn ($t) => [
            'id' => $t->id,
            'slug' => $t->slug,
            'name' => $t->name,
            'game_mode' => $t->game_mode,
            'status' => $t->status,
            'starts_at' => $t->starts_at?->toISOString(),
            'completed_matches' => (int) $t->completed_matches,
        ]);

        return ApiResponse::data($rows, [
            'pagination' => [
                'current_page' => $tournaments->currentPage(),
                'last_page' => $tournaments->lastPage(),
                'per_page' => $tournaments->perPage(),
                'total' => $tournaments->total(),
            ],
        ]);
    }

    /**
     * GET /api/v1/leaderboards/{tournament}
     */
    public function show(Request $request, Tournament $tournament): JsonResponse
    {
        if (! in_array($tournament->status, Tournament::PUBLIC_STATUSES, true)) {
            return ApiResponse::error('not_found', 'Tournament not found.', [], 404);
        }

        // ScoringService::standings() is the single ranking source of truth
        // and already returns ranked stdClass rows (team relation loaded), so
        // they are passed through untouched — the row shape is shared with the
        // web leaderboard and prize distribution.
        $rows = $this->scoring->standings($tournament);

        return ApiResponse::data(
            LeaderboardEntryResource::collection($rows),
            ['tie_breakers' => $this->scoring->currentRuleSet($tournament)->tieBreakers()]
        );
    }

    /**
     * GET /api/v1/players/{user}/ranking
     */
    public function playerRanking(Request $request, User $user): JsonResponse
    {
        $teams = $user->teams()->with('tournament')->get();

        $ranks = [];

        foreach ($teams as $team) {
            $tournament = $team->tournament;

            if ($tournament === null || ! in_array($tournament->status, Tournament::PUBLIC_STATUSES, true)) {
                continue;
            }

            $standings = $this->scoring->standings($tournament);

            foreach ($standings->values() as $index => $row) {
                // Standings rows are stdClass (ScoringService); read them
                // defensively so an array-shaped row still works.
                $rowTeamId = (int) (is_array($row) ? ($row['team_id'] ?? 0) : ($row->team_id ?? 0));

                if ($rowTeamId === $team->id) {
                    $ranks[] = [
                        'tournament_id' => $tournament->id,
                        'tournament_name' => $tournament->name,
                        'team_id' => $team->id,
                        'team_name' => $team->name,
                        'rank' => (int) (is_array($row) ? ($row['rank'] ?? $index + 1) : ($row->rank ?? $index + 1)),
                        'points' => (int) (is_array($row) ? ($row['points'] ?? 0) : ($row->points ?? 0)),
                        'matches_played' => (int) (is_array($row) ? ($row['matches_played'] ?? 0) : ($row->matches_played ?? 0)),
                        'kills' => (int) (is_array($row) ? ($row['kills'] ?? 0) : ($row->kills ?? 0)),
                    ];

                    break;
                }
            }
        }

        return ApiResponse::data(['rankings' => $ranks]);
    }
}
