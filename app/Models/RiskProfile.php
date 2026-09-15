<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A user's server-side fraud/risk profile (Phase 10).
 *
 * The score and level are recomputed deterministically by FraudRiskService
 * from the append-only risk_events; no client may set or alter them. Only
 * trusted services and admin actions modify these fields.
 */
class RiskProfile extends Model
{
    use HasFactory;

    public const LEVEL_LOW = 'low';
    public const LEVEL_MEDIUM = 'medium';
    public const LEVEL_HIGH = 'high';
    public const LEVEL_CRITICAL = 'critical';

    public const LEVELS = [
        self::LEVEL_LOW,
        self::LEVEL_MEDIUM,
        self::LEVEL_HIGH,
        self::LEVEL_CRITICAL,
    ];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_RESTRICTED = 'restricted';
    public const STATUS_SUSPENDED = 'suspended';

    protected $fillable = [];

    protected $casts = [
        'risk_score' => 'integer',
        'account_flags' => 'array',
        'manual_review_required' => 'boolean',
        'restricted_until' => 'datetime',
        'last_risk_calculation_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function levelPill(): string
    {
        return match ($this->risk_level) {
            self::LEVEL_CRITICAL => 'failed',
            self::LEVEL_HIGH => 'disputed',
            self::LEVEL_MEDIUM => 'pending',
            default => 'confirmed',
        };
    }

    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }

    public function isCurrentlyRestricted(): bool
    {
        if ($this->status === self::STATUS_RESTRICTED || $this->status === self::STATUS_SUSPENDED) {
            return true;
        }

        return $this->restricted_until !== null && $this->restricted_until->isFuture();
    }
}
