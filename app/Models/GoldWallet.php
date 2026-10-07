<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Virtual gold wallet (Gameberry economy).
 *
 * Concurrency (GAP-10 A2): `addGold()`/`spendGold()` used to call
 * `$this->lockForUpdate()`, which on a model instance simply *builds* a query
 * builder and never executes a statement — zero queries, no row lock. Two
 * concurrent spends could therefore both read the same balance and both
 * succeed (double-spend). The methods now open the transaction and fetch the
 * row with `static::query()->whereKey(...)->lockForUpdate()->firstOrFail()`,
 * exactly like `WalletService` does for the real BDT wallet, and all balance
 * checks and writes use that locked row.
 */
class GoldWallet extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'gold_balance',
        'total_earned',
        'total_spent',
        'total_won',
        'total_lost',
    ];

    protected $casts = [
        'gold_balance' => 'integer',
        'total_earned' => 'integer',
        'total_spent' => 'integer',
        'total_won' => 'integer',
        'total_lost' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function transactions()
    {
        return $this->hasMany(GoldTransaction::class);
    }

    public function addGold(int $amount, string $type, ?string $referenceType = null, ?string $referenceId = null, ?string $description = null): GoldTransaction
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be positive for add');
        }

        return DB::transaction(function () use ($amount, $type, $referenceType, $referenceId, $description) {
            $wallet = $this->lockRow();

            $wallet->gold_balance += $amount;
            $wallet->total_earned += $amount;
            if ($type === 'win') {
                $wallet->total_won += $amount;
            }
            $wallet->save();

            $this->syncFrom($wallet);

            return GoldTransaction::create([
                'user_id' => $wallet->user_id,
                'gold_wallet_id' => $wallet->id,
                'type' => $type,
                'amount' => $amount,
                'balance_after' => $wallet->gold_balance,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'description' => $description,
            ]);
        });
    }

    public function spendGold(int $amount, string $type, ?string $referenceType = null, ?string $referenceId = null, ?string $description = null): GoldTransaction
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be positive for spend');
        }

        return DB::transaction(function () use ($amount, $type, $referenceType, $referenceId, $description) {
            $wallet = $this->lockRow();

            if ($wallet->gold_balance < $amount) {
                throw new \Exception('Insufficient gold balance');
            }

            $wallet->gold_balance -= $amount;
            $wallet->total_spent += $amount;
            if ($type === 'bet' || $type === 'loss') {
                $wallet->total_lost += $amount;
            }
            $wallet->save();

            $this->syncFrom($wallet);

            return GoldTransaction::create([
                'user_id' => $wallet->user_id,
                'gold_wallet_id' => $wallet->id,
                'type' => $type,
                'amount' => -$amount,
                'balance_after' => $wallet->gold_balance,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'description' => $description,
            ]);
        });
    }

    /**
     * SELECT … FOR UPDATE the wallet row. Must be called inside a transaction.
     */
    protected function lockRow(): static
    {
        /** @var static $wallet */
        $wallet = static::query()
            ->whereKey($this->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        return $wallet;
    }

    /**
     * Keep the in-memory instance in sync with the committed row so callers
     * that read `$wallet->gold_balance` after the call see the new value
     * (the previous implementation relied on `refresh()` for that).
     */
    protected function syncFrom(self $wallet): void
    {
        $this->setRawAttributes($wallet->getAttributes(), true);
    }

    public function hasEnoughGold(int $amount): bool
    {
        return $this->gold_balance >= $amount;
    }
}
