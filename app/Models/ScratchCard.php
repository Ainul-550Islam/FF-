<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ScratchCard extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'code',
        'type',
        'reward_minor',
        'reward_gems',
        'status',
        'scratched_at',
        'expires_at',
        'metadata',
    ];

    protected $casts = [
        'reward_minor' => 'integer',
        'reward_gems' => 'integer',
        'scratched_at' => 'datetime',
        'expires_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected static function booted()
    {
        static::creating(function ($card) {
            if (empty($card->code)) {
                $card->code = 'SCRATCH-'.strtoupper(Str::random(8));
            }
            if (empty($card->expires_at)) {
                $card->expires_at = now()->addDays(7);
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isUnscratched(): bool
    {
        return $this->status === 'unscratched' && (! $this->expires_at || $this->expires_at->isFuture());
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function scratch(): array
    {
        if (! $this->isUnscratched()) {
            throw new \Exception('Card not available to scratch');
        }

        $this->status = 'scratched';
        $this->scratched_at = now();
        $this->save();

        return [
            'gold' => $this->reward_minor,
            'gems' => $this->reward_gems,
        ];
    }

    public function claim(): void
    {
        if ($this->status !== 'scratched') {
            throw new \Exception('Must scratch first');
        }
        $this->status = 'claimed';
        $this->save();
    }

    public function scopeUnscratched($query)
    {
        return $query->where('status', 'unscratched')->where(function ($q) {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });
    }
}
