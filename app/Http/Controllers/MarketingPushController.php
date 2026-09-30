<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMarketingPushSubscriptionRequest;
use App\Services\MarketingAttributionService;
use App\Services\MarketingPushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarketingPushController extends Controller
{
    public function subscribe(
        StoreMarketingPushSubscriptionRequest $request,
        MarketingPushService $service,
        MarketingAttributionService $attribution,
    ): JsonResponse {
        $anonymousId = $attribution->anonymousId($request);
        $subscription = $service->register(
            $request->user(),
            $anonymousId,
            $request->subscriptionData(),
            $request->userAgent(),
        );

        return response()->json([
            'ok' => true,
            'id' => $subscription->id,
            'provider' => $subscription->provider,
            'topics' => $subscription->topics ?? [],
            'active' => $subscription->isActive(),
        ]);
    }

    public function unsubscribe(Request $request, MarketingPushService $service): JsonResponse
    {
        $request->validate(['endpoint' => 'required|string']);

        $service->unsubscribe((string) $request->input('endpoint'));

        return response()->json(['ok' => true]);
    }
}
