<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Privacy-aware IP intelligence (Phase 10).
 *
 * Only cryptographic hashes are stored (the full address hash plus a subnet
 * hash for grouping) — raw IP addresses are never persisted and are never
 * shown to ordinary users. Many legitimate networks (carriers, NAT, cafés,
 * universities, VPNs) contain many users, so an IP is a risk signal, never
 * proof of abuse.
 */
class IpIntel extends Model
{
    use HasFactory;

    protected $table = 'ip_intel';

    protected $fillable = [];

    protected $casts = [
        'observation_count' => 'integer',
        'suspicious_count' => 'integer',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function links()
    {
        return $this->hasMany(IpLink::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'ip_links', 'ip_intel_id', 'user_id')
            ->withTimestamps();
    }
}
