<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A device session summary (from SessionManagementService::sessionsFor).
 * Never exposes raw IP, user agent, or fingerprint — only the derived
 * device label and activity timestamp.
 */
class SessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource['id'] ?? null,
            'device_label' => $this->resource['device_label'] ?? 'Unknown device',
            'last_activity' => isset($this->resource['last_activity'])
                ? now()->setTimestamp((int) $this->resource['last_activity'])->toISOString()
                : null,
            'is_current' => (bool) ($this->resource['is_current'] ?? false),
        ];
    }
}
