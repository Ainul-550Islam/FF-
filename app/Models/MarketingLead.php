<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A captured acquisition lead (Phase 20).
 *
 * Newsletter subscribers, organizer enquiries and contact/partner messages
 * share one table with a per-lead unsubscribe token, so every future lifecycle
 * message has a working opt-out from day one.
 *
 * @property int $id
 * @property string $email
 * @property string|null $name
 * @property int|null $user_id
 * @property string $type
 * @property string|null $source
 * @property string|null $medium
 * @property string|null $campaign
 * @property array|null $metadata
 * @property string $unsubscribe_token
 * @property Carbon|null $subscribed_at
 * @property Carbon|null $unsubscribed_at
 */
class MarketingLead extends Model
{
    use HasFactory;

    public const TYPE_NEWSLETTER = 'newsletter';

    public const TYPE_ORGANIZER = 'organizer';

    public const TYPE_CONTACT = 'contact';

    public const TYPE_PARTNER = 'partner';

    public const TYPES = [
        self::TYPE_NEWSLETTER,
        self::TYPE_ORGANIZER,
        self::TYPE_CONTACT,
        self::TYPE_PARTNER,
    ];

    protected $fillable = [
        'email', 'name', 'user_id', 'type', 'source', 'medium', 'campaign',
        'metadata', 'unsubscribe_token', 'subscribed_at', 'unsubscribed_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'subscribed_at' => 'datetime',
        'unsubscribed_at' => 'datetime',
    ];

    public function isSubscribed(): bool
    {
        return $this->unsubscribed_at === null;
    }
}
