<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A live event cursor row (Phase 12). Payloads are non-sensitive display
 * data only; visibility is gated by LiveEventService before this resource
 * ever runs.
 */
class LiveEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'tournament_id' => $this->tournament_id,
            'payload' => $this->payload ?? [],
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
