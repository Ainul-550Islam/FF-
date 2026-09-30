<?php

namespace App\Http\Controllers;

use App\Http\Requests\ApproveMarketingAffiliatePayoutRequest;
use App\Http\Requests\RejectMarketingAffiliatePayoutRequest;
use App\Http\Requests\RequestMarketingAffiliatePayoutRequest;
use App\Models\MarketingAffiliate;
use App\Models\MarketingAffiliatePayout;
use App\Services\MarketingAffiliatePayoutService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MarketingAffiliatePayoutController
{
    /**
     * Affiliate-facing index: redirects to dashboard where balance and payout history are displayed.
     */
    public function affiliateIndex(Request $request): RedirectResponse
    {
        return redirect()->route('marketing.affiliate.dashboard');
    }

    /**
     * Submit an affiliate payout request.
     */
    public function requestPayout(
        RequestMarketingAffiliatePayoutRequest $request,
        MarketingAffiliatePayoutService $payoutService
    ): RedirectResponse {
        $affiliate = MarketingAffiliate::query()
            ->where('user_id', $request->user()->id)
            ->where('status', MarketingAffiliate::STATUS_ACTIVE)
            ->firstOrFail();

        try {
            $metadata = [];
            if ($request->filled('notes')) {
                $metadata['notes'] = (string) $request->input('notes');
            }

            $payout = $payoutService->requestPayout(
                $affiliate,
                $request->requestedMinor(),
                $metadata
            );

            return back()->with('success', sprintf(
                'Payout request for ৳%s submitted successfully. Review reference: %s',
                number_format($payout->amount_minor / 100, 2),
                $payout->payout_reference
            ));
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Admin-facing list of all payout requests with status filter.
     */
    public function adminIndex(Request $request, MarketingAffiliatePayoutService $payoutService): View
    {
        $status = $request->query('status');
        $payouts = $payoutService->adminPaginated($status, 15);
        $pendingCount = MarketingAffiliatePayout::query()->pending()->count();

        return view('admin.marketing.affiliates.index', compact('payouts', 'status', 'pendingCount'));
    }

    /**
     * Admin-facing review detail for a specific payout.
     */
    public function adminShow(
        MarketingAffiliatePayout $payout,
        MarketingAffiliatePayoutService $payoutService
    ): View {
        $payout->loadMissing(['affiliate.user', 'reviewer']);
        $earnings = $payoutService->calculateEarnings($payout->affiliate);

        return view('admin.marketing.affiliates.show', compact('payout', 'earnings'));
    }

    /**
     * Admin approves a payout and credits the affiliate's wallet ledger.
     */
    public function approve(
        MarketingAffiliatePayout $payout,
        ApproveMarketingAffiliatePayoutRequest $request,
        MarketingAffiliatePayoutService $payoutService
    ): RedirectResponse {
        try {
            $payoutService->approve($payout, $request->user(), $request->reviewNotes());

            return redirect()->route('admin.marketing.affiliates.payouts.index')
                ->with('success', sprintf('Payout #%d approved and credited to partner wallet.', $payout->id));
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Admin rejects a payout with a mandatory audit reason.
     */
    public function reject(
        MarketingAffiliatePayout $payout,
        RejectMarketingAffiliatePayoutRequest $request,
        MarketingAffiliatePayoutService $payoutService
    ): RedirectResponse {
        try {
            $payoutService->reject(
                $payout,
                $request->user(),
                $request->rejectionReason(),
                $request->reviewNotes()
            );

            return redirect()->route('admin.marketing.affiliates.payouts.index')
                ->with('success', sprintf('Payout #%d rejected.', $payout->id));
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
