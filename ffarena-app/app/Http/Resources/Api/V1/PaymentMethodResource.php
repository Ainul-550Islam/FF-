<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A stored payment method. Identifiers are always masked; no raw card
 * numbers, tokens or provider secrets ever leave the server.
 */
class PaymentMethodResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'label' => $this->label,
            'identifier_masked' => $this->masked_identifier ?? null,
            'is_default' => (bool) ($this->is_default ?? false),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
