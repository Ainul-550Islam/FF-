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

        // Rows are stdClass in practice; array rows are accepted too so the
        // resource never breaks on an alternate standings driver.
        $value = fn (string $key, $default = null) => is_array($row)
            ? ($row[$key] ?? $default)
            : ($row->{$key} ?? $default);

        $team = $value('team');

        return [
            'rank' => $value('rank'),
            'team_id' => $value('team_id'),
            'team_name' => $team?->name ?? $value('team_name'),
            'matches_played' => (int) $value('matches_played', 0),
            'kills' => (int) $value('kills', 0),
            'placement_points' => (int) $value('placement_points', 0),
            'kill_points' => (int) $value('kill_points', 0),
            'points' => (int) $value('points', 0),
            'best_placement' => $value('best_placement'),
        ];
    }
}
