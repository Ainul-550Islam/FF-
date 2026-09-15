<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 19 — a user's push-channel notification preferences.
 *
 * A single row per user. Categories map to push delivery only; in-app and
 * email notification delivery is unaffected by these flags. The `security`
 * category is always-on (enforced by PushPreferenceService, never by the
 * client).
 */
class NotificationPreference extends Model
{
    public const CATEGORY_TOURNAMENT = 'tournament';

    public const CATEGORY_MATCH = 'match';

    public const CATEGORY_TEAM = 'team';

    public const CATEGORY_PAYMENT = 'payment';

    public const CATEGORY_PAYOUT = 'payout';

    public const CATEGORY_DISPUTE = 'dispute';

    public const CATEGORY_SECURITY = 'security';

    public const CATEGORY_SUPPORT = 'support';

    /**
     * The categories a user may toggle through the API. `security` is
     * intentionally excluded: it is always delivered and never disableable.
     */
    public const TOGGLEABLE_CATEGORIES = [
        self::CATEGORY_TOURNAMENT,
        self::CATEGORY_MATCH,
        self::CATEGORY_TEAM,
        self::CATEGORY_PAYMENT,
        self::CATEGORY_PAYOUT,
        self::CATEGORY_DISPUTE,
        self::CATEGORY_SUPPORT,
    ];

    /**
     * Every known category (toggleable + always-on security).
     */
    public const ALL_CATEGORIES = [
        self::CATEGORY_TOURNAMENT,
        self::CATEGORY_MATCH,
        self::CATEGORY_TEAM,
        self::CATEGORY_PAYMENT,
        self::CATEGORY_PAYOUT,
        self::CATEGORY_DISPUTE,
        self::CATEGORY_SECURITY,
        self::CATEGORY_SUPPORT,
    ];

    protected $table = 'notification_preferences';

    protected $fillable = [
        'push_tournament',
        'push_match',
        'push_team',
        'push_payment',
        'push_payout',
        'push_dispute',
        'push_security',
        'push_support',
    ];

    protected function casts(): array
    {
        return [
            'push_tournament' => 'boolean',
            'push_match' => 'boolean',
            'push_team' => 'boolean',
            'push_payment' => 'boolean',
            'push_payout' => 'boolean',
            'push_dispute' => 'boolean',
            'push_security' => 'boolean',
            'push_support' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The column backing a category flag.
     */
    public static function columnFor(string $category): string
    {
        return 'push_'.$category;
    }

    /**
     * The public API representation of the preference row.
     */
    public function toArrayForApi(): array
    {
        $flags = [];

        foreach (self::ALL_CATEGORIES as $category) {
            $flags[$category] = (bool) $this->{self::columnFor($category)};
        }

        return $flags;
    }
}
