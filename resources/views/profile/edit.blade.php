@extends('layouts.app')

@section('title', 'Edit Profile')

@section('content')
<div style="max-width: 800px; margin: 0 auto;">
    <div style="margin-bottom: 24px;">
        <h1 style="margin: 0 0 8px; font-size: 28px; font-weight: 800;">Edit Profile</h1>
        <p class="text-muted">Update your profile information, avatar, and preferences. Changes are audited.</p>
        <div style="margin-top: 12px; display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
            <span data-internet-status class="internet-status online"></span>
            <span class="text-muted" style="font-size: 12px;">Avatar max 2MB - JPEG, PNG, WebP, GIF</span>
        </div>
    </div>

    <form method="POST" action="{{ route('profile.update') }}" enctype="multipart/form-data" class="card">
        @csrf
        @method('PUT')

        {{-- Avatar Section --}}
        <div style="display: flex; gap: 24px; align-items: flex-start; margin-bottom: 32px; padding-bottom: 24px; border-bottom: 1px solid var(--border);">
            <div style="text-align: center;">
                <x-avatar :user="$user" size="xl" :editable="true" />
                <div style="margin-top: 12px; display: flex; flex-direction: column; gap: 8px;">
                    <button type="button" class="btn btn-ghost btn-sm" data-avatar-remove style="display: {{ $user->hasAvatar() ? 'inline-flex' : 'none' }};">Remove Avatar</button>
                    <input type="hidden" name="remove_avatar" value="0" data-avatar-remove-flag>
                </div>
            </div>
            <div style="flex: 1;">
                <h3 style="margin: 0 0 8px; font-size: 16px; font-weight: 700;">Profile Photo</h3>
                <p class="text-muted" style="font-size: 13px; margin: 0 0 12px;">Upload a new avatar. It will be visible to other players in tournaments and leaderboards. We store avatars privately and serve via authenticated controller.</p>
                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                    <label class="btn btn-secondary btn-sm" style="cursor: pointer;">
                        Choose Image
                        <input type="file" name="avatar" accept="image/jpeg,image/png,image/webp,image/gif" data-avatar-input data-preview-target="[data-avatar-preview]" style="display: none;">
                    </label>
                    <span class="text-muted" style="font-size: 12px; align-self: center;">Recommended: Square, 400x400px, max 2MB</span>
                </div>
                @error('avatar')
                    <div class="form-error">{{ $message }}</div>
                @enderror
                <div style="margin-top: 12px; padding: 10px; background: var(--bg-elevated); border-radius: 8px; border: 1px solid var(--border);">
                    <div style="font-size: 12px; font-weight: 600; margin-bottom: 4px;">Privacy Note</div>
                    <div style="font-size: 12px; color: var(--text-muted);">Avatars are served via authenticated route <code class="font-mono">/avatar/{user}</code> - not public URL. No EXIF data is stored. Images are validated server-side.</div>
                </div>
            </div>
        </div>

        <div class="grid grid-2">
            <div class="form-group">
                <label for="name" class="form-label required">Full Name</label>
                <input type="text" id="name" name="name" value="{{ old('name', $user->name) }}" class="form-input @error('name') is-invalid @enderror" required maxlength="255" autocomplete="name">
                @error('name') <div class="form-error">{{ $message }}</div> @enderror
            </div>

            <div class="form-group">
                <label for="display_name" class="form-label">Display Name</label>
                <input type="text" id="display_name" name="display_name" value="{{ old('display_name', $user->display_name) }}" class="form-input @error('display_name') is-invalid @enderror" maxlength="50" placeholder="How others see you">
                <div class="form-hint">Leave blank to use full name. 3-50 chars, letters, numbers, spaces, _ -</div>
                @error('display_name') <div class="form-error">{{ $message }}</div> @enderror
            </div>

            <div class="form-group">
                <label for="username" class="form-label">Username</label>
                <div style="display: flex; align-items: center;">
                    <span style="padding: 12px; background: var(--bg-elevated); border: 1px solid var(--border); border-right: none; border-radius: 8px 0 0 8px; color: var(--text-muted); font-size: 14px;">@</span>
                    <input type="text" id="username" name="username" value="{{ old('username', $user->username) }}" class="form-input @error('username') is-invalid @enderror" style="border-radius: 0 8px 8px 0;" maxlength="30" pattern="[a-zA-Z0-9_\.]+" autocomplete="username">
                </div>
                <div class="form-hint">
                    @if($user->canChangeUsername())
                        You can change username. Next change allowed after 30 days.
                    @else
                        Can change again in {{ $user->daysUntilUsernameChange() }} days (cooldown for anti-impersonation).
                    @endif
                </div>
                @error('username') <div class="form-error">{{ $message }}</div> @enderror
            </div>

            <div class="form-group">
                <label for="email" class="form-label required">Email</label>
                <input type="email" id="email" name="email" value="{{ old('email', $user->email) }}" class="form-input @error('email') is-invalid @enderror" required autocomplete="email">
                @if(!$user->email_verified_at)
                    <div class="form-hint" style="color: var(--warning);">Unverified - <a href="{{ route('verification.notice') }}" style="color: var(--warning);">Verify now</a></div>
                @else
                    <div class="form-hint" style="color: var(--success);">✓ Verified {{ $user->email_verified_at->diffForHumans() }}</div>
                @endif
                @error('email') <div class="form-error">{{ $message }}</div> @enderror
            </div>

            <div class="form-group">
                <label for="phone" class="form-label">Phone</label>
                <input type="tel" id="phone" name="phone" value="{{ old('phone', $user->phone) }}" class="form-input @error('phone') is-invalid @enderror" placeholder="+8801XXXXXXXXX" autocomplete="tel">
                <div class="form-hint">For OTP login and payment verification. Format: +8801...</div>
                @error('phone') <div class="form-error">{{ $message }}</div> @enderror
            </div>

            <div class="form-group">
                <label for="country" class="form-label">Country</label>
                <select id="country" name="country" class="form-select @error('country') is-invalid @enderror">
                    <option value="">Select country</option>
                    <option value="BD" {{ old('country', $user->country) === 'BD' ? 'selected' : '' }}>Bangladesh</option>
                    <option value="IN" {{ old('country', $user->country) === 'IN' ? 'selected' : '' }}>India</option>
                    <option value="PK" {{ old('country', $user->country) === 'PK' ? 'selected' : '' }}>Pakistan</option>
                    <option value="US" {{ old('country', $user->country) === 'US' ? 'selected' : '' }}>United States</option>
                    <option value="GB" {{ old('country', $user->country) === 'GB' ? 'selected' : '' }}>United Kingdom</option>
                    <option value="OTHER" {{ old('country', $user->country) === 'OTHER' ? 'selected' : '' }}>Other</option>
                </select>
                @error('country') <div class="form-error">{{ $message }}</div> @enderror
            </div>
        </div>

        <div class="form-group">
            <label for="bio" class="form-label">Bio</label>
            <textarea id="bio" name="bio" class="form-textarea @error('bio') is-invalid @enderror" maxlength="500" rows="4" placeholder="Tell others about your gaming style...">{{ old('bio', $user->bio) }}</textarea>
            <div class="form-hint">Max 500 characters. No sensitive personal data. Publicly visible.</div>
            @error('bio') <div class="form-error">{{ $message }}</div> @enderror
        </div>

        <div class="grid grid-2">
            <div class="form-group">
                <label for="date_of_birth" class="form-label">Date of Birth</label>
                <input type="date" id="date_of_birth" name="date_of_birth" value="{{ old('date_of_birth', $user->date_of_birth ? $user->date_of_birth->format('Y-m-d') : '') }}" class="form-input @error('date_of_birth') is-invalid @enderror" max="{{ now()->subYears(13)->format('Y-m-d') }}">
                <div class="form-hint">Must be 13+ to participate. Used for age verification only.</div>
                @error('date_of_birth') <div class="form-error">{{ $message }}</div> @enderror
            </div>

            <div class="form-group">
                <label for="gender" class="form-label">Gender</label>
                <select id="gender" name="gender" class="form-select @error('gender') is-invalid @enderror">
                    <option value="">Prefer not to say</option>
                    <option value="male" {{ old('gender', $user->gender) === 'male' ? 'selected' : '' }}>Male</option>
                    <option value="female" {{ old('gender', $user->gender) === 'female' ? 'selected' : '' }}>Female</option>
                    <option value="other" {{ old('gender', $user->gender) === 'other' ? 'selected' : '' }}>Other</option>
                </select>
                @error('gender') <div class="form-error">{{ $message }}</div> @enderror
            </div>

            <div class="form-group">
                <label for="timezone" class="form-label">Timezone</label>
                <select id="timezone" name="timezone" class="form-select @error('timezone') is-invalid @enderror">
                    <option value="Asia/Dhaka" {{ old('timezone', $user->timezone) === 'Asia/Dhaka' ? 'selected' : '' }}>Asia/Dhaka (BST)</option>
                    <option value="Asia/Kolkata" {{ old('timezone', $user->timezone) === 'Asia/Kolkata' ? 'selected' : '' }}>Asia/Kolkata (IST)</option>
                    <option value="UTC" {{ old('timezone', $user->timezone) === 'UTC' ? 'selected' : '' }}>UTC</option>
                </select>
                @error('timezone') <div class="form-error">{{ $message }}</div> @enderror
            </div>

            <div class="form-group">
                <label for="locale" class="form-label">Language</label>
                <select id="locale" name="locale" class="form-select @error('locale') is-invalid @enderror">
                    <option value="en" {{ old('locale', $user->locale) === 'en' ? 'selected' : '' }}>English</option>
                    <option value="bn" {{ old('locale', $user->locale) === 'bn' ? 'selected' : '' }}>বাংলা</option>
                </select>
                @error('locale') <div class="form-error">{{ $message }}</div> @enderror
            </div>
        </div>

        <div style="display: flex; gap: 12px; margin-top: 24px; padding-top: 24px; border-top: 1px solid var(--border);">
            <button type="submit" class="btn btn-primary" data-require-online>Save Changes</button>
            <a href="{{ route('profile.show') }}" class="btn btn-secondary">Cancel</a>
            <span style="margin-left: auto; display: flex; align-items: center; gap: 8px;">
                <span data-internet-status class="internet-status online"></span>
            </span>
        </div>
    </form>

    <div class="card" style="margin-top: 24px; border-color: rgba(214,48,49,0.3);">
        <div class="card-header">
            <h3 class="card-title" style="color: var(--danger);">Danger Zone</h3>
        </div>
        <div style="display: flex; flex-direction: column; gap: 12px;">
            <div style="display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap;">
                <div>
                    <div style="font-weight: 600;">Delete Account</div>
                    <div class="text-muted" style="font-size: 13px;">Permanently delete your account and all data. Wallet balance must be zero. This action is audited and irreversible.</div>
                </div>
                <button type="button" class="btn btn-danger btn-sm" data-confirm="Are you sure you want to delete your account? This cannot be undone and requires wallet balance to be zero.">Delete Account</button>
            </div>
        </div>
    </div>
</div>
@endsection
