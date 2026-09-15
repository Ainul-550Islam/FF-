<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An outbound webhook delivery record. The payload is intentionally NOT
 * serialized (it may contain business data); only status/diagnostics.
 */
class WebhookDeliveryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event,
            'delivery_id' => $this->delivery_id,
            'status' => $this->status,
            'attempts' => (int) $this->attempts,
            'next_retry_at' => $this->next_retry_at?->toISOString(),
            'last_status_code' => $this->last_status_code,
            'last_error' => $this->last_error,
            'delivered_at' => $this->delivered_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
