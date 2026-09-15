<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A payout. Exposes rank/status/amount only; no payment rails, provider
 * references, or processing internals.
 */
class PayoutResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tournament_id' => $this->tournament_id,
            'team_id' => $this->recipient_team_id,
            'rank' => (int) $this->rank,
            'amount_minor' => (int) $this->amount_minor,
            'currency' => $this->currency,
            'method' => $this->payout_method,
            'status' => $this->status,
            'processed_at' => $this->processed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
