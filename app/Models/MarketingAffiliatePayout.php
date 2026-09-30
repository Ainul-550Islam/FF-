<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An affiliate commission payout request and settlement record (Phase 22).
 *
 * Tracks the complete lifecycle of partner commission payouts. Available
 * commission is always recomputed server-side from eligible referrals.
 * Settled internal payouts atomically credit the affiliate's wallet via
 * WalletService with a matching ledger entry.
 *
 * @property int $id
 * @property int $affiliate_id
 * @property int $user_id
 * @property int $amount_minor
 * @property string $currency
 * @property string $status
 * @property string $payout_method
 * @property Carbon $requested_at
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $processed_at
 * @property string|null $rejection_reason
 * @property string|null $review_notes
 * @property array|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class MarketingAffiliatePayout extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_PROCESSING,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    public const METHOD_WALLET = 'wallet';

    public const METHOD_MANUAL = 'manual';

    public const METHODS = [
        self::METHOD_WALLET,
        self::METHOD_MANUAL,
    ];

    protected $fillable = [
        'affiliate_id',
        'user_id',
        'amount_minor',
        'currency',
        'status',
        'payout_method',
        'requested_at',
        'reviewed_by',
        'reviewed_at',
        'processed_at',
        'rejection_reason',
        'review_notes',
        'metadata',
    ];

    protected $casts = [
        'amount_minor' => 'integer',
        'requested_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'processed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function affiliate()
    {
        return $this->belongsTo(MarketingAffiliate::class, 'affiliate_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function amountBdt(): float
    {
        return round($this->amount_minor / 100, 2);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }
}
