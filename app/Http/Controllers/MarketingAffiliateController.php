<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMarketingAffiliateRequest;
use App\Services\MarketingAffiliateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Throwable;

/**
 * Phase 21 — affiliate program endpoints.
 *
 * /r/{code} is public (rate-limited redirect with attribution);
 * registration + dashboard require auth. Identity is always server-decided:
 * the authenticated user, never a client-supplied id.
 */
class MarketingAffiliateController extends Controller
{
    public function __construct(
        protected MarketingAffiliateService $affiliates,
    ) {}

    /**
     * Public referral landing: record the click (idempotent per visitor),
     * then send the visitor on to the affiliate's landing target.
     */
    public function click(Request $request, string $code): RedirectResponse
    {
        $affiliate = $this->affiliates->findActiveByCode($code);

        if ($affiliate === null) {
            abort(404);
        }

        $this->affiliates->recordClick($affiliate, $request);

        $target = $affiliate->landing_url;

        if (! is_string($target) || ! filter_var($target, FILTER_VALIDATE_URL)) {
            $target = route('home');
        }

        // The utm trio re-tags the landing so the attribution middleware
        // captures the visit under the affiliate's own campaign key — the
        // referral ties into the same first/last-touch funnel as every ad.
        $params = [
            'utm_source' => 'affiliate',
            'utm_medium' => 'referral',
            'utm_campaign' => $affiliate->code,
        ];

        return redirect()->away($target.(parse_url($target, PHP_URL_QUERY) ? '&' : '?').http_build_query($params));
    }

    /**
     * Register the authenticated user as an affiliate (idempotent).
     */
    public function store(StoreMarketingAffiliateRequest $request): RedirectResponse
    {
        try {
            DB::transaction(function () use ($request): void {
                $this->affiliates->register($request->user(), $request->affiliateData());
            });
        } catch (Throwable $e) {
            report($e);

            return back()->withInput()->with('error', 'Could not create your affiliate code right now. Please try again.');
        }

        return redirect()->route('marketing.affiliate.dashboard')
            ->with('success', 'You are in! Share your code to earn credit.');
    }

    /**
     * The affiliate's own dashboard (own code + own stats only).
     */
    public function dashboard(Request $request): View
    {
        $payload = $this->affiliates->dashboardFor($request->user());

        abort_if($payload === null, 404);

        return view('marketing.affiliate.dashboard', $payload);
    }
}
