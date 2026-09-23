<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A UTM governance snapshot row (Phase 21).
 *
 * A derived, rebuildable aggregate of marketing_attributions for one period
 * and one normalized dimension combination. Reporting only — the original
 * attribution rows are never rewritten.
 *
 * @property int $id
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property string $source
 * @property string $medium
 * @property string $campaign
 * @property string $content
 * @property string $term
 * @property int $touches
 * @property int $unique_visitors
 * @property int $conversions
 * @property int $attributed_users
 */
class MarketingUtmSnapshot extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'period_start', 'period_end', 'source', 'medium', 'campaign', 'content',
        'term', 'touches', 'unique_visitors', 'conversions', 'attributed_users',
        'created_at',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'created_at' => 'datetime',
    ];
}
