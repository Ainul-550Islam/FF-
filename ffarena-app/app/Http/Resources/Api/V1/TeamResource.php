<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A team. The captain's phone is only exposed to the captain, the tournament
 * organizer and staff; everyone else sees the roster and status.
 */
class TeamResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();

        $canSeePhone = $viewer !== null && (
            $viewer->id === $this->captain_id
            || ($this->tournament_id !== null && $this->tournament?->organizer_id === $viewer->id)
            || $viewer->isStaff()
        );

        return [
            'id' => $this->id,
            'tournament_id' => $this->tournament_id,
            'name' => $this->name,
            'captain_name' => $this->captain_name,
            'phone' => $this->when($canSeePhone, $this->phone),
            'game_uid' => $this->game_uid,
            'status' => $this->status,
            'checked_in_at' => $this->checked_in_at?->toISOString(),
            'waitlisted_at' => $this->waitlisted_at?->toISOString(),
            'waitlist_position' => $this->when($this->status === 'waitlisted', fn () => $this->waitlistPosition()),
            'captain' => $this->whenLoaded('captain', fn () => $this->captain ? [
                'id' => $this->captain->id,
                'name' => $this->captain->name,
                'username' => $this->captain->username,
            ] : null),
            'members' => TeamMemberResource::collection($this->whenLoaded('members')),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
