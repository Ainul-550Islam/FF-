<?php

namespace App\Support\Metrics;

use App\Contracts\MetricsInterface;

/**
 * Phase 16 — resolves the configured metrics backend.
 *
 * Drivers:
 *   log        — structured log lines (default, provider-neutral).
 *   null       — metrics disabled.
 *   prometheus — aggregated in the shared cache so the /metrics scrape
 *                endpoint can render the Prometheus exposition format
 *                (GAP-10 C).
 *
 * Further backends (OpenTelemetry) slot in here without touching any business
 * call site.
 */
class MetricsManager
{
    public function driver(): MetricsInterface
    {
        return match ((string) config('observability.metrics.driver', 'log')) {
            'null' => new NullMetrics(),
            'prometheus' => new PrometheusMetrics(null, (int) config('observability.metrics.ttl_seconds', PrometheusMetrics::TTL_SECONDS)),
            default => new LogMetrics((string) config('observability.metrics.channel', 'metrics')),
        };
    }
}
