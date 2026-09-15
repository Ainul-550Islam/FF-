<?php

namespace App\Services;

use App\Contracts\IdentityVerificationProviderInterface;
use App\Gateways\ManualIdentityProvider;
use App\Models\IdentityVerification;
use App\Models\Notification;
use App\Models\User;
use DomainException;
use Illuminate\Support\Carbon;

/**
 * Identity-verification state machine + provider abstraction (Phase 10).
 *
 *   unverified → pending → verified / rejected / review_required
 *   verified   → expired (lazily, when expires_at passes)
 *
 * The application NEVER fabricates verification: `verified` is only reached
 * through an explicit admin manual review. The default provider (manual)
 * cannot perform automated verification; future real KYC providers plug in
 * behind IdentityVerificationProviderInterface without changing the domain.
 */
class IdentityVerificationService
{
    /**
     * Registered providers, keyed by id.
     *
     * @var array<string, IdentityVerificationProviderInterface>
     */
    protected array $providers = [];

    public function __construct(
        ManualIdentityProvider $manual,
        protected NotificationService $notifications,
    ) {
        $this->providers[$manual->id()] = $manual;
    }

    /**
     * The user's verification record (lazily created as unverified).
     */
    public function recordFor(User $user): IdentityVerification
    {
        $record = $user->identityVerification()->first();

        if ($record !== null) {
            return $record;
        }

        $record = new IdentityVerification();
        $record->user_id = $user->id;
        $record->status = IdentityVerification::STATUS_UNVERIFIED;
        $record->provider = 'manual';
        $record->save();

        return $record;
    }

    /**
     * The effective status, lazily expiring stale verifications.
     */
    public function effectiveStatus(User $user): IdentityVerification
    {
        $record = $this->recordFor($user);

        if ($record->status === IdentityVerification::STATUS_VERIFIED
            && $record->expires_at !== null
            && $record->expires_at->isPast()) {
            $record->status = IdentityVerification::STATUS_EXPIRED;
            $record->save();
        }

        return $record;
    }

    /**
     * Request verification (self-service). An expired/rejected/unverified
     * record moves to pending; a verified record stays verified.
     */
    public function request(User $user): IdentityVerification
    {
        $record = $this->effectiveStatus($user);

        if (in_array($record->status, [IdentityVerification::STATUS_PENDING, IdentityVerification::STATUS_VERIFIED], true)) {
            return $record;
        }

        $record->status = IdentityVerification::STATUS_PENDING;
        $record->provider = 'manual';
        $record->save();

        return $record;
    }

    /**
     * Admin manual verification. This is the ONLY path to `verified` today
     * and is explicitly a human review — never a fabricated provider result.
     */
    public function verifyManually(User $user, User $admin, ?string $notes = null, ?Carbon $expiresAt = null): IdentityVerification
    {
        $record = $this->effectiveStatus($user);

        if (! in_array($record->status, [
            IdentityVerification::STATUS_PENDING,
            IdentityVerification::STATUS_REJECTED,
            IdentityVerification::STATUS_REVIEW_REQUIRED,
            IdentityVerification::STATUS_EXPIRED,
            IdentityVerification::STATUS_UNVERIFIED,
        ], true)) {
            throw new DomainException('This verification cannot be approved from its current state.');
        }

        $record->status = IdentityVerification::STATUS_VERIFIED;
        $record->provider = 'manual';
        $record->reviewed_by = $admin->id;
        $record->verified_at = now();
        $record->expires_at = $expiresAt;
        $record->notes = $notes !== null && trim($notes) !== '' ? trim($notes) : $record->notes;
        $record->save();

        // Phase 11 — notify the user their identity was verified.
        $this->notifications->send(
            $user,
            Notification::TYPE_IDENTITY_VERIFIED,
            'Identity verified',
            'Your identity has been verified.',
            NotificationService::link('wallet.index'),
        );

        return $record;
    }

    /**
     * Admin rejection of a verification.
     */
    public function reject(User $user, User $admin, ?string $notes = null): IdentityVerification
    {
        $record = $this->recordFor($user);

        if ($record->status === IdentityVerification::STATUS_VERIFIED) {
            throw new DomainException('A verified identity cannot be rejected; revoke it instead.');
        }

        $record->status = IdentityVerification::STATUS_REJECTED;
        $record->reviewed_by = $admin->id;
        $record->notes = $notes !== null && trim($notes) !== '' ? trim($notes) : $record->notes;
        $record->save();

        // Phase 11 — notify the user their verification was rejected.
        $this->notifications->send(
            $user,
            Notification::TYPE_IDENTITY_REJECTED,
            'Identity verification rejected',
            'Your identity verification was rejected.',
            NotificationService::link('wallet.index'),
        );

        return $record;
    }

    /**
     * Attempt a provider-driven verification. Honest: the manual provider
     * returns pending and never reports a fabricated success.
     */
    public function attemptViaProvider(User $user, string $providerId): array
    {
        $provider = $this->providers[$providerId] ?? null;

        if ($provider === null) {
            throw new DomainException("Unknown identity provider: {$providerId}");
        }

        return $provider->request($user);
    }
}
