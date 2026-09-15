<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A user's identity-verification state (Phase 10).
 *
 * Controlled state machine:
 *
 *   unverified → pending → verified / rejected / review_required
 *   verified   → expired (when expires_at passes)
 *   rejected / review_required → pending (re-request) / verified (review)
 *
 * The application never fabricates provider verification: `verified` is only
 * ever reached through an explicit admin manual review (provider = manual) or,
 * in the future, a real provider adapter. All fields are server-controlled.
 */
class IdentityVerification extends Model
{
    use HasFactory;

    public const STATUS_UNVERIFIED = 'unverified';
    public const STATUS_PENDING = 'pending';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_REVIEW_REQUIRED = 'review_required';

    public const STATUSES = [
        self::STATUS_UNVERIFIED,
        self::STATUS_PENDING,
        self::STATUS_VERIFIED,
        self::STATUS_REJECTED,
        self::STATUS_EXPIRED,
        self::STATUS_REVIEW_REQUIRED,
    ];

    protected $fillable = [];

    protected $casts = [
        'verified_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function reviewedBy()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isVerified(): bool
    {
        return $this->status === self::STATUS_VERIFIED;
    }

    public function statusLabel(): string
    {
        return ucwords(str_replace('_', ' ', $this->status));
    }

    public function statusPill(): string
    {
        return match ($this->status) {
            self::STATUS_VERIFIED => 'confirmed',
            self::STATUS_REJECTED, self::STATUS_EXPIRED => 'failed',
            self::STATUS_REVIEW_REQUIRED => 'disputed',
            self::STATUS_PENDING => 'pending',
            default => 'draft',
        };
    }
}
