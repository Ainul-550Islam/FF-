<?php

namespace App\Http\Controllers;

use App\Contracts\PaymentStatusQueryable;
use App\Models\Payment;
use App\Services\PaymentGatewayManager;
use App\Services\PaymentService;
use App\Support\PaymentCallbackState;
use DomainException;
use Illuminate\Http\Request;
use Throwable;

/**
 * Payer return endpoint for hosted payment gateways (Phase 20/G2).
 *
 * The provider redirects the payer here with its own query parameters
 * (paymentID, status, …). Those parameters are attacker-influenced, so they
 * are NEVER trusted on their own:
 *
 *   1. the `state` token (minted by us at checkout) must verify;
 *   2. the gateway must implement PaymentStatusQueryable and its
 *      server-to-server answer is authoritative;
 *   3. amount + currency are re-checked in PaymentService before settling.
 *
 * This route is outside the auth middleware (the payer returns from an
 * external domain and may have lost the session); it is protected by the
 * signed state instead. The handler is idempotent — refresh/replay is safe.
 */
class PaymentGatewayCallbackController extends Controller
{
    public function __construct(
        protected PaymentService $payments,
        protected PaymentGatewayManager $gateways,
    ) {}

    public function confirm(Request $request, string $provider)
    {
        // Hosted gateways return the payer by either GET redirect (bKash,
        // Nagad) or POST form (SSLCommerz); `input()` covers both.
        $paymentId = (int) $request->input('payment_id', 0);
        $state = (string) $request->input('state', '');
        $gatewayPaymentId = (string) $request->input('paymentID', '');

        $payment = $paymentId > 0 ? Payment::find($paymentId) : null;

        abort_unless($payment !== null, 404, 'Unknown payment.');
        abort_unless($payment->provider === $provider, 404, 'Provider mismatch.');
        abort_unless(PaymentCallbackState::verify($payment, $state), 403, 'Invalid callback state.');

        // Defense in depth: if the gateway echoes its own payment id back,
        // it must match the reference we stored at checkout.
        if ($gatewayPaymentId !== '' && $payment->provider_reference !== null && $payment->provider_reference !== $gatewayPaymentId) {
            abort(400, 'Payment reference mismatch.');
        }

        $tournament = $payment->tournament;
        $team = $payment->team;

        // Already settled → idempotent success redirect.
        if ($payment->isSuccessful()) {
            return redirect()->route('payment.pending', [$tournament, $team, $payment]);
        }

        $gateway = $this->gateways->gateway($provider);

        if (! $gateway instanceof PaymentStatusQueryable) {
            abort(404, 'This provider does not support server-side status queries.');
        }

        try {
            $result = $gateway->queryPaymentStatus($payment, $request->all());
        } catch (Throwable $e) {
            // Could not reach/verify with the provider — never assume success.
            return redirect()
                ->route('payment.pending', [$tournament, $team, $payment])
                ->with('error', 'We could not confirm your payment with '.$gateway->label().' yet. Please refresh in a moment.');
        }

        if ($result['status'] === 'completed') {
            try {
                $this->payments->confirmProviderPayment($payment, $result);
            } catch (DomainException $e) {
                return redirect()
                    ->route('payment.pending', [$tournament, $team, $payment])
                    ->with('error', $e->getMessage());
            }

            return redirect()->route('payment.pending', [$tournament, $team, $payment]);
        }

        if ($result['status'] === 'failed') {
            $this->payments->markGatewayFailed(
                $payment,
                (string) ($result['reference'] ?? ''),
                'gateway reported failure',
            );

            return redirect()
                ->route('payment.pending', [$tournament, $team, $payment])
                ->with('error', 'Your payment was declined by '.$gateway->label().'.');
        }

        // Still pending — the payer may not have completed the checkout.
        return redirect()->route('payment.pending', [$tournament, $team, $payment]);
    }
}
