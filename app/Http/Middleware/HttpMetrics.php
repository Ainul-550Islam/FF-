<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HttpMetrics
{
    public function handle(Request $request, Closure $next): Response
    {
        $start = microtime(true);
        $response = $next($request);
        $latency = (microtime(true) - $start) * 1000;
        $response->headers->set('X-Response-Time', round($latency, 2) . 'ms');
        return $response;
    }
}
