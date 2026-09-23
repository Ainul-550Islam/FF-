<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A marketing acquisition attribution touch (Phase 20).
 *
 * First-touch rows are immutable; last-touch columns are updated on
 * subsequent visits with the same campaign key. Rows start anonymous and are
 * attached to a user when they register or log in, which is the moment a
 * campaign becomes attributable to an acquisition.
 *
 * @property int $id
 * @property string $anonymous_id
 * @property int|null $user_id
 * @property string $campaign_key
 * @property string|null $source
 * @property string|null $medium
 * @property string|null $campaign
 * @property string|null $content
 * @property string|null $term
 * @property string|null $click_id_type
 * @property string|null $click_id
 * @property string|null $landing_path
 * @property string|null $referrer_host
 * @property Carbon|null $first_seen_at
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $converted_at
 * @property string|null $conversion_type
 */
class MarketingAttribution extends Model
{
    use HasFactory;

    public const CONVERSION_REGISTER = 'register';

    public const CONVERSION_LOGIN = 'login';

    /**
     * The attribution parameters captured from campaign URLs.
     */
    public const UTM_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];

    /**
     * Paid-ad click IDs, stored alongside their type for unambiguous reads.
     */
    public const CLICK_ID_KEYS = ['gclid', 'fbclid', 'msclkid'];

    public $timestamps = false;

    protected $fillable = [
        'anonymous_id', 'user_id', 'campaign_key', 'source', 'medium', 'campaign',
        'content', 'term', 'click_id_type', 'click_id', 'landing_path', 'referrer_host',
        'first_seen_at', 'last_seen_at', 'converted_at', 'conversion_type',
    ];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'converted_at' => 'datetime',
    ];
}
