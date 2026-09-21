<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class TracingMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $traceId = $request->header('X-Trace-ID') ?: ($request->header('traceparent') ? $this->parseTraceParent($request->header('traceparent')) : (string) Str::uuid());
        $spanId = (string) Str::uuid();
        
        // W3C Trace Context
        if ($request->header('traceparent')) {
            $traceParent = $request->header('traceparent');
            // Format: 00-traceID-spanID-flags
            if (preg_match('/^00-([a-f0-9]{32})-([a-f0-9]{16})-([a-f0-9]{2})$/', $traceParent, $matches)) {
                $traceId = $matches[1];
                $parentSpanId = $matches[2];
                $request->headers->set('X-Parent-Span-ID', $parentSpanId);
            }
        }
        
        $request->headers->set('X-Trace-ID', $traceId);
        $request->headers->set('X-Span-ID', $spanId);
        
        // Generate traceparent for downstream
        $traceParentOut = sprintf('00-%s-%s-01', str_replace('-', '', $traceId), substr(str_replace('-', '', $spanId), 0, 16));
        
        $response = $next($request);
        
        $response->headers->set('X-Trace-ID', $traceId);
        $response->headers->set('X-Span-ID', $spanId);
        $response->headers->set('traceparent', $traceParentOut);
        
        return $response;
    }
    
    protected function parseTraceParent(string $header): string
    {
        if (preg_match('/^00-([a-f0-9]{32})-/', $header, $matches)) {
            return $matches[1];
        }
        return (string) Str::uuid();
    }
}
