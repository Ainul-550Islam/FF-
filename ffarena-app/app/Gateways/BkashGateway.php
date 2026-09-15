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
 * bKash adapter (Phase 08 manual flow → Phase 20/G2 live Tokenized Checkout).
 *
 * Two honest operating modes, chosen by `configured()`:
 *
 *  1. UNCONFIGURED (no credentials) — the legacy manual flow. The payer
 *     submits their own bKash TrxID and an admin verifies it. The adapter
 *     never reports a provider-confirmed payment and never redirects.
 *
 *  2. CONFIGURED (credentials present) — live bKash Tokenized Checkout:
 *       createExternalPayment()   → create a checkout intent and return the
 *                                   bKash redirect URL (with a signed state);
 *       queryPaymentStatus()      → authoritative server-to-server status;
 *       refundExternal()          → real bKash refund via the tokenized API.
 *
 * Even in live mode the payer redirect is re-verified against bKash before a
 * payment is settled — the redirect query string alone is never trusted.
 */
class BkashGateway implements PaymentGatewayInterface, PaymentStatusQueryable
{
    public function __construct(protected BkashTokenizedClient $client) {}

    /**
     * The bKash API client (exposed for the callback controller's
     * server-side status query and for tests).
     */
    public function client(): BkashTokenizedClient
    {
        return $this->client;
    }

    public function id(): string
    {
        return 'bkash';
    }

    public function label(): string
    {
        return 'bKash';
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
        return $this->configured();
    }

    public function createExternalPayment(Payment $payment): array
    {
        if (! $this->configured()) {
            // Manual flow: the user submits a bKash TrxID that an admin
            // verifies. We never mark a payment provider-confirmed here.
            return [
                'status' => Payment::STATUS_PENDING,
                'provider_reference' => $payment->provider_reference,
                'redirect_url' => null,
            ];
        }

        $invoice = $payment->provider_reference ?? ('FFA-'.$payment->id);

        $callbackUrl = route('payments.callback', ['provider' => $this->id()])
            .'?payment_id='.$payment->id
            .'&state='.urlencode(PaymentCallbackState::build($payment));

        $result = $this->client->createPayment(
            Money::toDecimal($payment->amountMinor()),
            (string) $payment->payer_user_id,
            $callbackUrl,
            (string) $invoice,
        );

        $payment->status = Payment::STATUS_PROCESSING;
        $payment->provider_reference = (string) $result['paymentID'];
        $payment->save();

        return [
            'status' => Payment::STATUS_PROCESSING,
            'provider_reference' => (string) $result['paymentID'],
            'redirect_url' => (string) $result['bkashURL'],
        ];
    }

    /**
     * Authoritative server-to-server status query (Tokenized Checkout).
     *
     * @return array{status: string, reference: string, gateway_transaction_id: ?string, amount: ?string, currency: ?string}
     */
    public function queryPaymentStatus(Payment $payment, array $callbackData = []): array
    {
        if (! $this->configured()) {
            throw new DomainException('bKash is not configured — status cannot be queried.');
        }

        $reference = (string) ($payment->provider_reference ?? '');

        if ($reference === '') {
            throw new DomainException('Payment has no bKash paymentID to query.');
        }

        $data = $this->client->queryPayment($reference);

        $raw = strtolower(trim((string) ($data['transactionStatus'] ?? '')));

        $status = match (true) {
            in_array($raw, ['completed', 'success', 'successful'], true) => 'completed',
            in_array($raw, ['failed', 'declined', 'cancelled', 'canceled'], true) => 'failed',
            default => 'pending',
        };

        return [
            'status' => $status,
            'reference' => $reference,
            'gateway_transaction_id' => isset($data['trxID']) ? (string) $data['trxID'] : null,
            'amount' => isset($data['amount']) ? (string) $data['amount'] : null,
            'currency' => isset($data['currency']) ? strtoupper((string) $data['currency']) : null,
        ];
    }

    public function refundExternal(Payment $payment, Refund $refund): array
    {
        if (! $this->configured()) {
            throw new DomainException('External bKash refunds are not available — refunds are recorded platform-side only.');
        }

        $reference = (string) ($payment->provider_reference ?? '');

        if ($reference === '') {
            throw new DomainException('This payment has no bKash paymentID to refund.');
        }

        $result = $this->client->refund(
            $reference,
            Money::toDecimal($refund->amount_minor),
            (string) ($payment->trx_id ?? $reference),
            (string) ($refund->reason ?? 'Refund'),
        );

        return [
            'status' => 'refunded',
            'provider_reference' => isset($result['refundTrxID']) ? (string) $result['refundTrxID'] : $reference,
            'redirect_url' => null,
        ];
    }
}
