<?php

namespace App\Http\Controllers;

use App\Models\MarketingCampaign;
use App\Services\MarketingTrackingService;
use App\Support\Seo;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MarketingCampaignController extends Controller
{
    public function show(Request $request, string $slug, MarketingTrackingService $tracking): View
    {
        $campaign = MarketingCampaign::query()->where('slug', $slug)->firstOrFail();

        if (! $campaign->isLive()) {
            abort(404);
        }

        $tracking->record('campaign_view', [
            'campaign' => $campaign->slug,
            'campaign_key' => $campaign->campaignKey(),
        ], $request);

        $campaignKey = $campaign->campaignKey();

        app(Seo::class)
            ->title($campaign->headline.' — FF Arena')
            ->description($campaign->subheadline ?: $campaign->headline)
            ->canonical(route('marketing.campaigns.show', ['slug' => $campaign->slug]))
            ->indexable(true)
            ->ogImage(asset('img/og-default.png'), $campaign->headline);

        return view('marketing.landing', compact('campaign', 'campaignKey'));
    }
}
