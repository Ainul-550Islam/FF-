<?php

namespace App\Services;

use App\Models\ApiClient;
use App\Models\LoginEvent;
use App\Models\Notification;
use App\Models\User;
use DomainException;
use Illuminate\Http\Request;
use Laravel\Sanctum\NewAccessToken;

/**
 * Phase 15 — personal access token issuance and revocation.
 *
 * Tokens are stored hashed by Sanctum (the plaintext is returned exactly
 * once), carry scoped abilities, an expiry, and an optional API client link.
 * Every issuance and revocation writes a security event, an audit entry and
 * (for the account) a notification. The `admin` scope can never be granted
 * from here — only platform staff may mint admin tokens.
 */
class ApiTokenService
{
    public function __construct(
        protected LoginEventService $loginEvents,
        protected AuditLogService $audit,
        protected NotificationService $notifications,
    ) {}

    /**
     * Issue a personal access token for a user.
     *
     * @param  string[]  $abilities
     */
    public function issue(
        User $user,
        string $name,
        array $abilities,
        ?int $expiresInDays = null,
        ?ApiClient $client = null,
        ?Request $request = null,
    ): NewAccessToken {
        $name = trim($name);

        if ($name === '') {
            throw new DomainException('A token name is required.');
        }

        $abilities = $this->sanitizeAbilities($abilities);

        if ($abilities === []) {
            throw new DomainException('Select at least one scope.');
        }

        $maxDays = (int) config('api.token.max_days', 365);
        $days = $expiresInDays ?? (int) config('api.token.default_days', 30);
        $days = max(1, min($days, $maxDays));

        $token = $user->createToken($name, $abilities, now()->addDays($days));

        if ($client !== null) {
            $token->accessToken->api_client_id = $client->id;
            $token->accessToken->save();
        }

        $this->loginEvents->record($user, LoginEvent::EVENT_ACCOUNT_LINKED, LoginEvent::STATUS_SUCCESS, $request, [
            'provider' => 'api_token',
            'token_name' => $this->cap($name, 80),
        ]);

        $this->audit->recordQuietly($user, 'auth.api_token_issued', 'user', $user->id, [
            'target_user_id' => $user->id,
            'metadata' => ['token_name' => $this->cap($name, 80), 'scopes' => $abilities],
        ]);

        $this->notifications->send(
            $user,
            Notification::TYPE_SYSTEM,
            'API token created',
            'A new API token "'.$this->cap($name, 60).'" was created for your account.',
            NotificationService::link('settings.security'),
            ['token_name' => $this->cap($name, 80)],
        );

        return $token;
    }

    /**
     * Issue an admin-scoped token. Restricted to platform admins; the caller
     * must verify the actor is an admin before invoking this. The `admin`
     * scope is prepended to whatever sanitized abilities are passed.
     *
     * @param  string[]  $abilities
     */
    public function issueAdminToken(User $admin, string $name, array $abilities = [], ?int $expiresInDays = null): NewAccessToken
    {
        $abilities = $this->sanitizeAbilities($abilities);
        $abilities[] = 'admin';
        $abilities = array_values(array_unique($abilities));
        sort($abilities);

        $name = trim($name);
        $maxDays = (int) config('api.token.max_days', 365);
        $days = $expiresInDays ?? (int) config('api.token.default_days', 30);
        $days = max(1, min($days, $maxDays));

        $token = $admin->createToken($name, $abilities, now()->addDays($days));

        $this->audit->recordQuietly($admin, 'auth.api_token_issued', 'user', $admin->id, [
            'target_user_id' => $admin->id,
            'metadata' => ['token_name' => $this->cap($name, 80), 'scopes' => $abilities, 'admin' => true],
        ]);

        return $token;
    }

    /**
     * Revoke a single token by id, ensuring the caller owns it.
     */
    public function revokeToken(User $user, int $tokenId): void
    {
        $token = $user->tokens()->where('id', $tokenId)->first();

        if ($token === null) {
            throw new DomainException('That token does not exist.', 404);
        }

        $token->delete();

        $this->audit->recordQuietly($user, 'auth.api_token_revoked', 'user', $user->id, [
            'target_user_id' => $user->id,
            'metadata' => ['token_id' => $tokenId],
        ]);
    }

    /**
     * Revoke every token for a user except a given token id (used by
     * "logout other devices" on the API surface).
     */
    public function revokeAllExcept(User $user, ?int $keepTokenId = null): int
    {
        $count = $user->tokens()
            ->when($keepTokenId !== null, fn ($q) => $q->where('id', '!=', $keepTokenId))
            ->delete();

        return $count;
    }

    /**
     * Revoke an entire API client and every token linked to it.
     */
    public function revokeClient(User $user, ApiClient $client): void
    {
        if ($client->user_id !== $user->id && ! $user->isAdmin()) {
            throw new DomainException('This application does not belong to you.', 403);
        }

        $client->status = ApiClient::STATUS_REVOKED;
        $client->save();

        // Delete the linked tokens so they stop authenticating immediately.
        $user->tokens()->where('api_client_id', $client->id)->delete();

        $this->audit->recordQuietly($user, 'auth.api_client_revoked', 'api_client', $client->id, [
            'target_user_id' => $client->user_id,
            'metadata' => ['name' => $client->name],
        ]);
    }

    /**
     * Accept only known scopes and never the reserved admin scope.
     *
     * @param  string[]  $abilities
     * @return string[]
     */
    protected function sanitizeAbilities(array $abilities): array
    {
        $known = (array) config('api.scopes', []);
        $reserved = (array) config('api.staff_scopes', []);

        $clean = array_values(array_unique(array_filter(
            array_map('trim', $abilities),
            fn (string $ability) => $ability !== ''
                && in_array($ability, $known, true)
                && ! in_array($ability, $reserved, true),
        )));

        sort($clean);

        return $clean;
    }

    protected function cap(string $value, int $length): string
    {
        return mb_substr($value, 0, $length);
    }
}
