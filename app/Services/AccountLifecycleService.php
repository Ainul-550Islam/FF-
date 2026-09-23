<?php

namespace App\Services;

use App\Models\Dispute;
use App\Models\LoginEvent;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\Restriction;
use App\Models\User;
use DomainException;

/**
 * Account lifecycle: deactivate / reactivate / delete (Phase 14).
 *
 * Deletion is an anonymizing tombstone, never a physical row removal —
 * financial ledger, payouts, disputes, audit and security events are
 * immutable and must keep their foreign keys intact. Deletion is refused
 * while financial or security workflows are unresolved.
 */
class AccountLifecycleService
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_DEACTIVATED = 'deactivated';

    public const STATUS_DELETION_PENDING = 'deletion_pending';

    public const STATUS_DELETED = 'deleted';

    public function __construct(
        protected SessionManagementService $sessions,
        protected LoginEventService $loginEvents,
        protected NotificationService $notifications,
        protected AuditLogService $audit,
    ) {}

    /**
     * Deactivate an account (self-service or admin). All sessions are
     * revoked so the account is immediately signed out everywhere.
     */
    public function deactivate(User $user, ?User $actor = null): User
    {
        if ($user->account_status === self::STATUS_DEACTIVATED) {
            throw new DomainException('This account is already deactivated.');
        }

        if ($user->account_status === self::STATUS_DELETED) {
            throw new DomainException('This account has been deleted.');
        }

        $user->account_status = self::STATUS_DEACTIVATED;
        $user->deactivated_at = now();
        $user->save();

        $this->sessions->revokeAllSessions($user);

        $this->loginEvents->record($user, LoginEvent::EVENT_ACCOUNT_DEACTIVATED);

        $this->notifications->send(
            $user,
            Notification::TYPE_ACCOUNT_DEACTIVATED,
            'Account deactivated',
            'Your FF Arena account has been deactivated. You can reactivate it from your security settings.',
            NotificationService::link('login'),
        );

        $this->audit->recordQuietly($actor ?? $user, 'auth.account_deactivated', 'user', $user->id, [
            'target_user_id' => $user->id,
        ]);

        return $user;
    }

    /**
     * Reactivate a deactivated account.
     */
    public function reactivate(User $user, ?User $actor = null): User
    {
        if (! in_array($user->account_status, [self::STATUS_DEACTIVATED, self::STATUS_DELETION_PENDING], true)) {
            throw new DomainException('This account is not deactivated.');
        }

        $user->account_status = self::STATUS_ACTIVE;
        $user->deactivated_at = null;
        $user->save();

        $this->loginEvents->record($user, LoginEvent::EVENT_ACCOUNT_REACTIVATED);

        $this->notifications->send(
            $user,
            Notification::TYPE_SYSTEM,
            'Account reactivated',
            'Your FF Arena account has been reactivated.',
            NotificationService::link('home'),
        );

        $this->audit->recordQuietly($actor ?? $user, 'auth.account_reactivated', 'user', $user->id, [
            'target_user_id' => $user->id,
        ]);

        return $user;
    }

    /**
     * Request account deletion (self-service). Blocked while financial or
     * security workflows are unresolved.
     */
    public function requestDeletion(User $user): User
    {
        $this->assertDeletable($user);

        $user->account_status = self::STATUS_DELETION_PENDING;
        $user->save();

        $this->loginEvents->record($user, LoginEvent::EVENT_DELETION_REQUESTED);

        $this->notifications->send(
            $user,
            Notification::TYPE_SYSTEM,
            'Account deletion requested',
            'Your deletion request has been received and will be processed shortly.',
            NotificationService::link('settings.security'),
        );

        $this->audit->recordQuietly($user, 'auth.account_deletion_requested', 'user', $user->id, [
            'target_user_id' => $user->id,
        ]);

        return $user;
    }

    /**
     * Cancel a pending deletion request.
     */
    public function cancelDeletion(User $user): User
    {
        if ($user->account_status !== self::STATUS_DELETION_PENDING) {
            throw new DomainException('There is no pending deletion request.');
        }

        $user->account_status = self::STATUS_ACTIVE;
        $user->save();

        return $user;
    }

    /**
     * Execute deletion (admin) as an anonymizing tombstone. Never physically
     * removes the row: ledger/payout/dispute/audit/security history must keep
     * its references intact.
     */
    public function executeDeletion(User $user, User $admin): User
    {
        $this->assertDeletable($user);

        $this->sessions->revokeAllSessions($user);

        $user->account_status = self::STATUS_DELETED;
        $user->deactivated_at = now();
        $user->name = 'Deleted User';
        $user->username = null;
        $user->email = 'deleted-'.$user->id.'@ffarena.invalid';
        $user->phone = null;
        $user->game_uid = null;
        $user->bio = null;
        $user->country = null;
        $user->region = null;
        $user->avatar = null;
        $user->password = null;
        $user->remember_token = null;
        $user->save();

        // Provider identities are removed; password identity is gone with
        // the null password above.
        $user->identities()->delete();

        $this->audit->recordQuietly($admin, 'auth.account_deleted', 'user', $user->id, [
            'target_user_id' => $user->id,
        ]);

        return $user;
    }

    /**
     * A user is deletable only when no financial/security workflow is open.
     *
     * @throws DomainException otherwise.
     */
    protected function assertDeletable(User $user): void
    {
        if ($user->account_status === self::STATUS_DELETED) {
            throw new DomainException('This account has already been deleted.');
        }

        if (Payment::where('payer_user_id', $user->id)
            ->whereIn('status', [Payment::STATUS_PENDING, Payment::STATUS_PROCESSING])
            ->exists()) {
            throw new DomainException('Resolve your pending payments before deleting your account.');
        }

        if (Payout::where('recipient_user_id', $user->id)
            ->whereIn('status', [Payout::STATUS_PENDING, Payout::STATUS_PROCESSING])
            ->exists()) {
            throw new DomainException('Resolve your pending payouts before deleting your account.');
        }

        if (Dispute::where('opened_by', $user->id)
            ->whereIn('status', [Dispute::STATUS_OPEN, Dispute::STATUS_UNDER_REVIEW])
            ->exists()) {
            throw new DomainException('Resolve your open disputes before deleting your account.');
        }

        if (Restriction::where('user_id', $user->id)
            ->where('status', Restriction::STATUS_ACTIVE)
            ->exists()) {
            throw new DomainException('Contact support to review your account before deletion.');
        }
    }
}
