<?php

namespace App\Http\Middleware;

use App\Support\RequestContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 13/16 — per-request correlation.
 *
 * Assigns a correlation id to every HTTP request:
 *  - an inbound X-Request-ID is trusted ONLY when it matches the safe format
 *    (8-64 chars of [A-Za-z0-9-]); oversized/arbitrary ids are replaced;
 *  - the id is exposed as the response header (X-Request-ID);
 *  - it is stored on the request (request_id + audit_request_id) so the
 *    Phase 13 audit trail keeps correlating rows to requests;
 *  - it is registered in RequestContext so the structured-logging processor,
 *    the error reporter and the metrics pipeline can attach it.
 *
 * Cheap, side-effect free, and first in the global middleware stack.
 */
class AssignAuditRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) config('observability.request_id.header', 'X-Request-ID');
        $inbound = trim((string) $request->header($header, ''));

        $requestId = $this->resolve($inbound);

        $request->attributes->set('request_id', $requestId);
        $request->attributes->set('audit_request_id', $requestId);

        RequestContext::start($request, $requestId);

        $response = $next($request);

        $this->decorate($response, $header, $requestId);

        return $response;
    }

    protected function resolve(string $inbound): string
    {
        $pattern = (string) config('observability.request_id.pattern', '/^[A-Za-z0-9\-]{8,64}$/');

        if (config('observability.request_id.accept_inbound', true) && $inbound !== '' && preg_match($pattern, $inbound) === 1) {
            return $inbound;
        }

        return (string) Str::uuid();
    }

    protected function decorate(Response $response, string $header, string $requestId): void
    {
        if (! $response->headers->has($header)) {
            $response->headers->set($header, $requestId);
        }
    }
}
