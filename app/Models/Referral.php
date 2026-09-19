<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Referral extends Model
{
    use HasFactory;

    protected $fillable = [
        'referrer_id',
        'referred_id',
        'code',
        'referred_email_or_phone',
        'status',
        'bonus_minor',
        'completed_at',
        'rewarded_at',
        'metadata',
    ];

    protected $casts = [
        'bonus_minor' => 'integer',
        'completed_at' => 'datetime',
        'rewarded_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected static function booted()
    {
        static::creating(function ($referral) {
            if (empty($referral->code)) {
                $referral->code = strtoupper(Str::random(6)); // BGI20 style
            }
            if (empty($referral->bonus_minor)) {
                $referral->bonus_minor = 2500; // ₹25 bonus = 2500 minor
            }
        });
    }

    public function referrer()
    {
        return $this->belongsTo(User::class, 'referrer_id');
    }

    public function referred()
    {
        return $this->belongsTo(User::class, 'referred_id');
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed' || $this->status === 'rewarded';
    }

    public function complete(int $referredId): void
    {
        $this->referred_id = $referredId;
        $this->status = 'completed';
        $this->completed_at = now();
        $this->save();
    }

    public function reward(): void
    {
        $this->status = 'rewarded';
        $this->rewarded_at = now();
        $this->save();
    }

    public static function generateCode(int $userId): string
    {
        $user = User::find($userId);
        $prefix = strtoupper(substr($user->name ?? 'FF', 0, 2));
        return $prefix . strtoupper(Str::random(4)) . '20'; // BGI20 style
    }
}
