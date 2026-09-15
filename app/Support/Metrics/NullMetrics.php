<?php

namespace App\Support\Metrics;

use App\Contracts\MetricsInterface;

/**
 * Phase 16 — disabled metrics sink. Used when observability is turned off
 * (e.g. metrics driver "null"); consumes counters without writing anything.
 */
class NullMetrics implements MetricsInterface
{
    public function increment(string $metric, int $delta = 1, array $labels = []): void
    {
        //
    }

    public function gauge(string $metric, float $value, array $labels = []): void
    {
        //
    }

    public function timing(string $metric, float $milliseconds, array $labels = []): void
    {
        //
    }
}
