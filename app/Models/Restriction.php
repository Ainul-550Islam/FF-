<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A granular, auditable account restriction (Phase 10).
 *
 * Restrictions are specific (a type, a reason, an actor, an optional
 * expiry) rather than a single global "ban" flag, and are always revocable.
 * The enforcement of each type is performed by RestrictionService / the
 * FraudRiskService gates — never by trusting the client.
 */
class Restriction extends Model
{
    use HasFactory;

    public const TYPE_REGISTRATION_BLOCKED = 'registration_blocked';

    public const TYPE_CHECKIN_BLOCKED = 'checkin_blocked';

    public const TYPE_SCORE_SUBMISSION_BLOCKED = 'score_submission_blocked';

    public const TYPE_DISPUTE_BLOCKED = 'dispute_blocked';

    public const TYPE_PAYOUT_REVIEW = 'payout_review';

    public const TYPE_TOURNAMENT_PARTICIPATION_BLOCKED = 'tournament_participation_blocked';

    public const TYPE_ACCOUNT_SUSPENDED = 'account_suspended';

    public const TYPES = [
        self::TYPE_REGISTRATION_BLOCKED,
        self::TYPE_CHECKIN_BLOCKED,
        self::TYPE_SCORE_SUBMISSION_BLOCKED,
        self::TYPE_DISPUTE_BLOCKED,
        self::TYPE_PAYOUT_REVIEW,
        self::TYPE_TOURNAMENT_PARTICIPATION_BLOCKED,
        self::TYPE_ACCOUNT_SUSPENDED,
    ];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_LIFTED = 'lifted';

    protected $fillable = [];

    protected $casts = [
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'lifted_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function liftedBy()
    {
        return $this->belongsTo(User::class, 'lifted_by');
    }

    public function isActive(): bool
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }

        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    public function typeLabel(): string
    {
        return ucwords(str_replace('_', ' ', $this->type));
    }
}
