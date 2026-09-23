<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A marketing conversion event (Phase 20).
 *
 * Written by the server for the moments that matter (registration, payment,
 * campaign views) and by the consented client for behavioural acquisition
 * events. Event names are constrained to the configured taxonomy — the
 * pipeline is a measurement contract, not a free-form log.
 *
 * @property int $id
 * @property string|null $anonymous_id
 * @property int|null $user_id
 * @property int|null $attribution_id
 * @property string $name
 * @property array|null $properties
 * @property string|null $url
 * @property Carbon|null $created_at
 */
class MarketingEvent extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'anonymous_id', 'user_id', 'attribution_id', 'name', 'properties', 'url', 'created_at',
    ];

    protected $casts = [
        'properties' => 'array',
        'created_at' => 'datetime',
    ];
}
