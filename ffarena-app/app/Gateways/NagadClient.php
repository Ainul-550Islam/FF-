<?php

namespace App\Gateways;

use App\Support\GatewayHttp;
use DomainException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;

/**
 * Nagad Checkout API client (Phase 20/G2-B).
 *
 * Implements the server-to-server subset of the Nagad "DFS" Checkout API
 * (remote-payment-gateway-1.0):
 *
 *   - initialize POST …/check-out/initialize/{merchantId}/{orderId}
 *   - complete   POST …/check-out/complete/{paymentRefId}
 *   - verify     POST …/dfs/verify/payment/{paymentRefId}
 *
 * Every request body carries a `sensitiveData` (base64 of the JSON payload)
 * plus a `signature` — an RSA-SHA256 signature of that JSON payload produced
 * with the merchant's private key. The gateway's responses are re-verified
 * server-side (the `verify` endpoint) before any payment is settled.
 *
 * No credential is ever logged; error messages are secret-free.
 */
class NagadClient
{
    protected const SANDBOX = 'http://sandbox.mynagad.com:10080';

    protected const PRODUCTION = 'https://api.mynagad.com';

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(protected array $config = [])
    {
        $this->config = $config !== [] ? $config : (array) config('payments.providers.nagad', []);
    }

    public function configured(): bool
    {
        return (bool) ($this->config['enabled'] ?? false)
            && ! empty($this->config['merchant_id'])
            && ! empty($this->config['merchant_private_key'])
            && ! empty($this->config['pg_public_key']);
    }

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
     * Initialize a checkout. Returns the gateway payload containing the
     * `callBackUrl` the payer must be redirected to and the `paymentRefId`.
     *
     * @return array<string, mixed>
     */
    public function initialize(string $amount, string $orderId, string $callbackUrl): array
    {
        $sensitive = [
            'merchantId' => (string) $this->config['merchant_id'],
            'datetime' => now()->format('YmdHis'),
            'orderId' => $orderId,
            'challenge' => (string) Str::uuid(),
        ];

        $response = $this->post(
            "/remote-payment-gateway-1.0/api/dfs/check-out/initialize/{$this->config['merchant_id']}/{$orderId}",
            $sensitive,
        );

        $data = GatewayHttp::json($response, 'Nagad checkout initialize');

        if (empty($data['callBackUrl'])) {
            throw new DomainException('Nagad checkout initialize returned no callBackUrl.');
        }

        return $data;
    }

    /**
     * Complete a checkout for a payment reference.
     *
     * @return array<string, mixed>
     */
    public function complete(string $paymentRefId): array
    {
        $response = $this->post(
            "/remote-payment-gateway-1.0/api/dfs/check-out/complete/{$paymentRefId}",
            [
                'merchantId' => (string) $this->config['merchant_id'],
                'orderId' => '',
                'paymentRefId' => $paymentRefId,
            ],
        );

        return GatewayHttp::json($response, 'Nagad checkout complete');
    }

    /**
     * Verify the authoritative payment status.
     *
     * @return array<string, mixed>
     */
    public function verify(string $paymentRefId): array
    {
        $response = $this->post(
            "/remote-payment-gateway-1.0/api/dfs/verify/payment/{$paymentRefId}",
            [
                'merchantId' => (string) $this->config['merchant_id'],
                'paymentRefId' => $paymentRefId,
            ],
        );

        return GatewayHttp::json($response, 'Nagad payment verify');
    }

    /**
     * POST a signed request to the Nagad API.
     *
     * @param  array<string, mixed>  $sensitive
     */
    protected function post(string $path, array $sensitive): Response
    {
        $payload = json_encode($sensitive, JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            throw new DomainException('Nagad: could not encode request payload.');
        }

        return GatewayHttp::client()->asJson()->post($this->baseUrl().$path, [
            'sensitiveData' => base64_encode($payload),
            'signature' => $this->sign($payload),
        ]);
    }

    /**
     * RSA-SHA256 signature (base64) of the JSON payload using the merchant
     * private key, matching Nagad's SDK signing scheme.
     */
    protected function sign(string $json): string
    {
        $key = $this->privateKey();
        $signature = '';

        $ok = openssl_sign($json, $signature, $key, OPENSSL_ALGO_SHA256);

        if (! $ok) {
            throw new DomainException('Nagad: failed to sign request payload.');
        }

        return base64_encode($signature);
    }

    /**
     * The merchant private key as a usable PEM. Handles the .env convention of
     * a single-line value with literal \n line breaks.
     */
    protected function privateKey(): string
    {
        $key = trim((string) ($this->config['merchant_private_key'] ?? ''));

        if ($key === '') {
            throw new DomainException('Nagad: merchant private key is not configured.');
        }

        return str_replace('\\n', "\n", $key);
    }
}
