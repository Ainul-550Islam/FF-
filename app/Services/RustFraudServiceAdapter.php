<?php

namespace App\Services;

use App\Services\Integration\ServiceAuthenticator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RustFraudServiceAdapter
{
    public function evaluate(array $data): array
    {
        if (! config('services_go_rust.rust_security.enabled')) {
            return ['overall_score' => 0, 'level' => 'low', 'recommendation' => 'allow', 'fallback' => true];
        }
        try {
            $url = config('services_go_rust.rust_security.url').'/api/v1/fraud/evaluate';
            $body = (string) json_encode($data);

            // P2-3 (2026-10-07): sign outbound calls like the Go adapter so
            // the engine can verify them when it enforces HMAC.
            $headers = ServiceAuthenticator::generateHeaders(
                (string) config('services_go_rust.rust_security.hmac_secret'),
                'POST',
                '/api/v1/fraud/evaluate',
                $body
            );
            $headers['Authorization'] = 'Bearer '.config('services_go_rust.rust_security.token');
            $headers['X-Request-ID'] = (string) Str::uuid();

            $response = Http::withHeaders($headers)
                ->timeout(config('services_go_rust.rust_security.timeout', 5))
                ->withBody($body, 'application/json')
                ->post($url);

            return $response->json() ?? ['overall_score' => 0, 'level' => 'low'];
        } catch (\Throwable $e) {
            Log::error('Rust security service error', ['error' => $e->getMessage()]);

            return ['overall_score' => 0, 'level' => 'low', 'recommendation' => 'allow', 'fallback' => true];
        }
    }
}
