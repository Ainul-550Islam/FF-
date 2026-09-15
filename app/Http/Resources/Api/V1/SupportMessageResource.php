<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A support conversation message. Only the author's public identity is
 * exposed (never email/phone/IP); internal notes are never serialized.
 */
class SupportMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ticket_id' => $this->ticket_id,
            'author' => $this->whenLoaded('author', fn () => $this->author ? [
                'id' => $this->author->id,
                'name' => $this->author->name,
                'is_staff' => $this->author->isStaff(),
            ] : null),
            'body' => $this->body,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
