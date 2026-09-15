<?php

namespace Tests\Feature\Phase16\Stubs;

use App\Contracts\MetricsInterface;

/**
 * Phase 16 — in-memory recording metrics sink for tests.
 */
class RecordingMetrics implements MetricsInterface
{
    /** @var array<string, array<int, array{delta: int, labels: array}>> */
    public array $counters = [];

    /** @var array<string, array<int, array{value: float, labels: array}>> */
    public array $gauges = [];

    /** @var array<string, array<int, array{ms: float, labels: array}>> */
    public array $timings = [];

    public function increment(string $metric, int $delta = 1, array $labels = []): void
    {
        $this->counters[$metric][] = ['delta' => $delta, 'labels' => $labels];
    }

    public function gauge(string $metric, float $value, array $labels = []): void
    {
        $this->gauges[$metric][] = ['value' => $value, 'labels' => $labels];
    }

    public function timing(string $metric, float $milliseconds, array $labels = []): void
    {
        $this->timings[$metric][] = ['ms' => $milliseconds, 'labels' => $labels];
    }

    public function count(string $metric): int
    {
        return count($this->counters[$metric] ?? []);
    }
}
