<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A user-owned API application (Phase 15).
 *
 * Personal access tokens are issued against a client and linked through
 * personal_access_tokens.api_client_id, so an admin (or the owner) can
 * revoke an entire application's tokens in one step. Plaintext secrets are
 * never stored — only Sanctum's SHA-256 token hashes.
 */
class ApiClient extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [];

    protected $casts = [
        'last_used_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function tokens()
    {
        return $this->hasMany(PersonalAccessToken::class, 'api_client_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
