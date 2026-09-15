<?php

namespace App\Gateways;

use App\Contracts\IdentityVerificationProviderInterface;
use App\Models\User;

/**
 * Manual identity-review adapter (Phase 10).
 *
 * No external KYC provider is configured in this project, so this adapter is
 * deliberately honest: it never reports an automated verification and instead
 * returns `pending`, leaving the decision to an admin's manual review
 * (IdentityVerificationService::verifyManually). When a real KYC provider is
 * integrated, it becomes another adapter behind the same interface.
 */
class ManualIdentityProvider implements IdentityVerificationProviderInterface
{
    public function id(): string
    {
        return 'manual';
    }

    public function supportsAutomatedVerification(): bool
    {
        return false;
    }

    public function request(User $user): array
    {
        return [
            'status' => 'pending',
            'provider_reference' => null,
        ];
    }
}
