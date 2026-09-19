<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PrivateTableParticipant extends Model
{
    use HasFactory;

    protected $fillable = [
        'private_table_id',
        'user_id',
        'role',
        'team',
        'status',
        'is_in_auto_mode',
        'is_ready',
        'position',
        'gold_won_minor',
        'joined_at',
        'left_at',
        'auto_mode_on_at',
        'auto_mode_off_at',
        'metadata',
    ];

    protected $casts = [
        'is_in_auto_mode' => 'boolean',
        'is_ready' => 'boolean',
        'position' => 'integer',
        'gold_won_minor' => 'integer',
        'joined_at' => 'datetime',
        'left_at' => 'datetime',
        'auto_mode_on_at' => 'datetime',
        'auto_mode_off_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function privateTable()
    {
        return $this->belongsTo(PrivateTable::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isInAutoMode(): bool
    {
        return $this->is_in_auto_mode;
    }

    public function isReady(): bool
    {
        return $this->is_ready;
    }

    public function scopeInAutoMode($query)
    {
        return $query->where('is_in_auto_mode', true);
    }

    public function scopeReady($query)
    {
        return $query->where('is_ready', true);
    }
}
