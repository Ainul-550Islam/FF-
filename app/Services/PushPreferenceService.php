<?php

namespace App\Services;

use App\Models\NotificationPreference;
use App\Models\User;
use DomainException;

/**
 * Phase 19 — push-channel notification preferences.
 *
 * Single authority for a user's per-category push toggles. The `security`
 * category is always delivered and can never be disabled; every other
 * category defaults to enabled. The service never reads or writes anything
 * but its own table, and never trusts a client-provided category name that
 * is not in the known list.
 */
class PushPreferenceService
{
    /**
     * Load (or lazily create) the user's preference row.
     */
    public function forUser(User $user): NotificationPreference
    {
        $pref = NotificationPreference::where('user_id', $user->id)->first();

        if ($pref === null) {
            $pref = new NotificationPreference();
            $pref->user_id = $user->id;
            $pref->save();

            // Reload so the column defaults (all true) are reflected instead
            // of the unset in-memory attributes.
            return $pref->fresh();
        }

        return $pref;
    }

    /**
     * Update the toggleable categories for a user. `security` is accepted in
     * the payload but always ignored (it stays true). Returns the fresh row.
     *
     * @param  array<string, bool>  $flags
     */
    public function update(User $user, array $flags): NotificationPreference
    {
        $pref = $this->forUser($user);

        foreach ($flags as $category => $enabled) {
            if (! is_string($category)) {
                continue;
            }

            if ($category === NotificationPreference::CATEGORY_SECURITY) {
                // Security notifications are mandatory and cannot be muted.
                continue;
            }

            if (! in_array($category, NotificationPreference::TOGGLEABLE_CATEGORIES, true)) {
                throw new DomainException('Unknown notification category: '.$category);
            }

            $pref->{NotificationPreference::columnFor($category)} = (bool) $enabled;
        }

        $pref->save();

        return $pref->fresh();
    }

    /**
     * Whether push is enabled for a category. Security is always enabled.
     */
    public function isEnabled(User $user, ?string $category): bool
    {
        if ($category === null) {
            // Unmapped notification types always reach the user.
            return true;
        }

        if ($category === NotificationPreference::CATEGORY_SECURITY) {
            return true;
        }

        $pref = $this->forUser($user);

        return (bool) $pref->{NotificationPreference::columnFor($category)};
    }
}
