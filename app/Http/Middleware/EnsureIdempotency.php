<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureIdempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('POST') && $request->is('api/*')) {
            $key = $request->header('Idempotency-Key') ?: $request->header('X-Idempotency-Key');
            if (!$key) {
                return response()->json([
                    'error' => 'idempotency_key_required',
                    'message' => 'Idempotency-Key header required for POST api/*'
                ], 400);
            }
            if (strlen($key) < 8 || strlen($key) > 100) {
                return response()->json(['error' => 'invalid_idempotency_key', 'message' => 'Idempotency key must be 8-100 chars'], 400);
            }
        }
        return $next($request);
    }
}
