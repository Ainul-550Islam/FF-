<?php

namespace App\Gateways;

use App\Models\Payment;
use App\Models\Refund;
use App\Support\Money;
use App\Support\PaymentCallbackState;
use DomainException;

/**
 * Phase 20/G2-C — the shared SSLCommerz hosted-checkout flow.
 *
 * Both the dedicated `sslcommerz` provider and the `card` provider (cards are
 * processed through the SSLCommerz aggregator) need the exact same
 * server-to-server sequence. This class owns that sequence so the two
 * adapters stay thin and consistent:
 *
 *   start()  → create a hosted session and return the GatewayPageURL;
 *   status() → verify the IPN MD5 signature (when present), then re-validate
 *              server-to-server via the order-validation endpoint;
 *   refund() → external refund against the bank transaction id.
 */
class SslCommerzCheckout
{
    public function __construct(protected SslCommerzClient $client) {}

    /**
     * @return array{status: string, provider_reference: ?string, redirect_url: ?string}
     */
    public function start(Payment $payment, string $provider, string $tranIdPrefix): array
    {
        $tranId = $tranIdPrefix.$payment->id;

        $callbackUrl = route('payments.callback', ['provider' => $provider])
            .'?payment_id='.$payment->id
            .'&state='.urlencode(PaymentCallbackState::build($payment));

        $captain = $payment->team?->captain;

        $result = $this->client->createSession([
            'total_amount' => Money::toDecimal($payment->amountMinor()),
            'currency' => 'BDT',
            'tran_id' => $tranId,
            'success_url' => $callbackUrl,
            'fail_url' => $callbackUrl,
            'cancel_url' => $callbackUrl,
            'emi_option' => '0',
            'cus_name' => $payment->team?->captain_name ?? 'FF Arena Player',
            'cus_email' => $captain?->email ?? 'player@ffarena.local',
            'cus_phone' => $payment->team?->phone ?? '01700000000',
            'product_name' => 'FF Arena entry fee',
            'product_category' => 'eSports',
            'product_profile' => 'general',
            'shipping_method' => 'NO',
            'num_of_item' => '1',
        ]);

        $payment->status = Payment::STATUS_PROCESSING;
        $payment->provider_reference = isset($result['sessionkey']) ? (string) $result['sessionkey'] : $tranId;
        $payment->trx_id = $tranId;
        $payment->save();

        return [
            'status' => Payment::STATUS_PROCESSING,
            'provider_reference' => $payment->provider_reference,
            'redirect_url' => (string) $result['GatewayPageURL'],
        ];
    }

    /**
     * @return array{status: string, reference: string, gateway_transaction_id: ?string, amount: ?string, currency: ?string}
     */
    public function status(Payment $payment, array $callbackData = []): array
    {
        if (isset($callbackData['verify_sign']) || isset($callbackData['verify_key'])) {
            if (! $this->client->verifyIpnHash($callbackData)) {
                throw new DomainException('Invalid SSLCommerz signature.');
            }
        }

        $valId = (string) ($callbackData['val_id'] ?? '');

        if ($valId === '') {
            throw new DomainException('SSLCommerz callback is missing val_id.');
        }

        $data = $this->client->validate($valId);

        $raw = strtolower(trim((string) ($data['status'] ?? '')));

        $status = match (true) {
            in_array($raw, ['valid', 'validated', 'success', 'successful'], true) => 'completed',
            in_array($raw, ['failed', 'cancelled', 'canceled', 'expired', 'unattempted', 'invalid_transaction', 'invalid'], true) => 'failed',
            default => 'pending',
        };

        return [
            'status' => $status,
            'reference' => isset($data['tran_id']) ? (string) $data['tran_id'] : $valId,
            'gateway_transaction_id' => isset($data['bank_tran_id']) ? (string) $data['bank_tran_id'] : null,
            'amount' => isset($data['amount']) ? (string) $data['amount'] : null,
            'currency' => isset($data['currency_type']) ? strtoupper((string) $data['currency_type']) : 'BDT',
        ];
    }

    /**
     * @return array{status: string, provider_reference: string, redirect_url: null}
     */
    public function refund(Payment $payment, Refund $refund): array
    {
        $bankTranId = (string) ($payment->trx_id ?? '');

        if ($bankTranId === '') {
            throw new DomainException('This payment has no bank transaction id to refund.');
        }

        $result = $this->client->refund(
            $bankTranId,
            Money::toDecimal($refund->amount_minor),
            (string) ($refund->reason ?? 'Refund'),
        );

        return [
            'status' => 'refunded',
            'provider_reference' => isset($result['refund_ref_id']) ? (string) $result['refund_ref_id'] : $bankTranId,
            'redirect_url' => null,
        ];
    }
}
