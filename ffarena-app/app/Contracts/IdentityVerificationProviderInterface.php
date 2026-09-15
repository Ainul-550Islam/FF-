<?php

namespace App\Contracts;

use App\Models\User;
use DomainException;

/**
 * Provider abstraction for identity verification (Phase 10).
 *
 * The application never fabricates a successful verification: adapters must
 * honestly report what they can do. Today the only adapter is the manual
 * review provider, which returns `pending` and requires an admin to complete
 * the review. A real KYC provider can be added later without changing the
 * domain.
 */
interface IdentityVerificationProviderInterface
{
    /**
     * The stable provider identifier (stored on identity_verifications.provider).
     */
    public function id(): string;

    /**
     * Whether this provider can perform automated (provider-confirmed)
     * verification. The manual provider cannot.
     */
    public function supportsAutomatedVerification(): bool;

    /**
     * Initiate a verification for the user.
     *
     * @return array{status: string, provider_reference: ?string}
     *
     * @throws DomainException when the provider cannot actually verify.
     */
    public function request(User $user): array;
}
