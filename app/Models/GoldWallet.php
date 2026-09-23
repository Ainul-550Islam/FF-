<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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
            $this->lockForUpdate();
            $this->refresh();

            $this->gold_balance += $amount;
            $this->total_earned += $amount;
            if ($type === 'win') {
                $this->total_won += $amount;
            }
            $this->save();

            return GoldTransaction::create([
                'user_id' => $this->user_id,
                'gold_wallet_id' => $this->id,
                'type' => $type,
                'amount' => $amount,
                'balance_after' => $this->gold_balance,
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
            $this->lockForUpdate();
            $this->refresh();

            if ($this->gold_balance < $amount) {
                throw new \Exception('Insufficient gold balance');
            }

            $this->gold_balance -= $amount;
            $this->total_spent += $amount;
            if ($type === 'bet' || $type === 'loss') {
                $this->total_lost += $amount;
            }
            $this->save();

            return GoldTransaction::create([
                'user_id' => $this->user_id,
                'gold_wallet_id' => $this->id,
                'type' => $type,
                'amount' => -$amount,
                'balance_after' => $this->gold_balance,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'description' => $description,
            ]);
        });
    }

    public function hasEnoughGold(int $amount): bool
    {
        return $this->gold_balance >= $amount;
    }
}
