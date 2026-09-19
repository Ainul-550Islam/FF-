<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AutoModeLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'private_table_id',
        'reason',
        'is_auto_on',
        'auto_on_at',
        'auto_off_at',
        'metadata',
    ];

    protected $casts = [
        'is_auto_on' => 'boolean',
        'auto_on_at' => 'datetime',
        'auto_off_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function privateTable()
    {
        return $this->belongsTo(PrivateTable::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_auto_on', true);
    }

    public function durationSeconds(): ?int
    {
        if ($this->auto_on_at && $this->auto_off_at) {
            return $this->auto_on_at->diffInSeconds($this->auto_off_at);
        }
        return null;
    }
}
