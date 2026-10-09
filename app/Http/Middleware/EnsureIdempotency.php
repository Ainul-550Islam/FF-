<?php

namespace App\Http\Middleware;

use App\Services\IdempotencyService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Phase 15 — Idempotency-Key enforcement for critical mutation endpoints.
 *
 * When an `Idempotency-Key` header is present the request is resolved
 * against the idempotency store: a replay within the TTL returns the stored
 * response, a reuse with a different body (or a concurrent in-flight
 * twin) is a 409, and a fresh key's successful (2xx) response is stored for
 * future replays. Requests without the header pass straight through.
 *
 * AUDIT FIX (2026-10-08, GAPS-11): the key is now RESERVED before the
 * request runs (see IdempotencyService), so concurrent duplicates can never
 * both execute — the old store-after-response ordering was a TOCTOU window
 * around every mutation this middleware claims to protect. Non-2xx responses
 * and thrown exceptions release the reservation so client retries with the
 * same key keep working exactly as documented.
 */
class EnsureIdempotency
{
    public function __construct(protected IdempotencyService $idempotency) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = $this->idempotency->keyFrom($request);

        if ($key === null) {
            return $next($request);
        }

        $user = $request->user();

        // Claim the key (or receive the replay / a 409). DomainException
        // propagates to the API exception renderer, which maps the code.
        $stored = $this->idempotency->reserve($user, $key, $request);

        if ($stored !== null) {
            $stored->headers->set('Idempotency-Replayed', 'true');

            return $stored;
        }

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            // The mutation failed (or the handler threw) — free the key so a
            // legitimate retry is not blocked by our reservation.
            $this->idempotency->release($user, $key);

            throw $e;
        }

        // Only successful mutations are recorded; a failed request can be
        // safely retried with the same key.
        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            $this->idempotency->store($user, $key, $request, $response);
            $response->headers->set('Idempotency-Key-Processed', 'true');

            return $response;
        }

        $this->idempotency->release($user, $key);

        return $response;
    }
}
