<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An outbound webhook endpoint. The signing secret is never returned —
 * it is shown exactly once at creation (and rotated only by admins).
 */
class WebhookEndpointResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url,
            'description' => $this->description,
            'status' => $this->status,
            'events' => $this->events ?? [],
            'consecutive_failures' => (int) $this->consecutive_failures,
            'last_success_at' => $this->last_success_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
