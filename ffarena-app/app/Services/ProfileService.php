<?php

namespace App\Services;

use App\Models\LoginEvent;
use App\Models\Notification;
use App\Models\User;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * User profile, username, privacy, region and password management (Phase 14).
 *
 * The single authority for self-editable profile state. Users can never edit
 * rating/rank/risk/verification/roles/restrictions — those columns are not
 * reachable from any profile path. Every mutation is audited and the
 * sensitive ones are notified.
 */
class ProfileService
{
    public function __construct(
        protected NotificationService $notifications,
        protected AuditLogService $audit,
        protected LoginEventService $loginEvents,
        protected SessionManagementService $sessions,
    ) {
    }

    /**
     * Update basic profile fields (display name, bio, country, region, avatar).
     */
    public function update(User $user, array $data): User
    {
        $before = [
            'name' => $user->name,
            'bio' => $user->bio,
            'country' => $user->country,
            'region' => $user->region,
            'avatar' => $user->avatar,
        ];

        $user->name = trim($data['name']);
        $user->bio = isset($data['bio']) && trim($data['bio']) !== '' ? trim($data['bio']) : null;
        $user->country = isset($data['country']) && $data['country'] !== '' ? strtoupper(substr(trim($data['country']), 0, 2)) : null;
        $user->region = isset($data['region']) && trim($data['region']) !== '' ? trim($data['region']) : null;
        $user->avatar = isset($data['avatar']) && trim($data['avatar']) !== '' ? trim($data['avatar']) : null;
        $user->save();

        $this->audit->recordQuietly($user, 'profile.updated', 'user', $user->id, [
            'target_user_id' => $user->id,
            'before' => $before,
            'after' => [
                'name' => $user->name,
                'bio' => $user->bio,
                'country' => $user->country,
                'region' => $user->region,
                'avatar' => $user->avatar,
            ],
        ]);

        return $user;
    }

    /**
     * Change the username (normalized, unique, reserved-checked, cooldown-
     * limited).
     */
    public function updateUsername(User $user, string $username): User
    {
        $username = $this->normalizeUsername($username);
        $this->assertUsernameAvailable($username, $user);

        $cooldownDays = (int) config('account.username.change_cooldown_days', 7);

        if ($user->username_changed_at !== null
            && $user->username_changed_at->gt(now()->subDays($cooldownDays))
            && strtolower((string) $user->username) !== $username) {
            throw new DomainException("You can change your username once every {$cooldownDays} days.");
        }

        $before = $user->username;

        $user->username = $username;
        $user->username_changed_at = now();
        $user->save();

        $this->audit->recordQuietly($user, 'profile.username_changed', 'user', $user->id, [
            'target_user_id' => $user->id,
            'before' => ['username' => $before],
            'after' => ['username' => $username],
        ]);

        return $user;
    }

    /**
     * Change the account password (requires the current password) and revoke
     * every other session.
     */
    public function changePassword(User $user, string $current, string $new, ?Request $request = null): User
    {
        if (! $this->hasPassword($user)) {
            throw new DomainException('This account has no password yet — set one first.');
        }

        if (! Hash::check($current, $user->password)) {
            throw new DomainException('Your current password is incorrect.');
        }

        if (strlen($new) < 8) {
            throw new DomainException('Your new password must be at least 8 characters.');
        }

        $user->password = Hash::make($new);
        $user->save();

        // Keep only the current session alive.
        $this->sessions->revokeOtherSessions($user);

        $this->loginEvents->record($user, LoginEvent::EVENT_PASSWORD_CHANGED, LoginEvent::STATUS_SUCCESS, $request);

        $this->notifications->send(
            $user,
            Notification::TYPE_PASSWORD_CHANGED,
            'Password changed',
            'Your password was changed. If this was not you, reset it immediately.',
            NotificationService::link('settings.security'),
        );

        $this->audit->recordQuietly($user, 'auth.password_changed', 'user', $user->id, [
            'target_user_id' => $user->id,
        ]);

        return $user;
    }

