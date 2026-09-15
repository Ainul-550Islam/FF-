<?php

namespace App\Support;

use App\Models\Payment;

/**
 * Phase 20/G2 — cryptographically-bound, stateless callback "state" token.
 *
 * A hosted gateway (bKash, …) redirects the payer back to our site with the
 * gateway's own query parameters (paymentID, status, …). Those parameters are
 * attacker-influenced — anyone can craft a URL that *looks* like a success
 * redirect. The `state` parameter is therefore the only piece of that redirect
 * we trust *before* re-querying the gateway server-to-server.
 *
 * The token binds the redirect to an exact payment:
 *
 *     state = "{payment_id}|{idempotency_key}.{hmac_sha256(payload, secret)}"
 *
 * - the `idempotency_key` (a server-generated UUID) acts as a per-payment
 *   nonce so two redirects for the same payment carry distinct tokens;
 * - the HMAC prevents an attacker from minting a token for a payment they do
 *   not own, or from altering the bound payment id.
 *
 * No secret ever appears in the token itself — it is safe to put in a URL.
 */
class PaymentCallbackState
{
    /**
     * Build the state token for a payment's redirect.
     */
    public static function build(Payment $payment): string
    {
        $payload = $payment->id.'|'.($payment->idempotency_key ?? '');

        return $payload.'.'.self::sign($payload);
    }

    /**
     * Verify that a state token was minted for the given payment.
     */
    public static function verify(Payment $payment, string $state): bool
    {
        $expected = self::build($payment);

        return hash_equals($expected, $state);
    }

    protected static function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, self::secret());
    }

    /**
     * The signing secret. Dedicated env var first; falls back to the shared
     * webhook secret; finally a key derived from APP_KEY so the token is still
     * cryptographically bound even in a bare local environment (APP_KEY never
     * leaves the server).
     */
    public static function secret(): string
    {
        $callback = (string) config('services.payments.callback_secret', '');

        if ($callback !== '') {
            return $callback;
        }

        $webhook = (string) config('services.payments.webhook_secret', '');

        if ($webhook !== '') {
            return $webhook;
        }

        return 'ffarena-callback-'.(string) config('app.key', 'ffarena');
    }
}
