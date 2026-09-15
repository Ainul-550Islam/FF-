<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An immutable, append-only wallet ledger entry.
 *
 * Double-entry-style: each entry carries a direction (credit/debit), an
 * integer minor-unit amount and the running balance after the movement, so
 * every financial movement is explainable and the wallet balance can be
 * reconciled against the ledger at any time.
 *
 * Entries are only ever created by WalletService; they can never be edited
 * or deleted.
 */
class LedgerEntry extends Model
{
    use HasFactory;

    public const DIRECTION_CREDIT = 'credit';
    public const DIRECTION_DEBIT = 'debit';

    public const TYPE_DEPOSIT = 'deposit';
    public const TYPE_REFUND = 'refund';
    public const TYPE_ADJUSTMENT = 'adjustment';
    public const TYPE_REVERSAL = 'reversal';
    public const TYPE_PAYOUT = 'payout';

    public const UPDATED_AT = null;

    protected $fillable = [];

    protected $casts = [
        'amount_minor' => 'integer',
        'balance_after' => 'integer',
        'reference_id' => 'integer',
    ];

    public function wallet()
    {
        return $this->belongsTo(Wallet::class);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function isCredit(): bool
    {
        return $this->direction === self::DIRECTION_CREDIT;
    }
}
