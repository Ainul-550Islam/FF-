<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A marketing affiliate account (Phase 21).
 *
 * The affiliate's code is the attribution identity: /r/{code} clicks land as
 * utm_campaign=code touches in marketing_attributions and referrals are
 * credited server-side when the visitor registers. Distinct from the
 * Gameberry user-to-user Referral wallet-bonus program.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $code
 * @property string|null $name
 * @property string $status
 * @property string|null $landing_url
 * @property array|null $metadata
 * @property Carbon|null $approved_at
 * @property Carbon|null $suspended_at
 * @property Carbon|null $last_conversion_at
 */
class MarketingAffiliate extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACTIVE,
        self::STATUS_SUSPENDED,
    ];

    protected $fillable = [
        'user_id', 'code', 'name', 'status', 'landing_url', 'metadata',
        'approved_at', 'suspended_at', 'last_conversion_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'approved_at' => 'datetime',
        'suspended_at' => 'datetime',
        'last_conversion_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function referrals()
    {
        return $this->hasMany(MarketingAffiliateReferral::class, 'affiliate_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
