<?php

namespace App\Http\Controllers;

use App\Models\LoginEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth', 'active']);
    }

    public function show(Request $request)
    {
        $user = $request->user();
        $loginEvents = LoginEvent::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        return view('profile.show', [
            'user' => $user,
            'loginEvents' => $loginEvents,
        ]);
    }

    public function edit(Request $request)
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    public function update(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'display_name' => ['nullable', 'string', 'max:50', 'regex:/^[\pL\pN\s_\-]+$/u'],
            'username' => [
                'nullable',
                'string',
                'min:3',
                'max:30',
                'regex:/^[a-zA-Z0-9_\.]+$/',
                Rule::unique('users', 'username')->ignore($user->id),
            ],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^\+?[0-9\s\-\(\)]+$/'],
            'bio' => ['nullable', 'string', 'max:500'],
            'date_of_birth' => ['nullable', 'date', 'before:' . now()->subYears(13)->format('Y-m-d')],
            'gender' => ['nullable', 'in:male,female,other'],
            'country' => ['nullable', 'string', 'size:2', 'or', 'in:OTHER'],
            'timezone' => ['nullable', 'string', 'max:50'],
            'locale' => ['nullable', 'in:en,bn'],
            'avatar' => ['nullable', 'image', 'mimes:jpeg,png,webp,gif', 'max:2048'],
            'remove_avatar' => ['nullable', 'boolean'],
        ]);

        // Username cooldown check
        if (isset($validated['username']) && $validated['username'] !== $user->username) {
            if (!$user->canChangeUsername()) {
                return back()->withErrors(['username' => 'You can change username again in ' . $user->daysUntilUsernameChange() . ' days (30-day cooldown for anti-impersonation).'])->withInput();
            }
            $validated['username_changed_at'] = now();
        }

        // Avatar handling - production ready with private storage
        if ($request->hasFile('avatar')) {
            $file = $request->file('avatar');
            
            // Validate image dimensions and content
            try {
                $imageInfo = getimagesize($file->getRealPath());
                if (!$imageInfo) {
                    return back()->withErrors(['avatar' => 'Invalid image file'])->withInput();
                }
                // Check dimensions (min 100x100, max 4000x4000)
                if ($imageInfo[0] < 100 || $imageInfo[1] < 100) {
                    return back()->withErrors(['avatar' => 'Image too small, minimum 100x100'])->withInput();
                }
                if ($imageInfo[0] > 4000 || $imageInfo[1] > 4000) {
                    return back()->withErrors(['avatar' => 'Image too large, maximum 4000x4000'])->withInput();
                }
            } catch (\Throwable $e) {
                return back()->withErrors(['avatar' => 'Invalid image file'])->withInput();
            }

            // Delete old avatar if exists
            if ($user->avatar_path) {
                try {
                    if (Storage::disk('local')->exists($user->avatar_path)) {
                        Storage::disk('local')->delete($user->avatar_path);
                    }
                    if (Storage::disk('public')->exists($user->avatar_path)) {
                        Storage::disk('public')->delete($user->avatar_path);
                    }
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('avatar_delete_failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
                }
            }

            // Store new avatar in private disk with hashed name
            $filename = 'avatars/' . $user->id . '/' . Str::uuid() . '.' . $file->getClientOriginalExtension();
            $path = $file->storeAs('', $filename, 'local');
            $validated['avatar_path'] = $path;

            $this->auditLog('profile.avatar.updated', [
                'user_id' => $user->id,
                'filename' => $filename,
                'size' => $file->getSize(),
                'mime' => $file->getMimeType(),
            ]);
        } elseif ($request->boolean('remove_avatar')) {
            // Remove avatar
            if ($user->avatar_path) {
                try {
                    if (Storage::disk('local')->exists($user->avatar_path)) {
                        Storage::disk('local')->delete($user->avatar_path);
                    }
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('avatar_remove_failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
                }
            }
            $validated['avatar_path'] = null;
            $this->auditLog('profile.avatar.removed', ['user_id' => $user->id]);
        } else {
            unset($validated['avatar_path']);
        }

        unset($validated['avatar'], $validated['remove_avatar']);

        // Email change requires re-verification
        $emailChanged = isset($validated['email']) && $validated['email'] !== $user->email;
        if ($emailChanged) {
            $validated['email_verified_at'] = null;
        }

        $user->fill($validated);
        $user->save();

        $this->auditLog('profile.updated', [
            'user_id' => $user->id,
            'fields' => array_keys($validated),
            'email_changed' => $emailChanged,
        ]);

        return redirect()->route('profile.show')->with('success', 'Profile updated successfully' . ($emailChanged ? ' - please verify new email' : ''));
    }

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
                \Illuminate\Support\Facades\Log::warning('avatar_remove_failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            }
            $user->forceFill(['avatar_path' => null])->save();
            $this->auditLog('profile.avatar.removed', ['user_id' => $user->id]);
        }

        return back()->with('success', 'Avatar removed');
    }
}
