<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GameberryNotification extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'type', 'title', 'message', 'is_read', 'payload', 'read_at'];

    protected $casts = ['is_read' => 'boolean', 'payload' => 'array', 'read_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeUnread($query)
    {
        return $query->where('is_read', false);
    }

    public function markAsRead(): void
    {
        $this->is_read = true;
        $this->read_at = now();
        $this->save();
    }
}
