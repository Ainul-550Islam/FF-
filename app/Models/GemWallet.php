<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Virtual gem wallet (Gameberry economy).
 *
 * Concurrency (GAP-10 A2): see App\Models\GoldWallet — `$this->lockForUpdate()`
 * on a model instance executes no query at all, so `addGems()`/`spendGems()`
 * had no row lock and concurrent spends could double-spend gems. The locked
 * row is now fetched inside the transaction and used for the balance check
 * and the write.
 */
class GemWallet extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'gem_balance',
        'total_earned',
        'total_spent',
        'total_purchased',
    ];

    protected $casts = [
        'gem_balance' => 'integer',
        'total_earned' => 'integer',
        'total_spent' => 'integer',
        'total_purchased' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function transactions()
    {
        return $this->hasMany(GemTransaction::class);
    }

    public function addGems(int $amount, string $type, ?string $referenceType = null, ?string $referenceId = null, ?string $description = null): GemTransaction
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be positive');
        }

        return DB::transaction(function () use ($amount, $type, $referenceType, $referenceId, $description) {
            $wallet = $this->lockRow();

            $wallet->gem_balance += $amount;
            $wallet->total_earned += $amount;
            if ($type === 'purchase') {
                $wallet->total_purchased += $amount;
            }
            $wallet->save();

            $this->syncFrom($wallet);

            return GemTransaction::create([
                'user_id' => $wallet->user_id,
                'gem_wallet_id' => $wallet->id,
                'type' => $type,
                'amount' => $amount,
                'balance_after' => $wallet->gem_balance,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'description' => $description,
            ]);
        });
    }

    public function spendGems(int $amount, string $type, ?string $referenceType = null, ?string $referenceId = null, ?string $description = null): GemTransaction
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be positive');
        }

        return DB::transaction(function () use ($amount, $type, $referenceType, $referenceId, $description) {
            $wallet = $this->lockRow();

            if ($wallet->gem_balance < $amount) {
                throw new \Exception('Insufficient gems');
            }

            $wallet->gem_balance -= $amount;
            $wallet->total_spent += $amount;
            $wallet->save();

            $this->syncFrom($wallet);

            return GemTransaction::create([
                'user_id' => $wallet->user_id,
                'gem_wallet_id' => $wallet->id,
                'type' => $type,
                'amount' => -$amount,
                'balance_after' => $wallet->gem_balance,
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
     * Keep the in-memory instance in sync with the committed row.
     */
    protected function syncFrom(self $wallet): void
    {
        $this->setRawAttributes($wallet->getAttributes(), true);
    }

    public function hasEnoughGems(int $amount): bool
    {
        return $this->gem_balance >= $amount;
    }
}
