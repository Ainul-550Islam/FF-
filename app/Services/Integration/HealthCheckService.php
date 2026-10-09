<?php

namespace App\Services\Integration;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Liveness probes for the integration stack (database, cache, Redis) surfaced
 * through admin diagnostics and the sidecar health endpoints.
 *
 * AUDIT FIX (2026-10-08, GAPS-20): failure details are LOGGED, never
 * returned. `getMessage()` on a connection failure carries transport detail —
 * hostnames, ports, unix socket paths, sometimes DSN fragments — in an
 * operator-facing JSON payload that travels through admin UIs (and, if ever
 * cached, its responses). Clients get the failing check name; the reason
 * lives in the application log, correlated by the same request id.
 *
 * The Redis probe also stops hard-coding one placeholder string: any
 * `CHANGE_ME_*` password counts as unconfigured (matches the .env.example
 * placeholder convention and the ServiceAuthenticator fail-closed rule), so
 * a freshly rendered deployment never attempts AUTH with a published value.
 */
class HealthCheckService
{
    public function checkDatabase(): array
    {
        try {
            DB::connection()->getPdo();
            DB::select('SELECT 1');

            return ['status' => 'ok'];
        } catch (\Throwable $e) {
            $this->logFailure('database', $e);

            return ['status' => 'fail', 'message' => 'Database connection failed. See application logs.'];
        }
    }

    public function checkCache(): array
    {
        try {
            Cache::put('health-check', 'ok', 10);

            return ['status' => 'ok'];
        } catch (\Throwable $e) {
            $this->logFailure('cache', $e);

            return ['status' => 'fail', 'message' => 'Cache store is unavailable. See application logs.'];
        }
    }

    public function checkRedis(): array
    {
        try {
            if (class_exists(\Redis::class)) {
                $redis = new \Redis();
                $redis->connect(config('database.redis.default.host', '127.0.0.1'), (int) config('database.redis.default.port', 6379), 1.0);
                $password = config('database.redis.default.password');

                if ($password && ! str_starts_with((string) $password, 'CHANGE_ME')) {
                    $redis->auth($password);
                }
                $redis->ping();
                $redis->close();

                return ['status' => 'ok'];
            }

            return ['status' => 'not_configured'];
        } catch (\Throwable $e) {
            $this->logFailure('redis', $e);

            return ['status' => 'fail', 'message' => 'Redis is unavailable. See application logs.'];
        }
    }

    public function fullCheck(): array
    {
        return ['database' => $this->checkDatabase(), 'cache' => $this->checkCache(), 'redis' => $this->checkRedis()];
    }

    protected function logFailure(string $check, \Throwable $e): void
    {
        Log::warning('Integration health check failed.', [
            'check' => $check,
            'error' => substr($e->getMessage(), 0, 500),
        ]);
    }
}
