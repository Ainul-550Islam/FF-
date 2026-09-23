<?php

namespace App\Services;

use App\Models\MarketingCampaign;
use Illuminate\Support\Collection;

/**
 * Phase 20 — campaign landing page lookups.
 *
 * Campaign pages are the only nameable utm_campaign surfaces; the service
 * keeps their resolution in one place so attribution, governance and the
 * landing controller all agree on what "live" means.
 */
class MarketingCampaignService
{
    /**
     * A campaign by slug, regardless of liveness (the caller decides what
     * to do with non-live rows — pages 404, governance still counts them).
     */
    public function findBySlug(string $slug): ?MarketingCampaign
    {
        return MarketingCampaign::query()->where('slug', $slug)->first();
    }

    /**
     * Only live (active + inside its optional window) campaigns render.
     */
    public function findLiveBySlug(string $slug): ?MarketingCampaign
    {
        return MarketingCampaign::query()
            ->where('slug', $slug)
            ->where('active', true)
            ->first();
    }

    /**
     * Registered campaign keys for UTM governance checks.
     */
    public function registeredKeys(): Collection
    {
        return MarketingCampaign::query()->get()->map(
            fn (MarketingCampaign $campaign) => mb_strtolower($campaign->campaignKey())
        );
    }
}
