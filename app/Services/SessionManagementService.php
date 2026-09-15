<?php

namespace App\Services;

use App\Models\LoginEvent;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;

/**
 * Session/device management (Phase 14).
 *
 * Reads the framework session store (database driver in production) to list
 * a user's active sessions, and revokes them ("logout this device",
 * "logout other devices", "logout everywhere"). Raw IPs are never shown —
 * only a derived device label and last-activity time.
 */
class SessionManagementService
{
    public function __construct(
        protected LoginEventService $loginEvents,
        protected NotificationService $notifications,
        protected AuditLogService $audit,
        protected LiveEventService $live,
        protected DeviceFingerprintService $devices,
    ) {
    }

    /**
     * The user's active sessions with a safe device label and current flag.
     *
     * @return Collection<int, array{id: string, last_activity: int, device_label: string, is_current: bool}>
     */
    public function sessionsFor(User $user): Collection
    {
        $current = $this->currentId();

        $rows = DB::table('sessions')
            ->where('user_id', $user->id)
            ->orderByDesc('last_activity')
            ->get();

        return $rows->map(function ($row) use ($current) {
            return [
                'id' => $row->id,
                'last_activity' => (int) $row->last_activity,
                'device_label' => $this->devices->deviceLabelFromUserAgent((string) ($row->user_agent ?? '')),
                'is_current' => $current !== null && hash_equals($current, (string) $row->id),
            ];
        });
    }

    /**
     * Revoke every session except the current one.
     *
     * @return int number of sessions revoked
     */
    public function revokeOtherSessions(User $user): int
    {
        $current = $this->currentId();

        $deleted = DB::table('sessions')
            ->where('user_id', $user->id)
            ->when($current !== null, fn ($q) => $q->where('id', '!=', $current))
            ->delete();

        if ($deleted > 0) {
            $this->recordRevocation($user, LoginEvent::EVENT_SESSION_REVOKED);
        }

        return $deleted;
    }

    /**
     * Revoke all of the user's sessions (logout everywhere). The caller is
     * responsible for ending the current session/guard afterwards.
     *
     * @return int number of sessions revoked
     */
    public function revokeAllSessions(User $user): int
    {
        $deleted = DB::table('sessions')->where('user_id', $user->id)->delete();

        if ($deleted > 0) {
            $this->recordRevocation($user, LoginEvent::EVENT_SESSIONS_REVOKED);
        }

        return $deleted;
    }

    /**
     * Admin-initiated revocation of all of a user's sessions.
     */
    public function revokeAllForUser(User $user, User $admin): int
    {
        $deleted = $this->revokeAllSessions($user);

        $this->audit->recordQuietly($admin, 'auth.sessions_revoked', 'user', $user->id, [
            'target_user_id' => $user->id,
            'metadata' => ['count' => $deleted],
        ]);

        return $deleted;
    }

    /**
     * The current session id (null when unavailable, e.g. console).
     */
    public function currentId(): ?string
    {
        try {
            return Session::getId();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Side effects shared by revocations: login event, notification, audit
     * and a user-targeted live event. Never throws into the caller.
     */
    protected function recordRevocation(User $user, string $event): void
    {
        $this->loginEvents->record($user, $event);

        $this->notifications->send(
            $user,
            Notification::TYPE_SESSION_REVOKED,
            'Sessions revoked',
            'One or more of your active sessions were signed out for security.',
            NotificationService::link('settings.sessions'),
        );

        $this->audit->recordQuietly($user, 'auth.sessions_revoked', 'user', $user->id, [
            'target_user_id' => $user->id,
            'metadata' => ['event' => $event],
        ]);

        $this->live->recordForUserQuietly($user, null, \App\Models\LiveEvent::TYPE_ACCOUNT_SESSION_REVOKED, [
            'event' => $event,
        ]);
    }
}
