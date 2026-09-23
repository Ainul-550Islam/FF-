<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFeatureEnabled
{
    public function handle(Request $request, Closure $next, ...$params): Response
    {
        $feature = $params[0] ?? null;
        if ($feature) {
            $enabled = config('features.'.$feature);
            if ($enabled === null) {
                $enabled = config('features.flags.'.$feature, true);
            }
            if ($enabled === false) {
                return response()->json(['error' => 'not_found', 'message' => 'Feature disabled'], 404);
            }
        }

        return $next($request);
    }
}
