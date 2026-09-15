<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * The Sanctum personal access token, with the Phase 15 `api_client_id` link.
 *
 * The table is Sanctum's own (hashed tokens, abilities, expiration, last-use
 * tracking); the only addition is the owning API client for grouped
 * revocation and admin management.
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    /**
     * Mirrors Sanctum's own fillable list. `api_client_id` is intentionally
     * excluded and assigned server-side only.
     */
    protected $fillable = [
        'name',
        'token',
        'abilities',
        'expires_at',
    ];

    public function client()
    {
        return $this->belongsTo(ApiClient::class, 'api_client_id');
    }
}
