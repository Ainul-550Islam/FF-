<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A support ticket (Phase 13).
 *
 * Server-controlled state machine: every field is excluded from mass
 * assignment and status/assignee changes go through SupportTicketService,
 * which validates the transition map. Internal staff notes live in a
 * separate table and are never visible to the requester.
 */
class SupportTicket extends Model
{
    use HasFactory;

    public const STATUS_OPEN = 'open';
    public const STATUS_PENDING = 'pending';
    public const STATUS_WAITING_ON_USER = 'waiting_on_user';
    public const STATUS_WAITING_ON_STAFF = 'waiting_on_staff';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_PENDING,
        self::STATUS_WAITING_ON_USER,
        self::STATUS_WAITING_ON_STAFF,
        self::STATUS_RESOLVED,
        self::STATUS_CLOSED,
    ];

    /**
     * Controlled status transitions. `resolved` and `closed` are terminal
     * except for reopen (back to `open`).
     */
    public const TRANSITIONS = [
        self::STATUS_OPEN => [
            self::STATUS_PENDING,
            self::STATUS_WAITING_ON_USER,
            self::STATUS_WAITING_ON_STAFF,
            self::STATUS_RESOLVED,
            self::STATUS_CLOSED,
        ],
        self::STATUS_PENDING => [
            self::STATUS_OPEN,
            self::STATUS_WAITING_ON_USER,
            self::STATUS_WAITING_ON_STAFF,
            self::STATUS_RESOLVED,
            self::STATUS_CLOSED,
        ],
        self::STATUS_WAITING_ON_USER => [
            self::STATUS_WAITING_ON_STAFF,
            self::STATUS_RESOLVED,
            self::STATUS_CLOSED,
        ],
        self::STATUS_WAITING_ON_STAFF => [
            self::STATUS_WAITING_ON_USER,
            self::STATUS_RESOLVED,
            self::STATUS_CLOSED,
        ],
        self::STATUS_RESOLVED => [self::STATUS_CLOSED, self::STATUS_OPEN],
        self::STATUS_CLOSED => [self::STATUS_OPEN],
    ];

    public const CATEGORIES = ['general', 'payment', 'payout', 'dispute', 'account', 'technical', 'other'];

    public const PRIORITY_LOW = 'low';
    public const PRIORITY_NORMAL = 'normal';
    public const PRIORITY_HIGH = 'high';
    public const PRIORITY_URGENT = 'urgent';

    public const PRIORITIES = [
        self::PRIORITY_LOW,
        self::PRIORITY_NORMAL,
        self::PRIORITY_HIGH,
        self::PRIORITY_URGENT,
    ];

    /** Statuses that are still actionable (not resolved/closed). */
    public const OPEN_STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_PENDING,
        self::STATUS_WAITING_ON_USER,
        self::STATUS_WAITING_ON_STAFF,
    ];

    protected $fillable = [];

    protected $casts = [
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
        'last_activity_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function match()
    {
        return $this->belongsTo(GameMatch::class, 'match_id');
    }

    public function messages()
    {
        return $this->hasMany(SupportMessage::class, 'ticket_id')->orderBy('id');
    }

    public function internalNotes()
    {
        return $this->hasMany(SupportInternalNote::class, 'ticket_id')->orderBy('id');
    }

    public function canTransitionTo(string $target): bool
    {
        return in_array($target, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function isResolved(): bool
    {
        return $this->status === self::STATUS_RESOLVED;
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'Pending',
            self::STATUS_WAITING_ON_USER => 'Waiting on user',
            self::STATUS_WAITING_ON_STAFF => 'Waiting on staff',
            self::STATUS_RESOLVED => 'Resolved',
            self::STATUS_CLOSED => 'Closed',
            default => 'Open',
        };
    }

    public function statusPill(): string
    {
        return match ($this->status) {
            self::STATUS_RESOLVED => 'confirmed',
            self::STATUS_CLOSED => 'cancelled',
            self::STATUS_WAITING_ON_USER => 'waitlisted',
            self::STATUS_WAITING_ON_STAFF => 'ready',
            self::STATUS_PENDING => 'pending',
            default => 'open',
        };
    }

    public function categoryLabel(): string
    {
        return ucfirst($this->category);
    }
}
