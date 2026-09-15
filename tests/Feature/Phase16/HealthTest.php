<?php

namespace Tests\Feature\Phase16;

use App\Services\HealthService;
use Illuminate\Support\Facades\DB;

/**
 * Phase 16 — health, liveness and readiness checks.
 */
class HealthTest extends Phase16TestCase
{
    public function test_live_endpoint_returns_ok(): void
    {
        $this->getJson('/health/live')
            ->assertStatus(200)
            ->assertExactJson(['status' => 'ok']);
    }

    public function test_index_endpoint_is_safe(): void
    {
        $this->getJson('/health')
            ->assertStatus(200)
            ->assertJson(['status' => 'ok'])
            ->assertJsonStructure(['status', 'service']);
    }

    public function test_ready_endpoint_reports_all_checks(): void
    {
        $this->getJson('/health/ready')
            ->assertStatus(200)
            ->assertJson(['status' => 'ready'])
            ->assertJsonStructure([
                'status',
                'checks' => [
                    'database' => ['ok'],
                    'cache' => ['ok'],
                    'filesystem' => ['ok'],
                    'queue' => ['ok'],
                    'config' => ['ok'],
                ],
            ]);
    }

    public function test_ready_reports_not_ready_when_database_fails(): void
    {
        $original = (string) config('database.default');

        // Point the default connection at a missing file WITHOUT touching the
        // migrated connection used by the rest of the suite.
        config(['database.connections.sqlite_broken' => [
            'driver' => 'sqlite',
            'database' => '/nonexistent-path/missing.sqlite',
            'foreign_key_constraints' => true,
        ]]);
        config(['database.default' => 'sqlite_broken']);

        try {
            $ready = app(HealthService::class)->ready();

            $this->assertSame('not_ready', $ready['status']);
            $this->assertFalse($ready['checks']['database']['ok']);
        } finally {
            config(['database.default' => $original]);
            DB::disconnect('sqlite_broken');
        }
    }

    public function test_ready_reports_not_ready_when_cache_fails(): void
    {
        $snapshot = $this->snapshotConfig(['cache.default']);

        // No Redis client is installed in the test environment, so selecting
        // the redis store makes the cache probe throw.
        config(['cache.default' => 'redis']);

        try {
            $ready = app(HealthService::class)->ready();

            $this->assertSame('not_ready', $ready['status']);
            $this->assertFalse($ready['checks']['cache']['ok']);
        } finally {
            $this->restoreConfig($snapshot);
        }
    }

    public function test_ready_reports_not_ready_when_filesystem_fails(): void
    {
        $snapshot = $this->snapshotConfig(['filesystems.default']);

        config(['filesystems.disks.broken_fs' => [
            'driver' => 'local',
            'root' => '/nonexistent-path/storage',
            'throw' => false,
        ]]);
        config(['filesystems.default' => 'broken_fs']);

        try {
            $ready = app(HealthService::class)->ready();

            $this->assertSame('not_ready', $ready['status']);
            $this->assertFalse($ready['checks']['filesystem']['ok']);
        } finally {
            $this->restoreConfig($snapshot);
        }
    }

    public function test_diagnostics_never_expose_secrets(): void
    {
        $diagnostics = app(HealthService::class)->diagnostics();

        // Only a closed set of safe keys may exist.
        $allowed = ['status', 'checks', 'queue', 'maintenance', 'request_id'];

        foreach (array_keys($diagnostics) as $key) {
            $this->assertContains($key, $allowed);
        }

        $serialized = json_encode($diagnostics);
        $this->assertStringNotContainsString('password', $serialized);
        $this->assertStringNotContainsString('secret', $serialized);
        $this->assertStringNotContainsString((string) config('app.key'), $serialized);
    }

    public function test_health_remains_available_during_maintenance(): void
    {
        $this->artisan('down')->assertExitCode(0);

        try {
            $this->getJson('/health/live')->assertStatus(200);
            $this->getJson('/health/ready')->assertStatus(200);
        } finally {
            $this->artisan('up')->assertExitCode(0);
        }
    }

    public function test_live_service_shape(): void
    {
        $this->assertSame(['status' => 'ok'], app(HealthService::class)->live());
    }
}
