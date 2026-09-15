<?php

namespace App\Support\Metrics;

use App\Contracts\MetricsInterface;
use App\Support\RequestContext;
use Illuminate\Support\Facades\Log;

/**
 * Phase 16 — log-backed metrics.
 *
 * Emits one structured line per observation to the dedicated `metrics` log
 * channel. This is the default production backend: cheap, durable (rotated
 * with the rest of the logs) and shippable to Prometheus/OpenTelemetry later
 * without changing call sites. Never throws and never records secrets.
 */
class LogMetrics implements MetricsInterface
{
    public function __construct(protected string $channel = 'metrics') {}

    public function increment(string $metric, int $delta = 1, array $labels = []): void
    {
        $this->emit('counter', $metric, (float) $delta, $labels);
    }

    public function gauge(string $metric, float $value, array $labels = []): void
    {
        $this->emit('gauge', $metric, $value, $labels);
    }

    public function timing(string $metric, float $milliseconds, array $labels = []): void
    {
        $this->emit('timing', $metric, $milliseconds, $labels);
    }

    /**
     * @param  array<string, string>  $labels
     */
    protected function emit(string $kind, string $metric, float $value, array $labels): void
    {
        $context = RequestContext::snapshot();
        $context['kind'] = $kind;
        $context['metric'] = $metric;
        $context['value'] = $value;

        if ($labels !== []) {
            $context['labels'] = $labels;
        }

        Log::channel($this->channel)->info('metric', $context);
    }
}
