<?php

namespace App\Support;

use App\Contracts\MetricsInterface;

/**
 * Phase 16 — exception-safe metrics facade.
 *
 * Business seams call these static helpers instead of the container directly
 * so that a metrics misconfiguration can never break a payment, a payout, a
 * registration or a score submission. Every call is swallowed on failure and
 * labels are truncated/sanitized to keep cardinality bounded.
 */
final class Metrics
{
    public static function increment(string $metric, int $delta = 1, array $labels = []): void
    {
        try {
            app(MetricsInterface::class)->increment($metric, $delta, self::sanitize($labels));
        } catch (\Throwable) {
            // Metrics must never affect business flows.
        }
    }

    public static function gauge(string $metric, float $value, array $labels = []): void
    {
        try {
            app(MetricsInterface::class)->gauge($metric, $value, self::sanitize($labels));
        } catch (\Throwable) {
            // Metrics must never affect business flows.
        }
    }

    public static function timing(string $metric, float $milliseconds, array $labels = []): void
    {
        try {
            app(MetricsInterface::class)->timing($metric, $milliseconds, self::sanitize($labels));
        } catch (\Throwable) {
            // Metrics must never affect business flows.
        }
    }

    /**
     * Keep labels low-cardinality and safe: string-cast, trimmed, truncated.
     *
     * @param  array<string, string>  $labels
     * @return array<string, string>
     */
    private static function sanitize(array $labels): array
    {
        $clean = [];

        foreach ($labels as $key => $value) {
            $clean[substr((string) $key, 0, 40)] = substr(trim((string) $value), 0, 64);
        }

        return $clean;
    }
}
