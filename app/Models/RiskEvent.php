<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An immutable, append-only fraud/risk signal (Phase 10).
 *
 * Each event carries a type, severity, deterministic score contribution,
 * source and (optionally) the tournament it relates to. Events are only ever
 * created by FraudRiskService; they are never edited or deleted, which keeps
 * the risk score fully reproducible.
 */
class RiskEvent extends Model
{
    use HasFactory;

    public const SEVERITY_INFO = 'info';

    public const SEVERITY_LOW = 'low';

    public const SEVERITY_MEDIUM = 'medium';

    public const SEVERITY_HIGH = 'high';

    public const SEVERITY_CRITICAL = 'critical';

    public const SEVERITIES = [
        self::SEVERITY_INFO,
        self::SEVERITY_LOW,
        self::SEVERITY_MEDIUM,
        self::SEVERITY_HIGH,
        self::SEVERITY_CRITICAL,
    ];

    // Event types.
    public const TYPE_AUTH_DEVICE_SHARED = 'auth.device_shared';

    public const TYPE_AUTH_IP_SHARED = 'auth.ip_shared';

    public const TYPE_BAN_EVASION = 'auth.ban_evasion';

    public const TYPE_ACCOUNT_LINKED = 'account.linked';

    public const TYPE_ACCOUNT_RESTRICTED = 'account.restricted';

    public const TYPE_REGISTRATION_VOLUME = 'account.registration_volume';

    public const TYPE_WITHDRAWAL_REPEAT = 'account.withdrawal_repeat';

    public const TYPE_PAYMENT_FAILED = 'payment.failed';

    public const TYPE_PAYMENT_REPEAT = 'payment.repeat';

    public const TYPE_PAYOUT_RECIPIENT = 'payout.recipient_risk';

    public const TYPE_PRIZE_WIN_PATTERN = 'prize.win_pattern';

    public const TYPE_DISPUTE_REPEAT = 'dispute.repeat';

    public const TYPE_MATCH_ANOMALY = 'match.anomaly';

    public const TYPE_ANTI_CHEAT_CONFIRMED = 'anti_cheat.confirmed';

    public const TYPE_RISK_FLAG = 'risk.flag';

    public const TYPE_RISK_REVIEW = 'risk.review_required';

    public const UPDATED_AT = null;

    protected $fillable = [];

    protected $casts = [
        'score_contribution' => 'integer',
        'metadata' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }
}
