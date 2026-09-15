<?php

namespace App\Contracts;

use DomainException;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Provider abstraction for Google OAuth/OIDC sign-in (Phase 14).
 *
 * The real implementation wraps Laravel Socialite's Google driver (the
 * maintained, server-side OAuth/OIDC flow with signed state and id-token
 * verification). Tests substitute a deterministic fake so they never touch
 * the network.
 */
interface GoogleOAuthProviderInterface
{
    /**
     * Whether Google credentials are configured.
     */
    public function isConfigured(): bool;

    /**
     * Redirect the user to Google's consent screen (state is handled by the
     * underlying Socialite driver).
     *
     * @throws DomainException when not configured.
     */
    public function redirect(): RedirectResponse;

    /**
     * Resolve the OAuth callback into a normalized Google user.
     *
     * @return array{id: string, email: ?string, email_verified: bool, name: ?string}
     *
     * @throws DomainException on invalid state, token errors or a provider
     *                         that is not configured.
     */
    public function user(): array;
}
