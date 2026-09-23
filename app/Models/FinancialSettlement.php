<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Immutable per-tournament financial snapshot (Phase 09).
 *
 * Written once when a prize distribution is finalized (completed). It
 * preserves gross collection, refunds, net collection, prize pool, allocated
 * prizes, completed payouts, platform revenue, adjustments, the
 * reconciliation result and the finalizing admin/timestamp.
 *
 * After finalization these values are never edited in place — corrections
 * are represented by SettlementAdjustment records, never by mutating the
 * snapshot.
 */
class FinancialSettlement extends Model
{
    use HasFactory;

    public const STATUS_BALANCED = 'balanced';

    public const STATUS_UNDERFUNDED = 'underfunded';

    public const STATUS_OVERALLOCATED = 'overallocated';

    public const STATUS_MISMATCH = 'mismatch';

    public const STATUSES = [
        self::STATUS_BALANCED,
        self::STATUS_UNDERFUNDED,
        self::STATUS_OVERALLOCATED,
        self::STATUS_MISMATCH,
    ];

    protected $fillable = [];

    protected $casts = [
        'gross_collected_minor' => 'integer',
        'refunded_minor' => 'integer',
        'net_collected_minor' => 'integer',
        'prize_pool_minor' => 'integer',
        'allocated_prizes_minor' => 'integer',
        'completed_payouts_minor' => 'integer',
        'platform_revenue_minor' => 'integer',
        'adjustments_minor' => 'integer',
        'finalized_at' => 'datetime',
    ];

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    public function finalizedBy()
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function statusLabel(): string
    {
        return ucfirst($this->reconciliation_status);
    }

    public function statusPill(): string
    {
        return match ($this->reconciliation_status) {
            self::STATUS_BALANCED => 'confirmed',
            self::STATUS_OVERALLOCATED => 'failed',
            self::STATUS_UNDERFUNDED, self::STATUS_MISMATCH => 'pending',
            default => 'draft',
        };
    }
}
