<?php

namespace App\Http\Controllers;

use App\Contracts\PaymentStatusQueryable;
use App\Models\Payment;
use App\Services\PaymentGatewayManager;
use App\Services\PaymentService;
use App\Support\PaymentCallbackState;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentGatewayCallbackController extends Controller
{
    /**
     * Hosted-gateway payer return (GET/POST /payments/callback/{provider}).
     *
     * AUDIT FIX (2026-10-07, FIX-03):
     *  1. The `state` token used to be verified ONLY when present
     *     (`if ($request->has('state'))`), so omitting it skipped the HMAC
     *     check entirely. It is now MANDATORY — a redirect without a valid
     *     state token is rejected before any gateway query happens.
     *  2. `payment_id` / `provider_reference` are validated (no raw input into
     *     queries, no `findOrFail` oracle that lets bots enumerate payment
     *     ids or burn provider API quota).
     *  3. Unknown providers fail closed instead of throwing an unhandled 500.
     *
     * The gateway truth is still re-queried server-to-server; the state token
     * only proves the redirect belongs to this payment.
     */
    public function confirm(
        Request $request,
        string $provider,
        PaymentService $payments,
        PaymentGatewayManager $gateways,
    ): RedirectResponse {
        try {
            $gateway = $gateways->gateway($provider);
        } catch (DomainException) {
            abort(404, 'Unknown payment provider.');
        }

        $data = $request->validate([
            'payment_id' => 'nullable|integer|min:1',
            'provider_reference' => 'nullable|string|max:128',
            'tran_id' => 'nullable|string|max:128',
            // Required by default (fail closed). If a provider is PROVEN to
            // strip query params from the redirect, ops may set
            // PAYMENTS_CALLBACK_REQUIRE_STATE=false — the request is then
            // still verified server-to-server against the stored provider
            // reference + amount/currency match, and the bypass is logged.
            'state' => 'nullable|string|max:512',
        ]);

        $payment = $this->resolvePayment($data);

        if ($payment === null) {
            // Generic failure: do not reveal whether the id exists (no
            // enumeration oracle), and never query the provider.
            Log::warning('payment-callback: unresolvable payment reference', [
                'provider' => $provider,
                'ip' => $request->ip(),
            ]);

            return redirect()->route('home')
                ->with('error', 'We could not verify that payment return. If money left your account, contact support.');
        }

        // The redirect must carry a state token minted for THIS payment.
        // Previously `if ($request->has('state'))` meant omitting the token
        // skipped verification entirely. Now a missing/invalid token is
        // rejected unless the ops escape hatch is explicitly enabled.
        $state = (string) ($data['state'] ?? '');
        $stateOk = $state !== '' && PaymentCallbackState::verify($payment, $state);

        if (! $stateOk && (bool) config('payments.callback.require_state', true)) {
            Log::warning('payment-callback: missing or forged state token', [
                'payment_id' => $payment->id,
                'provider' => $provider,
                'ip' => $request->ip(),
            ]);

            abort(403, 'Forged state token.');
        }

        if (! $stateOk) {
            Log::warning('payment-callback: state check bypassed by ops config', [
                'payment_id' => $payment->id,
                'provider' => $provider,
                'ip' => $request->ip(),
            ]);
        }

        // Providers gap (2026-10-07, P1): manual methods (Rocket, bank
        // transfer) have no hosted checkout and no server-to-server status
        // API — they settle through admin verification of the payer's
        // TrxID. A return URL for them carries nothing to confirm, so land
        // on the pending screen (which hosts the manual flow) instead of
        // fataling on an undefined query method.
        if (! $gateway instanceof PaymentStatusQueryable || ! $gateway->supportsCallbacks()) {
            return redirect()->route('payment.pending', [
                $payment->tournament,
                $payment->team,
                $payment,
            ])->with('info', 'This payment method is verified manually — submit your transaction reference below.');
        }

        try {
            $result = $gateway->queryPaymentStatus($payment, $request->all());

            if (($result['status'] ?? '') === 'completed') {
                $payments->confirmProviderPayment($payment, $result);
            } elseif (($result['status'] ?? '') === 'failed') {
                $payments->markGatewayFailed($payment, (string) ($result['reference'] ?? ''), 'Gateway reported payment failed');
            }
        } catch (DomainException) {
            // Non-fatal or already processed.
        }

        return redirect()->route('payment.pending', [
            $payment->tournament,
            $payment->team,
            $payment,
        ]);
    }

    /**
     * Resolve the payment from validated references only. Returns null
     * instead of 404ing so the caller can answer generically.
     */
    protected function resolvePayment(array $data): ?Payment
    {
        if (! empty($data['payment_id'])) {
            return Payment::with(['tournament', 'team'])->find((int) $data['payment_id']);
        }

        if (! empty($data['tran_id']) && preg_match('/(\d+)$/', (string) $data['tran_id'], $matches)) {
            $payment = Payment::with(['tournament', 'team'])->find((int) $matches[1]);

            if ($payment !== null) {
                return $payment;
            }
        }

        if (! empty($data['provider_reference'])) {
            return Payment::with(['tournament', 'team'])
                ->where('provider_reference', (string) $data['provider_reference'])
                ->first();
        }

        return null;
    }
}
