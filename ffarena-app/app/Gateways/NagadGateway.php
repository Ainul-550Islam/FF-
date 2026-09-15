<?php

namespace App\Gateways;

use App\Contracts\PaymentGatewayInterface;
use App\Contracts\PaymentStatusQueryable;
use App\Models\Payment;
use App\Models\Refund;
use App\Support\Money;
use App\Support\PaymentCallbackState;
use DomainException;

/**
 * Nagad adapter (Phase 14 manual flow → Phase 20/G2-B live Checkout API).
 *
 * Two honest operating modes, chosen by `configured()`:
 *
 *  1. UNCONFIGURED (no merchant credentials/keys) — the legacy manual flow.
 *     The payer submits their own Nagad TrxID and an admin verifies it.
 *
 *  2. CONFIGURED — live Nagad Checkout:
 *       createExternalPayment()  → initialize a checkout and return Nagad's
 *                                  hosted `callBackUrl` (with a signed state);
 *       queryPaymentStatus()     → authoritative server-to-server `verify`;
 *       refundExternal()         → still refused: Nagad's standard Checkout
 *                                  API exposes no refund endpoint, so we stay
 *                                  honest and record refunds platform-side.
 */
class NagadGateway implements PaymentGatewayInterface, PaymentStatusQueryable
{
    public function __construct(protected NagadClient $client) {}

    public function client(): NagadClient
    {
        return $this->client;
    }

    public function id(): string
    {
        return 'nagad';
    }

    public function label(): string
    {
        return 'Nagad';
    }

    public function configured(): bool
    {
        return $this->client->configured();
    }

    public function supportsCallbacks(): bool
    {
        return $this->configured();
    }

    public function supportsRefunds(): bool
    {
        // Nagad's standard Checkout API has no refund endpoint.
        return false;
    }

    public function createExternalPayment(Payment $payment): array
    {
        if (! $this->configured()) {
            // Manual fallback: the admin verifies the user's Nagad TrxID.
            return [
                'status' => Payment::STATUS_PENDING,
                'provider_reference' => $payment->provider_reference,
                'redirect_url' => null,
            ];
        }

        $orderId = $payment->provider_reference ?? ('FFA-'.$payment->id);

        $callbackUrl = route('payments.callback', ['provider' => $this->id()])
            .'?payment_id='.$payment->id
            .'&state='.urlencode(PaymentCallbackState::build($payment));

        $result = $this->client->initialize(
            Money::toDecimal($payment->amountMinor()),
            (string) $orderId,
            $callbackUrl,
        );

        $paymentRefId = isset($result['paymentRefId']) && $result['paymentRefId'] !== ''
            ? (string) $result['paymentRefId']
            : null;

        $payment->status = Payment::STATUS_PROCESSING;

        if ($paymentRefId !== null) {
            $payment->provider_reference = $paymentRefId;
        }

        $payment->save();

        return [
            'status' => Payment::STATUS_PROCESSING,
            'provider_reference' => $paymentRefId ?? $payment->provider_reference,
            'redirect_url' => (string) $result['callBackUrl'],
        ];
    }

    /**
     * Authoritative server-to-server status via the Nagad `verify` endpoint.
     *
     * @return array{status: string, reference: string, gateway_transaction_id: ?string, amount: ?string, currency: ?string}
     */
    public function queryPaymentStatus(Payment $payment, array $callbackData = []): array
    {
        if (! $this->configured()) {
            throw new DomainException('Nagad is not configured — status cannot be queried.');
        }

        $reference = (string) ($payment->provider_reference ?? '');

        if ($reference === '') {
            throw new DomainException('Payment has no Nagad paymentRefId to verify.');
        }

        $data = $this->client->verify($reference);

        $raw = strtolower(trim((string) ($data['status'] ?? '')));

        $status = match (true) {
            in_array($raw, ['success', 'successful', 'completed', 'paid'], true) => 'completed',
            in_array($raw, ['failed', 'cancelled', 'canceled', 'declined'], true) => 'failed',
            default => 'pending',
        };

        return [
            'status' => $status,
            'reference' => $reference,
            'gateway_transaction_id' => isset($data['issuerPaymentRefNo']) ? (string) $data['issuerPaymentRefNo'] : null,
            'amount' => isset($data['amount']) ? (string) $data['amount'] : null,
            'currency' => 'BDT',
        ];
    }

    public function refundExternal(Payment $payment, Refund $refund): array
    {
        throw new DomainException('External Nagad refunds are not available — refunds are recorded platform-side only.');
    }
}
