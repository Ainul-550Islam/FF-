<?php

namespace App\Gateways;

use App\Contracts\PaymentGatewayInterface;
use App\Contracts\PaymentStatusQueryable;
use App\Models\Payment;
use App\Models\Refund;
use DomainException;

/**
 * Card adapter (Phase 14 stub → Phase 20/G2-D hosted card checkout).
 *
 * FF Arena does not hold a direct card-acquiring agreement; cards are
 * processed through the SSLCommerz aggregator's hosted page. This adapter is
 * therefore a thin, honest delegation:
 *
 *   configured()           → true only when CARD_GATEWAY=sslcommerz AND the
 *                            SSLCommerz store credentials are present;
 *   createExternalPayment()→ hosted card session (same server-to-server
 *                            sequence as SSLCommerz, owned by
 *                            SslCommerzCheckout);
 *   queryPaymentStatus()   → IPN signature check + order validation;
 *   refundExternal()       → refund via the aggregator.
 *
 * When not configured it refuses (a card payment cannot be manually verified),
 * so the checkout UI never presents an unbacked card option.
 */
class CardGateway implements PaymentGatewayInterface, PaymentStatusQueryable
{
    public function __construct(protected SslCommerzCheckout $checkout, protected SslCommerzClient $client) {}

    public function id(): string
    {
        return 'card';
    }

    public function label(): string
    {
        return 'Card';
    }

    /**
     * The hosted backend slug that processes cards.
     */
    public function backend(): string
    {
        return (string) config('payments.providers.card.gateway', 'sslcommerz');
    }

    public function configured(): bool
    {
        return $this->backend() === 'sslcommerz' && $this->client->configured();
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
            throw new DomainException('Card payments are not configured. Choose another payment method.');
        }

        return $this->checkout->start($payment, $this->id(), 'CARD');
    }

    /**
     * @return array{status: string, reference: string, gateway_transaction_id: ?string, amount: ?string, currency: ?string}
     */
    public function queryPaymentStatus(Payment $payment, array $callbackData = []): array
    {
        if (! $this->configured()) {
            throw new DomainException('Card payments are not configured — status cannot be queried.');
        }

        return $this->checkout->status($payment, $callbackData);
    }

    public function refundExternal(Payment $payment, Refund $refund): array
    {
        if (! $this->configured()) {
            throw new DomainException('External card refunds are not configured.');
        }

        return $this->checkout->refund($payment, $refund);
    }
}
