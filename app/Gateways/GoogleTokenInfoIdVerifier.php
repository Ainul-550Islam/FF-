<?php

namespace App\Gateways;

use App\Contracts\GoogleIdTokenVerifierInterface;
use DomainException;
use Illuminate\Support\Facades\Http;

/**
 * Google OpenID Connect id_token verification via Google's tokeninfo
 * endpoint (Phase 15).
 *
 * The token is validated server-side against Google: issuer, audience (the
 * configured client id) and `email_verified` must all pass before the
 * identity is accepted. No client-supplied email/name is trusted. When the
 * OAuth credentials are absent the verifier honestly reports "not
 * configured".
 *
 * NOTE: for new integrations prefer verifying the JWT signature against
 * Google's JWKS endpoint; tokeninfo is retained here for its simplicity and
 * is only ever called with a token the client obtained from Google directly.
 */
class GoogleTokenInfoIdVerifier implements GoogleIdTokenVerifierInterface
{
    public const TOKENINFO_URL = 'https://oauth2.googleapis.com/tokeninfo';

    public function isConfigured(): bool
    {
        return ! empty(config('services.google.client_id'));
    }

    public function verify(string $idToken): array
    {
        if (! $this->isConfigured()) {
            throw new DomainException('Google Sign-In is not configured.');
        }

        $idToken = trim($idToken);

        if ($idToken === '') {
            throw new DomainException('A Google id token is required.');
        }

        $response = Http::timeout(10)->get(self::TOKENINFO_URL, ['id_token' => $idToken]);

        if ($response->failed()) {
            throw new DomainException('The Google id token could not be verified.');
        }

        $claims = $response->json();

        if (! is_array($claims)) {
            throw new DomainException('The Google id token could not be verified.');
        }

        // Server-side validation: issuer + audience + email_verified.
        if (($claims['iss'] ?? null) !== 'accounts.google.com'
            && ($claims['iss'] ?? null) !== 'https://accounts.google.com') {
            throw new DomainException('The Google id token issuer is invalid.');
        }

        $audience = $claims['aud'] ?? null;
        $expectedAudience = (string) config('services.google.client_id');

        if ($audience !== $expectedAudience) {
            throw new DomainException('The Google id token audience is invalid.');
        }

        $subject = (string) ($claims['sub'] ?? '');

        if ($subject === '') {
            throw new DomainException('The Google id token has no subject.');
        }

        $email = isset($claims['email']) ? (string) $claims['email'] : null;
        $emailVerified = (bool) ($claims['email_verified'] ?? false);

        if ($email !== null && ! $emailVerified) {
            // Never key an identity on an unverified email.
            $email = null;
        }

        return [
            'id' => $subject,
            'email' => $email,
            'email_verified' => $emailVerified,
            'name' => isset($claims['name']) ? (string) $claims['name'] : null,
        ];
    }
}
