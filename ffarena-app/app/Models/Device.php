<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A pseudonymous device identity (Phase 10).
 *
 * Only a server-derived cryptographic hash is stored — never raw
 * fingerprints, user-agents or other invasive identifiers. A device's
 * association with many accounts is a risk signal, not proof of abuse.
 */
class Device extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_BLOCKED = 'blocked';

    protected $fillable = [];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function links()
    {
        return $this->hasMany(DeviceLink::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'device_links', 'device_id', 'user_id')
            ->withTimestamps();
    }

    public function isBlocked(): bool
    {
        return $this->status === self::STATUS_BLOCKED;
    }
}
