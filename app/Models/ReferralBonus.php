<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReferralBonus extends Model
{
    use HasFactory;

    protected $fillable = ['referral_id', 'user_id', 'type', 'amount_minor', 'gems', 'status', 'awarded_at', 'metadata'];

    protected $casts = ['amount_minor' => 'integer', 'gems' => 'integer', 'awarded_at' => 'datetime', 'metadata' => 'array'];

    public function referral()
    {
        return $this->belongsTo(Referral::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isAwarded(): bool
    {
        return $this->status === 'awarded';
    }
}
