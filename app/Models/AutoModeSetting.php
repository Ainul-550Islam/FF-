<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AutoModeSetting extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'is_enabled', 'auto_on_disconnect', 'auto_on_afk', 'afk_timeout_seconds', 'strategy'];

    protected $casts = ['is_enabled' => 'boolean', 'auto_on_disconnect' => 'boolean', 'auto_on_afk' => 'boolean', 'afk_timeout_seconds' => 'integer'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isEnabled(): bool
    {
        return $this->is_enabled && ($this->auto_on_disconnect || $this->auto_on_afk);
    }
}
