<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ProfileService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        $request->validate([
            'name' => 'required|string|max:120',
            'bio' => 'nullable|string|max:1000',
            'country' => 'nullable|string|max:2',
            'region' => 'nullable|string|max:120',
            'avatar' => 'nullable',
        ]);

        $data = [
            'name' => (string) $request->input('name'),
            'bio' => $request->input('bio'),
            'country' => $request->input('country'),
            'region' => $request->input('region'),
            'avatar' => is_string($request->input('avatar')) ? $request->input('avatar') : $user->avatar,
        ];

        if ($request->hasFile('avatar')) {
            $file = $request->file('avatar');
            $path = $file->store("avatars/{$user->id}", 'local');
            $user->avatar_path = $path;
            $user->save();
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
        $user->avatar_path = null;
        $user->avatar = null;
        $user->save();

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
