<?php

namespace App\Http\Controllers;

use App\Exceptions\WebhookSignatureRejected;
use App\Services\WebhookIngressService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 08 legacy provider webhook endpoint (`POST /webhooks/payments/{provider}`).
 *
 * Verification, timestamp tolerance, event-id idempotency and business
 * validation all live in WebhookIngressService; this controller only maps the
 * outcome to HTTP.
 *
 * GAP-10 A5: a rejected signature answers 400 on this surface — the status the
 * Phase 08 integration and `PaymentSecurityTest` expect — while the Phase 15
 * API surface keeps answering 401 through `WebhookSignatureRejected`'s
 * inherited code. Both are refusals: nothing settles and the attempt is
 * recorded as a WebhookEvent either way.
 */
class WebhookController extends Controller
{
    public function handle(
        Request $request,
        string $provider,
        WebhookIngressService $ingress,
    ): JsonResponse {
        try {
            $result = $ingress->handle($request, $provider);

            return response()->json([
                'status' => 'ok',
                'event_id' => $result['event']->id ?? null,
                'replay' => $result['replay'] ?? false,
            ]);
        } catch (WebhookSignatureRejected $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], WebhookSignatureRejected::LEGACY_STATUS);
        } catch (DomainException $e) {
            $statusCode = $e->getCode();
            if ($statusCode < 400 || $statusCode >= 600) {
                $statusCode = 400;
            }

            return response()->json([
                'error' => $e->getMessage(),
            ], $statusCode);
        }
    }
}
