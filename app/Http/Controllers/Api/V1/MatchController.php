<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\MatchResource;
use App\Http\Resources\Api\V1\ScoreResource;
use App\Models\GameMatch;
use App\Models\Score;
use App\Models\ScoringRule;
use App\Models\Team;
use App\Services\FraudRiskService;
use App\Services\ScoringService;
use App\Support\ApiResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 15 — match reads and score submission.
 *
 * Match-state mutation is server-side only; the only client mutation is
 * submitting raw kills/placement for their own participating team, which the
 * scoring engine turns into points.
 */
class MatchController extends Controller
{
    public function __construct(
        protected ScoringService $scoring,
        protected FraudRiskService $risk,
    ) {
    }

    /**
     * GET /api/v1/matches/{match}
     */
    public function show(Request $request, GameMatch $match): JsonResponse
    {
        if (! $this->matchIsPublic($match)) {
            return ApiResponse::error('not_found', 'Match not found.', [], 404);
        }

        $match->load(['team1', 'team2', 'winner', 'scores.team']);

        return ApiResponse::data(new MatchResource($match));
    }

    /**
     * POST /api/v1/matches/{match}/scores
     */
    public function submitScore(Request $request, GameMatch $match): JsonResponse
    {
        if (! $this->matchIsPublic($match)) {
            return ApiResponse::error('not_found', 'Match not found.', [], 404);
        }

        $data = $request->validate([
            'team_id' => 'required|integer|exists:teams,id',
            'kills' => 'required|integer|min:0',
            'placement' => 'required|integer|min:1|max:' . ScoringRule::MAX_PLACEMENT,
            // Authoritative totals and match state can never be supplied by a
            // client — they are computed server-side by the scoring engine.
            'points' => 'prohibited',
            'status' => 'prohibited',
            'placement_points' => 'prohibited',
            'kill_points' => 'prohibited',
        ]);

        $team = Team::find($data['team_id']);

        if ($team === null || ! $match->hasParticipant($team)) {
            return ApiResponse::error('forbidden', 'This team is not part of this match.', [], 403);
        }

        $this->authorize('submitScore', $team);

        // Phase 10 — fraud/risk gate for score submission.
        try {
            $this->risk->gate($request->user(), 'score_submission', $match->tournament);
        } catch (DomainException $e) {
            return ApiResponse::error('score_refused', $e->getMessage(), [], 422);
        }

        // Scoring is only possible while the match is ready or live.
        if (! $match->acceptsScoreSubmission()) {
            return ApiResponse::error('score_refused', 'Score submission is not open for this match.', [], 409);
        }

        // Duplicate + placement-claim guards (the scoring engine re-checks
        // these inside its transaction too).
        if (Score::where('match_id', $match->id)->where('team_id', $team->id)->exists()) {
            return ApiResponse::error('duplicate_score', 'A score for this team has already been submitted.', [], 409);
        }

        if (Score::where('match_id', $match->id)->where('placement', (int) $data['placement'])->exists()) {
            return ApiResponse::error('placement_taken', 'Another team in this match has already claimed that placement.', [], 409);
        }

        try {
            // The server computes every point — the client's values are only
            // raw inputs (kills + placement).
            $score = $this->scoring->submitScore(
                $match,
                $team,
                (int) $data['kills'],
                (int) $data['placement'],
            );
        } catch (DomainException $e) {
            return ApiResponse::error('score_refused', $e->getMessage(), [], 422);
        }

        return ApiResponse::created(new ScoreResource($score->load('team')));
    }

    /**
     * A match is publicly readable only once its tournament is public.
     */
    protected function matchIsPublic(GameMatch $match): bool
    {
        $tournament = $match->tournament()->first();

        return $tournament !== null
            && in_array($tournament->status, \App\Models\Tournament::PUBLIC_STATUSES, true);
    }
}
