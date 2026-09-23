<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class TracingService
{
    protected string $serviceName;

    protected ?string $jaegerEndpoint;

    protected array $spans = [];

    public function __construct()
    {
        $this->serviceName = config('app.name', 'ffarena');
        $this->jaegerEndpoint = env('JAEGER_ENDPOINT') ?: env('OTEL_EXPORTER_JAEGER_ENDPOINT');
    }

    public function generateTraceId(): string
    {
        return bin2hex(random_bytes(16)); // 32 hex chars
    }

    public function generateSpanId(): string
    {
        return bin2hex(random_bytes(8)); // 16 hex chars
    }

    public function startSpan(string $name, ?string $traceId = null, ?string $parentSpanId = null): array
    {
        $traceId = $traceId ?: $this->generateTraceId();
        $spanId = $this->generateSpanId();

        $span = [
            'trace_id' => $traceId,
            'span_id' => $spanId,
            'parent_id' => $parentSpanId,
            'name' => $name,
            'service' => $this->serviceName,
            'start_time' => microtime(true),
            'tags' => [],
        ];

        return $span;
    }

    public function endSpan(array &$span): void
    {
        $span['end_time'] = microtime(true);
        $span['duration_ms'] = ($span['end_time'] - $span['start_time']) * 1000;

        $this->spans[] = $span;

        // Export if batch size reached or in dev log
        if (count($this->spans) >= 100 || app()->environment('local', 'development')) {
            $this->export();
        }
    }

    public function export(): void
    {
        if (empty($this->spans)) {
            return;
        }

        $spans = $this->spans;
        $this->spans = [];

        if ($this->jaegerEndpoint) {
            // In production, send to Jaeger via HTTP
            // $this->sendToJaeger($spans);
            Log::info('Tracing export', ['spans' => count($spans), 'endpoint' => $this->jaegerEndpoint]);
        } elseif (app()->environment('local', 'development')) {
            foreach ($spans as $span) {
                Log::debug('Trace', [
                    'name' => $span['name'],
                    'trace_id' => $span['trace_id'],
                    'span_id' => $span['span_id'],
                    'duration_ms' => $span['duration_ms'] ?? 0,
                    'tags' => $span['tags'] ?? [],
                ]);
            }
        }
    }

    public function toTraceParent(string $traceId, string $spanId, bool $sampled = true): string
    {
        $flags = $sampled ? '01' : '00';
        // Ensure traceId 32 chars, spanId 16 chars
        $traceId = str_pad(substr(str_replace('-', '', $traceId), 0, 32), 32, '0');
        $spanId = str_pad(substr(str_replace('-', '', $spanId), 0, 16), 16, '0');

        return "00-{$traceId}-{$spanId}-{$flags}";
    }

    public function parseTraceParent(string $header): ?array
    {
        if (! preg_match('/^00-([a-f0-9]{32})-([a-f0-9]{16})-([a-f0-9]{2})$/', $header, $matches)) {
            return null;
        }

        return [
            'trace_id' => $matches[1],
            'span_id' => $matches[2],
            'flags' => $matches[3],
            'sampled' => $matches[3] === '01',
        ];
    }
}
