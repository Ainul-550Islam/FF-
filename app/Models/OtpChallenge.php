<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A one-time phone verification challenge (Phase 14).
 *
 * Only a hash of the code is stored; the raw code exists solely in transit.
 * A challenge is single-use: it expires, caps verification attempts, and is
 * marked consumed once used, so replay and brute force are both contained.
 */
class OtpChallenge extends Model
{
    use HasFactory;

    public const PURPOSE_LOGIN = 'login';
    public const PURPOSE_SIGNUP = 'signup';
    public const PURPOSE_LINK = 'link';
    public const PURPOSE_RECOVERY = 'recovery';

    public const PURPOSES = [
        self::PURPOSE_LOGIN,
        self::PURPOSE_SIGNUP,
        self::PURPOSE_LINK,
        self::PURPOSE_RECOVERY,
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CONSUMED = 'consumed';

    protected $fillable = [];

    protected $casts = [
        'expires_at' => 'datetime',
        'verified_at' => 'datetime',
        'attempts' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at === null || $this->expires_at->isPast();
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING && ! $this->isExpired();
    }
}
