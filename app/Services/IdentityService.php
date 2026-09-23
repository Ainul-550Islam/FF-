<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserIdentity;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Account identity-linking engine (Phase 14).
 *
 * The single authority for provider identities (google, phone) on a user
 * account, with the duplicate-account rules baked in:
 *
 *  - one Google `sub`  → exactly one account (unique provider+subject);
 *  - one phone number → exactly one account;
 *  - a verified Google email already on an account links into it rather than
 *    creating a duplicate;
 *  - a user can never unlink their last sign-in method.
 *
 * The password identity is the users.password column itself and is managed by
 * ProfileService (change/set), not by rows here.
 */
class IdentityService
{
    /**
     * Find the identity for a provider + subject.
     */
    public function find(string $provider, string $subject): ?UserIdentity
    {
        return UserIdentity::where('provider', $provider)
            ->where('provider_subject', $subject)
            ->first();
    }

    /**
     * The user's identity for a provider (google/phone), if any.
     */
    public function identityFor(User $user, string $provider): ?UserIdentity
    {
        return $user->identities()->where('provider', $provider)->first();
    }

    /**
     * All provider identities for a user.
     */
    public function identitiesFor(User $user)
    {
        return $user->identities()->orderBy('id')->get();
    }

    /**
     * Link a Google identity (subject is the stable Google `sub`).
     *
     * @throws DomainException when the subject is already linked elsewhere.
     */
    public function linkGoogle(User $user, string $subject, ?string $email, bool $emailVerified, ?string $name = null): UserIdentity
    {
        if (! $this->validateProviderSubject('google', $subject)) {
            throw new DomainException('Invalid Google identity.');
        }

        $existing = $this->find('google', $subject);

        if ($existing !== null && $existing->user_id !== $user->id) {
            throw new DomainException('This Google account is already connected to another FF Arena account.');
        }

        if ($existing !== null) {
            $existing->last_used_at = now();
            $existing->save();

            return $existing;
        }

        $identity = new UserIdentity();
        $identity->user_id = $user->id;
        $identity->provider = UserIdentity::PROVIDER_GOOGLE;
        $identity->provider_subject = $subject;
        $identity->provider_email = $email;
        $identity->verified_at = $emailVerified ? now() : null;
        $identity->last_used_at = now();
        $identity->metadata = $name !== null ? ['name' => $this->cap($name)] : null;
        $identity->save();

        return $identity;
    }

    /**
     * Link a phone identity (subject is the normalized E.164 number).
     *
     * @throws DomainException when the phone is already linked elsewhere.
     */
    public function linkPhone(User $user, string $normalizedPhone): UserIdentity
    {
        if (! preg_match('/^\+8801\d{9}$/', $normalizedPhone)) {
            throw new DomainException('Invalid phone number.');
        }

        $existing = $this->find(UserIdentity::PROVIDER_PHONE, $normalizedPhone);

        if ($existing !== null && $existing->user_id !== $user->id) {
            throw new DomainException('This phone number is already connected to another FF Arena account.');
        }

        if ($existing !== null) {
            $existing->verified_at = $existing->verified_at ?? now();
            $existing->last_used_at = now();
            $existing->save();

            return $existing;
        }

        $identity = new UserIdentity();
        $identity->user_id = $user->id;
        $identity->provider = UserIdentity::PROVIDER_PHONE;
        $identity->provider_subject = $normalizedPhone;
        $identity->verified_at = now();
        $identity->last_used_at = now();
        $identity->save();

        // Keep the account's phone column in sync with the verified number.
        if ($user->phone !== $normalizedPhone) {
            $user->phone = $normalizedPhone;
            $user->save();
        }

        return $identity;
    }

    /**
     * Unlink a provider identity, refusing to remove the last sign-in method.
     *
     * @throws DomainException when the identity is absent or it is the last
     *                         remaining sign-in method.
     */
    public function unlink(User $user, string $provider): void
    {
        $identity = $this->identityFor($user, $provider);

        if ($identity === null) {
            throw new DomainException('This account is not linked to that provider.');
        }

        if ($this->signInMethodCount($user) <= 1) {
            throw new DomainException('Add another sign-in method before unlinking this one.');
        }

        $identity->delete();
    }

