<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A contest of a match result, opened by a participant or staff member and
 * processed through a controlled moderation state machine.
 *
 * All fields are server-controlled — nothing is mass-assignable.
 */
class Dispute extends Model
{
    use HasFactory;

    public const STATUS_OPEN = 'open';

    public const STATUS_UNDER_REVIEW = 'under_review';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_UNDER_REVIEW,
        self::STATUS_RESOLVED,
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
    ];

    /**
     * Valid dispute state transitions. `resolved`, `rejected` and `cancelled`
     * are terminal.
     *
     * open         → under_review, resolved, rejected, cancelled
     * under_review → resolved, rejected, cancelled
     */
    public const TRANSITIONS = [
        self::STATUS_OPEN => [self::STATUS_UNDER_REVIEW, self::STATUS_RESOLVED, self::STATUS_REJECTED, self::STATUS_CANCELLED],
        self::STATUS_UNDER_REVIEW => [self::STATUS_RESOLVED, self::STATUS_REJECTED, self::STATUS_CANCELLED],
        self::STATUS_RESOLVED => [],
        self::STATUS_REJECTED => [],
        self::STATUS_CANCELLED => [],
    ];

    /**
     * The categories a participant/staff member may pick when opening a
     * dispute. Used by the form and validated server-side.
     */
    public const CATEGORIES = [
        'wrong_winner',
        'wrong_score',
        'rule_violation',
        'technical_issue',
        'other',
    ];

    /**
     * Statuses that are still actionable (evidence may be added, staff may
     * act). Once resolved/rejected/cancelled a dispute is frozen.
     */
    public const ACTIONABLE_STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_UNDER_REVIEW,
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

    public function opener()
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function resolutionWinner()
    {
        return $this->belongsTo(Team::class, 'resolution_winner_team_id');
    }

    public function evidence()
    {
        return $this->hasMany(DisputeEvidence::class);
    }

    public function events()
    {
        return $this->hasMany(ModerationEvent::class)->orderBy('created_at')->orderBy('id');
    }

    public function canTransitionTo(string $target): bool
    {
        return in_array($target, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function isActionable(): bool
    {
        return in_array($this->status, self::ACTIONABLE_STATUSES, true);
    }

    public function isTerminal(): bool
    {
        return ! $this->isActionable();
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_UNDER_REVIEW => 'Under Review',
            self::STATUS_RESOLVED => 'Resolved',
            self::STATUS_REJECTED => 'Rejected',
            self::STATUS_CANCELLED => 'Cancelled',
            default => 'Open',
        };
    }

    public function categoryLabel(): string
    {
        return ucwords(str_replace('_', ' ', $this->category));
    }

    /**
     * Status pill class name reusing the shared layout palette.
     */
    public function statusPill(): string
    {
        return match ($this->status) {
            self::STATUS_RESOLVED => 'confirmed',
            self::STATUS_REJECTED => 'finished',
            self::STATUS_CANCELLED => 'cancelled',
            self::STATUS_UNDER_REVIEW => 'live',
            default => 'pending',
        };
    }
}
