<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A marketing push re-engagement subscription (Phase 21).
 *
 * Follows the mobile_device_tokens safety model: endpoint keys are stored
 * encrypted at rest (never serialized — hidden), identified by the SHA-256
 * endpoint_hash, and associated with the anonymous visitor and/or user.
 * One row per endpoint; resubscribing re-activates instead of duplicating.
 *
 * @property int $id
 * @property string $provider
 * @property string $endpoint
 * @property string $endpoint_hash
 * @property array|null $encrypted_keys
 * @property string|null $anonymous_id
 * @property int|null $user_id
 * @property string|null $user_agent
 * @property array|null $topics
 * @property Carbon|null $subscribed_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $last_sent_at
 * @property int $failure_count
 */
class MarketingPushSubscription extends Model
{
    use HasFactory;

    public const PROVIDER_FCM = 'fcm';

    public const PROVIDER_WEB = 'web';

    public const PROVIDERS = [
        self::PROVIDER_FCM,
        self::PROVIDER_WEB,
    ];

    protected $fillable = [
        'provider', 'endpoint', 'endpoint_hash', 'encrypted_keys', 'anonymous_id',
        'user_id', 'user_agent', 'topics', 'subscribed_at', 'revoked_at',
        'last_sent_at', 'failure_count',
    ];

    protected $hidden = ['encrypted_keys', 'endpoint_hash'];

    protected function casts(): array
    {
        return [
            'encrypted_keys' => 'encrypted:array',
            'topics' => 'array',
            'subscribed_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_sent_at' => 'datetime',
            'failure_count' => 'integer',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }
}
