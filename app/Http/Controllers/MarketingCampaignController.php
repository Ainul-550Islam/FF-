<?php

namespace App\Http\Controllers;

use App\Services\MarketingCampaignService;
use App\Services\MarketingTrackingService;
use App\Support\Seo;
use Illuminate\Http\Request;

/**
 * Phase 20 — public campaign landing pages (/campaign/{slug}).
 *
 * Only live campaigns render; everything else 404s. Every view is recorded
 * as a campaign_view conversion event and the landing request is attributable
 * via the utm_campaign key injected into the page's CTAs.
 */
class MarketingCampaignController extends Controller
{
    public function __construct(
        protected MarketingCampaignService $campaigns,
        protected MarketingTrackingService $tracking,
    ) {}

    public function show(Request $request, string $slug)
    {
        $campaign = $this->campaigns->findLiveBySlug($slug);

        if ($campaign === null || ! $campaign->isLive()) {
            abort(404);
        }

        $siteName = (string) config('app.name', 'FF Arena');

        app(Seo::class)
            ->title($campaign->seo_title ?: ($campaign->headline.' — '.$siteName))
            ->description($campaign->seo_description ?: ($campaign->subheadline ?? $campaign->headline))
            ->canonical(route('marketing.campaigns.show', ['slug' => $campaign->slug]))
            ->indexable();

        $this->tracking->record('campaign_view', [
            'campaign' => $campaign->campaignKey(),
        ], $request);

        return view('marketing.landing', [
            'campaign' => $campaign,
            'campaignKey' => $campaign->campaignKey(),
        ]);
    }
}
