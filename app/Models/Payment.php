<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PAID = 'paid';
    /**
     * Legacy (Phase 01–07) success status: manually verified by an admin.
     * Kept distinct from `paid` (provider-confirmed) so we never falsely
     * relabel a manual verification as a gateway confirmation.
     */
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REFUNDED = 'refunded';

    /**
     * Controlled payment state machine. A normal user can never move a
     * payment into `paid`/`verified`; only the admin verification workflow
     * or a verified provider callback may.
     */
    public const TRANSITIONS = [
        self::STATUS_PENDING => [
            self::STATUS_PROCESSING,
            self::STATUS_PAID,
            self::STATUS_VERIFIED,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
        ],
        self::STATUS_PROCESSING => [
            self::STATUS_PAID,
            self::STATUS_VERIFIED,
            self::STATUS_FAILED,
        ],
        self::STATUS_PAID => [self::STATUS_REFUNDED],
        self::STATUS_VERIFIED => [self::STATUS_REFUNDED],
        self::STATUS_FAILED => [],
        self::STATUS_CANCELLED => [],
        self::STATUS_REFUNDED => [],
    ];

    /**
     * Statuses that represent a successfully settled payment.
     */
    public const SUCCESS_STATUSES = [
        self::STATUS_PAID,
        self::STATUS_VERIFIED,
    ];

    /**
     * Statuses that still block a new payment attempt for the same team.
     */
    public const ACTIVE_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PROCESSING,
        self::STATUS_PAID,
        self::STATUS_VERIFIED,
    ];

    /**
     * tournament_id, team_id, amount, amount_minor, currency, provider,
     * provider_reference, idempotency_key, payer_user_id, paid_at and
     * status are all server-controlled. Only the raw bKash submission fields
     * are mass-assignable.
     */
    protected $fillable = [
        'method',
        'trx_id',
    ];

    protected $casts = [
        'amount' => 'float',
        'amount_minor' => 'integer',
        'paid_at' => 'datetime',
        'refunded_at' => 'datetime',
    ];

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

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

    public function canTransitionTo(string $target): bool
    {
        return in_array($target, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function isSuccessful(): bool
    {
        return in_array($this->status, self::SUCCESS_STATUSES, true);
    }

    public function isRefundable(): bool
    {
        return $this->isSuccessful();
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_FAILED, self::STATUS_CANCELLED, self::STATUS_REFUNDED], true);
    }

    /**
     * The authoritative integer minor-unit amount (poisha).
     */
    public function amountMinor(): int
    {
        return (int) ($this->amount_minor ?? 0);
    }

    /**
     * Human status label for Blade views.
     */
    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PROCESSING => 'Processing',
            self::STATUS_PAID => 'Paid',
            self::STATUS_VERIFIED => 'Verified',
            self::STATUS_FAILED => 'Failed',
            self::STATUS_CANCELLED => 'Cancelled',
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
            self::STATUS_PAID, self::STATUS_VERIFIED => 'confirmed',
            self::STATUS_FAILED => 'failed',
            self::STATUS_CANCELLED => 'cancelled',
            self::STATUS_REFUNDED => 'finished',
            self::STATUS_PROCESSING => 'live',
            default => 'pending',
        };
    }
}
