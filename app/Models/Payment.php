<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class Payment extends Model
{
    use HasFactory;
    protected $fillable = ['user_id','wallet_id','provider','external_id','provider_reference','amount_minor','amount','currency','method','status','idempotency_key','idempotency_fingerprint','metadata','tournament_id','team_id','payer_user_id','trx_id','authorized_at','succeeded_at','failed_at'];
    protected $casts = ['amount_minor'=>'integer','metadata'=>'array','authorized_at'=>'datetime','succeeded_at'=>'datetime','failed_at'=>'datetime'];
    public const STATUS_CREATED='created'; public const STATUS_PENDING='pending'; public const STATUS_PROCESSING='processing'; public const STATUS_AUTHORIZED='authorized'; public const STATUS_SUCCEEDED='succeeded'; public const STATUS_FAILED='failed'; public const STATUS_EXPIRED='expired'; public const STATUS_CANCELLED='cancelled'; public const STATUS_REFUNDING='refunding'; public const STATUS_REFUNDED='refunded';
    // Legacy (Phase 01-07) statuses — `verified` = manually verified,
    // `paid` = provider-confirmed (kept distinct, per financial-architecture).
    public const STATUS_VERIFIED='verified'; public const STATUS_PAID='paid';

    // Statuses that represent a successfully completed payment (both
    // generations: provider-confirmed `succeeded`/`paid` and the legacy
    // manually-verified `verified`).
    public const SUCCESS_STATUSES = [
        self::STATUS_SUCCEEDED,
        self::STATUS_PAID,
        self::STATUS_VERIFIED,
    ];

    // Non-terminal (in-flight) statuses — an active payment blocks a duplicate
    // checkout for the same team (see CheckoutController).
    public const ACTIVE_STATUSES = [
        self::STATUS_CREATED,
        self::STATUS_PENDING,
        self::STATUS_PROCESSING,
        self::STATUS_AUTHORIZED,
    ];

    /**
     * Payment amount in minor units. Prefers the Phase-04 minor-unit column;
     * falls back to the legacy whole-unit `amount` column (×100).
     */
    public function amountMinor(): int
    {
        if ($this->amount_minor !== null) {
            return (int) $this->amount_minor;
        }
        return (int) round(((float) ($this->amount ?? 0)) * 100);
    }
}
