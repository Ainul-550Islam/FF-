<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ProfileService;
use App\Support\Seo;
use DomainException;
use Illuminate\Http\Request;

/**
 * Public profiles + profile settings (Phase 14).
 *
 * Users edit only their own profile (admins may too); rating, rank, risk,
 * verification, roles and restrictions are never editable from here.
 */
class ProfileController extends Controller
{
    public function __construct(
        protected ProfileService $profiles,
    ) {}

    /**
     * Public profile, honouring the target's privacy preset.
     */
    public function show(User $user)
    {
        $profile = $this->profiles->publicProfile($user, auth()->user());

        $this->applyProfileSeo($user, $profile);

        return view('profile.show', compact('user', 'profile'));
    }

    /**
     * Phase 17 — profile SEO. Private/limited profiles are noindex by default
     * and never contribute name/bio to metadata; only genuinely public
     * profiles opt in to indexing + ProfilePage structured data.
     *
     * @param  array<string, mixed>  $profile
     */
    private function applyProfileSeo(User $user, array $profile): void
    {
        $siteName = (string) config('app.name', 'FF Arena');

        if (! empty($profile['visible'])) {
            $description = trim((string) ($profile['bio'] ?? ''));
            if ($description === '') {
                $description = $profile['name'].' — Free Fire player on '.$siteName.'.';
            }

            app(Seo::class)
                ->title($profile['name'].' — '.$siteName)
                ->description($description)
                ->canonical(route('profile.show', $user))
                ->indexable()
                ->ogType('profile')
                ->jsonLd([
                    '@context' => 'https://schema.org',
                    '@type' => 'ProfilePage',
                    'name' => $profile['name'],
                    'url' => route('profile.show', $user),
                    'mainEntity' => [
                        '@type' => 'Person',
                        'name' => $profile['name'],
                        'alternateName' => $profile['username'] ?? '',
                    ],
                ]);

            return;
        }

        // Private / limited visibility: never index, never leak profile data
        // into the <head> — the page body still honours its normal render.
        app(Seo::class)
            ->title('Private profile — '.$siteName)
            ->description('This profile is private.')
            ->canonical(route('profile.show', $user));
    }

    /**
     * The signed-in user's profile settings.
     */
    public function edit()
    {
        $user = auth()->user();

        $this->authorize('updateProfile', $user);

        return view('profile.edit', compact('user'));
    }

    /**
     * Update basic profile fields.
     */
    public function update(Request $request)
    {
        $user = auth()->user();
        $this->authorize('updateProfile', $user);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'bio' => 'nullable|string|max:500',
            'country' => 'nullable|string|max:2',
            'region' => 'nullable|string|max:100',
            'avatar' => 'nullable|url|max:255',
        ]);

        try {
            $this->profiles->update($user, $data);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Profile updated.');
    }

    /**
     * Change the username (normalized, unique, reserved-checked, rate-limited).
     */
    public function updateUsername(Request $request)
    {
        $user = auth()->user();
        $this->authorize('updateProfile', $user);

        $data = $request->validate([
            'username' => 'required|string',
        ]);

        try {
            $this->profiles->updateUsername($user, $data['username']);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Username updated.');
    }

    /**
     * Update the profile privacy preset.
     */
    public function updatePrivacy(Request $request)
    {
        $user = auth()->user();
        $this->authorize('updateProfile', $user);

        $data = $request->validate([
            'privacy' => 'required|in:public,registered,private',
        ]);

        try {
            $this->profiles->updatePrivacy($user, $data['privacy']);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Privacy setting updated.');
    }

    /**
     * Update country/region/language/timezone preferences.
     */
    public function updatePreferences(Request $request)
    {
        $user = auth()->user();
        $this->authorize('updateProfile', $user);

        $data = $request->validate([
            'country' => 'nullable|string|max:2',
            'region' => 'nullable|string|max:100',
            'language' => 'nullable|string|max:5',
            'timezone' => 'nullable|string|max:64',
        ]);

        try {
            $this->profiles->updatePreferences($user, $data);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Preferences updated.');
    }

    /**
     * Change (or set) the account password.
     */
    public function changePassword(Request $request)
    {
        $user = auth()->user();
        $this->authorize('manageSecurity', $user);

        $hasPassword = $this->profiles->hasPassword($user);

        $data = $request->validate([
            'current_password' => $hasPassword ? 'required|string' : 'nullable|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        try {
            if ($hasPassword) {
                $this->profiles->changePassword($user, $data['current_password'], $data['password'], $request);
            } else {
                $this->profiles->setPassword($user, $data['password'], $request);
            }

            // Regenerate the session so the current session id changes and
            // any session-fixation window closes.
            $request->session()->regenerate();
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Password updated.');
    }
}
