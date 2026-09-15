<?php

namespace App\Services;

use App\Models\ApiClient;
use App\Models\User;
use DomainException;
use Laravel\Sanctum\NewAccessToken;

/**
 * Phase 15 — API application (client) management.
 *
 * A client is a named, user-owned API application. Creating one mints its
 * first token (returned exactly once, never stored in plaintext). Admins can
 * additionally inspect and revoke any client.
 */
class ApiClientService
{
    public function __construct(
        protected ApiTokenService $tokens,
        protected AuditLogService $audit,
    ) {
    }

    /**
     * Create a client and issue its first token.
     *
     * @param  string[]  $abilities
     */
    public function create(User $owner, string $name, string $description, array $abilities, ?int $expiresInDays = null): NewAccessToken
    {
        $name = trim($name);

        if ($name === '') {
            throw new DomainException('An application name is required.');
        }

        $client = new ApiClient();
        $client->user_id = $owner->id;
        $client->name = mb_substr($name, 0, 80);
        $client->description = $description !== '' ? mb_substr(trim($description), 0, 255) : null;
        $client->status = ApiClient::STATUS_ACTIVE;
        $client->save();

        $this->audit->recordQuietly($owner, 'auth.api_client_created', 'api_client', $client->id, [
            'target_user_id' => $owner->id,
            'metadata' => ['name' => $client->name],
        ]);

        return $this->tokens->issue($owner, $client->name, $abilities, $expiresInDays, $client);
    }

    /**
     * The user's clients, newest first.
     */
    public function forUser(User $user)
    {
        return ApiClient::where('user_id', $user->id)->orderByDesc('id')->get();
    }

    /**
     * The user's personal access tokens, newest first.
     */
    public function tokensFor(User $user)
    {
        return $user->tokens()->orderByDesc('id')->get();
    }

    /**
     * Admin view of all clients.
     */
    public function allClients()
    {
        return ApiClient::with('user:id,name,email')->orderByDesc('id')->get();
    }
}