    /**
     * Set a password on an account that does not have one (e.g. a Google-only
     * account), without requiring a current password.
     */
    public function setPassword(User $user, string $new, ?Request $request = null): User
    {
        if (strlen($new) < 8) {
            throw new DomainException('Your password must be at least 8 characters.');
        }

        $user->password = Hash::make($new);
        $user->save();

        $this->loginEvents->record($user, LoginEvent::EVENT_PASSWORD_CHANGED, LoginEvent::STATUS_SUCCESS, $request);

        $this->notifications->send(
            $user,
            Notification::TYPE_PASSWORD_CHANGED,
            'Password set',
            'A password was added to your account.',
            NotificationService::link('settings.security'),
        );

        $this->audit->recordQuietly($user, 'auth.password_changed', 'user', $user->id, [
            'target_user_id' => $user->id,
        ]);

        return $user;
    }

    /**
     * Update the profile privacy preset.
     */
    public function updatePrivacy(User $user, string $privacy): User
    {
        if (! in_array($privacy, (array) config('account.privacy', ['public', 'registered', 'private']), true)) {
            throw new DomainException('Invalid privacy setting.');
        }

        $before = $user->privacy;

        $user->privacy = $privacy;
        $user->save();

        $this->audit->recordQuietly($user, 'profile.privacy_changed', 'user', $user->id, [
            'target_user_id' => $user->id,
            'before' => ['privacy' => $before],
            'after' => ['privacy' => $privacy],
        ]);

        return $user;
    }

    /**
     * Update country/region/language/timezone preferences.
     */
    public function updatePreferences(User $user, array $data): User
    {
        $user->country = isset($data['country']) && $data['country'] !== '' ? strtoupper(substr(trim($data['country']), 0, 2)) : null;
        $user->region = isset($data['region']) && trim($data['region']) !== '' ? trim($data['region']) : null;
        $user->language = isset($data['language']) && $data['language'] !== '' ? substr(trim($data['language']), 0, 5) : 'en';

        $timezone = isset($data['timezone']) ? trim($data['timezone']) : 'UTC';
        $user->timezone = in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'UTC';

        $user->save();

        $this->audit->recordQuietly($user, 'profile.updated', 'user', $user->id, [
            'target_user_id' => $user->id,
        ]);

        return $user;
    }

    /**
     * Build the display-safe public profile for a viewer, honouring the
     * target's privacy preset. Never includes email/phone/wallet/risk data.
     */
    public function publicProfile(User $target, ?User $viewer): array
    {
        $privacy = $target->privacy ?? 'public';

        $visible = match ($privacy) {
            'private' => $viewer !== null && ($viewer->id === $target->id || $viewer->isStaff()),
            'registered' => $viewer !== null,
            default => true,
        };

        if (! $visible) {
            return [
                'visible' => false,
                'name' => $target->name,
                'username' => $target->username,
                'privacy' => $privacy,
            ];
        }

        return [
            'visible' => true,
            'name' => $target->name,
            'username' => $target->username,
            'avatar' => $target->avatar,
            'bio' => $target->bio,
            'country' => $target->country,
            'region' => $target->region,
            'role' => $target->role,
            'joined_at' => $target->created_at?->toDateString(),
            'privacy' => $privacy,
        ];
    }

    /**
     * Whether the user has a password.
     */
    public function hasPassword(User $user): bool
    {
        return $user->password !== null && $user->password !== '';
    }

    /**
     * Normalize a username: trim, lowercase for uniqueness but keep the
     * user's casing for display when valid.
     */
    public function normalizeUsername(string $username): string
    {
        $username = trim($username);

        $min = (int) config('account.username.min_length', 3);
        $max = (int) config('account.username.max_length', 20);
        $regex = (string) config('account.username.allowed_regex', '/^[a-zA-Z0-9._-]+$/');

        if (mb_strlen($username) < $min || mb_strlen($username) > $max) {
            throw new DomainException("Username must be between {$min} and {$max} characters.");
        }

        if (! preg_match($regex, $username)) {
            throw new DomainException('Username may only contain letters, numbers, dots, dashes and underscores.');
        }

        $reserved = (array) config('account.username.reserved', []);

        if (in_array(strtolower($username), array_map('strtolower', $reserved), true)) {
            throw new DomainException('That username is reserved.');
        }

        return $username;
    }

    /**
     * Uniqueness check against other users (case-insensitive).
     */
    public function assertUsernameAvailable(string $username, User $except): void
    {
        $exists = User::query()
            ->where('id', '!=', $except->id)
            ->whereRaw('lower(username) = ?', [strtolower($username)])
            ->exists();

        if ($exists) {
            throw new DomainException('That username is already taken.');
        }
    }
}
