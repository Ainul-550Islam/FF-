<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\WebhookDeliveryResource;
use App\Http\Resources\Api\V1\WebhookEndpointResource;
use App\Models\WebhookEndpoint;
use App\Services\WebhookSubscriptionService;
use App\Support\ApiResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 15 — outbound webhook subscription management (admin-only).
 */
class WebhookSubscriptionController extends Controller
{
    public function __construct(
        protected WebhookSubscriptionService $subscriptions,
    ) {
    }

    /**
     * GET /api/v1/admin/webhooks/endpoints
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::data(WebhookEndpointResource::collection($this->subscriptions->all()));
    }

    /**
     * POST /api/v1/admin/webhooks/endpoints — the secret is returned exactly
     * once.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'url' => 'required|string|max:500',
            'description' => 'nullable|string|max:255',
            'events' => 'required|array|min:1',
            'events.*' => 'string',
        ]);

        try {
            $result = $this->subscriptions->create(
                $request->user(),
                $data['url'],
                $data['description'] ?? '',
                $data['events'],
            );
        } catch (DomainException $e) {
            return ApiResponse::error('subscription_refused', $e->getMessage(), [], 422);
        }

        return ApiResponse::created([
            'endpoint' => new WebhookEndpointResource($result['endpoint']),
            'secret' => $result['secret'],
        ], ['note' => 'Store this secret now. It is shown only once.']);
    }

    /**
     * GET /api/v1/admin/webhooks/endpoints/{endpoint}
     */
    public function show(Request $request, WebhookEndpoint $endpoint): JsonResponse
    {
        return ApiResponse::data(new WebhookEndpointResource($endpoint));
    }

    /**
     * POST /api/v1/admin/webhooks/endpoints/{endpoint}/rotate-secret
     */
    public function rotateSecret(Request $request, WebhookEndpoint $endpoint): JsonResponse
    {
        try {
            $secret = $this->subscriptions->rotateSecret($request->user(), $endpoint);
        } catch (DomainException $e) {
            return ApiResponse::error('rotation_refused', $e->getMessage(), [], 422);
        }

        return ApiResponse::data(['secret' => $secret], ['note' => 'Store this secret now. It is shown only once.']);
    }

    /**
     * POST /api/v1/admin/webhooks/endpoints/{endpoint}/toggle
     */
    public function toggle(Request $request, WebhookEndpoint $endpoint): JsonResponse
    {
        $data = $request->validate([
            'status' => 'required|in:active,disabled',
        ]);

        try {
            $endpoint = $this->subscriptions->setStatus($request->user(), $endpoint, $data['status']);
        } catch (DomainException $e) {
            return ApiResponse::error('toggle_refused', $e->getMessage(), [], 422);
        }

        return ApiResponse::data(new WebhookEndpointResource($endpoint));
    }

    /**
     * GET /api/v1/admin/webhooks/endpoints/{endpoint}/deliveries
     */
    public function deliveries(Request $request, WebhookEndpoint $endpoint): JsonResponse
    {
        $deliveries = $endpoint->deliveries()
            ->orderByDesc('id')
            ->paginate(min(100, max(1, (int) $request->query('per_page', 30))));

        return ApiResponse::data(
            WebhookDeliveryResource::collection($deliveries),
            [
                'pagination' => [
                    'current_page' => $deliveries->currentPage(),
                    'last_page' => $deliveries->lastPage(),
                    'per_page' => $deliveries->perPage(),
                    'total' => $deliveries->total(),
                ],
            ]
        );
    }

    /**
     * GET /api/v1/admin/webhooks/events — the event vocabulary.
     */
    public function events(Request $request): JsonResponse
    {
        return ApiResponse::data(['events' => $this->subscriptions->vocabulary()]);
    }
}
