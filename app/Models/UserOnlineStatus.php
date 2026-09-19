<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserOnlineStatus extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'is_online',
        'hide_online_status',
        'notify_friends_online',
        'is_in_auto_mode',
        'last_online_at',
        'last_offline_at',
        'current_game',
        'current_table_code',
        'device_info',
    ];

    protected $casts = [
        'is_online' => 'boolean',
        'hide_online_status' => 'boolean',
        'notify_friends_online' => 'boolean',
        'is_in_auto_mode' => 'boolean',
        'last_online_at' => 'datetime',
        'last_offline_at' => 'datetime',
        'device_info' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isVisibleOnline(): bool
    {
        return $this->is_online && !$this->hide_online_status;
    }

    public function shouldNotifyFriends(): bool
    {
        return $this->notify_friends_online && $this->is_online && !$this->hide_online_status;
    }

    public function goOnline(?string $game = null, ?string $tableCode = null): void
    {
        $this->is_online = true;
        $this->last_online_at = now();
        if ($game) $this->current_game = $game;
        if ($tableCode) $this->current_table_code = $tableCode;
        $this->save();
    }

    public function goOffline(): void
    {
        $this->is_online = false;
        $this->last_offline_at = now();
        $this->current_game = null;
        $this->current_table_code = null;
        $this->save();
    }

    public function setAutoMode(bool $auto, string $reason = 'disconnect'): void
    {
        $this->is_in_auto_mode = $auto;
        $this->save();

        \App\Models\AutoModeLog::create([
            'user_id' => $this->user_id,
            'private_table_id' => null,
            'reason' => $reason,
            'is_auto_on' => $auto,
            'auto_on_at' => $auto ? now() : null,
            'auto_off_at' => !$auto ? now() : null,
        ]);
    }

    public function scopeOnline($query)
    {
        return $query->where('is_online', true);
    }

    public function scopeVisible($query)
    {
        return $query->where('is_online', true)->where('hide_online_status', false);
    }
}
