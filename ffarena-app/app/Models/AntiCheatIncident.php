<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A defensive anti-cheat incident/case (Phase 10).
 *
 * Controlled state machine:
 *
 *   flagged → under_review → cleared | confirmed | restricted | dismissed
 *
 * A `confirmed` or `restricted` outcome applies an auditable account
 * restriction via RestrictionService; a `cleared`/`dismissed` outcome is a
 * false-positive-safe close. The actual moderation decision is always made
 * by an authorized reviewer, never by an automated rule alone.
 */
class AntiCheatIncident extends Model
{
    use HasFactory;

    public const STATUS_FLAGGED = 'flagged';
    public const STATUS_UNDER_REVIEW = 'under_review';
    public const STATUS_CLEARED = 'cleared';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_RESTRICTED = 'restricted';
    public const STATUS_DISMISSED = 'dismissed';

    public const STATUSES = [
        self::STATUS_FLAGGED,
        self::STATUS_UNDER_REVIEW,
        self::STATUS_CLEARED,
        self::STATUS_CONFIRMED,
        self::STATUS_RESTRICTED,
        self::STATUS_DISMISSED,
    ];

    public const TRANSITIONS = [
        self::STATUS_FLAGGED => [self::STATUS_UNDER_REVIEW, self::STATUS_DISMISSED],
        self::STATUS_UNDER_REVIEW => [
            self::STATUS_CLEARED,
            self::STATUS_CONFIRMED,
            self::STATUS_RESTRICTED,
            self::STATUS_DISMISSED,
        ],
        self::STATUS_CLEARED => [],
        self::STATUS_CONFIRMED => [],
        self::STATUS_RESTRICTED => [],
        self::STATUS_DISMISSED => [],
    ];

    public const SOURCE_SYSTEM = 'system';
    public const SOURCE_PARTICIPANT = 'participant';
    public const SOURCE_STAFF = 'staff';

    public const SEVERITIES = ['low', 'medium', 'high', 'critical'];

    public const RESOLUTIONS = [
        self::STATUS_CLEARED,
        self::STATUS_CONFIRMED,
        self::STATUS_RESTRICTED,
        self::STATUS_DISMISSED,
    ];

    protected $fillable = [];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    public function match()
    {
        return $this->belongsTo(GameMatch::class, 'match_id');
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function accusedUser()
    {
        return $this->belongsTo(User::class, 'accused_user_id');
    }

    public function reporter()
    {
        return $this->belongsTo(User::class, 'reporter_user_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function canTransitionTo(string $target): bool
    {
        return in_array($target, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_FLAGGED, self::STATUS_UNDER_REVIEW], true);
    }

    public function statusLabel(): string
    {
        return ucwords(str_replace('_', ' ', $this->status));
    }

    public function statusPill(): string
    {
        return match ($this->status) {
            self::STATUS_CONFIRMED, self::STATUS_RESTRICTED => 'failed',
            self::STATUS_CLEARED, self::STATUS_DISMISSED => 'confirmed',
            self::STATUS_UNDER_REVIEW => 'live',
            default => 'pending',
        };
    }
}
