<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMarketingLeadRequest;
use App\Models\MarketingLead;
use App\Services\MarketingLeadService;
use Illuminate\Http\RedirectResponse;
use Throwable;

/**
 * Phase 20 — public lead capture endpoints (newsletter / contact /
 * organizer enquiries).
 *
 * Public, rate-limited, CSRF-protected. Validation failures re-render the
 * form with errors; success redirects back with a flash — the same pattern
 * every other public form in this codebase uses.
 */
class MarketingLeadController extends Controller
{
    public function __construct(
        protected MarketingLeadService $leads,
    ) {}

    public function store(StoreMarketingLeadRequest $request): RedirectResponse
    {
        // The posted type must match the endpoint that accepted it.
        $type = $request->routeIs('marketing.contact.store')
            ? MarketingLead::TYPE_CONTACT
            : MarketingLead::TYPE_NEWSLETTER;

        try {
            $this->leads->capture($request->forceType($type), $request);
        } catch (Throwable $e) {
            report($e);

            return back()->withInput()->with('error', 'Could not save your subscription right now. Please try again.');
        }

        return back()->with('success', $type === MarketingLead::TYPE_CONTACT
            ? 'Message received — we will get back to you soon.'
            : 'You are on the list! Watch your inbox for tournament drops.');
    }

    public function unsubscribe(string $token): RedirectResponse
    {
        $unsubscribed = $this->leads->unsubscribe($token);

        return redirect()->route('home')->with(
            $unsubscribed ? 'success' : 'error',
            $unsubscribed
                ? 'You have been unsubscribed. Sorry to see you go!'
                : 'That unsubscribe link is not valid.'
        );
    }
}
