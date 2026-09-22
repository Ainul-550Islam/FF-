<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A user's saved payment method (Phase 14).
 *
 * Only the provider id, a user label and a masked identifier are stored.
 * Card numbers, full phone numbers and account details never reach this
 * table. Methods belong to exactly one user; every read/write is ownership-
 * checked server-side.
 */
class PaymentMethod extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_REMOVED = 'removed';

    /**
     * Providers a user may save. Mirrors the configured gateway set.
     */
    public const PROVIDERS = ['bkash', 'nagad', 'rocket', 'card', 'bank'];

    protected $fillable = [];

    protected $casts = [
        'is_default' => 'boolean',
        'verified_at' => 'datetime',
        'last_used_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
