<?php

namespace App\Http\Controllers;

use App\Http\Requests\ApplyMarketingPromoCodeRequest;
use App\Services\MarketingPromoCodeService;
use Illuminate\Http\JsonResponse;

/**
 * Phase 21 — promo code application endpoint.
 *
 * Auth-only, rate-limited. The client sends a code and an optional
 * tournament context — every amount in the response is computed
 * server-side from the tournament's own entry fee.
 */
class MarketingPromoCodeController extends Controller
{
    public function __construct(
        protected MarketingPromoCodeService $promos,
    ) {}

    public function apply(ApplyMarketingPromoCodeRequest $request): JsonResponse
    {
        $result = $this->promos->apply($request->promoContext(), $request->user());

        if (! ($result['ok'] ?? false)) {
            return response()->json([
                'ok' => false,
                'error' => (string) ($result['error'] ?? 'This promo code cannot be applied.'),
            ], 422);
        }

        return response()->json($result);
    }
}
