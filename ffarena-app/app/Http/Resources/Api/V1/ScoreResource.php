<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * A score entry. All points are server-computed; the client's raw inputs are
 * kills + placement only. The screenshot URL is exposed only when present.
 */
class ScoreResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'match_id' => $this->match_id,
            'team_id' => $this->team_id,
            'team_name' => $this->whenLoaded('team', fn () => $this->team?->name),
            'kills' => (int) $this->kills,
            'placement' => (int) $this->placement,
            'placement_points' => (int) $this->placement_points,
            'kill_points' => (int) $this->kill_points,
            'bonus_points' => (int) $this->bonus_points,
            'penalty_points' => (int) $this->penalty_points,
            'points' => (int) $this->points,
            'status' => $this->status,
            'screenshot_url' => $this->when(
                ! empty($this->screenshot_path),
                fn () => Storage::disk('public')->url($this->screenshot_path)
            ),
            'submitted_at' => $this->created_at?->toISOString(),
        ];
    }
}
