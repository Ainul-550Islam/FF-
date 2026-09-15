<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A staff-only internal note on a support ticket (Phase 13). Never visible
 * to the ticket requester — only admins/moderators may read or write notes.
 */
class SupportInternalNote extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function ticket()
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }
}
