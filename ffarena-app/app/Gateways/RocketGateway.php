<?php

namespace App\Gateways;

use App\Contracts\PaymentGatewayInterface;
use App\Models\Payment;
use App\Models\Refund;
use DomainException;

/**
 * Rocket (DBBL Mobile Banking) adapter (Phase 14).
 *
 * Honest by construction: without merchant credentials there is no checkout
 * or callback, so payments fall back to the manual "Send Money" verification
 * flow and refunds are never fabricated.
 */
class RocketGateway implements PaymentGatewayInterface
{
    public function id(): string
    {
        return 'rocket';
    }

    public function label(): string
    {
        return 'Rocket';
    }

    public function configured(): bool
    {
        $config = (array) config('payments.providers.rocket', []);

        return ($config['enabled'] ?? false)
            && ! empty($config['merchant_id'])
            && ! empty($config['merchant_secret']);
    }

    public function supportsCallbacks(): bool
    {
        return false;
    }

    public function supportsRefunds(): bool
    {
        return false;
    }

    public function createExternalPayment(Payment $payment): array
    {
        return [
            'status' => Payment::STATUS_PENDING,
            'provider_reference' => $payment->provider_reference,
            'redirect_url' => null,
        ];
    }

    public function refundExternal(Payment $payment, Refund $refund): array
    {
        throw new DomainException('External Rocket refunds are not available — refunds are recorded platform-side only.');
    }
}
