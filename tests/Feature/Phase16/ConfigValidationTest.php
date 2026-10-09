<?php

namespace Tests\Feature\Phase16;

use App\Services\HealthService;

/**
 * Phase 16 — production environment validation with safe diagnostics.
 */
class ConfigValidationTest extends Phase16TestCase
{
    protected function withConfig(array $values, callable $assert): void
    {
        $snapshot = $this->snapshotConfig(array_keys($values));
        config($values);

        try {
            $assert();
        } finally {
            $this->restoreConfig($snapshot);
        }
    }

    public function test_production_detects_debug_and_missing_key(): void
    {
        $this->withConfig([
            'app.env' => 'production',
            'app.debug' => true,
            'app.key' => null,
            'app.url' => 'http://localhost',
            'cache.default' => 'redis',
            'queue.default' => 'redis',
        ], function () {
            $issues = app(HealthService::class)->productionIssues();
            $keys = array_column($issues, 'key');

            $this->assertContains('APP_DEBUG', $keys);
            $this->assertContains('APP_KEY', $keys);
            $this->assertContains('APP_URL', $keys);

            // No issue may leak a secret value.
            foreach ($issues as $issue) {
                $this->assertArrayNotHasKey('value', $issue);
                $this->assertStringNotContainsString('base64:', $issue['message']);
            }
        });
    }

    public function test_production_with_safe_config_has_no_issues(): void
    {
        $this->withConfig([
            'app.env' => 'production',
            'app.debug' => false,
            'app.key' => 'base64:ok',
            'app.url' => 'https://arena.example.com',
            'cache.default' => 'redis',
            'queue.default' => 'database',
            'database.default' => 'pgsql',
            'database.connections.pgsql.sslmode' => 'require',
            // AUDIT FIX (2026-10-08, GAPS-01): a SAFE production config now
            // additionally requires a real webhook trust root — see the
            // companion test below for the failure side.
            'services.payments.webhook_secret' => 'a-real-rotated-secret-for-this-fixture',
        ], function () {
            $this->assertSame([], app(HealthService::class)->productionIssues());
        });
    }

    public function test_production_flags_a_missing_or_placeholder_webhook_secret(): void
    {
        $cases = ['', 'CHANGE_ME_replace_with_64_hex_chars'];

        foreach ($cases as $secret) {
            $this->withConfig([
                'app.env' => 'production',
                'app.debug' => false,
                'app.key' => 'base64:ok',
                'app.url' => 'https://arena.example.com',
                'cache.default' => 'redis',
                'queue.default' => 'database',
                'database.default' => 'pgsql',
                'database.connections.pgsql.sslmode' => 'require',
                'services.payments.webhook_secret' => $secret,
            ], function () use ($secret) {
                $keys = array_column(app(HealthService::class)->productionIssues(), 'key');

                $this->assertContains('PAYMENT_WEBHOOK_SECRET', $keys, "Secret [{$secret}] must be reported.");
            });
        }
    }

    public function test_unshared_cache_store_is_flagged(): void
    {
        $this->withConfig([
            'app.env' => 'production',
            'app.debug' => false,
            'app.key' => 'base64:ok',
            'app.url' => 'https://arena.example.com',
            'cache.default' => 'array',
            'queue.default' => 'redis',
        ], function () {
            $issues = app(HealthService::class)->productionIssues();
            $keys = array_column($issues, 'key');

            $this->assertContains('CACHE_STORE', $keys);
        });
    }

    public function test_sync_queue_is_flagged_as_warning(): void
    {
        $this->withConfig([
            'app.env' => 'production',
            'app.debug' => false,
            'app.key' => 'base64:ok',
            'app.url' => 'https://arena.example.com',
            'cache.default' => 'redis',
            'queue.default' => 'sync',
        ], function () {
            $issues = app(HealthService::class)->productionIssues();

            $queueIssue = collect($issues)->firstWhere('key', 'QUEUE_CONNECTION');
            $this->assertNotNull($queueIssue);
            $this->assertSame('warning', $queueIssue['severity']);
        });
    }

    public function test_diagnostics_include_safe_environment_only(): void
    {
        $diagnostics = app(HealthService::class)->diagnostics();

        $this->assertArrayHasKey('environment', $diagnostics['checks']);
        $this->assertArrayHasKey('value', $diagnostics['checks']['environment']);

        // Environment value is a single word, never a secret.
        $this->assertMatchesRegularExpression('/^[a-z]+$/i', (string) $diagnostics['checks']['environment']['value']);
    }
}
