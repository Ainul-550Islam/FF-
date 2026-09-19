<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Services\Integration\ServiceAuthenticator;

class GoPaymentGatewayAdapter
{
    protected ServiceAuthenticator $authenticator;
    
    public function __construct(ServiceAuthenticator $authenticator)
    {
        $this->authenticator = $authenticator;
    }

    public function createPayment(array $data): array
    {
        if (!config('services_go_rust.go_payment.enabled')) {
            return ['status' => 'succeeded', 'provider' => 'manual', 'fallback' => true];
        }

        // Environment safety guard
        if (!$this->validatePaymentEnv()) {
            return ['status' => 'failed', 'error' => 'environment_safety_guard_failed'];
        }

        try {
            $url = config('services_go_rust.go_payment.url') . '/api/v1/payments';
            $idempotencyKey = $data['idempotency_key'] ?? (string) Str::uuid();
            $requestId = (string) Str::uuid();
            $body = json_encode($data);
            
            // Service authentication: X-Service-ID, X-Timestamp, X-Nonce, X-Signature, X-Request-ID
            $headers = $this->authenticator->generateHeaders('POST', '/api/v1/payments', $body);
            $headers['Authorization'] = 'Bearer ' . config('services_go_rust.go_payment.token');
            $headers['Idempotency-Key'] = $idempotencyKey;
            $headers['X-Request-ID'] = $requestId;
            $headers['X-Idempotency-Key'] = $idempotencyKey;

            $response = Http::withHeaders($headers)
                ->timeout(config('services_go_rust.go_payment.timeout', 15))
                ->withBody($body, 'application/json')
                ->post($url);

            $result = $response->json() ?? ['status' => 'failed', 'error' => 'invalid_response'];
            
            // Audit logging without secrets
            Log::info('Go payment gateway create', [
                'provider' => $data['provider'] ?? 'unknown',
                'external_id' => $data['external_id'] ?? null,
                'status' => $result['status'] ?? 'unknown',
                'request_id' => $requestId,
                'idempotency_key' => $idempotencyKey,
            ]);

            return $result;
        } catch (\Throwable $e) {
            Log::error('Go payment gateway error', [
                'error' => $e->getMessage(),
                'provider' => $data['provider'] ?? 'unknown',
            ]);
            return ['status' => 'failed', 'fallback' => true, 'error' => $e->getMessage()];
        }
    }

    public function queryPayment(string $externalId, string $providerReference = null): array
    {
        if (!config('services_go_rust.go_payment.enabled')) {
            return ['status' => 'pending', 'provider_reference' => $providerReference];
        }

        try {
            $url = config('services_go_rust.go_payment.url') . '/api/v1/payments/' . $externalId;
            $requestId = (string) Str::uuid();
            $body = '';
            $headers = $this->authenticator->generateHeaders('GET', '/api/v1/payments/' . $externalId, $body);
            $headers['Authorization'] = 'Bearer ' . config('services_go_rust.go_payment.token');
            $headers['X-Request-ID'] = $requestId;

            $response = Http::withHeaders($headers)
                ->timeout(config('services_go_rust.go_payment.timeout', 10))
                ->get($url);

            return $response->json() ?? ['status' => 'failed', 'error' => 'invalid_response'];
        } catch (\Throwable $e) {
            Log::error('Go payment gateway query error', ['error' => $e->getMessage()]);
            return ['status' => 'failed', 'error' => $e->getMessage()];
        }
    }

    public function refund(array $data): array
    {
        try {
            $url = config('services_go_rust.go_payment.url') . '/api/v1/payments/refund';
            $idempotencyKey = $data['idempotency_key'] ?? (string) Str::uuid();
            $requestId = (string) Str::uuid();
            $body = json_encode($data);
            $headers = $this->authenticator->generateHeaders('POST', '/api/v1/payments/refund', $body);
            $headers['Authorization'] = 'Bearer ' . config('services_go_rust.go_payment.token');
            $headers['Idempotency-Key'] = $idempotencyKey;
            $headers['X-Request-ID'] = $requestId;

            $response = Http::withHeaders($headers)
                ->timeout(20)
                ->withBody($body, 'application/json')
                ->post($url);

            return $response->json() ?? ['status' => 'failed'];
        } catch (\Throwable $e) {
            Log::error('Go payment gateway refund error', ['error' => $e->getMessage()]);
            return ['status' => 'failed', 'error' => $e->getMessage()];
        }
    }

    public function listMethods(): array
    {
        try {
            $url = config('services_go_rust.go_payment.url') . '/api/v1/payments/methods';
            $requestId = (string) Str::uuid();
            $headers = $this->authenticator->generateHeaders('GET', '/api/v1/payments/methods', '');
            $headers['Authorization'] = 'Bearer ' . config('services_go_rust.go_payment.token');
            $headers['X-Request-ID'] = $requestId;

            $response = Http::withHeaders($headers)->timeout(5)->get($url);
            return $response->json() ?? ['methods' => ['manual']];
        } catch (\Throwable $e) {
            return ['methods' => ['manual'], 'error' => $e->getMessage()];
        }
    }

    public function healthCheck(): array
    {
        try {
            $url = config('services_go_rust.go_payment.url') . '/health';
            $response = Http::timeout(5)->get($url);
            return $response->json() ?? ['status' => 'unknown'];
        } catch (\Throwable $e) {
            return ['status' => 'down', 'error' => $e->getMessage()];
        }
    }

    public function getCapabilities(): array
    {
        try {
            $url = config('services_go_rust.go_payment.url') . '/api/v1/providers/capabilities';
            $response = Http::timeout(5)->get($url);
            return $response->json() ?? ['capabilities' => []];
        } catch (\Throwable $e) {
            return ['capabilities' => ['manual']];
        }
    }

    protected function validatePaymentEnv(): bool
    {
        $env = config('app.env');
        $paymentEnv = config('services_go_rust.payment_env', 'sandbox');
        $goUrl = config('services_go_rust.go_payment.url', '');

        // Safety guard: automated tests must reject production payment credentials/endpoints
        if (in_array($env, ['testing', 'test'])) {
            if (str_contains($goUrl, 'pay.bka.sh') && !str_contains($goUrl, 'sandbox')) {
                if ($paymentEnv !== 'production') {
                    Log::error('Safety guard: test env with production endpoint', ['url' => $goUrl]);
                    return false;
                }
            }
        }

        return true;
    }
}
