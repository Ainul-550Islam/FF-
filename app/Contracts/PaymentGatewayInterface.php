<?php

namespace App\Contracts;

use App\Models\Payment;
use App\Models\Refund;
use DomainException;

/**
 * Provider abstraction for payment gateways (Phase 08, extended Phase 14).
 *
 * The application never invents a successful external transaction: adapters
 * must honestly report what they can and cannot do. `configured()` tells the
 * UI and the checkout flow whether a provider's credentials are present; when
 * they are not, the adapter reports `supportsCallbacks()`/`supportsRefunds()`
 * as false and throws on unsupported operations, so the platform can still
 * distinguish locally created, manually verified and provider-confirmed
 * payments.
 */
interface PaymentGatewayInterface
{
    /**
     * The stable provider identifier (stored on payments.provider).
     */
    public function id(): string;

    /**
     * A short human label for the provider.
     */
    public function label(): string;

    /**
     * Whether the provider has the credentials/config it needs to operate.
     * When false, the checkout UI shows "Provider is not configured".
     */
    public function configured(): bool;

    /**
     * Whether this provider pushes server-to-server callbacks/webhooks.
     */
    public function supportsCallbacks(): bool;

    /**
     * Whether this provider can execute external refunds.
     */
    public function supportsRefunds(): bool;

    /**
     * Create an external payment/checkout intent for the given payment.
     *
     * @return array{status: string, provider_reference: ?string, redirect_url: ?string}
     *
     * @throws DomainException when the operation is not supported (e.g. no
     *                          live credentials).
     */
    public function createExternalPayment(Payment $payment): array;

    /**
     * Refund an external payment.
     *
     * @throws DomainException when external refunds are not supported.
     */
    public function refundExternal(Payment $payment, Refund $refund): array;
}
