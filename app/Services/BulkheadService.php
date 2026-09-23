<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class BulkheadService
{
    protected int $maxConcurrent = 10;

    protected int $maxQueue = 100;

    public function execute(string $provider, callable $callback, int $timeoutMs = 30000)
    {
        $activeKey = "bulkhead_{$provider}_active";
        $queueKey = "bulkhead_{$provider}_queue";

        $active = (int) Cache::get($activeKey, 0);

        if ($active >= $this->maxConcurrent) {
            // Check queue
            $queued = (int) Cache::get($queueKey, 0);
            if ($queued >= $this->maxQueue) {
                Log::warning('Bulkhead at capacity', ['provider' => $provider, 'active' => $active, 'queued' => $queued]);
                throw new \RuntimeException("Bulkhead $provider at capacity: too many requests");
            }

            // Queue the request
            Cache::increment($queueKey);
            try {
                // Wait with timeout
                $start = microtime(true);
                while ((int) Cache::get($activeKey, 0) >= $this->maxConcurrent) {
                    if ((microtime(true) - $start) * 1000 > $timeoutMs) {
                        Cache::decrement($queueKey);
                        throw new \RuntimeException("Bulkhead $provider timeout waiting for slot");
                    }
                    usleep(50000); // 50ms
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
        return [
            'provider' => $provider,
            'active' => (int) Cache::get("bulkhead_{$provider}_active", 0),
            'queued' => (int) Cache::get("bulkhead_{$provider}_queue", 0),
            'max_concurrent' => $this->maxConcurrent,
            'max_queue' => $this->maxQueue,
        ];
    }
}
