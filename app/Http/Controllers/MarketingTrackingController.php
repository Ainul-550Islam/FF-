<?php

namespace App\Http\Controllers;

use App\Models\MarketingConsent;
use App\Services\MarketingAttributionService;
use App\Services\MarketingTrackingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarketingTrackingController extends Controller
{
    public function event(Request $request, MarketingTrackingService $tracking): JsonResponse
    {
        $result = $tracking->recordFromRequest($request);

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    public function consent(
        Request $request,
        MarketingAttributionService $attribution,
    ): JsonResponse {
        $data = $request->validate([
            'analytics' => 'required|boolean',
            'marketing' => 'required|boolean',
            'withdraw' => 'nullable|boolean',
            'policy_version' => 'nullable|string|max:32',
        ]);

        $anonymousId = $attribution->anonymousId($request);
        $withdrawn = (bool) ($data['withdraw'] ?? false);

        $consent = MarketingConsent::create([
            'anonymous_id' => $anonymousId,
            'user_id' => $request->user()?->id,
            'analytics_consent' => (bool) $data['analytics'],
            'marketing_consent' => (bool) $data['marketing'],
            'policy_version' => $data['policy_version'] ?? 'v1',
            'granted_at' => $withdrawn ? null : now(),
            'withdrawn_at' => $withdrawn ? now() : null,
            'created_at' => now(),
        ]);

        $payload = [
            'analytics' => $consent->analytics_consent,
            'marketing' => $consent->marketing_consent,
            'withdrawn' => $withdrawn,
            'version' => $consent->policy_version,
            'timestamp' => now()->toISOString(),
        ];

        $cookie = cookie(
            'ff_consent',
            json_encode($payload),
            60 * 24 * 365,
            '/',
            null,
            false,
            false,
        );

        return response()->json([
            'ok' => true,
            'consent' => $payload,
        ])->withCookie($cookie);
    }
}
