<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\WebhookIngressService;
use App\Support\ApiResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 15 — inbound provider webhooks.
 *
 * Signature verification, timestamp tolerance, event-id idempotency and
 * business validation all live in WebhookIngressService (which delegates
 * `payment.*` events to the Phase 08 PaymentService). This controller only
 * maps the outcome to HTTP.
 */
class WebhookInboundController extends Controller
{
    public function __construct(
        protected WebhookIngressService $ingress,
    ) {
    }

    /**
     * POST /api/v1/webhooks/inbound/{provider}
     */
    public function handle(Request $request, string $provider): JsonResponse
    {
        try {
            $result = $this->ingress->handle($request, $provider);
        } catch (DomainException $e) {
            return ApiResponse::error(
                'webhook_rejected',
                $e->getMessage(),
                [],
                $e->getCode() >= 400 && $e->getCode() < 600 ? (int) $e->getCode() : 400
            );
        }

        $event = $result['event'];

        return ApiResponse::data([
            'status' => $result['replay'] ? 'replayed' : $event->status,
            'event_id' => $event->external_event_id,
            'replay' => (bool) $result['replay'],
            'payment' => $result['payment'] ?? null,
        ]);
    }
}
