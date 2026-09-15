<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One leaderboard row produced by ScoringService::standings — the single
 * ranking source of truth (Phase 12).
 */
class LeaderboardEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // ScoringService::standings() emits stdClass rows (shared with the web
        // leaderboard and prize distribution), so this resource reads object
        // properties, not array keys.
        $row = $this->resource;

        return [
            'rank' => $row->rank ?? null,
            'team_id' => $row->team_id ?? null,
            'team_name' => $row->team?->name,
            'matches_played' => (int) ($row->matches_played ?? 0),
            'kills' => (int) ($row->kills ?? 0),
            'placement_points' => (int) ($row->placement_points ?? 0),
            'kill_points' => (int) ($row->kill_points ?? 0),
            'points' => (int) ($row->points ?? 0),
            'best_placement' => $row->best_placement ?? null,
        ];
    }
}
