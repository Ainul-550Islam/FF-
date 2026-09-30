<?php

namespace App\Http\Controllers;

use App\Http\Requests\ApplyMarketingPromoCodeRequest;
use App\Services\MarketingPromoCodeService;
use Illuminate\Http\JsonResponse;

class MarketingPromoCodeController extends Controller
{
    public function apply(
        ApplyMarketingPromoCodeRequest $request,
        MarketingPromoCodeService $service,
    ): JsonResponse {
        $user = $request->user();

        if (! $user) {
            return response()->json(['ok' => false, 'error' => 'Unauthenticated.'], 401);
        }

        $result = $service->apply($request->promoContext(), $user);

        return response()->json($result, $result['ok'] ? 200 : 422);
    }
}
