<?php

namespace Tests\Feature\Phase16;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Phase 16 — HTTP security headers + rate-limit infrastructure.
 */
class SecurityHeadersTest extends Phase16TestCase
{
    public function test_baseline_security_headers_are_present(): void
    {
        $response = $this->get('/');

        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
        $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
        $this->assertStringContainsString('camera=()', (string) $response->headers->get('Permissions-Policy'));
    }

    public function test_hsts_is_not_sent_over_plain_http(): void
    {
        // The test environment is not HTTPS — HSTS must be withheld.
        $response = $this->get('/');

        $this->assertNull($response->headers->get('Strict-Transport-Security'));
    }

    public function test_hsts_is_sent_over_https(): void
    {
        // Force the URL generator's root to https by binding a secure
        // request and forgetting the cached UrlGenerator singleton.
        $this->app->forgetInstance('url');
        $this->app->instance('request', Request::create(
            'https://arena.test/', 'GET', [], [], [], ['HTTPS' => 'on']
        ));

        $response = $this->get('/');

        $hsts = $response->headers->get('Strict-Transport-Security');
        $this->assertNotNull($hsts);
        $this->assertStringContainsString('max-age=', (string) $hsts);
    }

    public function test_health_rate_limiter_is_registered(): void
    {
        $this->assertNotNull(RateLimiter::limiter('health'));
    }

    public function test_security_headers_apply_to_api_too(): void
    {
        $response = $this->getJson('/api/v1/tournaments');

        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function test_cors_is_not_wildcard(): void
    {
        $this->assertNotSame('*', config('cors.allowed_origins')[0] ?? null);
        $this->assertFalse(config('cors.supports_credentials'));
    }
}
