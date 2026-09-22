<?php

namespace App\Http\Middleware;

use App\Support\Metrics;
use App\Support\RequestContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 16 — HTTP/API request metrics.
 *
 * Counts requests and status classes and records response latency. Labels are
 * strictly low-cardinality (method + api/web + status class) — never paths,
 * IPs or user identifiers.
 */
class HttpMetrics
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $isApi = str_starts_with($request->path(), 'api/');

        Metrics::increment($isApi ? 'http.api_requests' : 'http.requests', 1, [
            'method' => strtolower($request->getMethod()),
        ]);

        $class = $this->statusClass($response->getStatusCode());

        Metrics::increment('http.responses', 1, ['status' => $class.'xx', 'kind' => $isApi ? 'api' : 'web']);

        if ($class >= 4) {
            Metrics::increment($class === 5 ? 'http.server_errors' : 'http.client_errors', 1, [
                'kind' => $isApi ? 'api' : 'web',
            ]);
        }

        $elapsed = RequestContext::elapsedMs();

        if ($elapsed !== null) {
            Metrics::timing('http.response_ms', $elapsed, ['kind' => $isApi ? 'api' : 'web']);
        }

        return $response;
    }

    protected function statusClass(int $status): int
    {
        return (int) floor($status / 100);
    }
}
