<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ProfileService;
use App\Support\Seo;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
    public function show(?User $user = null)
    {
        if ($user === null) {
            // `/profile` with no target is the signed-in user's own profile;
            // guests have nothing to show and go to login.
            $self = auth()->user();

            if ($self === null) {
                return redirect()->route('login');
            }

            $user = $self;
        }

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
            // `avatar` is either an uploaded image (legacy form) or a URL —
            // both are validated explicitly below.
            'avatar' => 'nullable',
            'remove_avatar' => 'nullable|boolean',
        ]);

        if ($request->hasFile('avatar')) {
            $request->validate(['avatar' => 'image|mimes:jpeg,png,webp,gif|max:2048']);

            $file = $request->file('avatar');
            $dimensions = @getimagesize($file->getRealPath());

            if ($dimensions === false) {
                return back()->withErrors(['avatar' => 'Invalid image file'])->withInput();
            }
            if ($dimensions[0] < 100 || $dimensions[1] < 100) {
                return back()->withErrors(['avatar' => 'Image too small, minimum 100x100'])->withInput();
            }
            if ($dimensions[0] > 4000 || $dimensions[1] > 4000) {
                return back()->withErrors(['avatar' => 'Image too large, maximum 4000x4000'])->withInput();
            }

            if ($user->avatar_path) {
                foreach (['local', 'public'] as $disk) {
                    try {
                        if (Storage::disk($disk)->exists($user->avatar_path)) {
                            Storage::disk($disk)->delete($user->avatar_path);
                        }
                    } catch (\Throwable $e) {
                        Log::warning('avatar_delete_failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
                    }
                }
            }

            $user->forceFill([
                'avatar_path' => $file->storeAs(
                    'avatars/'.$user->id,
                    Str::uuid().'.'.$file->getClientOriginalExtension(),
                    'local',
                ),
            ])->save();

            unset($data['avatar']);
        } elseif ($request->boolean('remove_avatar')) {
            $this->removeAvatar($request);
            unset($data['avatar']);
        } elseif (is_string($request->input('avatar')) && trim((string) $request->input('avatar')) !== '') {
            $request->validate(['avatar' => 'url|max:255']);
        }

        unset($data['remove_avatar']);

        try {
            $this->profiles->update($user, $data);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('profile.show')->with('success', 'Profile updated.');
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

    /**
     * Remove the signed-in user's avatar from every disk that may hold it and
     * clear the stored path.
     */
    public function removeAvatar(Request $request)
    {
        $user = $request->user();

        if ($user->avatar_path) {
            try {
                if (Storage::disk('local')->exists($user->avatar_path)) {
                    Storage::disk('local')->delete($user->avatar_path);
                }
                if (Storage::disk('public')->exists($user->avatar_path)) {
                    Storage::disk('public')->delete($user->avatar_path);
                }
            } catch (\Throwable $e) {
                Log::warning('avatar_remove_failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            }

            $user->forceFill(['avatar_path' => null])->save();
        }

        return back()->with('success', 'Avatar removed');
    }
}