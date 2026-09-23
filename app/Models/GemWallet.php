<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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
            $this->lockForUpdate();
            $this->refresh();

            $this->gem_balance += $amount;
            $this->total_earned += $amount;
            if ($type === 'purchase') {
                $this->total_purchased += $amount;
            }
            $this->save();

            return GemTransaction::create([
                'user_id' => $this->user_id,
                'gem_wallet_id' => $this->id,
                'type' => $type,
                'amount' => $amount,
                'balance_after' => $this->gem_balance,
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
            $this->lockForUpdate();
            $this->refresh();

            if ($this->gem_balance < $amount) {
                throw new \Exception('Insufficient gems');
            }

            $this->gem_balance -= $amount;
            $this->total_spent += $amount;
            $this->save();

            return GemTransaction::create([
                'user_id' => $this->user_id,
                'gem_wallet_id' => $this->id,
                'type' => $type,
                'amount' => -$amount,
                'balance_after' => $this->gem_balance,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'description' => $description,
            ]);
        });
    }

    public function hasEnoughGems(int $amount): bool
    {
        return $this->gem_balance >= $amount;
    }
}
