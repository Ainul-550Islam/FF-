<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\Restriction;
use App\Models\RiskEvent;
use App\Models\RiskProfile;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Granular, auditable account restrictions (Phase 10).
 *
 * Restrictions are specific and revocable — there is no uncontrolled global
 * "ban" flag. Each restriction records its type, reason, source, actor,
 * start and optional expiry. Enforcement is read by the FraudRiskService
 * gates and RestrictionService::isBlocked(); no controller mutates risk or
 * restriction state directly.
 */
class RestrictionService
{
    public function __construct(
        protected FraudRiskService $risk,
        protected NotificationService $notifications,
    ) {}

    /**
     * Apply a restriction to a user.
     */
    public function restrict(
        User $user,
        string $type,
        string $reason,
        string $source = 'manual',
        ?User $actor = null,
        ?Carbon $expiresAt = null,
    ): Restriction {
        if (! in_array($type, Restriction::TYPES, true)) {
            throw new DomainException('Unknown restriction type.');
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException('A restriction reason is required.');
        }

        return DB::transaction(function () use ($user, $type, $reason, $source, $actor, $expiresAt) {
            $restriction = new Restriction();
            $restriction->user_id = $user->id;
            $restriction->type = $type;
            $restriction->reason = $reason;
            $restriction->source = $source;
            $restriction->actor_id = $actor?->id;
            $restriction->starts_at = now();
            $restriction->expires_at = $expiresAt;
            $restriction->status = Restriction::STATUS_ACTIVE;
            $restriction->save();

            $severity = $type === Restriction::TYPE_ACCOUNT_SUSPENDED
                ? RiskEvent::SEVERITY_CRITICAL
                : RiskEvent::SEVERITY_HIGH;

            $this->risk->recordSignal($user, RiskEvent::TYPE_ACCOUNT_RESTRICTED, $severity, 'moderation', [
                'restriction_id' => $restriction->id,
                'type' => $type,
                'reason' => $reason,
            ]);

            // Suspensions also freeze the profile so the status is visible
            // even without reading the restrictions table.
            if ($type === Restriction::TYPE_ACCOUNT_SUSPENDED) {
                $profile = $this->risk->profileFor($user);
                $profile->status = RiskProfile::STATUS_SUSPENDED;
                $profile->restricted_until = $expiresAt;
                $profile->save();
            }

            // Phase 11 — notify the user (email is the channel that still
            // reaches a suspended account).
            $this->notifications->send(
                $user,
                Notification::TYPE_RESTRICTION_APPLIED,
                'Account restriction applied',
                'Your account has been restricted: '.$reason,
                null,
                ['restriction_id' => $restriction->id, 'type' => $type],
            );

            return $restriction;
        });
    }

    /**
     * Lift a restriction (authorized override). A lifted suspension restores
     * the profile status when no other suspension remains active.
     */
    public function lift(Restriction $restriction, User $actor): Restriction
    {
        if ($restriction->status === Restriction::STATUS_LIFTED) {
            throw new DomainException('This restriction has already been lifted.');
        }

        return DB::transaction(function () use ($restriction, $actor) {
            $restriction->status = Restriction::STATUS_LIFTED;
            $restriction->lifted_by = $actor->id;
            $restriction->lifted_at = now();
            $restriction->save();

            if ($restriction->type === Restriction::TYPE_ACCOUNT_SUSPENDED) {
                $stillSuspended = Restriction::where('user_id', $restriction->user_id)
                    ->where('type', Restriction::TYPE_ACCOUNT_SUSPENDED)
                    ->where('status', Restriction::STATUS_ACTIVE)
                    ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                    ->exists();

                if (! $stillSuspended) {
                    $profile = $this->risk->profileFor($restriction->user);
                    $profile->status = RiskProfile::STATUS_ACTIVE;
                    $profile->restricted_until = null;
                    $profile->save();
                }
            }

            // Phase 11 — notify the user their restriction was lifted.
            $this->notifications->send(
                $restriction->user,
                Notification::TYPE_RESTRICTION_LIFTED,
                'Restriction lifted',
                'A restriction on your account has been lifted.',
                null,
                ['restriction_id' => $restriction->id, 'type' => $restriction->type],
            );

            return $restriction;
        });
    }

    /**
     * The user's active (unexpired, unlifted) restrictions.
     *
     * @return Collection<int, Restriction>
     */
    public function activeRestrictions(User $user): Collection
    {
        return Restriction::where('user_id', $user->id)
            ->where('status', Restriction::STATUS_ACTIVE)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get();
    }

    /**
     * Whether a user is blocked by any active restriction of the given
     * types, or by an account suspension.
     */
    public function isBlocked(User $user, array $types = []): bool
    {
        $types[] = Restriction::TYPE_ACCOUNT_SUSPENDED;
        $types = array_values(array_unique($types));

        $blocked = Restriction::where('user_id', $user->id)
            ->where('status', Restriction::STATUS_ACTIVE)
            ->whereIn('type', $types)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->exists();

        if ($blocked) {
            return true;
        }

        // Profile-level suspension is a backstop.
        $profile = $user->riskProfile()->first();

        return $profile !== null && $profile->isSuspended();
    }
}
