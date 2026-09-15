<?php

namespace App\Contracts;

use App\Models\Payment;

/**
 * Phase 20/G2 — capability for gateways that can answer an authoritative
 * server-to-server status query for an in-flight payment.
 *
 * A payer redirect is never trusted on its own. Before a payment is settled,
 * the callback controller asks the provider (via this contract) what actually
 * happened, then compares amount and currency against the server-side value.
 *
 * `$callbackData` carries the raw parameters the provider sent to our return
 * URL (GET query or POST body). Most gateways ignore it (they look the
 * payment up by the reference they already stored); hosted gateways like
 * SSLCommerz need it because the authoritative `val_id` arrives in the
 * redirect/IPN and is not stored on the payment beforehand.
 *
 * @see App\Services\PaymentService::confirmProviderPayment()
 */
interface PaymentStatusQueryable
{
    /**
     * Query the provider for the authoritative status of a payment.
     *
     * @param  array<string, mixed>  $callbackData
     * @return array{
     *     status: string,
     *     reference: string,
     *     gateway_transaction_id: ?string,
     *     amount: ?string,
     *     currency: ?string
     * }
     *
     * `status` is one of:
     *   - 'completed' — the provider confirms the payment was captured;
     *   - 'failed'    — the provider reports a terminal failure;
     *   - 'pending'   — still in flight (payer did not complete, or the
     *                   provider has not yet settled).
     *
     * `amount`/`currency` mirror the provider's records and must be
     * re-validated by the caller before settling.
     */
    public function queryPaymentStatus(Payment $payment, array $callbackData = []): array;
}
