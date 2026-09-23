<?php

namespace App\Http\Controllers;

use App\Models\MarketingConsent;
use App\Services\MarketingAttributionService;
use App\Services\MarketingTrackingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Phase 20 — public marketing tracking + consent endpoints.
 *
 * POST /marketing/event    → consented first-party conversion events (§40
 *                            taxonomy, strictly validated).
 * POST /marketing/consent  → the consent decision (grant/change/withdraw),
 *                            persisted as an append-only ledger row and
 *                            echoed back as the consent cookie.
 */
class MarketingTrackingController extends Controller
{
    public function __construct(
        protected MarketingTrackingService $tracking,
        protected MarketingAttributionService $attribution,
    ) {}

    public function event(Request $request): JsonResponse
    {
        $result = $this->tracking->recordFromRequest($request);

        if (! $result['ok']) {
            return response()->json(['ok' => false, 'error' => $result['error']], 422);
        }

        return response()->json(['ok' => true]);
    }

    public function consent(Request $request): JsonResponse
    {
        $data = $request->validate([
            'analytics' => 'required|boolean',
            'marketing' => 'required|boolean',
            'withdraw' => 'nullable|boolean',
        ]);

        $anonymousId = $this->attribution->anonymousId($request);
        $withdraw = (bool) ($data['withdraw'] ?? false);

        try {
            MarketingConsent::create([
                'anonymous_id' => $anonymousId,
                'user_id' => $request->user()?->id,
                'analytics_consent' => $withdraw ? false : (bool) $data['analytics'],
                'marketing_consent' => $withdraw ? false : (bool) $data['marketing'],
                'policy_version' => (string) config('marketing.consent.policy_version'),
                'granted_at' => $withdraw ? null : now(),
                'withdrawn_at' => $withdraw ? now() : null,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['ok' => false, 'error' => 'Could not store consent.'], 500);
        }

        $minutes = (int) config('marketing.consent.cookie_minutes', 259200);

        $payload = $withdraw
            ? ['analytics' => false, 'marketing' => false, 'v' => (string) config('marketing.consent.policy_version'), 'withdrawn' => true]
            : ['analytics' => (bool) $data['analytics'], 'marketing' => (bool) $data['marketing'], 'v' => (string) config('marketing.consent.policy_version')];

        return response()
            ->json(['ok' => true, 'consent' => $payload])
            ->cookie(
                (string) config('marketing.consent.cookie', 'ff_consent'),
                (string) json_encode($payload, JSON_UNESCAPED_SLASHES),
                $minutes,
                '/',
                null,
                $request->isSecure(),
                false // the layout JS must read the consent state to gate trackers.
            );
    }
}
