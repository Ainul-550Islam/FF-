<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An inbound webhook event record. The encrypted raw payload is never
 * serialized; only the safe metadata + state machine status.
 */
class WebhookEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'external_event_id' => $this->external_event_id,
            'event_type' => $this->event_type,
            'signature_status' => $this->signature_status,
            'status' => $this->status,
            'attempts' => (int) $this->attempts,
            'metadata' => $this->metadata ?? [],
            'received_at' => $this->received_at?->toISOString(),
            'processed_at' => $this->processed_at?->toISOString(),
        ];
    }
}
