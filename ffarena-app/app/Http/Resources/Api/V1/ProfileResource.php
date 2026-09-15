<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A privacy-honoring player profile. Backed by ProfileService::publicProfile
 * so private/registered profiles never leak fields merely because the caller
 * knows the user id.
 */
class ProfileResource extends JsonResource
{
    /**
     * @param  array<string, mixed>  $resource  ProfileService::publicProfile output
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
