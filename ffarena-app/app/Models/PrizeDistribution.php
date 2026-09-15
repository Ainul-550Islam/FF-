<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The prize-distribution workflow for a tournament (Phase 09).
 *
 * A controlled state machine — a client can never move a distribution
 * between arbitrary states:
 *
 *   draft      → calculated, cancelled
 *   calculated → approved, cancelled, draft (recalculate)
 *   approved   → processing, cancelled
 *   processing → completed, failed
 *   completed  → (terminal)
 *   failed     → (terminal — a retry starts a fresh distribution)
 *   cancelled  → (terminal)
 *
 * The `pool_minor` and snapshot items recorded at calculation time are
 * immutable: later edits to the tournament prize pool, fees, tiers or scores
 * never rewrite an already-calculated distribution.
 */
class PrizeDistribution extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_CALCULATED = 'calculated';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public const TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_CALCULATED, self::STATUS_CANCELLED],
        self::STATUS_CALCULATED => [self::STATUS_APPROVED, self::STATUS_CANCELLED, self::STATUS_DRAFT],
        self::STATUS_APPROVED => [self::STATUS_PROCESSING, self::STATUS_CANCELLED],
        self::STATUS_PROCESSING => [self::STATUS_COMPLETED, self::STATUS_FAILED],
        self::STATUS_COMPLETED => [],
        self::STATUS_FAILED => [],
        self::STATUS_CANCELLED => [],
    ];

    /**
     * Statuses of the currently "live" (non-terminal) distribution attempt.
     */
    public const ACTIVE_STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_CALCULATED,
        self::STATUS_APPROVED,
        self::STATUS_PROCESSING,
    ];

    protected $fillable = [];

    protected $casts = [
        'pool_minor' => 'integer',
        'total_allocated_minor' => 'integer',
        'approved_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function snapshotItems()
    {
        return $this->hasMany(PrizeSnapshotItem::class, 'distribution_id')->orderBy('position');
    }

    public function payouts()
    {
        return $this->hasMany(Payout::class, 'distribution_id')->orderBy('rank');
    }

    public function canTransitionTo(string $target): bool
    {
        return in_array($target, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function isTerminal(): bool
    {
        return ! in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_CALCULATED => 'Calculated',
            self::STATUS_APPROVED => 'Approved',
            self::STATUS_PROCESSING => 'Processing',
            self::STATUS_COMPLETED => 'Completed',
            self::STATUS_FAILED => 'Failed',
            self::STATUS_CANCELLED => 'Cancelled',
            default => 'Draft',
        };
    }

    public function statusPill(): string
    {
        return match ($this->status) {
            self::STATUS_COMPLETED => 'confirmed',
            self::STATUS_FAILED => 'failed',
            self::STATUS_CANCELLED => 'cancelled',
            self::STATUS_APPROVED, self::STATUS_PROCESSING => 'live',
            self::STATUS_CALCULATED => 'ready',
            default => 'pending',
        };
    }
}
