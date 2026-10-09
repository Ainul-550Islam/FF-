<?php

namespace App\Services;

use App\Support\RequestContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Phase 16 — health checks, readiness probes and safe diagnostics.
 *
 * Public readiness only ever exposes per-check "ok" booleans; detailed
 * diagnostics (with redacted error strings) are admin/CLI-only via
 * HealthService::diagnostics() and the ffarena:health command.
 */
class HealthService
{
    /**
     * Liveness: the PHP process is up and serving. Always true when reached.
     */
    public function live(): array
    {
        return ['status' => 'ok'];
    }

    /**
     * Readiness: the application can actually do its job right now.
     *
     * @return array{status: string, checks: array<string, array{ok: bool, label: string}>}
     */
    public function ready(): array
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'cache' => $this->checkCache(),
            'filesystem' => $this->checkFilesystem(),
            'queue' => $this->checkQueue(),
            'config' => $this->checkConfig(),
        ];

        $allOk = true;

        foreach ($checks as $check) {
            if (! $check['ok']) {
                $allOk = false;

                break;
            }
        }

        return [
            'status' => $allOk ? 'ready' : 'not_ready',
            'checks' => $checks,
        ];
    }

    /**
     * Detailed diagnostics for admins/CLI. Includes a short redacted reason
     * per failing check but never a secret, host, credential or stack trace.
     *
     * @return array<string, mixed>
     */
    public function diagnostics(): array
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'cache' => $this->checkCache(),
            'filesystem' => $this->checkFilesystem(),
            'queue' => $this->checkQueue(),
            'config' => $this->checkConfig(),
            'environment' => [
                'ok' => true,
                'label' => 'environment',
                'value' => (string) config('app.env'),
            ],
        ];

        $queueStats = $this->queueStats();

        return [
            'status' => $this->ready()['status'],
            'checks' => $checks,
            'queue' => $queueStats,
            'maintenance' => app()->isDownForMaintenance(),
            'request_id' => RequestContext::requestId(),
        ];
    }

    /**
     * Validate the current configuration without leaking values.
     *
     * @return array<int, array{severity: string, key: string, message: string}>
     */
    public function productionIssues(): array
    {
        $env = (string) config('app.env');
        $debug = (bool) config('app.debug');
        $key = (string) config('app.key');
        $url = (string) config('app.url');
        $cacheStore = (string) config('cache.default');
        $queue = (string) config('queue.default');

        $issues = [];

        if ($env === 'production') {
            if ($debug) {
                $issues[] = [
                    'severity' => 'critical',
                    'key' => 'APP_DEBUG',
                    'message' => 'APP_DEBUG must be false in production.',
                ];
            }

            if (empty($key)) {
                $issues[] = [
                    'severity' => 'critical',
                    'key' => 'APP_KEY',
                    'message' => 'APP_KEY is not set — encryption/validation will fail.',
                ];
            }

            if ($url === '' || $url === 'http://localhost') {
                $issues[] = [
                    'severity' => 'error',
                    'key' => 'APP_URL',
                    'message' => 'APP_URL is not set to the production origin.',
                ];
            }

            // AUDIT FIX (2026-10-08, GAPS-01) — the payment webhook trust
            // root must be a real, environment-injected secret. The old
            // committed fallback ('ffarena-local-webhook-secret') let anyone
            // who read the public source forge a settlement webhook; the
            // ingress now fails closed without a configured key, so an
            // unset/placeholder secret is a CRITICAL deploy blocker, not a
            // silent downgrade.
            $webhookSecret = trim((string) config('services.payments.webhook_secret', ''));

            if ($webhookSecret === '' || str_starts_with($webhookSecret, 'CHANGE_ME')) {
                $issues[] = [
                    'severity' => 'critical',
                    'key' => 'PAYMENT_WEBHOOK_SECRET',
                    'message' => 'Payment webhook secret is unset or still a placeholder — inbound provider webhooks fail closed and no payment settles from a callback. Generate one (php -r "echo bin2hex(random_bytes(32));") and set PAYMENT_WEBHOOK_SECRET.',
                ];
            }

            // The hosted-gateway `state` token proves a payer redirect belongs
            // to a payment. Disabling the check is an explicit ops escape
            // hatch — it must never be an unnoticed production default.
            if (! (bool) config('payments.callback.require_state', true)) {
                $issues[] = [
                    'severity' => 'warning',
                    'key' => 'PAYMENTS_CALLBACK_REQUIRE_STATE',
                    'message' => 'payment-callback state tokens are bypassed (PAYMENTS_CALLBACK_REQUIRE_STATE=false). Only acceptable while a provider is proven to strip query params; server-to-server verification still applies.',
                ];
            }

            // Sanctum bearer tokens must expire in production (0 = forever).
            if ((int) config('sanctum.expiration', 0) === 0) {
                $issues[] = [
                    'severity' => 'warning',
                    'key' => 'SANCTUM_EXPIRATION',
                    'message' => 'sanctum.expiration is unlimited — leaked bearer tokens stay valid forever. Set SANCTUM_EXPIRATION (minutes, e.g. 10080 for 7 days) in production.',
                ];
            }
        }

        if (in_array($cacheStore, ['array', 'null'], true)) {
            $issues[] = [
                'severity' => 'error',
                'key' => 'CACHE_STORE',
                'message' => "Cache store '{$cacheStore}' is not shared — rate limits and locks are per-process.",
            ];
        }

        if (in_array($queue, ['sync', 'null'], true)) {
            $issues[] = [
                'severity' => 'warning',
                'key' => 'QUEUE_CONNECTION',
                'message' => "Queue '{$queue}' runs jobs inline — asynchronous work (webhooks, mail) is not deferred.",
            ];
        }

        // Phase 19/G1 — PostgreSQL is the production datastore.
        $dbDefault = (string) config('database.default');

        if ($env === 'production' && $dbDefault === 'sqlite') {
            $issues[] = [
                'severity' => 'error',
                'key' => 'DB_CONNECTION',
                'message' => 'SQLite is not a production datastore — set DB_CONNECTION=pgsql (see G1 PostgreSQL migration).',
            ];
        }

        if ($env === 'production' && $dbDefault === 'pgsql' && in_array((string) config('database.connections.pgsql.sslmode', 'prefer'), ['disable'], true)) {
            $issues[] = [
                'severity' => 'warning',
                'key' => 'DB_SSLMODE',
                'message' => "PostgreSQL sslmode is 'disable' in production — prefer 'require' behind a managed endpoint.",
            ];
        }

        return $issues;
    }

    /**
     * Database statistics for operators (CLI only). Driver-aware and fully
     * redacted: reachability, server version, migration state and round-trip
     * latency — never a host, port, user, password or DSN.
     *
     * @return array<string, mixed>
     */
    public function databaseStats(): array
    {
        $stats = [
            'driver' => (string) DB::connection()->getDriverName(),
            'reachable' => false,
            'version' => null,
            'migrations' => 'unknown',
            'latency_ms' => null,
        ];

        try {
            $pdo = DB::connection()->getPdo();

            $start = microtime(true);
            $pdo->query('SELECT 1')->fetchColumn();
            $stats['latency_ms'] = (int) round((microtime(true) - $start) * 1000);
            $stats['reachable'] = true;

            if ($stats['driver'] === 'pgsql') {
                $stats['version'] = (string) $pdo->query('SHOW server_version')->fetchColumn();
            } elseif ($stats['driver'] === 'sqlite') {
                $stats['version'] = (string) $pdo->query('SELECT sqlite_version()')->fetchColumn();
            }

            try {
                $applied = (int) DB::table('migrations')->count();
                $files = count(glob(database_path('migrations').'/*.php')) ?: 0;
                $stats['migrations'] = $applied >= $files
                    ? 'up to date'
                    : "pending — {$applied} applied, {$files} defined";
            } catch (Throwable $e) {
                $stats['migrations'] = 'unavailable (migrations table missing)';
            }
        } catch (Throwable $e) {
            $stats['error'] = $this->safeReason($e);
        }

        return $stats;
    }

    /**
     * @return array{ok: bool, label: string}
     */
    protected function checkDatabase(): array
    {
        try {
            DB::connection()->getPdo()->query('SELECT 1')->fetchColumn();

            return ['ok' => true, 'label' => 'database'];
        } catch (Throwable $e) {
            return ['ok' => false, 'label' => 'database', 'error' => $this->safeReason($e)];
        }
    }

    /**
     * @return array{ok: bool, label: string}
     */
    protected function checkCache(): array
    {
        try {
            $probe = 'ffarena:health:'.bin2hex(random_bytes(6));
            Cache::put($probe, 1, (int) config('observability.health.cache_probe_ttl_seconds', 30));

            if (Cache::get($probe) !== 1) {
                return ['ok' => false, 'label' => 'cache', 'error' => 'cache probe read-back failed'];
            }

            Cache::forget($probe);

            return ['ok' => true, 'label' => 'cache'];
        } catch (Throwable $e) {
            return ['ok' => false, 'label' => 'cache', 'error' => $this->safeReason($e)];
        }
    }

    /**
     * @return array{ok: bool, label: string}
     */
    protected function checkFilesystem(): array
    {
        try {
            $path = 'health-probe-'.bin2hex(random_bytes(4)).'.txt';

            if (! Storage::put($path, 'ok')) {
                return ['ok' => false, 'label' => 'filesystem', 'error' => 'write failed'];
            }

            Storage::delete($path);

            return ['ok' => true, 'label' => 'filesystem'];
        } catch (Throwable $e) {
            return ['ok' => false, 'label' => 'filesystem', 'error' => $this->safeReason($e)];
        }
    }

    /**
     * @return array{ok: bool, label: string}
     */
    protected function checkQueue(): array
    {
        try {
            $stats = $this->queueStats();

            if (! $stats['reachable']) {
                return ['ok' => false, 'label' => 'queue', 'error' => 'queue table unreachable'];
            }

            return ['ok' => true, 'label' => 'queue'];
        } catch (Throwable $e) {
            return ['ok' => false, 'label' => 'queue', 'error' => $this->safeReason($e)];
        }
    }

    /**
     * @return array{ok: bool, label: string}
     */
    protected function checkConfig(): array
    {
        if (empty(config('app.key'))) {
            return ['ok' => false, 'label' => 'config', 'error' => 'APP_KEY missing'];
        }

        return ['ok' => true, 'label' => 'config'];
    }

    /**
     * Queue statistics + scheduler heartbeat. Safe for the dashboard and the
     * readiness probe; contains counts and ages only.
     *
     * @return array<string, mixed>
     */
    public function queueStats(): array
    {
        $stats = [
            'reachable' => false,
            'pending_jobs' => 0,
            'failed_jobs' => 0,
            'oldest_pending_seconds' => null,
            'scheduler_heartbeat_seconds_ago' => null,
        ];

        try {
            $stats['pending_jobs'] = (int) DB::table('jobs')->count();
            $stats['failed_jobs'] = (int) DB::table('failed_jobs')->count();
            $stats['reachable'] = true;

            $oldest = DB::table('jobs')->min('available_at');

            if ($oldest !== null) {
                $stats['oldest_pending_seconds'] = max(0, now()->timestamp - (int) $oldest);
            }

            $beat = DB::table('operations_heartbeats')->where('source', 'scheduler')->value('last_beat_at');

            if ($beat !== null) {
                $stats['scheduler_heartbeat_seconds_ago'] = max(0, now()->timestamp - strtotime((string) $beat));
            }
        } catch (Throwable) {
            // Keep the default (unreachable) shape.
        }

        return $stats;
    }

    /**
     * Private files/dir sizes for the dashboard, in bytes.
     *
     * @return array{exists: bool, size_bytes: int, files: int}
     */
    public function storageStats(): array
    {
        $dir = (string) config('backup.private_dir', storage_path('app/private'));

        if (! is_dir($dir)) {
            return ['exists' => false, 'size_bytes' => 0, 'files' => 0];
        }

        $files = File::allFiles($dir);

        return [
            'exists' => true,
            'size_bytes' => (int) array_sum(array_map(fn ($f) => (int) ($f->getSize() ?? 0), $files)),
            'files' => count($files),
        ];
    }

    /**
     * Never echo raw exception messages publicly; keep a short, class-only
     * hint for the admin/CLI diagnostics.
     */
    protected function safeReason(Throwable $e): string
    {
        return substr((string) ($e::class), 0, 80);
    }
}
