<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GoldAtStake extends Model
{
    use HasFactory;

    protected $table = 'gold_at_stake';

    protected $fillable = ['private_table_id', 'user_id', 'amount', 'status', 'won_amount', 'refunded_amount', 'settled_at'];

    protected $casts = ['amount' => 'integer', 'won_amount' => 'integer', 'refunded_amount' => 'integer', 'settled_at' => 'datetime'];

    public function privateTable()
    {
        return $this->belongsTo(PrivateTable::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isWon(): bool
    {
        return $this->status === 'won';
    }

    public function isLost(): bool
    {
        return $this->status === 'lost';
    }

    public function isRefunded(): bool
    {
        return $this->status === 'refunded';
    }
}
