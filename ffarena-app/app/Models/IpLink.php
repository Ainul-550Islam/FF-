<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An association between a user and a hashed IP observation (Phase 10).
 */
class IpLink extends Model
{
    use HasFactory;

    protected $fillable = [];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function ipIntel()
    {
        return $this->belongsTo(IpIntel::class, 'ip_intel_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
