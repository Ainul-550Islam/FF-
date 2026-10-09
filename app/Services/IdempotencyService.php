<?php

namespace App\Services;

use App\Models\ApiIdempotencyKey;
use App\Models\User;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 15 — API idempotency for critical mutation endpoints.
 *
 * The client sends an `Idempotency-Key` header. The service stores a hash of
 * the key plus a hash of the canonical request body. A repeat within the TTL
 * returns the stored response; a repeat with a *different* body is a 409
 * conflict. Only the SHA-256 of every input is persisted — never the raw key
 * or body.
 *
 * AUDIT FIX (2026-10-08, GAPS-11) — reserve-then-execute. The middleware used
 * to only WRITE the record after the request completed, so two concurrent
 * requests carrying the same key both resolved to "nothing stored" and both
 * executed the mutation — the exact double-spend the header promises to
 * prevent (and the "already in progress" branch in resolve() was dead code,
 * since a row only ever existed with a response in it). Now the FIRST request
 * atomically claims the key by inserting an in-flight reservation, relying on
 * the `(user_id, key)` unique constraint the store has always had; concurrent
 * duplicates collide on it and receive 409 / the replayed response. Abandoned
 * reservations (process crash mid-request) are taken over once older than
 * RESERVATION_STALE_SECONDS, so a dead worker can never wedge a key for the
 * full 24h TTL.
 *
 * Portability note: the unique index treats NULL `user_id` as distinct on
 * PostgreSQL, so reservations only hard-collide for authenticated callers —
 * which is the documented consumer set (guest idempotency stays best-effort,
 * exactly as before this fix).
 */
class IdempotencyService
{
    /**
     * An in-flight reservation older than this is assumed abandoned and may
     * be superseded. Comfortably above the slowest API request the app can
     * make (gateway round-trips included).
     */
    public const RESERVATION_STALE_SECONDS = 900;

    /**
     * Resolve the idempotency key for a request, or null when the header is
     * absent (non-idempotent requests pass through).
     */
    public function keyFrom(Request $request): ?string
    {
        $key = trim((string) $request->header('Idempotency-Key', ''));

        return $key === '' ? null : $key;
    }

    /**
     * Look up a prior response for a key, enforcing the body fingerprint.
     *
     * @throws DomainException when the key was reused with a different body.
     */
    public function resolve(?User $user, string $key, Request $request): ?Response
    {
        $hash = $this->hash($key);
        $record = $this->find($user, $hash);

        if ($record === null) {
            return null;
        }

        if ($record->isExpired()) {
            $record->delete();

            return null;
        }

        return $this->decide($record, $this->fingerprint($request));
    }

    /**
     * Claim a key for execution.
     *
     * Returns null when the caller now OWNS the key (an in-flight
     * reservation row exists and the request may proceed). Returns the stored
     * Response when an earlier completed response must be replayed. Throws
     * 409 DomainException when the key is mid-flight in another request or
     * was reused with a different body.
     */
    public function reserve(?User $user, string $key, Request $request): ?Response
    {
        $hash = $this->hash($key);
        $fingerprint = $this->fingerprint($request);

        // Bounded retry: each collision re-reads the winning row and decides
        // on it; a row that keeps disappearing means an opponent actively
        // cleaning up — never spin past the attempt budget.
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $record = $this->find($user, $hash);

            if ($record !== null) {
                if ($record->isExpired() || $this->isStaleReservation($record)) {
                    $record->delete();
                    $record = null;
                } else {
                    return $this->decide($record, $fingerprint);
                }
            }

            if ($record === null) {
                try {
                    $this->insertReservation($user, $hash, $request, $fingerprint);

                    return null;
                } catch (UniqueConstraintViolationException) {
                    // Lost the race for this key — loop and read the winner.
                    continue;
                }
            }
        }

