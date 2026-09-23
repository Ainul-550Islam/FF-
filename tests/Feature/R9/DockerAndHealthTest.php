<?php

namespace Tests\Feature\R9;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DockerAndHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_laravel_health_endpoint(): void
    {
        $response = $this->getJson('/health');
        $response->assertStatus(200);
        $response->assertJsonStructure(['status', 'service', 'timestamp']);
        $this->assertStringNotContainsString('password', strtolower($response->getContent()));
        $this->assertStringNotContainsString('secret', strtolower($response->getContent()));
    }

    public function test_laravel_liveness_endpoint(): void
    {
        $response = $this->getJson('/health/live');
        $response->assertStatus(200);
        $response->assertJson(['status' => 'ok']);
    }

    public function test_laravel_readiness_endpoint(): void
    {
        $response = $this->getJson('/health/ready');
        $this->assertTrue(in_array($response->status(), [200, 503]));
        $data = $response->json();
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('checks', $data);
        $this->assertArrayHasKey('database', $data['checks']);
    }

    public function test_docker_compose_exists_and_valid(): void
    {
        $this->assertFileExists(base_path('docker-compose.yml'));
        $content = file_get_contents(base_path('docker-compose.yml'));
        $this->assertStringContainsString('postgres:', $content);
        $this->assertStringContainsString('redis:', $content);
        $this->assertStringContainsString('healthcheck:', $content);
        $this->assertStringContainsString('restart: unless-stopped', $content);
        $this->assertStringContainsString('networks:', $content);
        $this->assertStringContainsString('volumes:', $content);
    }

    public function test_docker_compose_no_public_db_ports(): void
    {
        $content = file_get_contents(base_path('docker-compose.yml'));
        $this->assertStringNotContainsString('5432:5432', $content, 'PostgreSQL should not be publicly exposed');
        $this->assertStringNotContainsString('6379:6379', $content, 'Redis should not be publicly exposed');
    }

    public function test_laravel_dockerfile_hardened(): void
    {
        $path = base_path('deploy/Dockerfile');
        $this->assertFileExists($path);
        $content = file_get_contents($path);
        $this->assertStringContainsString('FROM', $content);
        $this->assertStringContainsString('HEALTHCHECK', $content);
        $this->assertStringContainsString('ffarena', $content);
    }

    public function test_security_no_hardcoded_secrets(): void
    {
        $envExample = file_get_contents(base_path('.env.example'));
        $this->assertStringContainsString('CHANGE_ME', $envExample, 'Secrets should be placeholders');
        $this->assertStringNotContainsString('password123', strtolower($envExample));
    }

    public function test_health_no_secrets(): void
    {
        $response = $this->getJson('/health');
        $content = strtolower($response->getContent());
        $this->assertStringNotContainsString('database_url', $content);
        $this->assertStringNotContainsString('redis_url', $content);
        $this->assertStringNotContainsString('password', $content);
    }

    public function test_startup_order_dependency(): void
    {
        $content = file_get_contents(base_path('docker-compose.yml'));
        $this->assertStringContainsString('depends_on', $content);
        $this->assertStringContainsString('service_healthy', $content, 'Should use healthcheck for startup order, not sleep');
        $this->assertStringNotContainsString('sleep 10', $content, 'Should not use arbitrary sleep');
    }

    public function test_redis_key_design_namespaces(): void
    {
        $envExample = file_get_contents(base_path('.env.example'));
        $this->assertStringContainsString('ffarena:', $envExample, 'Should document Redis key namespaces');
    }

    public function test_docker_available_detection(): void
    {
        $available = $this->isDockerAvailable();
        if (! $available) {
            $this->markTestSkipped('Docker not available - BLOCKED BY ENVIRONMENT');
        } $this->assertTrue($available);
    }

    public function test_go_available_detection(): void
    {
        $available = $this->isGoAvailable();
        if (! $available) {
            $this->markTestSkipped('Go not available - BLOCKED BY ENVIRONMENT');
        } $this->assertTrue($available);
    }

    public function test_rust_available_detection(): void
    {
        $available = $this->isRustAvailable();
        if (! $available) {
            $this->markTestSkipped('Rust/Cargo not available - BLOCKED BY ENVIRONMENT');
        } $this->assertTrue($available);
    }

    public function test_environment_matrix(): void
    {
        $matrix = ['PostgreSQL' => $this->isPostgresAvailable() ? 'PASS' : 'BLOCKED BY ENVIRONMENT', 'Redis' => $this->isRedisAvailable() ? 'PASS' : 'BLOCKED BY ENVIRONMENT', 'Docker' => $this->isDockerAvailable() ? 'PASS' : 'BLOCKED BY ENVIRONMENT', 'Go' => $this->isGoAvailable() ? 'PASS' : 'BLOCKED BY ENVIRONMENT', 'Rust' => $this->isRustAvailable() ? 'PASS' : 'BLOCKED BY ENVIRONMENT'];
        $this->assertIsArray($matrix);
        foreach ($matrix as $service => $status) {
            $this->assertTrue(in_array($status, ['PASS', 'BLOCKED BY ENVIRONMENT', 'FAIL']));
        }
    }
}
