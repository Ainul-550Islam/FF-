<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class DistributedBulkheadService
{
    protected int $maxConcurrent = 10;

    protected int $maxQueue = 100;

    protected bool $useRedis = false;

    public function __construct()
    {
        $this->useRedis = extension_loaded('redis') && config('database.redis.default.host');
    }

    public function execute(string $provider, callable $callback, int $timeoutMs = 30000)
    {
        $activeKey = "bulkhead:{$provider}:active";
        $queueKey = "bulkhead:{$provider}:queue";

        // Try distributed via Redis if available
        if ($this->useRedis) {
            try {
                return $this->executeWithRedis($provider, $callback, $timeoutMs);
            } catch (\Exception $e) {
                Log::warning('Redis bulkhead failed, falling back to Cache', ['error' => $e->getMessage()]);
            }
        }

        // Fallback to Cache (file/database)
        return $this->executeWithCache($provider, $callback, $timeoutMs);
    }

    protected function executeWithRedis(string $provider, callable $callback, int $timeoutMs)
    {
        $activeKey = "bulkhead:{$provider}:active";

        $count = Redis::incr($activeKey);
        Redis::expire($activeKey, 60); // Auto-cleanup

        if ($count > $this->maxConcurrent) {
            Redis::decr($activeKey);
            throw new \RuntimeException("Bulkhead $provider at capacity (distributed): $count/{$this->maxConcurrent}");
        }

        try {
            return $callback();
        } finally {
            Redis::decr($activeKey);
        }
    }

    protected function executeWithCache(string $provider, callable $callback, int $timeoutMs)
    {
        $activeKey = "bulkhead_{$provider}_active";
        $queueKey = "bulkhead_{$provider}_queue";

        $active = (int) Cache::get($activeKey, 0);

        if ($active >= $this->maxConcurrent) {
            $queued = (int) Cache::get($queueKey, 0);
            if ($queued >= $this->maxQueue) {
                throw new \RuntimeException("Bulkhead $provider at capacity: too many requests");
            }

            Cache::increment($queueKey);
            try {
                $start = microtime(true);
                while ((int) Cache::get($activeKey, 0) >= $this->maxConcurrent) {
                    if ((microtime(true) - $start) * 1000 > $timeoutMs) {
                        Cache::decrement($queueKey);
                        throw new \RuntimeException("Bulkhead $provider timeout");
                    }
                    usleep(50000);
                }
            } finally {
                Cache::decrement($queueKey);
            }
        }

        Cache::increment($activeKey);
        try {
            return $callback();
        } finally {
            Cache::decrement($activeKey);
        }
    }

    public function getStats(string $provider): array
    {
        if ($this->useRedis) {
            try {
                $active = (int) Redis::get("bulkhead:{$provider}:active") ?: 0;

                return [
                    'provider' => $provider,
                    'active' => $active,
                    'max_concurrent' => $this->maxConcurrent,
                    'max_queue' => $this->maxQueue,
                    'distributed' => true,
                    'backend' => 'redis',
                ];
            } catch (\Exception $e) {
                // Fallback
            }
        }

        return [
            'provider' => $provider,
            'active' => (int) Cache::get("bulkhead_{$provider}_active", 0),
            'queued' => (int) Cache::get("bulkhead_{$provider}_queue", 0),
            'max_concurrent' => $this->maxConcurrent,
            'max_queue' => $this->maxQueue,
            'distributed' => false,
            'backend' => 'cache',
        ];
    }
}
