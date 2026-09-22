<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class LedgerEntry extends Model
{
    use HasFactory;

    // ledger_entries.type — source-of-truth movement categories.
    public const TYPE_DEPOSIT = 'deposit';
    public const TYPE_WITHDRAWAL = 'withdrawal';
    public const TYPE_REFUND = 'refund';
    public const TYPE_ADJUSTMENT = 'adjustment';
    public const TYPE_PAYOUT = 'payout';
    public const TYPE_REVERSAL = 'reversal';

    public const DIRECTION_CREDIT = 'credit';
    public const DIRECTION_DEBIT = 'debit';

    /**
     * Ledger rows are the immutable financial source of truth and are written
     * exclusively by WalletService — nothing is mass-assignable.
     */
    protected $fillable = [];
    protected $casts = ['amount_minor'=>'integer','balance_after_minor'=>'integer','balance_after'=>'integer','metadata'=>'array'];
    public function wallet(){return $this->belongsTo(Wallet::class);}
    public function user(){return $this->belongsTo(User::class);}

    /**
     * The actor who caused the movement (admin credit/debit, refund processor,
     * payout processor). Null for system-generated movements.
     */
    public function actor(){return $this->belongsTo(User::class, 'actor_id');}

    public function isCredit(): bool
    {
        return $this->direction === self::DIRECTION_CREDIT;
    }

    public function isDebit(): bool
    {
        return $this->direction === self::DIRECTION_DEBIT;
    }

    /**
     * The post-movement running balance. Both generations of the schema are
     * supported: `balance_after` (financial architecture) and
     * `balance_after_minor` (legacy) — whichever column carries the value.
     */
    public function getBalanceAfterAttribute(): ?int
    {
        $value = $this->attributes['balance_after'] ?? $this->attributes['balance_after_minor'] ?? null;

        return $value === null ? null : (int) $value;
    }

    /**
     * Keep the two running-balance columns in lockstep on write so a ledger
     * row is always readable through either name.
     */
    public function setBalanceAfterAttribute(?int $value): void
    {
        $this->attributes['balance_after'] = $value;
        $this->attributes['balance_after_minor'] = $value;
    }

    // ledger_entries table is source of truth for financial integrity
}
