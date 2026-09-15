<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A tournament's public fields. Financial amounts are exposed as both the
 * decimal display value and the integer minor unit; every value is
 * server-derived.
 */
class TournamentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'game_mode' => $this->game_mode,
            'map' => $this->map,
            'format' => $this->format,
            'status' => $this->status,
            'entry_fee' => $this->entry_fee,
            'entry_fee_minor' => $this->entryFeeMinor(),
            'currency' => 'BDT',
            'prize_pool' => $this->prize_pool,
            'team_slots' => $this->team_slots,
            'team_size' => $this->team_size,
            'starts_at' => $this->starts_at?->toISOString(),
            'check_in_starts_at' => $this->check_in_starts_at?->toISOString(),
            'check_in_ends_at' => $this->check_in_ends_at?->toISOString(),
            'slots_left' => $this->slotsLeft(),
            'is_full' => $this->isFull(),
            'accepts_registration' => $this->acceptsRegistration(),
            'organizer' => $this->whenLoaded('organizer', fn () => [
                'id' => $this->organizer?->id,
                'name' => $this->organizer?->name,
            ]),
            'confirmed_teams_count' => $this->when(
                $this->resource->getAttribute('confirmed_teams_count') !== null,
                (int) $this->resource->getAttribute('confirmed_teams_count')
            ),
        ];
    }
}
