<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A marketing campaign landing page (Phase 20).
 *
 * DB-driven landing pages at /campaign/{slug}. The slug doubles as the
 * default utm_campaign key so every visit is attributable without a deploy.
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string $headline
 * @property string|null $subheadline
 * @property string|null $body
 * @property string|null $hero_image
 * @property string|null $cta_label
 * @property string|null $cta_url
 * @property string|null $utm_campaign
 * @property string|null $seo_title
 * @property string|null $seo_description
 * @property bool $active
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property array|null $metadata
 */
class MarketingCampaign extends Model
{
    use HasFactory;

    protected $fillable = [
        'slug', 'name', 'headline', 'subheadline', 'body', 'hero_image',
        'cta_label', 'cta_url', 'utm_campaign', 'seo_title', 'seo_description',
        'active', 'starts_at', 'ends_at', 'metadata',
    ];

    protected $casts = [
        'active' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'metadata' => 'array',
    ];

    /**
     * Landing pages are public content and must render noindex until they
     * are within their run window and active.
     */
    public function isLive(): bool
    {
        if (! $this->active) {
            return false;
        }

        if ($this->starts_at !== null && $this->starts_at->isFuture()) {
            return false;
        }

        if ($this->ends_at !== null && $this->ends_at->isPast()) {
            return false;
        }

        return true;
    }

    public function campaignKey(): string
    {
        return $this->utm_campaign ?: $this->slug;
    }
}
