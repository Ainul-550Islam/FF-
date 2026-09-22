<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A prize payout record for one awarded rank (Phase 09).
 *
 * The recipient (user/team), rank and amount are ALWAYS derived server-side
 * from the prize snapshot and final standings — never from client input.
 *
 * Controlled state machine:
 *
 *   pending    → approved, cancelled
 *   approved   → processing, cancelled
 *   processing → completed, failed
 *   completed  → (terminal)
 *   failed     → (terminal)
 *   cancelled  → (terminal)
 *
 * Internal wallet payouts credit the recipient's wallet through the
 * Phase 08 WalletService (with a matching ledger entry) inside the same
 * transaction that marks the payout completed, so a payout can never be
 * "completed but not credited" or vice versa. A payout is never processed
 * twice (unique idempotency key + unique (distribution, rank)).
 */
class Payout extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public const TRANSITIONS = [
        self::STATUS_PENDING => [self::STATUS_APPROVED, self::STATUS_CANCELLED],
        self::STATUS_APPROVED => [self::STATUS_PROCESSING, self::STATUS_CANCELLED],
        self::STATUS_PROCESSING => [self::STATUS_COMPLETED, self::STATUS_FAILED],
        self::STATUS_COMPLETED => [],
        self::STATUS_FAILED => [],
        self::STATUS_CANCELLED => [],
    ];

    /**
     * Payout methods. `wallet` credits the recipient's internal wallet;
     * `manual` is a manually processed external payout (never faked).
     */
    public const METHOD_WALLET = 'wallet';
    public const METHOD_MANUAL = 'manual';

    protected $fillable = [];

    protected $casts = [
        'rank' => 'integer',
        'amount_minor' => 'integer',
        'processed_at' => 'datetime',
    ];

    public function distribution()
    {
        return $this->belongsTo(PrizeDistribution::class, 'distribution_id');
    }

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    public function recipient()
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function team()
    {
        return $this->belongsTo(Team::class, 'recipient_team_id');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function processedBy()
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function events()
    {
        return $this->hasMany(PayoutEvent::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    public function canTransitionTo(string $target): bool
    {
        return in_array($target, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED], true);
    }

    public function isInternal(): bool
    {
        return $this->provider === self::METHOD_WALLET;
    }

    public function amountMinor(): int
    {
        return (int) $this->amount_minor;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_APPROVED => 'Approved',
            self::STATUS_PROCESSING => 'Processing',
            self::STATUS_COMPLETED => 'Completed',
            self::STATUS_FAILED => 'Failed',
            self::STATUS_CANCELLED => 'Cancelled',
            default => 'Pending',
        };
    }

    public function statusPill(): string
    {
        return match ($this->status) {
            self::STATUS_COMPLETED => 'confirmed',
            self::STATUS_FAILED => 'failed',
            self::STATUS_CANCELLED => 'cancelled',
            self::STATUS_APPROVED, self::STATUS_PROCESSING => 'live',
            default => 'pending',
        };
    }
}
