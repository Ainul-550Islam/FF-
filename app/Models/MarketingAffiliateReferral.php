<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An attributed affiliate referral (Phase 21).
 *
 * Created on the affiliate's first /r/{code} click for an anonymous visitor
 * and credited to exactly one user when that visitor registers — the unique
 * referred_user_id makes duplicate attribution structurally impossible.
 *
 * @property int $id
 * @property int $affiliate_id
 * @property string|null $anonymous_id
 * @property int|null $referred_user_id
 * @property int|null $attribution_id
 * @property string|null $landing_path
 * @property string $status
 * @property Carbon|null $clicked_at
 * @property Carbon|null $signed_up_at
 * @property array|null $metadata
 */
class MarketingAffiliateReferral extends Model
{
    use HasFactory;

    public const STATUS_CLICKED = 'clicked';

    public const STATUS_SIGNED_UP = 'signed_up';

    public const STATUSES = [
        self::STATUS_CLICKED,
        self::STATUS_SIGNED_UP,
    ];

    protected $fillable = [
        'affiliate_id', 'anonymous_id', 'referred_user_id', 'attribution_id',
        'landing_path', 'status', 'clicked_at', 'signed_up_at', 'metadata',
    ];

    protected $casts = [
        'clicked_at' => 'datetime',
        'signed_up_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function affiliate()
    {
        return $this->belongsTo(MarketingAffiliate::class, 'affiliate_id');
    }

    public function referredUser()
    {
        return $this->belongsTo(User::class, 'referred_user_id');
    }

    public function attribution()
    {
        return $this->belongsTo(MarketingAttribution::class, 'attribution_id');
    }

    public function isCredited(): bool
    {
        return $this->referred_user_id !== null;
    }
}
