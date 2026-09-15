<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An idempotency record for a critical API mutation (Phase 15).
 *
 * Stores only the SHA-256 of the client key and the SHA-256 of the canonical
 * request body, plus the stored JSON response. A replayed request within the
 * TTL returns the stored response instead of executing again.
 */
class ApiIdempotencyKey extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [];

    protected $casts = [
        'response_body' => 'array',
        'created_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at === null || $this->expires_at->isPast();
    }
}
