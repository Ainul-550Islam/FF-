<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A user's account balance (BDT, integer poisha internally).
 *
 * The balance is the single source of truth, always kept in sync with the
 * append-only ledger by WalletService. No controller may mutate the balance
 * directly.
 */
class Wallet extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_FROZEN = 'frozen';

    protected $fillable = ['user_id', 'currency', 'balance_minor', 'is_locked'];

    protected $casts = [
        'balance_minor' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function ledgerEntries()
    {
        return $this->hasMany(LedgerEntry::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function balanceMinor(): int
    {
        return (int) $this->balance_minor;
    }
}
