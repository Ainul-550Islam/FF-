@extends('layouts.app')

@section('title', 'Profile - ' . $user->display_name_or_name)

@section('content')
<div class="profile-header">
    <x-avatar :user="$user" size="xl" />
    <div class="profile-meta">
        <h1>{{ $user->display_name_or_name }}</h1>
        <p class="text-muted">@{{ $user->username ?? Str::slug($user->name) }} • {{ $user->email }}</p>
        @if($user->bio)
            <p style="margin-top: 12px; max-width: 500px;">{{ $user->bio }}</p>
        @endif
        <div style="display: flex; gap: 12px; margin-top: 16px; flex-wrap: wrap;">
            <x-status-pill :status="$user->is_active ? 'active' : 'offline'" :label="$user->is_active ? 'Active' : 'Inactive'" />
            @if($user->is_admin)
                <x-status-pill status="info" label="Admin" />
            @endif
            @if($user->phone_verified_at)
                <x-status-pill status="success" label="Phone Verified" />
            @endif
            <span class="internet-status {{ $user->last_seen_at && $user->last_seen_at->diffInMinutes() < 5 ? 'online' : 'offline' }}">
                Last seen {{ $user->last_seen_at ? $user->last_seen_at->diffForHumans() : 'never' }}
            </span>
        </div>
        <div class="profile-stats">
            <div class="stat">
                <div class="stat-value">{{ $user->wallets()->count() }}</div>
                <div class="stat-label">Wallets</div>
            </div>
            <div class="stat">
                <div class="stat-value">{{ $user->payments()->count() }}</div>
                <div class="stat-label">Payments</div>
            </div>
            <div class="stat">
                <div class="stat-value">{{ $user->created_at->diffInDays() }}</div>
                <div class="stat-label">Days</div>
            </div>
        </div>
    </div>
    <div style="margin-left: auto; display: flex; gap: 8px; flex-wrap: wrap;">
        <a href="{{ route('profile.edit') }}" class="btn btn-primary">Edit Profile</a>
        <a href="{{ route('settings.security') }}" class="btn btn-secondary">Security</a>
    </div>
</div>

<div class="grid grid-2">
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Profile Information</h2>
        </div>
        <div style="display: grid; gap: 12px;">
            <div>
                <div class="text-muted" style="font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em;">Display Name</div>
                <div style="font-weight: 600;">{{ $user->display_name ?? $user->name }}</div>
            </div>
            <div>
                <div class="text-muted" style="font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em;">Username</div>
                <div style="font-weight: 600;" class="font-mono">@{{ $user->username ?? 'not set' }}</div>
                @if($user->username_changed_at)
                    <div class="text-muted" style="font-size: 12px;">Changed {{ $user->username_changed_at->diffForHumans() }} - Can change again in {{ $user->daysUntilUsernameChange() }} days</div>
                @endif
            </div>
            <div>
                <div class="text-muted" style="font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em;">Email</div>
                <div style="font-weight: 600;">{{ $user->email }} @if($user->email_verified_at) <x-status-pill status="success" label="Verified" /> @else <x-status-pill status="warning" label="Unverified" /> @endif</div>
            </div>
            <div>
                <div class="text-muted" style="font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em;">Phone</div>
                <div style="font-weight: 600;">{{ $user->phone ?? 'Not set' }} @if($user->phone_verified_at) <x-status-pill status="success" label="Verified" /> @endif</div>
            </div>
            <div>
                <div class="text-muted" style="font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em;">Country / Timezone</div>
                <div style="font-weight: 600;">{{ $user->country ?? 'Not set' }} • {{ $user->timezone ?? 'Asia/Dhaka' }}</div>
            </div>
            <div>
                <div class="text-muted" style="font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em;">Member Since</div>
                <div style="font-weight: 600;">{{ $user->created_at->format('M d, Y') }} ({{ $user->created_at->diffForHumans() }})</div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Internet & Device Status</h2>
            <span data-internet-status class="internet-status online"></span>
        </div>
        <div style="display: grid; gap: 16px;">
            <div class="alert alert-info">
                <span>ℹ</span>
                <div>
                    <strong>Connection Check</strong>
                    <p style="margin: 4px 0 0; font-size: 13px;">Your browser reports <span id="conn-status-text">checking...</span>. We continuously monitor connectivity to ensure your tournament actions are not lost.</p>
                </div>
            </div>
            <div>
                <div class="text-muted" style="font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em;">Current Session</div>
                <div style="font-size: 13px; margin-top: 4px;">
                    IP: <span class="font-mono">{{ request()->ip() }}</span><br>
                    Device: <span class="font-mono">{{ Str::limit(request()->userAgent(), 60) }}</span><br>
                    Last Active: {{ now()->format('Y-m-d H:i:s') }}
                </div>
            </div>
            <div style="display: flex; gap: 8px;">
                <button onclick="window.FFArena?.checkInternet()" class="btn btn-secondary btn-sm" data-require-online>Check Connection</button>
                <a href="{{ route('settings.sessions') }}" class="btn btn-ghost btn-sm">Manage Sessions</a>
            </div>
            <div class="text-muted" style="font-size: 12px;">
                <strong>Tip:</strong> If you go offline during a tournament registration, your request will be retried automatically when you reconnect. Idempotency ensures no duplicate charges.
            </div>
        </div>
    </div>
</div>

<div class="card" style="margin-top: 24px;">
    <div class="card-header">
        <h2 class="card-title">Recent Activity</h2>
        <a href="{{ route('settings.login-history') }}" class="btn btn-ghost btn-sm">View All</a>
    </div>
    @if($loginEvents ?? false && $loginEvents->count())
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Event</th>
                        <th>Device</th>
                        <th>IP</th>
                        <th>Time</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($loginEvents as $event)
                        <tr>
                            <td>{{ ucfirst($event->event) }}</td>
                            <td>{{ $event->device_label ?? Str::limit($event->user_agent, 30) }}</td>
                            <td class="font-mono">{{ $event->ip_address ?? '—' }}</td>
                            <td>{{ $event->created_at->diffForHumans() }}</td>
                            <td><x-status-pill :status="$event->successful ? 'success' : 'danger'" :label="$event->successful ? 'Success' : 'Failed'" /></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <x-empty-state icon="🔒" title="No recent activity" text="Your login history will appear here. This helps you detect unauthorized access." />
    @endif
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const el = document.getElementById('conn-status-text');
    if (el) {
        function upd() {
            el.textContent = navigator.onLine ? 'online (browser reports connected)' : 'offline (browser reports disconnected)';
        }
        window.addEventListener('online', upd);
        window.addEventListener('offline', upd);
        upd();
    }
});
</script>
@endpush
@endsection