        throw new DomainException('This idempotency key is being processed. Retry shortly.', 409);
    }

    /**
     * Absolve a reservation when the request failed with a non-2xx response
     * (or threw): the client must be allowed to retry the same key, matching
     * the pre-fix behaviour where failures were never stored.
     */
    public function release(?User $user, string $key): void
    {
        $hash = $this->hash($key);

        $record = $this->find($user, $hash);

        if ($record !== null && $record->response_status === null) {
            $record->delete();
        }
    }

    /**
     * Persist the result of an idempotent request for future replays. Called
     * with the reservation row already present (reserve()); the update path
     * also tolerates a missing row so a manual store stays valid.
     */
    public function store(?User $user, string $key, Request $request, Response $response): void
    {
        $hash = $this->hash($key);
        $record = $this->find($user, $hash);

        if ($record === null) {
            $record = new ApiIdempotencyKey();
            $record->user_id = $user?->id;
            $record->key = $hash;
        }

        $record->method = $request->method();
        $record->path = $this->capPath($request->path());
        $record->request_fingerprint = $this->fingerprint($request);
        $record->response_status = $response->getStatusCode();
        $record->response_body = $this->bodyToArray($response);
        $record->expires_at = now()->addSeconds((int) config('api.idempotency.ttl_seconds', 86400));
        $record->save();
    }

    /**
     * Decide the outcome of hitting an EXISTING, live record.
     *
     * @throws DomainException
     */
    protected function decide(ApiIdempotencyKey $record, string $fingerprint): ?Response
    {
        if ($record->request_fingerprint !== $fingerprint) {
            throw new DomainException('This idempotency key was already used with a different request body.', 409);
        }

        // A row with no stored status is a live in-flight reservation — the
        // mutation is executing elsewhere; racing it is exactly what the key
        // forbids.
        if ($record->response_status === null) {
            throw new DomainException('A request with this idempotency key is already in progress.', 409);
        }

        return new Response(
            json_encode($record->response_body, JSON_UNESCAPED_SLASHES),
            (int) $record->response_status,
            ['Content-Type' => 'application/json'],
        );
    }

    protected function insertReservation(?User $user, string $hash, Request $request, string $fingerprint): void
    {
        $record = new ApiIdempotencyKey();
        $record->user_id = $user?->id;
        $record->key = $hash;
        $record->method = $request->method();
        $record->path = $this->capPath($request->path());
        $record->request_fingerprint = $fingerprint;
        $record->response_status = null;   // in flight
        $record->response_body = null;
        $record->expires_at = now()->addSeconds((int) config('api.idempotency.ttl_seconds', 86400));
        $record->save();
    }

    protected function isStaleReservation(ApiIdempotencyKey $record): bool
    {
        return $record->response_status === null
            && $record->created_at !== null
            && $record->created_at->lt(now()->subSeconds(self::RESERVATION_STALE_SECONDS));
    }

    /**
     * A client may only ever see its own records (null user = anonymous).
     */
    protected function find(?User $user, string $hash): ?ApiIdempotencyKey
    {
        return ApiIdempotencyKey::where('key', $hash)
            ->when($user !== null, fn ($q) => $q->where('user_id', $user->id))
            ->when($user === null, fn ($q) => $q->whereNull('user_id'))
            ->first();
    }

    protected function hash(string $key): string
    {
        return hash('sha256', $key);
    }

    /**
     * Canonical fingerprint of the request: method + path + sorted body.
     *
     * The sort is RECURSIVE (GAPS-11): the previous single-level ksort left
     * nested objects key-order-sensitive, so a byte-different-but-semantically
     * identical JSON body on replay was misclassified as "different request"
     * and rejected with a spurious 409.
     */
    protected function fingerprint(Request $request): string
    {
        $body = Arr::sortRecursive($request->all());

        return hash('sha256', $request->method().'|'.$request->path().'|'.json_encode($body, JSON_UNESCAPED_SLASHES));
    }

    protected function bodyToArray(Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);

        return is_array($decoded) ? $decoded : ['raw' => (string) $response->getContent()];
    }

    protected function capPath(string $path): string
    {
        return mb_substr($path, 0, 255);
    }
}
