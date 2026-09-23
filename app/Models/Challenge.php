<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Challenge extends Model
{
    use HasFactory;

    protected $fillable = [
        'challenger_id',
        'challenged_id',
        'private_table_id',
        'type',
        'status',
        'bet_amount_minor',
        'expires_at',
        'responded_at',
        'metadata',
    ];

    protected $casts = [
        'bet_amount_minor' => 'integer',
        'expires_at' => 'datetime',
        'responded_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function challenger()
    {
        return $this->belongsTo(User::class, 'challenger_id');
    }

    public function challenged()
    {
        return $this->belongsTo(User::class, 'challenged_id');
    }

    public function privateTable()
    {
        return $this->belongsTo(PrivateTable::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending' && (! $this->expires_at || $this->expires_at->isFuture());
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast() && $this->status === 'pending';
    }

    public function accept(): void
    {
        if (! $this->isPending()) {
            throw new \Exception('Challenge not pending');
        }
        $this->status = 'accepted';
        $this->responded_at = now();
        $this->save();
    }

    public function deny(): void
    {
        if (! $this->isPending()) {
            throw new \Exception('Challenge not pending');
        }
        $this->status = 'denied';
        $this->responded_at = now();
        $this->save();
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending')->where(function ($q) {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });
    }
}
