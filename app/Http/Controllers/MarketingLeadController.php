<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMarketingLeadRequest;
use App\Models\MarketingLead;
use App\Services\MarketingLeadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MarketingLeadController extends Controller
{
    public function store(StoreMarketingLeadRequest $request, MarketingLeadService $service): JsonResponse|RedirectResponse
    {
        $forcedType = $request->is('contact') || $request->routeIs('marketing.contact.store')
            ? MarketingLead::TYPE_CONTACT
            : MarketingLead::TYPE_NEWSLETTER;

        $lead = $service->capture($request->forceType($forcedType), $request);

        if ($request->expectsJson() || $request->isJson()) {
            return response()->json([
                'ok' => true,
                'lead' => [
                    'id' => $lead->id,
                    'email' => $lead->email,
                    'type' => $lead->type,
                ],
            ]);
        }

        return back()->with('success', 'Thank you! Your information has been received.');
    }

    public function unsubscribe(Request $request, string $token, MarketingLeadService $service): JsonResponse|RedirectResponse
    {
        $unsubscribed = $service->unsubscribe($token);

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => $unsubscribed,
                'unsubscribed' => $unsubscribed,
            ]);
        }

        if ($unsubscribed) {
            return redirect()->route('marketing.privacy')->with('success', 'You have been unsubscribed successfully.');
        }

        return redirect()->route('marketing.privacy')->with('error', 'Invalid or expired unsubscribe token.');
    }
}
