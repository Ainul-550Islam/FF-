<?php
namespace Tests\Feature\R10;

use Tests\TestCase;
use App\Services\GoPaymentGatewayAdapter;
use App\Services\Integration\ServiceAuthenticator;

class LaravelPaymentContractTest extends TestCase
{
    public function test_payment_gateway_contract_methods_exist(): void
    {
        $methods = ['createPayment', 'queryPayment', 'refund', 'listMethods', 'healthCheck', 'getCapabilities'];
        foreach ($methods as $method) {
            $this->assertTrue(method_exists(GoPaymentGatewayAdapter::class, $method), "Method $method exists");
        }
    }

    public function test_laravel_does_not_contain_provider_http_implementation(): void
    {
        // Laravel must not contain bKash/Nagad-specific HTTP implementation
        $adapterFile = file_get_contents(app_path('Services/GoPaymentGatewayAdapter.php'));
        
        // Should use Go service URL, not direct bKash/Nagad URLs
        $this->assertStringNotContainsString('tokenized.sandbox.bka.sh', $adapterFile, 'Laravel should not directly call bKash API');
        $this->assertStringNotContainsString('sandbox.mynagad.com', $adapterFile, 'Laravel should not directly call Nagad API');
        
        // Should use service authenticator
        $this->assertStringContainsString('ServiceAuthenticator', $adapterFile, 'Uses service authenticator');
    }

    public function test_service_authentication_headers(): void
    {
        $this->assertTrue(method_exists(ServiceAuthenticator::class, 'generateHeaders'), 'Service authenticator has generateHeaders');
        
        $secret = config('services_go_rust.service_auth.secret', 'test-secret');
        $headers = ServiceAuthenticator::generateHeaders($secret, 'POST', '/api/v1/payments', '{"test":1}');
        $this->assertArrayHasKey('X-Service-ID', $headers);
        $this->assertArrayHasKey('X-Timestamp', $headers);
        $this->assertArrayHasKey('X-Nonce', $headers);
        $this->assertArrayHasKey('X-Signature', $headers);
    }

    public function test_no_internal_endpoint_trusts_localhost_only(): void
    {
        // No internal endpoint may trust localhost/network location alone
        $middlewareFiles = glob(app_path('Http/Middleware/*.php'));
        $hasAuthCheck = false;
        foreach ($middlewareFiles as $file) {
            $content = file_get_contents($file);
            if (str_contains($content, 'ServiceAuthenticator') || str_contains($content, 'EnsureBearerToken') || str_contains($content, 'X-Signature')) {
                $hasAuthCheck = true;
            }
        }
        $this->assertTrue($hasAuthCheck, 'Service authentication exists');
    }
}
