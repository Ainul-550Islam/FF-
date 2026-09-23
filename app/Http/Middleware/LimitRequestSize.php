<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LimitRequestSize
{
    protected int $maxSize = 2 * 1024 * 1024; // 2MB

    public function handle(Request $request, Closure $next): Response
    {
        $contentLength = $request->header('Content-Length');
        if ($contentLength && (int) $contentLength > $this->maxSize) {
            return response()->json(['error' => 'payload_too_large', 'message' => 'Request body too large, max 2MB'], 413);
        }

        // Also check actual content
        $content = $request->getContent();
        if (strlen($content) > $this->maxSize) {
            return response()->json(['error' => 'payload_too_large'], 413);
        }

        return $next($request);
    }
}
