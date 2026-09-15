<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The authenticated user's own profile — richer than the public profile but
 * still scoped: no phone number, no fraud/risk state, no device/IP data.
 */
class MeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $identities = app(\App\Services\IdentityService::class);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'email' => $this->email,
            'email_verified' => $this->email_verified_at !== null,
            'avatar' => $this->avatar,
            'bio' => $this->bio,
            'country' => $this->country,
            'region' => $this->region,
            'role' => $this->role,
            'privacy' => $this->privacy ?? 'public',
            'joined_at' => $this->created_at?->toDateString(),
            'sign_in' => [
                'has_password' => $identities->hasPassword($this->resource),
                'has_google' => $identities->hasGoogle($this->resource),
                'has_phone' => $identities->hasVerifiedPhone($this->resource),
            ],
        ];
    }
}
