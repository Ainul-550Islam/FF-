<?php

namespace App\Gateways;

use App\Contracts\GoogleOAuthProviderInterface;
use DomainException;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Google OAuth/OIDC provider backed by Laravel Socialite (Phase 14).
 *
 * Socialite implements Google's server-side OAuth 2.0 / OpenID Connect flow:
 * a signed `state` parameter stored in the session, authorization-code
 * exchange, and id-token verification against Google's keys. We never hand-
 * roll that cryptography.
 *
 * No credentials are configured in this project, so `isConfigured()` is
 * false and both `redirect()` and `user()` refuse to run — the UI honestly
 * reports "Google Sign-In is not configured".
 */
class SocialiteGoogleProvider implements GoogleOAuthProviderInterface
{
    public function isConfigured(): bool
    {
        return ! empty(config('services.google.client_id'))
            && ! empty(config('services.google.client_secret'))
            && ! empty(config('services.google.redirect'));
    }

    public function redirect(): RedirectResponse
    {
        if (! $this->isConfigured()) {
            throw new DomainException('Google Sign-In is not configured.');
        }

        return Socialite::driver('google')
            ->scopes(['openid', 'email', 'profile'])
            ->redirect();
    }

    public function user(): array
    {
        if (! $this->isConfigured()) {
            throw new DomainException('Google Sign-In is not configured.');
        }

        // NOTE: not stateless — the session-bound `state` parameter is
        // verified by Socialite, which is the CSRF defence for OAuth.
        $googleUser = Socialite::driver('google')->user();

        // `sub` is the stable Google subject identifier — never key an
        // identity on the (user-editable) display email alone.
        return [
            'id' => (string) $googleUser->getId(),
            'email' => $googleUser->getEmail(),
            'email_verified' => (bool) ($googleUser->user['email_verified'] ?? false),
            'name' => $googleUser->getName(),
        ];
    }
}
