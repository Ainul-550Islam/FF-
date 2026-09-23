<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;

    /**
     * The tournament/team/payer, the amounts, the currency, the status and
     * the provider state are server-controlled (derived from the tournament's
     * entry fee and the verification workflow). Only the raw bKash submission
     * fields are mass-assignable.
     */
    protected $fillable = [
        'method',
        'trx_id',
    ];

    protected $casts = ['amount_minor' => 'integer', 'metadata' => 'array', 'authorized_at' => 'datetime', 'succeeded_at' => 'datetime', 'failed_at' => 'datetime', 'paid_at' => 'datetime', 'refunded_at' => 'datetime'];

    public const STATUS_CREATED = 'created';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_AUTHORIZED = 'authorized';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_REFUNDING = 'refunding';

    public const STATUS_REFUNDED = 'refunded';

    // Legacy (Phase 01-07) statuses — `verified` = manually verified,
    // `paid` = provider-confirmed (kept distinct, per financial-architecture).
    public const STATUS_VERIFIED = 'verified';

    public const STATUS_PAID = 'paid';

    // Statuses that represent a successfully completed payment (both
    // generations: provider-confirmed `succeeded`/`paid` and the legacy
    // manually-verified `verified`).
    public const SUCCESS_STATUSES = [
        self::STATUS_SUCCEEDED,
        self::STATUS_PAID,
        self::STATUS_VERIFIED,
    ];

    /**
     * Controlled payment state machine (Phase 08). A normal user can never
     * move a payment into a success state; only the admin verification
     * workflow or a verified provider callback may.
     */
    public const TRANSITIONS = [
        self::STATUS_CREATED => [self::STATUS_PENDING, self::STATUS_FAILED, self::STATUS_EXPIRED],
        self::STATUS_PENDING => [
            self::STATUS_PROCESSING,
            self::STATUS_AUTHORIZED,
            self::STATUS_SUCCEEDED,
            self::STATUS_PAID,
            self::STATUS_VERIFIED,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
        ],
        self::STATUS_PROCESSING => [
            self::STATUS_SUCCEEDED,
            self::STATUS_PAID,
            self::STATUS_VERIFIED,
            self::STATUS_FAILED,
        ],
        self::STATUS_AUTHORIZED => [self::STATUS_SUCCEEDED, self::STATUS_PAID, self::STATUS_FAILED],
        self::STATUS_SUCCEEDED => [self::STATUS_REFUNDING, self::STATUS_REFUNDED],
        self::STATUS_PAID => [self::STATUS_REFUNDING, self::STATUS_REFUNDED],
        self::STATUS_VERIFIED => [self::STATUS_REFUNDING, self::STATUS_REFUNDED],
        self::STATUS_REFUNDING => [self::STATUS_REFUNDED, self::STATUS_FAILED],
        self::STATUS_FAILED => [],
        self::STATUS_EXPIRED => [],
        self::STATUS_CANCELLED => [],
        self::STATUS_REFUNDED => [],
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

    // ------------------------------------------------------------------
    // Relations (Phase 08 payment lifecycle)
    // ------------------------------------------------------------------

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * The user who actually paid (Phase 14 checkout flow). Falls back to the
     * legacy `user_id` owner column when reading `$payment->payer`.
     */
    public function payer()
    {
        return $this->belongsTo(User::class, 'payer_user_id');
    }

    public function refund()
    {
        return $this->hasOne(Refund::class);
    }

    public function events()
    {
        return $this->hasMany(PaymentEvent::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    public function belongsToTournament(Tournament $tournament): bool
    {
        return $this->tournament_id === $tournament->id;
    }

    public function belongsToTeam(Team $team): bool
    {
        return $this->team_id === $team->id;
    }

    /**
     * Controlled payment state machine — a normal user can never move a
     * payment into paid/verified; only the admin verification workflow or a
     * verified provider callback may.
     */
    public function canTransitionTo(string $target): bool
    {
        return in_array($target, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function isSuccessful(): bool
    {
        return in_array($this->status, self::SUCCESS_STATUSES, true);
    }

    /**
     * Only settled payments can be refunded (a pending payment has no money
     * to give back).
     */
    public function isRefundable(): bool
    {
        return $this->isSuccessful();
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_FAILED, self::STATUS_CANCELLED, self::STATUS_REFUNDED], true);
    }

    /**
     * Human status label for Blade views.
     */
    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PROCESSING => 'Processing',
            self::STATUS_AUTHORIZED => 'Authorized',
            self::STATUS_SUCCEEDED, self::STATUS_PAID => 'Paid',
            self::STATUS_VERIFIED => 'Verified',
            self::STATUS_FAILED => 'Failed',
            self::STATUS_EXPIRED => 'Expired',
            self::STATUS_CANCELLED => 'Cancelled',
            self::STATUS_REFUNDING => 'Refunding',
            self::STATUS_REFUNDED => 'Refunded',
            default => 'Pending',
        };
    }

    /**
     * Status pill class reusing the shared layout palette.
     */
    public function statusPill(): string
    {
        return match ($this->status) {
            self::STATUS_SUCCEEDED, self::STATUS_PAID, self::STATUS_VERIFIED => 'confirmed',
            self::STATUS_FAILED, self::STATUS_EXPIRED => 'failed',
            self::STATUS_CANCELLED => 'cancelled',
            self::STATUS_REFUNDED, self::STATUS_REFUNDING => 'finished',
            default => 'pending',
        };
    }
}
