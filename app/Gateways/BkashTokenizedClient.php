<?php

namespace App\Gateways;

use App\Support\GatewayHttp;
use DomainException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;

/**
 * bKash Tokenized Checkout API client (Phase 20/G2).
 *
 * Implements the server-to-server subset of the bKash Tokenized Checkout API
 * (v1.2.0-beta):
 *
 *   - grant token    POST /tokenized/checkout/token/grant
 *   - create payment POST /tokenized/checkout/create
 *   - execute payment POST /tokenized/checkout/execute
 *   - query payment  POST /tokenized/checkout/payment/status
 *   - refund         POST /tokenized/checkout/payment/refund
 *
 * The access token is cached until shortly before expiry. Every failure is
 * honest — the caller is told the gateway failed, never that a payment
 * succeeded.
 */
class BkashTokenizedClient
{
    protected const SANDBOX = 'https://tokenized.sandbox.bka.sh/v1.2.0-beta';

    protected const PRODUCTION = 'https://tokenized.pay.bka.sh/v1.2.0-beta';

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(protected array $config = [])
    {
        $this->config = $config !== [] ? $config : (array) config('payments.providers.bkash', []);
    }

    /**
     * Whether all credentials needed to operate are present.
     */
    public function configured(): bool
    {
        return (bool) ($this->config['enabled'] ?? false)
            && ! empty($this->config['app_key'])
            && ! empty($this->config['app_secret'])
            && ! empty($this->config['username'])
            && ! empty($this->config['password']);
    }

    /**
     * Resolve the API base URL (explicit config → mode default).
     */
    public function baseUrl(): string
    {
        if (! empty($this->config['base_url'])) {
            return rtrim((string) $this->config['base_url'], '/');
        }

        return ($this->config['mode'] ?? 'sandbox') === 'production'
            ? self::PRODUCTION
            : self::SANDBOX;
    }

    /**
     * The current access token, cached until just before expiry. The cache key
     * is bound to the app key so sandbox and production tokens never collide.
     */
    public function token(): string
    {
        $cacheKey = 'bkash:token:'.substr(md5((string) ($this->config['app_key'] ?? '')), 0, 12);
        $ttl = max(30, (int) ($this->config['token_ttl_seconds'] ?? 3300));

        $cached = Cache::get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = GatewayHttp::client()->withBasicAuth(
            (string) ($this->config['username'] ?? ''),
            (string) ($this->config['password'] ?? ''),
        )->post($this->baseUrl().'/tokenized/checkout/token/grant', [
            'app_key' => (string) ($this->config['app_key'] ?? ''),
            'app_secret' => (string) ($this->config['app_secret'] ?? ''),
        ]);

        $data = GatewayHttp::json($response, 'bKash token grant');
        $token = (string) ($data['id_token'] ?? '');

        if ($token === '') {
            throw new DomainException('bKash token grant returned no token.');
        }

        Cache::put($cacheKey, $token, $ttl);

        return $token;
    }

    /**
     * Create a checkout intent. Returns the gateway payload containing
     * `paymentID` and the `bkashURL` the payer must be redirected to.
     *
     * @return array<string, mixed>
     */
    public function createPayment(string $amount, string $payerReference, string $callbackUrl, string $merchantInvoiceNumber): array
    {
        $response = $this->authed()->post($this->baseUrl().'/tokenized/checkout/create', [
            'mode' => '0011',
            'payerReference' => $payerReference,
            'callbackURL' => $callbackUrl,
            'amount' => $amount,
            'currency' => 'BDT',
            'intent' => 'sale',
            'merchantInvoiceNumber' => $merchantInvoiceNumber,
        ]);

        $data = GatewayHttp::json($response, 'bKash payment create');

        if (empty($data['paymentID']) || empty($data['bkashURL'])) {
            throw new DomainException('bKash payment create returned an incomplete response.');
        }

        return $data;
    }

    /**
     * Execute (capture) a payment the payer approved.
     *
     * @return array<string, mixed>
     */
    public function executePayment(string $paymentId): array
    {
        return GatewayHttp::json(
            $this->authed()->post($this->baseUrl().'/tokenized/checkout/execute', [
                'paymentID' => $paymentId,
            ]),
            'bKash payment execute',
        );
    }

    /**
     * Query the authoritative payment status.
     *
     * @return array<string, mixed>
     */
    public function queryPayment(string $paymentId): array
    {
        return GatewayHttp::json(
            $this->authed()->post($this->baseUrl().'/tokenized/checkout/payment/status', [
                'paymentID' => $paymentId,
            ]),
            'bKash payment query',
        );
    }

    /**
     * Issue an external refund.
     *
     * @return array<string, mixed>
     */
    public function refund(string $paymentId, string $amount, string $trxId, string $reason): array
    {
        return GatewayHttp::json(
            $this->authed()->post($this->baseUrl().'/tokenized/checkout/payment/refund', [
                'paymentID' => $paymentId,
                'amount' => $amount,
                'trxID' => $trxId,
                'sku' => 'refund',
                'reason' => $reason,
            ]),
            'bKash payment refund',
        );
    }

    /**
     * A client pre-authenticated with the token + X-APP-Key header.
     */
    protected function authed(): PendingRequest
    {
        return GatewayHttp::client()
            ->withToken($this->token())
            ->withHeaders(['X-APP-Key' => (string) ($this->config['app_key'] ?? '')]);
    }
}
