<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A public-safe user summary. Only ever shows the caller's own (or a staff
 * member's) email; never phone, IP, device, risk or identity internals.
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'avatar' => $this->avatar,
            'role' => $this->role,
            'joined_at' => $this->created_at?->toDateString(),
            'email' => $this->when(
                $viewer !== null && ($viewer->id === $this->id || $viewer->isAdmin()),
                $this->email
            ),
        ];
    }
}
