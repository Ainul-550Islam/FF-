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

    protected $fillable = ['wallet_id','user_id','direction','amount_minor','balance_after_minor','type','description','reference_type','reference_id','idempotency_key','metadata'];
    protected $casts = ['amount_minor'=>'integer','balance_after_minor'=>'integer','metadata'=>'array'];
    public function wallet(){return $this->belongsTo(Wallet::class);}
    public function user(){return $this->belongsTo(User::class);}

    /**
     * Alias used across services/tests for the post-movement balance.
     */
    public function getBalanceAfterAttribute()
    {
        return $this->balance_after_minor;
    }

    // ledger_entries table is source of truth for financial integrity
}
