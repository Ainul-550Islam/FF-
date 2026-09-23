<?php

namespace Tests\Feature\Api;

use App\Contracts\GoogleIdTokenVerifierInterface;

/**
 * Deterministic Phase 15 test double for Google id_token verification.
 */
class ApiFakeGoogleVerifier implements GoogleIdTokenVerifierInterface
{
    /**
     * @param  array{id: string, email: ?string, email_verified: bool, name: ?string}  $user
     */
    public function __construct(
        protected array $user,
        protected ?\Throwable $error = null,
        protected bool $configured = true,
    ) {}

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function verify(string $idToken): array
    {
        if ($this->error !== null) {
            throw $this->error;
        }

        return $this->user;
    }
}
