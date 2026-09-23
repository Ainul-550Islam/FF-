<?php

namespace App\Http\Middleware;

use App\Services\MarketingAttributionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 20 — first-party marketing attribution capture.
 *
 * Runs on every web request: derives (or carries) the anonymous visitor id
 * cookie and, when the landing URL carries campaign parameters (UTM /
 * gclid / fbclid / msclkid) or an external referrer, records the
 * first-touch/last-touch attribution row. First-party only — no third-party
 * tags are involved and the visitor id is a random UUID.
 */
class CaptureMarketingAttribution
{
    public function __construct(
        protected MarketingAttributionService $attribution,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Never break the product path for measurement.
        try {
            $anonymousId = $this->attribution->anonymousId($request);
            $this->attribution->capture($request, $anonymousId);

            $minutes = (int) config('marketing.first_party.cookie_minutes', 525600);

            $response->cookie(
                (string) config('marketing.first_party.cookie', 'ff_aid'),
                $anonymousId,
                $minutes,
                '/',
                null,
                $request->isSecure(),
                true // httpOnly — the visitor id is server-side only; JS never needs it.
            );
        } catch (\Throwable $e) {
            report($e);
        }

        return $response;
    }
}
