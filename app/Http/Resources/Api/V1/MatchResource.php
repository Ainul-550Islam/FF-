<?php

namespace App\Http\Resources\Api\V1;

use App\Models\GameMatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A match. The room id/pass are only exposed while the match is ready or
 * live, and only to participants, the organizer, or staff — never leaked to
 * the general public.
 */
class MatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();

        $isCaptainOfAParticipant = $viewer !== null && (
            ($this->team1 !== null && $this->team1->captain_id === $viewer->id)
            || ($this->team2 !== null && $this->team2->captain_id === $viewer->id)
        );

        $canSeeRoom = $viewer !== null && in_array($this->status, [
            GameMatch::STATUS_READY,
            GameMatch::STATUS_LIVE,
        ], true) && (
            $viewer->isStaff()
            || $this->tournament?->organizer_id === $viewer->id
            || $isCaptainOfAParticipant
        );

        return [
            'id' => $this->id,
            'tournament_id' => $this->tournament_id,
            'round' => (int) $this->round,
            'match_no' => (int) $this->match_no,
            'bracket' => $this->bracket,
            'status' => $this->status,
            'scheduled_at' => $this->scheduled_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'room_id' => $this->when($canSeeRoom, $this->room_id),
            'room_pass' => $this->when($canSeeRoom, $this->room_pass),
            'team1' => $this->whenLoaded('team1', fn () => $this->team1 ? [
                'id' => $this->team1->id,
                'name' => $this->team1->name,
            ] : null),
            'team2' => $this->whenLoaded('team2', fn () => $this->team2 ? [
                'id' => $this->team2->id,
                'name' => $this->team2->name,
            ] : null),
            'winner' => $this->whenLoaded('winner', fn () => $this->winner ? [
                'id' => $this->winner->id,
                'name' => $this->winner->name,
            ] : null),
            'scores' => ScoreResource::collection($this->whenLoaded('scores')),
        ];
    }
}
