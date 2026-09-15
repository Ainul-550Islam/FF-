<?php

namespace App\Gateways;

use App\Contracts\PaymentGatewayInterface;
use App\Contracts\PaymentStatusQueryable;
use App\Models\Payment;
use App\Models\Refund;
use DomainException;

/**
 * SSLCommerz aggregator adapter (Phase 14 stub → Phase 20/G2-C live hosted
 * checkout).
 *
 * CONFIGURED mode (store credentials present):
 *   createExternalPayment()  → create a hosted session and return
 *                              `GatewayPageURL` (with a signed state);
 *   queryPaymentStatus()     → verify the IPN MD5 signature (when present)
 *                              and then re-validate server-to-server via the
 *                              order-validation endpoint — the provider's own
 *                              guidance is that the MD5 is "necessary, not
 *                              sufficient";
 *   refundExternal()         → real refund against the bank transaction id.
 *
 * UNCONFIGURED mode: manual fallback — the payer records a TrxID for admin
 * verification, and no external refund is fabricated.
 *
 * The server-to-server sequence is shared with CardGateway via
 * SslCommerzCheckout (cards are processed through the same aggregator).
 */
class SslCommerzGateway implements PaymentGatewayInterface, PaymentStatusQueryable
{
    public function __construct(protected SslCommerzCheckout $checkout, protected SslCommerzClient $client) {}

    public function client(): SslCommerzClient
    {
        return $this->client;
    }

    public function id(): string
    {
        return 'sslcommerz';
    }

    public function label(): string
    {
        return 'SSLCommerz';
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
            return [
                'status' => Payment::STATUS_PENDING,
                'provider_reference' => $payment->provider_reference,
                'redirect_url' => null,
            ];
        }

        return $this->checkout->start($payment, $this->id(), 'FFA');
    }

    /**
     * Authoritative status: IPN signature check (when present) followed by the
     * server-to-server order-validation endpoint.
     *
     * @return array{status: string, reference: string, gateway_transaction_id: ?string, amount: ?string, currency: ?string}
     */
    public function queryPaymentStatus(Payment $payment, array $callbackData = []): array
    {
        if (! $this->configured()) {
            throw new DomainException('SSLCommerz is not configured — status cannot be queried.');
        }

        return $this->checkout->status($payment, $callbackData);
    }

    public function refundExternal(Payment $payment, Refund $refund): array
    {
        if (! $this->configured()) {
            throw new DomainException('External SSLCommerz refunds are not available — refunds are recorded platform-side only.');
        }

        return $this->checkout->refund($payment, $refund);
    }
}
