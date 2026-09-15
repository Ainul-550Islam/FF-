<?php

namespace App\Http\Controllers;

use App\Services\PaymentService;
use DomainException;
use Illuminate\Http\Request;

/**
 * Provider payment webhook endpoint (Phase 08).
 *
 * Authentication is cryptographic (HMAC-SHA256 of the raw body against the
 * configured secret), not session-based — this route sits OUTSIDE the auth
 * middleware. Unknown, unsigned or replayed callbacks are rejected.
 *
 * No live provider currently calls this endpoint (bKash is configured for
 * manual verification); it exists so the callback security + idempotency
 * logic is production-ready the moment credentials are added.
 */
class WebhookController extends Controller
{
    public function __construct(
        protected PaymentService $payments,
    ) {
    }

    public function handle(Request $request, string $provider)
    {
        $signature = (string) $request->header('X-Signature', '');
        $rawBody = (string) $request->getContent();

        $payload = json_decode($rawBody, true);

        if (! is_array($payload)) {
            abort(400, 'Invalid webhook payload.');
        }

        try {
            $payment = $this->payments->handleProviderCallback(
                $provider,
                $payload,
                $signature,
                $rawBody,
            );
        } catch (DomainException $e) {
            $status = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400;

            abort($status, $e->getMessage());
        }

        return response()->json([
            'received' => true,
            'payment_id' => $payment->id,
            'status' => $payment->status,
        ]);
    }
}
