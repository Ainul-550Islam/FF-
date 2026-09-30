<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMarketingAffiliateRequest;
use App\Models\MarketingAffiliate;
use App\Services\MarketingAffiliateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MarketingAffiliateController extends Controller
{
    public function dashboard(Request $request, MarketingAffiliateService $service): View
    {
        $payload = $service->dashboardFor($request->user());

        if ($payload === null) {
            return view('marketing.affiliate.dashboard', [
                'affiliate' => null,
                'stats' => null,
            ]);
        }

        return view('marketing.affiliate.dashboard', $payload);
    }

    public function store(StoreMarketingAffiliateRequest $request, MarketingAffiliateService $service): RedirectResponse
    {
        $service->register($request->user(), $request->affiliateData());

        return redirect()->route('marketing.affiliates.dashboard')->with('success', 'Affiliate registration complete.');
    }

    public function click(Request $request, string $code, MarketingAffiliateService $service): RedirectResponse
    {
        $affiliate = $service->findActiveByCode($code);

        if ($affiliate === null) {
            abort(404);
        }

        $service->recordClick($affiliate, $request);

        $targetUrl = $affiliate->landing_url ?: route('home');
        $delimiter = str_contains($targetUrl, '?') ? '&' : '?';
        $redirectUrl = $targetUrl.$delimiter.http_build_query([
            'utm_source' => 'affiliate',
            'utm_medium' => 'referral',
            'utm_campaign' => strtoupper($code),
        ]);

        return redirect()->away($redirectUrl);
    }
}
