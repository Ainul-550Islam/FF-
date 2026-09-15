<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An approved third-party webhook subscription (Phase 15, outbound).
 *
 * The per-endpoint signing secret is stored encrypted and is never returned
 * by the API after creation. Subscriptions are event-scoped: a subscriber
 * only ever receives the events it selected.
 */
class WebhookEndpoint extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';

    protected $fillable = [];

    protected $casts = [
        'events' => 'array',
        'consecutive_failures' => 'integer',
        'last_success_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function deliveries()
    {
        return $this->hasMany(WebhookDelivery::class, 'endpoint_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function subscribesTo(string $event): bool
    {
        return in_array($event, (array) $this->events, true);
    }
}
