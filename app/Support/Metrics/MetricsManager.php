<?php

namespace App\Support\Metrics;

use App\Contracts\MetricsInterface;

/**
 * Phase 16 — resolves the configured metrics backend.
 *
 * Drivers:
 *   log  — structured log lines (default, provider-neutral).
 *   null — metrics disabled.
 *
 * Future backends (Prometheus, OpenTelemetry) slot in here without touching
 * any business call site.
 */
class MetricsManager
{
    public function driver(): MetricsInterface
    {
        return match ((string) config('observability.metrics.driver', 'log')) {
            'null' => new NullMetrics(),
            default => new LogMetrics((string) config('observability.metrics.channel', 'metrics')),
        };
    }
}
