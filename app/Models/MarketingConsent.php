<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A marketing/analytics consent record (Phase 20).
 *
 * Append-only: each grant, change and withdrawal is its own row with the
 * policy version it applied to. The latest row per visitor wins; third-party
 * trackers must never load without a granted row.
 *
 * @property int $id
 * @property string|null $anonymous_id
 * @property int|null $user_id
 * @property bool $analytics_consent
 * @property bool $marketing_consent
 * @property string|null $policy_version
 * @property Carbon|null $granted_at
 * @property Carbon|null $withdrawn_at
 * @property Carbon|null $created_at
 */
class MarketingConsent extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'anonymous_id', 'user_id', 'analytics_consent', 'marketing_consent',
        'policy_version', 'granted_at', 'withdrawn_at', 'created_at',
    ];

    protected $casts = [
        'analytics_consent' => 'boolean',
        'marketing_consent' => 'boolean',
        'granted_at' => 'datetime',
        'withdrawn_at' => 'datetime',
        'created_at' => 'datetime',
    ];
}
