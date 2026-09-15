<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A ledger entry. Exposes only the append-only balance history that belongs
 * to the caller — never internal reconciliation deltas or actor identity
 * details beyond the direction/amount/type.
 */
class LedgerEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'direction' => $this->direction,
            'amount_minor' => (int) $this->amount_minor,
            'balance_after_minor' => (int) $this->balance_after,
            'currency' => $this->currency,
            'type' => $this->type,
            'description' => $this->description,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
