<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChatMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'private_table_id',
        'user_id',
        'receiver_id',
        'message',
        'emoji',
        'type',
        'is_muted',
        'is_reported',
        'metadata',
    ];

    protected $casts = [
        'is_muted' => 'boolean',
        'is_reported' => 'boolean',
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

    public function receiver()
    {
        return $this->belongsTo(User::class, 'receiver_id');
    }

    public function isEmoji(): bool
    {
        return $this->type === 'emoji' && ! empty($this->emoji);
    }

    public function isSystem(): bool
    {
        return $this->type === 'system';
    }

    public function scopeForTable($query, int $tableId)
    {
        return $query->where('private_table_id', $tableId);
    }

    public function scopeNotMuted($query)
    {
        return $query->where('is_muted', false);
    }
}
