<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A promo code definition (Phase 21).
 *
 * value is type-scoped: percentage 1-100 for TYPE_PERCENT, integer minor
 * units (poisha) for TYPE_FIXED. Eligibility (window, caps, minimum entry
 * fee) is always evaluated server-side from the tournament's own fee.
 *
 * @property int $id
 * @property string $code
 * @property string $type
 * @property int $value
 * @property string|null $description
 * @property int|null $min_entry_fee_minor
 * @property bool $active
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property int|null $max_redemptions
 * @property array|null $metadata
 */
class MarketingPromoCode extends Model
{
    use HasFactory;

    public const TYPE_PERCENT = 'percent';

    public const TYPE_FIXED = 'fixed';

    public const TYPES = [
        self::TYPE_PERCENT,
        self::TYPE_FIXED,
    ];

    protected $fillable = [
        'code', 'type', 'value', 'description', 'min_entry_fee_minor', 'active',
        'starts_at', 'ends_at', 'max_redemptions', 'metadata',
    ];

    protected $casts = [
        'active' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function redemptions()
    {
        return $this->hasMany(MarketingPromoRedemption::class, 'promo_code_id');
    }

    public function isWithinWindow(): bool
    {
        if ($this->starts_at !== null && $this->starts_at->isFuture()) {
            return false;
        }

        if ($this->ends_at !== null && $this->ends_at->isPast()) {
            return false;
        }

        return true;
    }
}
