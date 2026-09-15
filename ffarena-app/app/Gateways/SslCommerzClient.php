<?php

namespace App\Gateways;

use App\Support\GatewayHttp;
use DomainException;

/**
 * SSLCommerz Hosted Checkout API client (Phase 20/G2-C).
 *
 * Implements the server-to-server subset of the SSLCommerz API:
 *
 *   - createSession  POST /gwprocess/v4/api.php            (form-encoded)
 *   - validate       GET  /validator/api/validationserverAPI.php
 *   - refund         POST /validator/api/merchantTransIDvalidationAPI.php
 *   - verifyIpnHash  MD5(store_passwd + concat(verify_key field values))
 *
 * The IPN / return POST carries `verify_sign` + `verify_key`. Per the
 * provider's own guidance this MD5 signature is "necessary, not sufficient":
 * every payment is additionally confirmed with the authoritative
 * order-validation endpoint before anything is fulfilled.
 */
class SslCommerzClient
{
    protected const SANDBOX = 'https://sandbox.sslcommerz.com';

    protected const PRODUCTION = 'https://securepay.sslcommerz.com';

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(protected array $config = [])
    {
        $this->config = $config !== [] ? $config : (array) config('payments.providers.sslcommerz', []);
    }

    public function configured(): bool
    {
        return (bool) ($this->config['enabled'] ?? false)
            && ! empty($this->config['store_id'])
            && ! empty($this->config['store_password']);
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
     * Create a hosted checkout session. Returns the gateway payload containing
     * `status`, `sessionkey` and `GatewayPageURL`.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function createSession(array $params): array
    {
        $response = GatewayHttp::client()
            ->asForm()
            ->post($this->baseUrl().'/gwprocess/v4/api.php', array_merge([
                'store_id' => (string) $this->config['store_id'],
                'store_passwd' => (string) $this->config['store_password'],
            ], $params));

        $data = GatewayHttp::json($response, 'SSLCommerz session init');

        if (($data['status'] ?? '') !== 'SUCCESS' || empty($data['GatewayPageURL'])) {
            throw new DomainException('SSLCommerz could not create a checkout session.');
        }

        return $data;
    }

    /**
     * Order validation — the authoritative money check.
     *
     * @return array<string, mixed>
     */
    public function validate(string $valId): array
    {
        $response = GatewayHttp::client()->get($this->baseUrl().'/validator/api/validationserverAPI.php', [
            'val_id' => $valId,
            'store_id' => (string) $this->config['store_id'],
            'store_passwd' => (string) $this->config['store_password'],
            'v' => '1',
            'format' => 'json',
        ]);

        return GatewayHttp::json($response, 'SSLCommerz order validation');
    }

    /**
     * Initiate an external refund (against the bank transaction id).
     *
     * @return array<string, mixed>
     */
    public function refund(string $bankTranId, string $amount, string $remarks): array
    {
        $response = GatewayHttp::client()
            ->asForm()
            ->post($this->baseUrl().'/validator/api/merchantTransIDvalidationAPI.php', [
                'val_id' => $bankTranId,
                'store_id' => (string) $this->config['store_id'],
                'store_passwd' => (string) $this->config['store_password'],
                'refund_amount' => $amount,
                'refund_remarks' => $remarks,
                'v' => '1',
                'format' => 'json',
            ]);

        return GatewayHttp::json($response, 'SSLCommerz refund');
    }

    /**
     * Verify the provider-mandated MD5 signature of an IPN / return POST.
     *
     * Algorithm (per the provider's integration docs):
     *
     *   digest = MD5(store_passwd + concat(values of verify_key fields,
     *             in the exact order listed by verify_key))
     *
     * compared to `verify_sign` with a constant-time comparison.
     *
     * @param  array<string, mixed>  $params
     */
    public function verifyIpnHash(array $params): bool
    {
        $verifyKey = (string) ($params['verify_key'] ?? '');
        $verifySign = (string) ($params['verify_sign'] ?? '');

        if ($verifyKey === '' || $verifySign === '') {
            return false;
        }

        $fields = array_values(array_filter(array_map('trim', explode(',', $verifyKey)), fn ($f) => $f !== ''));

        if ($fields === []) {
            return false;
        }

        $hash = (string) $this->config['store_password'];

        foreach ($fields as $field) {
            $hash .= (string) ($params[$field] ?? '');
        }

        return hash_equals(strtolower(md5($hash)), strtolower($verifySign));
    }
}
