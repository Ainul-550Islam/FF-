<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 18/19 — a registered mobile push device.
 *
 * The raw push token is never exposed: it is stored only encrypted at rest
 * (Laravel `encrypted` cast, decrypted transparently for server-side push
 * delivery) and identified by its SHA-256 hash. Rows are owned by exactly
 * one user and are only ever readable by that user. No serialized response
 * includes the token, its hash or the encrypted ciphertext.
 */
class MobileDevice extends Model
{
    protected $table = 'mobile_device_tokens';

    public const PLATFORMS = ['android', 'ios'];

    public const PROVIDERS = ['fcm', 'apns'];

    public const ENVIRONMENTS = ['development', 'staging', 'production'];

    protected $fillable = [
        'user_id',
        'platform',
        'provider',
        'token_hash',
        'encrypted_token',
        'device_label',
        'app_version',
        'environment',
        'is_active',
        'last_seen_at',
    ];

    protected $hidden = ['encrypted_token', 'token_hash'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_seen_at' => 'datetime',
            'encrypted_token' => 'encrypted',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * One-way fingerprint for a raw push token. Tokens are compared and
     * deduplicated by this value only.
     */
    public static function hashToken(string $raw): string
    {
        return hash('sha256', $raw);
    }
}
