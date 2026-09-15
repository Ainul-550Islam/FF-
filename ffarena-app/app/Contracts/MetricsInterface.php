<?php

namespace App\Contracts;

/**
 * Phase 16 — internal application metrics.
 *
 * A deliberately small, provider-neutral surface (counters, gauges, timings).
 * The default backend writes structured log lines; Prometheus/OpenTelemetry
 * can be added later without changing call sites.
 *
 * Implementations MUST be side-effect safe (never throw into business code)
 * and MUST NOT record secrets: no passwords, tokens, raw IPs/devices, or
 * payment credentials.
 */
interface MetricsInterface
{
    /**
     * Increment a counter. Labels must be low-cardinality (status classes,
     * event names) — never user identifiers, IPs or unbounded input.
     *
     * @param  array<string, string>  $labels
     */
    public function increment(string $metric, int $delta = 1, array $labels = []): void;

    /**
     * Record an instantaneous value (queue backlog, oldest job age, …).
     *
     * @param  array<string, string>  $labels
     */
    public function gauge(string $metric, float $value, array $labels = []): void;

    /**
     * Record a duration in milliseconds (HTTP latency, job runtime, …).
     *
     * @param  array<string, string>  $labels
     */
    public function timing(string $metric, float $milliseconds, array $labels = []): void;
}
