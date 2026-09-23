<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A provider-linked identity on a user's account (Phase 14).
 *
 * Only non-password providers are stored here (google, phone); the password
 * itself is the users.password column. `(provider, provider_subject)` is
 * unique so one Google subject or one normalized phone can only ever be
 * linked to a single local account — the key duplicate-account guard.
 *
 * No OAuth access/refresh tokens are stored: simple sign-in does not need
 * them, and persisting them would be an unnecessary secret to protect.
 */
class UserIdentity extends Model
{
    use HasFactory;

    public const PROVIDER_GOOGLE = 'google';

    public const PROVIDER_PHONE = 'phone';

    public const PROVIDERS = [
        self::PROVIDER_GOOGLE,
        self::PROVIDER_PHONE,
    ];

    protected $fillable = [];

    protected $casts = [
        'verified_at' => 'datetime',
        'last_used_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    public function touchLastUsed(): self
    {
        $this->last_used_at = now();
        $this->save();

        return $this;
    }
}
