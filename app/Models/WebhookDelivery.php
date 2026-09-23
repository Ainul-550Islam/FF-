<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A single outbound webhook delivery attempt (Phase 15).
 *
 * Carries the redacted event payload, the signed signature and the delivery
 * state machine. Retries follow the configured exponential backoff; a
 * delivery that ultimately fails marks the endpoint disabled.
 */
class WebhookDelivery extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DISABLED = 'disabled';

    protected $fillable = [];

    protected $casts = [
        'payload' => 'array',
        'attempts' => 'integer',
        'next_retry_at' => 'datetime',
        'last_status_code' => 'integer',
        'delivered_at' => 'datetime',
    ];

    public function endpoint()
    {
        return $this->belongsTo(WebhookEndpoint::class, 'endpoint_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_SUCCESS,
            self::STATUS_FAILED,
            self::STATUS_DISABLED,
        ], true);
    }
}
