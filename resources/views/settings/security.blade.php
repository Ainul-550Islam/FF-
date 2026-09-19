@extends('layouts.app')

@section('title', 'Security Settings')

@section('content')
<div class="settings-layout">
    @include('settings._nav')

    <div class="settings-content">
        <div style="margin-bottom: 24px;">
            <h1 style="margin: 0 0 8px; font-size: 24px; font-weight: 800;">Security Settings</h1>
            <p class="text-muted">Manage your password, two-factor authentication, and security preferences.</p>
            <div style="margin-top: 12px;">
                <span data-internet-status class="internet-status online"></span>
            </div>
        </div>

        <div class="card" style="margin-bottom: 24px;">
            <div class="card-header">
                <h2 class="card-title">Change Password</h2>
            </div>
            <form method="POST" action="{{ route('settings.security.password') }}">
                @csrf
                @method('PUT')
                <div class="form-group">
                    <label for="current_password" class="form-label required">Current Password</label>
                    <input type="password" id="current_password" name="current_password" class="form-input @error('current_password') is-invalid @enderror" required autocomplete="current-password">
                    @error('current_password') <div class="form-error">{{ $message }}</div> @enderror
                </div>
                <div class="grid grid-2">
                    <div class="form-group">
                        <label for="password" class="form-label required">New Password</label>
                        <input type="password" id="password" name="password" class="form-input @error('password') is-invalid @enderror" required autocomplete="new-password" minlength="8">
                        <div class="form-hint">Min 8 chars, mix of letters, numbers, symbols</div>
                        @error('password') <div class="form-error">{{ $message }}</div> @enderror
                    </div>
                    <div class="form-group">
                        <label for="password_confirmation" class="form-label required">Confirm New Password</label>
                        <input type="password" id="password_confirmation" name="password_confirmation" class="form-input" required autocomplete="new-password">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary" data-require-online>Update Password</button>
            </form>
        </div>

        <div class="card" style="margin-bottom: 24px;">
            <div class="card-header">
                <h2 class="card-title">Two-Factor Authentication</h2>
                <x-status-pill status="{{ $user->two_factor_enabled ?? false ? 'success' : 'warning' }}" :label="($user->two_factor_enabled ?? false) ? 'Enabled' : 'Disabled'" />
            </div>
            <div style="display: grid; gap: 16px;">
                <p class="text-muted" style="font-size: 14px; margin: 0;">Add an extra layer of security to your account. When enabled, you'll need to enter a code from your authenticator app during login.</p>
                
                @if($user->two_factor_enabled ?? false)
                    <div class="alert alert-success">
                        <span>✓</span>
                        <span>2FA is enabled. Your account is protected with time-based one-time passwords.</span>
                    </div>
                    <div style="display: flex; gap: 8px;">
                        <button class="btn btn-secondary btn-sm">View Recovery Codes</button>
                        <form method="POST" action="{{ route('settings.security.2fa.disable') }}" style="display: inline;">
                            @csrf
                            <button type="submit" class="btn btn-danger btn-sm" data-confirm="Disable 2FA? This reduces account security.">Disable 2FA</button>
                        </form>
                    </div>
                @else
                    <div class="alert alert-warning">
                        <span>⚠</span>
                        <span>2FA is not enabled. We strongly recommend enabling it to protect your wallet and tournament entries.</span>
                    </div>
                    <a href="{{ route('settings.security.2fa.setup') }}" class="btn btn-primary btn-sm" style="align-self: flex-start;">Enable 2FA</a>
                @endif
            </div>
        </div>

        <div class="card" style="margin-bottom: 24px;">
            <div class="card-header">
                <h2 class="card-title">Login Notifications</h2>
            </div>
            <div style="display: grid; gap: 12px;">
                <label style="display: flex; align-items: center; gap: 12px; cursor: pointer;">
                    <input type="checkbox" name="notify_new_device" value="1" {{ old('notify_new_device', $user->notify_new_device ?? true) ? 'checked' : '' }} style="width: 18px; height: 18px;">
                    <span>
                        <strong>New device login</strong>
                        <span class="text-muted" style="display: block; font-size: 13px;">Email notification when login from new device</span>
                    </span>
                </label>
                <label style="display: flex; align-items: center; gap: 12px; cursor: pointer;">
                    <input type="checkbox" name="notify_failed_login" value="1" {{ old('notify_failed_login', $user->notify_failed_login ?? true) ? 'checked' : '' }} style="width: 18px; height: 18px;">
                    <span>
                        <strong>Failed login attempts</strong>
                        <span class="text-muted" style="display: block; font-size: 13px;">Notify after 3 failed attempts</span>
                    </span>
                </label>
            </div>
        </div>

        <div class="card" style="border-color: rgba(214,48,49,0.3);">
            <div class="card-header">
                <h2 class="card-title" style="color: var(--danger);">Active Sessions</h2>
                <a href="{{ route('settings.sessions') }}" class="btn btn-ghost btn-sm">Manage All</a>
            </div>
            <p class="text-muted" style="font-size: 14px;">If you see an unfamiliar device, revoke its session immediately and change your password.</p>
            <div style="margin-top: 12px;">
                <div style="display: flex; gap: 12px; align-items: center; padding: 12px; background: var(--bg-elevated); border-radius: 8px; border: 1px solid var(--border);">
                    <span style="width: 10px; height: 10px; background: var(--success); border-radius: 50%; display: inline-block;"></span>
                    <div style="flex: 1;">
                        <div style="font-weight: 600; font-size: 14px;">Current Session • {{ request()->ip() }}</div>
                        <div class="text-muted" style="font-size: 12px;">{{ Str::limit(request()->userAgent(), 80) }} • Active now</div>
                    </div>
                    <x-status-pill status="success" label="Current" />
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
