<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ProfileService;
use App\Support\Seo;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function show(?User $user = null, ?ProfileService $profiles = null): View|RedirectResponse
    {
        $profiles = $profiles ?? app(ProfileService::class);
        $target = $user && $user->exists ? $user : auth()->user();

        if (! $target) {
            return redirect()->route('login');
        }

        $profile = $profiles->publicProfile($target, auth()->user());

        // Phase 17 — a profile is indexable only while the *viewer* may see it
        // and the owner chose public privacy. The metadata never contains more
        // than the page itself renders: a hidden profile is noindex and carries
        // no description, no JSON-LD and no bio, so a crawler can never read
        // what the page withholds.
        $seo = app(Seo::class);
        $seo->canonical(route('profile.show', $target));

        if (($profile['visible'] ?? false) === true && ($profile['privacy'] ?? 'private') === 'public') {
            $displayName = (string) ($profile['name'] ?? $target->name);
            $username = (string) ($profile['username'] ?? $target->username ?? '');

            $seo->title($displayName.($username !== '' ? ' (@'.$username.')' : '').' — FF Arena')
                ->description(
                    trim((string) ($profile['bio'] ?? '')) !== ''
                        ? (string) $profile['bio']
                        : 'FF Arena player profile for '.$displayName.'.'
                )
                ->indexable(true)
                ->ogType('profile')
                ->jsonLd([
                    '@context' => 'https://schema.org',
                    '@type' => 'ProfilePage',
                    'url' => route('profile.show', $target),
                    'inLanguage' => 'en-BD',
                    'mainEntity' => [
                        '@type' => 'Person',
                        'name' => $displayName,
                        'alternateName' => $username,
                        'description' => trim((string) ($profile['bio'] ?? '')),
                        'url' => route('profile.show', $target),
                        'address' => array_filter([
                            '@type' => 'PostalAddress',
                            'addressCountry' => $profile['country'] ?? null,
                            'addressRegion' => $profile['region'] ?? null,
                        ], static fn ($value) => $value !== null && $value !== ''),
                    ],
                ]);
        } else {
            $seo->indexable(false);
        }

        return view('profile.show', [
            'user' => $target,
            'profile' => $profile,
        ]);
    }

    public function edit(): View
    {
        return view('profile.edit', [
            'user' => auth()->user(),
        ]);
    }

    public function update(Request $request, ProfileService $profiles): RedirectResponse
    {
        $user = $request->user();

        // AUDIT FIX (2026-10-08, GAPS-10) — avatar uploads were validated as
        // bare `nullable`: ANY file type (HTML, SVG-with-script, PHP, 50 MB
        // blobs) was accepted, stored unrenamed under the user's folder and
        // later served back from the same origin — stored XSS + disk
        // exhaustion in one shot. Two accepted avatar forms now:
        //   - an upload: a real raster image (jpg/jpeg/png/webp), ≤ 4 MB,
        //     re-stored under a random name with a whitelisted extension;
        //   - an external URL string (the legacy `avatar` column), https/http
        //     only — never javascript:/data:, capped at 2048 chars.

        $isUpload = $request->hasFile('avatar') && $request->file('avatar') instanceof UploadedFile;

        $request->validate([
            'name' => 'required|string|max:120',
            'bio' => 'nullable|string|max:1000',
            'country' => 'nullable|string|max:2',
            'region' => 'nullable|string|max:120',
            'avatar' => $isUpload
                ? ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096', 'dimensions:min_width=16,min_height=16,max_width=4096,max_height=4096']
                : ['nullable', 'string', 'max:2048'],
        ]);

        $data = [
            'name' => (string) $request->input('name'),
            'bio' => $request->input('bio'),
            'country' => $request->input('country'),
            'region' => $request->input('region'),
            'avatar' => null,
        ];

        if (! $isUpload) {
            $avatarUrl = trim((string) $request->input('avatar', ''));

            if ($avatarUrl !== '') {
                // External avatars are display-only URLs; reject every scheme
                // that a browser could ever navigate or execute.
                if (! preg_match('#^https?://#i', $avatarUrl)) {
                    return back()->withInput()->withErrors([
                        'avatar' => 'External avatar URLs must start with http:// or https://.',
                    ]);
                }
            }

            $data['avatar'] = $avatarUrl !== '' ? $avatarUrl : $user->avatar;
        }

        if ($isUpload) {
            $file = $request->file('avatar');

            // Extension whitelist re-derived from the validated image — the
            // stored name is random, so the served path can never inherit an
            // attacker-chosen `.html`/`.svg` suffix.
            $extension = strtolower((string) $file->guessExtension());

            if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                $extension = 'png';
            }

            $previousPath = $user->avatar_path;

            $path = $file->storeAs(
                "avatars/{$user->id}",
                Str::uuid()->toString().'.'.$extension,
                'local',
            );

            $user->avatar_path = $path;
            $user->avatar = null; // a stored upload wins over an external URL
            $user->save();

            // Best-effort cleanup of the replaced upload (never fatal).
            if ($previousPath !== null && $previousPath !== $path) {
                rescue(fn () => Storage::disk('local')->delete($previousPath));
            }
        } elseif ($request->input('remove_avatar') === '1') {
            $user->avatar_path = null;
            $user->avatar = null;
            $user->save();
        }

        $profiles->update($user, $data);

        return redirect()->route('profile.show')->with('success', 'Profile updated successfully.');
    }

    public function updateUsername(Request $request, ProfileService $profiles): RedirectResponse
    {
        $request->validate(['username' => 'required|string|max:30']);

        try {
            $profiles->updateUsername($request->user(), (string) $request->input('username'));

            return back()->with('success', 'Username updated.');
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function updatePrivacy(Request $request, ProfileService $profiles): RedirectResponse
    {
        $request->validate(['privacy' => 'required|string|in:public,registered,private']);

        try {
            $profiles->updatePrivacy($request->user(), (string) $request->input('privacy'));

            return back()->with('success', 'Privacy setting updated.');
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function updatePreferences(Request $request, ProfileService $profiles): RedirectResponse
    {
        $profiles->updatePreferences($request->user(), $request->all());

        return back()->with('success', 'Preferences updated.');
    }

    public function removeAvatar(Request $request): RedirectResponse
    {
        $user = $request->user();

        $previousPath = $user->avatar_path;

        $user->avatar_path = null;
        $user->avatar = null;
        $user->save();

        if ($previousPath !== null) {
            rescue(fn () => Storage::disk('local')->delete($previousPath));
        }

        return back()->with('success', 'Avatar removed.');
    }

    public function changePassword(Request $request, ProfileService $profiles): RedirectResponse
    {
        $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        try {
            $profiles->changePassword(
                $request->user(),
                (string) $request->input('current_password'),
                (string) $request->input('password'),
                $request,
            );

            return back()->with('success', 'Password changed successfully.');
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
