<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DiceExchange extends Model
{
    use HasFactory;

    protected $fillable = [
        'sender_id',
        'receiver_id',
        'dice_id',
        'status',
        'is_facebook_only',
        'expires_at',
        'responded_at',
    ];

    protected $casts = [
        'is_facebook_only' => 'boolean',
        'expires_at' => 'datetime',
        'responded_at' => 'datetime',
    ];

    protected static function booted()
    {
        static::creating(function ($exchange) {
            if (!isset($exchange->is_facebook_only)) {
                $exchange->is_facebook_only = true; // Gameberry FAQ: Facebook only
            }
            if (empty($exchange->expires_at)) {
                $exchange->expires_at = now()->addDays(7);
            }
        });
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'receiver_id');
    }

    public function dice()
    {
        return $this->belongsTo(Dice::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending' && (!$this->expires_at || $this->expires_at->isFuture());
    }

    public function canBeExchanged(): bool
    {
        if (!$this->isPending()) return false;
        // Facebook-only validation - must be Facebook friends
        if ($this->is_facebook_only) {
            // Check if users are Facebook connected - check game_buddies with facebook source or user facebook_id
            $sender = $this->sender;
            $receiver = $this->receiver;
            // Allow if both have facebook_id or are buddies - simplified for now, but enforce flag
            return true; // Facebook check would be here with actual Facebook Graph API
        }
        return true;
    }

    public function accept(): void
    {
        if (!$this->isPending()) {
            throw new \Exception('Exchange not pending');
        }
        if (!$this->canBeExchanged()) {
            throw new \Exception('Facebook only exchange - must be Facebook friends');
        }

        \Illuminate\Support\Facades\DB::transaction(function () {
            $senderDice = UserDice::where('user_id', $this->sender_id)->where('dice_id', $this->dice_id)->first();
            if (!$senderDice || $senderDice->quantity < 2) {
                throw new \Exception('Sender must have at least 2 of this dice to exchange');
            }

            // Decrease sender
            $senderDice->quantity -= 1;
            $senderDice->save();

            // Increase receiver
            $receiverDice = UserDice::firstOrCreate(
                ['user_id' => $this->receiver_id, 'dice_id' => $this->dice_id],
                ['quantity' => 0]
            );
            $receiverDice->quantity += 1;
            $receiverDice->save();

            $this->status = 'accepted';
            $this->responded_at = now();
            $this->save();
        });
    }

    public function deny(): void
    {
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

    public function scopeFacebookOnly($query)
    {
        return $query->where('is_facebook_only', true);
    }
}
