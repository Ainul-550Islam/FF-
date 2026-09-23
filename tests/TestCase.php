<?php

namespace Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Create a model row through explicit attribute assignment.
     *
     * The financial models (LedgerEntry, Payout, FinancialSettlement, Payment)
     * are deliberately fully guarded — no financial effect may ever be
     * mass-assigned — so test fixtures that need a raw row build it
     * attribute-by-attribute instead of Model::create([...]).
     *
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $attributes
     */
    protected function createRow(string $model, array $attributes)
    {
        $instance = new $model();

        foreach ($attributes as $key => $value) {
            $instance->{$key} = $value;
        }

        $instance->save();

        return $instance;
    }

    /**
     * Update a guarded model through explicit attribute assignment (the
     * counterpart to createRow for workflow rows that cannot be mass-assigned).
     *
     * @param  Model  $instance
     * @param  array<string, mixed>  $attributes
     */
    protected function updateRow($instance, array $attributes)
    {
        foreach ($attributes as $key => $value) {
            $instance->{$key} = $value;
        }

        $instance->save();

        return $instance;
    }

    protected function isPostgresAvailable(): bool
    {
        try {
            $config = config('database.connections.pgsql');
            if (! $config) {
                return false;
            }
            $pdo = new \PDO("pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}", $config['username'], $config['password'], [\PDO::ATTR_TIMEOUT => 2]);
            $pdo->query('SELECT 1');

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function isRedisAvailable(): bool
    {
        try {
            if (! class_exists(\Redis::class) && ! class_exists(\Illuminate\Support\Facades\Redis::class)) {
                return false;
            }
            if (class_exists(\Redis::class)) {
                $redis = new \Redis();
                $redis->connect(config('database.redis.default.host', '127.0.0.1'), (int) config('database.redis.default.port', 6379), 1.0);
                $password = config('database.redis.default.password');
                if ($password && $password !== 'CHANGE_ME_REDIS_PASSWORD_PLACEHOLDER') {
                    $redis->auth($password);
                }
                $redis->ping();
                $redis->close();

                return true;
            }
            \Illuminate\Support\Facades\Redis::connection()->ping();

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function isDockerAvailable(): bool
    {
        try {
            $output = shell_exec('docker --version 2>&1');

            return $output && str_contains($output, 'Docker version');
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function isGoAvailable(): bool
    {
        try {
            $output = shell_exec('go version 2>&1');

            return $output && str_contains($output, 'go version');
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function isRustAvailable(): bool
    {
        try {
            $output = shell_exec('cargo --version 2>&1');

            return $output && str_contains($output, 'cargo');
        } catch (\Throwable $e) {
            return false;
        }
    }
}
