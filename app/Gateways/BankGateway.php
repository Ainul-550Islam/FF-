<?php

namespace App\Gateways;

use App\Contracts\PaymentGatewayInterface;
use App\Models\Payment;
use App\Models\Refund;
use DomainException;

/**
 * Manual bank-transfer adapter (Phase 14).
 *
 * Bank transfer is a manual method: the user transfers and submits a
 * reference which an admin verifies. There is never a provider callback or
 * an external refund to fake.
 */
class BankGateway implements PaymentGatewayInterface
{
    public function id(): string
    {
        return 'bank';
    }

    public function label(): string
    {
        return 'Bank Transfer';
    }

    public function configured(): bool
    {
        $config = (array) config('payments.providers.bank', []);

        return $config['enabled'] ?? false;
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
        throw new DomainException('Bank transfers are refunded manually by an admin.');
    }
}
