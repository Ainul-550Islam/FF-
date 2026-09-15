<?php

namespace App\Contracts;

use DomainException;

/**
 * Verifier for a Google OpenID Connect id_token (Phase 15 — mobile/API
 * Google sign-in).
 *
 * The real implementation validates the token server-side against Google
 * (issuer + audience + email_verified). Tests substitute a deterministic
 * fake so they never touch the network. When Google is not configured the
 * verifier reports `isConfigured() === false` and refuses to run.
 */
interface GoogleIdTokenVerifierInterface
{
    /**
     * Whether Google OIDC verification is configured.
     */
    public function isConfigured(): bool;

    /**
     * Verify an id_token and return the normalized Google user.
     *
     * @return array{id: string, email: ?string, email_verified: bool, name: ?string}
     *
     * @throws DomainException when the token is invalid or the provider is
     *                         not configured.
     */
    public function verify(string $idToken): array;
}
