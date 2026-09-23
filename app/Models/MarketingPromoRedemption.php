<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A promo code redemption (Phase 21).
 *
 * The idempotency ledger: unique (promo_code_id, user_id) means a repeat
 * apply returns the same row instead of double-counting usage. The stored
 * discount is the server-computed minor-unit amount; it never feeds the
 * payment state machine — settlement stays authoritative.
 *
 * @property int $id
 * @property int $promo_code_id
 * @property int $user_id
 * @property int|null $tournament_id
 * @property int $discount_minor
 * @property array|null $metadata
 */
class MarketingPromoRedemption extends Model
{
    use HasFactory;

    protected $fillable = [
        'promo_code_id', 'user_id', 'tournament_id', 'discount_minor', 'metadata',
    ];

    protected $casts = [
        'discount_minor' => 'integer',
        'metadata' => 'array',
    ];

    public function promoCode()
    {
        return $this->belongsTo(MarketingPromoCode::class, 'promo_code_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
