@extends('layouts.app')

@section('title', 'Connected Accounts')

@section('content')
<div class="settings-layout">
    @include('settings._nav')

    <div class="settings-content">
        <div style="margin-bottom: 24px; display: flex; justify-content: space-between; gap: 16px; flex-wrap: wrap;">
            <div>
                <h1 style="margin: 0 0 8px; font-size: 24px; font-weight: 800;">Connected Accounts</h1>
                <p class="text-muted">Link your Google account or phone for faster login and recovery. OAuth data is stored securely.</p>
            </div>
            <span data-internet-status class="internet-status online"></span>
        </div>

        <div class="grid" style="gap: 16px;">
            {{-- Google --}}
            <div class="card" style="display: flex; gap: 16px; align-items: center;">
                <div style="width: 48px; height: 48px; background: white; border-radius: 12px; display: grid; place-items: center; font-size: 24px;" aria-hidden="true">G</div>
                <div style="flex: 1;">
                    <div style="font-weight: 700; font-size: 15px;">Google</div>
                    <div class="text-muted" style="font-size: 13px;">
                        @if($googleIdentity ?? false)
                            Connected as {{ $googleIdentity->email ?? $googleIdentity->name }} • Last used {{ $googleIdentity->last_used_at ? \Carbon\Carbon::parse($googleIdentity->last_used_at)->diffForHumans() : 'recently' }}
                        @else
                            Not connected - Link Google for one-click login
                        @endif
                    </div>
                </div>
                <div>
                    @if($googleIdentity ?? false)
                        <form method="POST" action="{{ route('settings.connected-accounts.disconnect', 'google') }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-danger btn-sm" data-confirm="Disconnect Google account? You will need password to login." data-require-online>Disconnect</button>
                        </form>
                    @else
                        <a href="{{ route('auth.google.redirect') }}" class="btn btn-secondary btn-sm" data-require-online>Connect Google</a>
                    @endif
                </div>
            </div>

            {{-- Phone --}}
            <div class="card" style="display: flex; gap: 16px; align-items: center;">
                <div style="width: 48px; height: 48px; background: var(--bg-elevated); border: 1px solid var(--border); border-radius: 12px; display: grid; place-items: center; font-size: 20px;" aria-hidden="true">📱</div>
                <div style="flex: 1;">
                    <div style="font-weight: 700; font-size: 15px;">Phone Number</div>
                    <div class="text-muted" style="font-size: 13px;">
                        @if($user->phone)
                            {{ $user->phone }} @if($user->phone_verified_at) <x-status-pill status="success" label="Verified" /> @else <x-status-pill status="warning" label="Unverified" /> @endif
                        @else
                            No phone linked - Add phone for OTP login
                        @endif
                    </div>
                </div>
                <div style="display: flex; gap: 8px;">
                    @if($user->phone && !$user->phone_verified_at)
                        <form method="POST" action="{{ route('settings.phone.verify.request') }}">
                            @csrf
                            <button type="submit" class="btn btn-primary btn-sm" data-require-online>Verify</button>
                        </form>
                    @endif
                    <a href="{{ route('profile.edit') }}" class="btn btn-secondary btn-sm">Manage</a>
                </div>
            </div>

            {{-- Future providers placeholder --}}
            <div class="card" style="display: flex; gap: 16px; align-items: center; opacity: 0.6;">
                <div style="width: 48px; height: 48px; background: var(--bg-elevated); border: 1px solid var(--border); border-radius: 12px; display: grid; place-items: center; font-size: 20px;" aria-hidden="true">🎮</div>
                <div style="flex: 1;">
                    <div style="font-weight: 700; font-size: 15px;">Discord (Coming Soon)</div>
                    <div class="text-muted" style="font-size: 13px;">Link Discord for team coordination and tournament announcements</div>
                </div>
                <div>
                    <button class="btn btn-ghost btn-sm" disabled>Soon</button>
                </div>
            </div>
        </div>

        <div class="card" style="margin-top: 24px;">
            <h3 style="margin: 0 0 12px; font-size: 14px; font-weight: 700;">Security & Privacy</h3>
            <ul style="margin: 0; padding-left: 18px; font-size: 13px; color: var(--text-muted); display: grid; gap: 6px;">
                <li>OAuth tokens are encrypted at rest and never logged</li>
                <li>We only request <code>email</code> and <code>profile</code> scopes - no extra permissions</li>
                <li>Disconnecting removes token immediately, audit logged</li>
                <li>Phone numbers are hashed for rate limiting, OTP codes are hashed (bcrypt) and expire in 5 minutes</li>
                <li>Internet required for OAuth flow - offline fallback to password/OTP</li>
            </ul>
            <div style="margin-top: 12px; display: flex; gap: 8px; align-items: center;">
                <span data-internet-status class="internet-status online"></span>
                <span class="text-muted" style="font-size: 12px;">OAuth requires internet. If offline, use password login.</span>
            </div>
        </div>
    </div>
</div>
@endsection
