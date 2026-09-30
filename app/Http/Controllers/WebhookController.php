<?php

namespace App\Http\Controllers;

use App\Services\WebhookIngressService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
