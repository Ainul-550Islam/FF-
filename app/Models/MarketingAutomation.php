<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A lifecycle automation definition (Phase 21).
 *
 * trigger is a lifecycle moment key; action is the NotificationService
 * payload {type,title,body,link}; audience is an optional filter
 * {role: "..."}; cooldown_hours is the per-recipient deduplication window.
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string $trigger
 * @property array|null $audience
 * @property array $action
 * @property int $cooldown_hours
 * @property bool $enabled
 * @property Carbon|null $last_run_at
 * @property array|null $metadata
 */
class MarketingAutomation extends Model
{
    use HasFactory;

    public const TRIGGER_USER_REGISTERED = 'user.registered';

    public const TRIGGER_LEAD_SUBSCRIBED = 'lead.subscribed';

    public const TRIGGER_PAYMENT_FAILED = 'payment.failed';

    public const TRIGGERS = [
        self::TRIGGER_USER_REGISTERED,
        self::TRIGGER_LEAD_SUBSCRIBED,
        self::TRIGGER_PAYMENT_FAILED,
    ];

    protected $fillable = [
        'key', 'name', 'trigger', 'audience', 'action', 'cooldown_hours',
        'enabled', 'last_run_at', 'metadata',
    ];

    protected $casts = [
        'audience' => 'array',
        'action' => 'array',
        'cooldown_hours' => 'integer',
        'enabled' => 'boolean',
        'last_run_at' => 'datetime',
        'metadata' => 'array',
    ];
}
