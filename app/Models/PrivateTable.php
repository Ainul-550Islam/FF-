<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PrivateTable extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'link',
        'creator_id',
        'game_variation',
        'mode',
        'bet_amount_minor',
        'max_players',
        'status',
        'is_team_up',
        'is_facebook_only',
        'allow_spectators',
        'expires_at',
        'started_at',
        'completed_at',
        'settings',
        'metadata',
    ];

    protected $casts = [
        'bet_amount_minor' => 'integer',
        'max_players' => 'integer',
        'is_team_up' => 'boolean',
        'is_facebook_only' => 'boolean',
        'allow_spectators' => 'boolean',
        'expires_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'settings' => 'array',
        'metadata' => 'array',
    ];

    protected static function booted()
    {
        static::creating(function ($table) {
            if (empty($table->code)) {
                $table->code = strtoupper(Str::random(6)); // 6-char code for sharing
            }
            if (empty($table->link)) {
                $table->link = url('/private-tables/join/'.$table->code);
            }
            if (empty($table->expires_at)) {
                $table->expires_at = now()->addHours(2);
            }
        });
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function participants()
    {
        return $this->hasMany(PrivateTableParticipant::class);
    }

    public function chatMessages()
    {
        return $this->hasMany(ChatMessage::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function isWaiting(): bool
    {
        return $this->status === 'waiting' && ! $this->isExpired();
    }

    public function canJoin(): bool
    {
        return $this->isWaiting() && $this->participants()->count() < $this->max_players;
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'waiting')->where('expires_at', '>', now());
    }

    public function isFull(): bool
    {
        return $this->participants()->count() >= $this->max_players;
    }

    public function getHostAttribute()
    {
        return $this->creator;
    }

    public function getHostIdAttribute()
    {
        return $this->creator_id;
    }

    public function host()
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function getBetAmountAttribute()
    {
        return $this->bet_amount_minor;
    }

    public function getGameModeAttribute()
    {
        return $this->game_variation ?? $this->mode ?? 'classic';
    }
}
