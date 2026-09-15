<?php

namespace App\Services;

use App\Models\ApiIdempotencyKey;
use App\Models\User;
use DomainException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 15 — API idempotency for critical mutation endpoints.
 *
 * The client sends an `Idempotency-Key` header. The service stores a hash of
 * the key plus a hash of the canonical request body. A repeat within the TTL
 * returns the stored response; a repeat with a *different* body is a 409
 * conflict. Only the SHA-256 of every input is persisted — never the raw key
 * or body.
 */
class IdempotencyService
{
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

        $fingerprint = $this->fingerprint($request);

        if ($record->request_fingerprint !== $fingerprint) {
            throw new DomainException('This idempotency key was already used with a different request body.', 409);
        }

        // A stored response that has not yet run has no status; that means a
        // concurrent in-flight request — treat as conflict to avoid races.
        if ($record->response_status === null) {
            throw new DomainException('A request with this idempotency key is already in progress.', 409);
        }

        return new Response(
            json_encode($record->response_body, JSON_UNESCAPED_SLASHES),
            (int) $record->response_status,
            ['Content-Type' => 'application/json'],
        );
    }

    /**
     * Persist the result of an idempotent request for future replays.
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
     */
    protected function fingerprint(Request $request): string
    {
        $body = $request->all();

        ksort($body);

        return hash('sha256', $request->method() . '|' . $request->path() . '|' . json_encode($body, JSON_UNESCAPED_SLASHES));
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