    /**
     * Whether the user has a password.
     */
    public function hasPassword(User $user): bool
    {
        return $user->password !== null && $user->password !== '';
    }

    /**
     * Whether the user has a verified phone identity.
     */
    public function hasVerifiedPhone(User $user): bool
    {
        return $this->identityFor($user, UserIdentity::PROVIDER_PHONE)?->isVerified() ?? false;
    }

    /**
     * Whether the user has a Google identity.
     */
    public function hasGoogle(User $user): bool
    {
        return $this->identityFor($user, UserIdentity::PROVIDER_GOOGLE) !== null;
    }

    /**
     * Count of available sign-in methods (password + identities).
     */
    public function signInMethodCount(User $user): int
    {
        $count = $this->hasPassword($user) ? 1 : 0;

        return $count + $user->identities()->count();
    }

    /**
     * The user that owns a provider identity, or null.
     */
    public function userFor(string $provider, string $subject): ?User
    {
        return $this->find($provider, $subject)?->user;
    }

    /**
     * Resolve (or create) an account from a verified Google identity, with
     * duplicate-account prevention:
     *
     *  1. subject already linked → sign into that account;
     *  2. verified email already registered → link Google into that account
     *     (never a silent duplicate);
     *  3. otherwise → create a new Google-only account.
     *
     * @param  array{id: string, email: ?string, email_verified: bool, name: ?string}  $googleUser
     * @return array{user: User, created: bool, linked: bool}
     */
    public function resolveGoogle(array $googleUser): array
    {
        $subject = (string) $googleUser['id'];
        $email = $googleUser['email'] !== null ? Str::lower(trim($googleUser['email'])) : null;
        $emailVerified = (bool) $googleUser['email_verified'];
        $name = $googleUser['name'] !== null ? trim($googleUser['name']) : null;

        return DB::transaction(function () use ($subject, $email, $emailVerified, $name) {
            $existingIdentity = $this->find('google', $subject);

            if ($existingIdentity !== null) {
                $existingIdentity->last_used_at = now();
                $existingIdentity->save();

                return ['user' => $existingIdentity->user, 'created' => false, 'linked' => false];
            }

            if ($emailVerified && $email !== null && $email !== '') {
                $byEmail = User::where('email', $email)->first();

                if ($byEmail !== null) {
                    $this->linkGoogle($byEmail, $subject, $email, true, $name);

                    return ['user' => $byEmail, 'created' => false, 'linked' => true];
                }
            }

            $user = new User();
            $user->name = $name !== null && $name !== '' ? $name : 'Free Fire Player';
            $user->email = $email;
            $user->username = $this->uniqueUsername($email, $name);
            $user->password = null;            // Google-only account
            $user->role = 'player';
            $user->email_verified_at = $emailVerified ? now() : null;
            $user->privacy = 'public';
            $user->account_status = 'active';
            $user->save();

            $this->linkGoogle($user, $subject, $email, $emailVerified, $name);

            return ['user' => $user, 'created' => true, 'linked' => false];
        });
    }

    /**
     * Derive a unique, rules-compliant username from email/name.
     */
    public function uniqueUsername(?string $email, ?string $name): string
    {
        $base = '';

        if ($email !== null && str_contains($email, '@')) {
            $base = strtolower((string) strtok($email, '@'));
        } elseif ($name !== null) {
            $base = Str::slug($name, '');
        }

        $base = preg_replace('/[^a-z0-9._-]/', '', $base) ?? '';

        if (strlen($base) < 3) {
            $base = 'player';
        }

        $base = substr($base, 0, 15);
        $candidate = $base;
        $i = 0;

        while (User::where('username', $candidate)->exists()) {
            $i++;
            $candidate = substr($base, 0, 15).$i;
        }

        return $candidate;
    }

    /**
     * Validate a provider subject (non-empty, bounded).
     */
    protected function validateProviderSubject(string $provider, string $subject): bool
    {
        $subject = trim($subject);

        return $subject !== '' && strlen($subject) <= 255;
    }

    /**
     * Cap a stored display string.
     */
    protected function cap(string $value): string
    {
        return mb_substr($value, 0, 100);
    }
}
