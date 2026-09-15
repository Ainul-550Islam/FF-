<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Central, append-only admin/security audit log (Phase 13).
 *
 * Rows are created exclusively by AuditLogService. Updates and deletes are
 * refused at the model layer, and no route or UI exposes any mutation of
 * audit rows. `before`/`after`/`metadata` payloads are whitelisted by callers
 * and redacted by the service, so no credential, secret or personal
 * identifier ever lands here.
 */
class AuditLog extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Append-only: refuse any in-place mutation or deletion.
        static::updating(fn () => false);
        static::deleting(fn () => false);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function targetUser()
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }
}
