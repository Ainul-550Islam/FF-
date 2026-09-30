<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Services\PaymentGatewayManager;
use App\Services\PaymentService;
use App\Support\PaymentCallbackState;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PaymentGatewayCallbackController extends Controller
{
    public function confirm(
        Request $request,
        string $provider,
        PaymentService $payments,
        PaymentGatewayManager $gateways,
    ): RedirectResponse {
        $paymentId = $request->input('payment_id');

        if (! $paymentId && $request->has('tran_id')) {
            $tranId = (string) $request->input('tran_id');
            if (preg_match('/(\d+)$/', $tranId, $matches)) {
                $paymentId = (int) $matches[1];
            }
        }

        if (! $paymentId && $request->has('provider_reference')) {
            $payment = Payment::with(['tournament', 'team'])
                ->where('provider_reference', $request->input('provider_reference'))
                ->first();
        } else {
            $payment = Payment::with(['tournament', 'team'])->findOrFail($paymentId);
        }

        if ($request->has('state')) {
            if (! PaymentCallbackState::verify($payment, (string) $request->input('state'))) {
                abort(403, 'Forged state token.');
            }
        }

        try {
            $gateway = $gateways->gateway($provider);
            $result = $gateway->queryPaymentStatus($payment, $request->all());

            if (($result['status'] ?? '') === 'completed') {
                $payments->confirmProviderPayment($payment, $result);
            } elseif (($result['status'] ?? '') === 'failed') {
                $payments->markGatewayFailed($payment, (string) ($result['reference'] ?? ''), 'Gateway reported payment failed');
            }
        } catch (DomainException) {
            // Non-fatal or already processed
        }

        return redirect()->route('payment.pending', [
            $payment->tournament,
            $payment->team,
            $payment,
        ]);
    }
}
