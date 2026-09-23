<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMarketingPushSubscriptionRequest;
use App\Services\MarketingAttributionService;
use App\Services\MarketingPushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 21 — push re-engagement subscription endpoints.
 *
 * Identity is decided server-side: the authenticated user when signed in,
 * otherwise the anonymous visitor cookie. Secret keys are stored encrypted
 * and never echoed back in any response.
 */
class MarketingPushController extends Controller
{
    public function __construct(
        protected MarketingPushService $push,
        protected MarketingAttributionService $attribution,
    ) {}

    /**
     * Subscribe the current visitor/user (idempotent per endpoint).
     */
    public function subscribe(StoreMarketingPushSubscriptionRequest $request): JsonResponse
    {
        $data = $request->subscriptionData();

        $subscription = $this->push->register(
            $request->user(),
            $this->attribution->anonymousId($request),
            $data,
            (string) $request->userAgent(),
        );

        return response()->json([
            'ok' => true,
            'id' => $subscription->id,
            'provider' => $subscription->provider,
            'topics' => $subscription->topics,
            'active' => $subscription->isActive(),
        ]);
    }

    /**
     * Unsubscribe by endpoint (idempotent).
     */
    public function unsubscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => 'required|string|min:16|max:500',
        ]);

        $this->push->unsubscribe((string) $data['endpoint']);

        return response()->json(['ok' => true]);
    }
}
