<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MagicChest extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'type',
        'status',
        'gold_reward',
        'gem_reward',
        'dice_rewards',
        'available_at',
        'opened_at',
        'expires_at',
        'metadata',
    ];

    protected $casts = [
        'gold_reward' => 'integer',
        'gem_reward' => 'integer',
        'dice_rewards' => 'array',
        'available_at' => 'datetime',
        'opened_at' => 'datetime',
        'expires_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isAvailable(): bool
    {
        return $this->status === 'available' && (!$this->expires_at || $this->expires_at->isFuture());
    }

    public function isOpened(): bool
    {
        return $this->status === 'opened';
    }

    public function open(): array
    {
        if (!$this->isAvailable()) {
            throw new \Exception('Chest not available');
        }

        $this->status = 'opened';
        $this->opened_at = now();
        $this->save();

        return [
            'gold' => $this->gold_reward,
            'gems' => $this->gem_reward,
            'dices' => $this->dice_rewards ?? [],
        ];
    }

    public function scopeAvailable($query)
    {
        return $query->where('status', 'available')->where(function ($q) {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });
    }
}
