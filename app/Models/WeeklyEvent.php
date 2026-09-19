<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WeeklyEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'type',
        'starts_at',
        'ends_at',
        'status',
        'rewards',
        'requirements',
        'metadata',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'rewards' => 'array',
        'requirements' => 'array',
        'metadata' => 'array',
    ];

    public function participants()
    {
        return $this->hasMany(WeeklyEventParticipant::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && $this->starts_at <= now() && $this->ends_at >= now();
    }

    public function isUpcoming(): bool
    {
        return $this->status === 'upcoming' && $this->starts_at > now();
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active')->where('starts_at', '<=', now())->where('ends_at', '>=', now());
    }

    public function scopeUpcoming($query)
    {
        return $query->where('status', 'upcoming')->where('starts_at', '>', now());
    }
}
