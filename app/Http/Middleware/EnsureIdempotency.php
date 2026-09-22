<?php

namespace App\Http\Middleware;

use App\Services\IdempotencyService;
use Closure;
use DomainException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 15 — Idempotency-Key enforcement for critical mutation endpoints.
 *
 * When an `Idempotency-Key` header is present the request is resolved
 * against the idempotency store: a replay within the TTL returns the stored
 * response, a reuse with a different body is a 409, and a fresh key's
 * successful (2xx) response is stored for future replays. Requests without
 * the header pass straight through.
 */
class EnsureIdempotency
{
    public function __construct(protected IdempotencyService $idempotency)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $key = $this->idempotency->keyFrom($request);

        if ($key === null) {
            return $next($request);
        }

        $stored = $this->idempotency->resolve($request->user(), $key, $request);

        if ($stored !== null) {
            $response = $stored;

            // Surface replays with the standard header.
            $response->headers->set('Idempotency-Replayed', 'true');

            return $response;
        }

        try {
            $response = $next($request);
        } catch (DomainException $e) {
            // The service layer has already rejected a conflicting reuse.
            throw $e;
        }

        // Only successful mutations are recorded; a failed request can be
        // safely retried with the same key.
        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            $this->idempotency->store($request->user(), $key, $request, $response);
            $response->headers->set('Idempotency-Key-Processed', 'true');
        }

        return $response;
    }
}
