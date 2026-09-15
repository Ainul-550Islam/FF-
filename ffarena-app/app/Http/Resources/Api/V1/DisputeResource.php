<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A dispute summary. Evidence, corrections and internal review notes are
 * deliberately excluded — only the state and resolution the participant is
 * entitled to see.
 */
class DisputeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'match_id' => $this->match_id,
            'team_id' => $this->team_id,
            'category' => $this->category,
            'status' => $this->status,
            'description' => $this->description,
            'resolution' => $this->resolution,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
