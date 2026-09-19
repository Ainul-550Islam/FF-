@extends('layouts.app')

@section('title', 'Sessions')

@section('content')
<div class="settings-layout">
    @include('settings._nav')

    <div class="settings-content">
        <div style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap;">
            <div>
                <h1 style="margin: 0 0 8px; font-size: 24px; font-weight: 800;">Active Sessions</h1>
                <p class="text-muted">Manage devices that are logged into your account. Revoke any you don't recognize.</p>
            </div>
            <span data-internet-status class="internet-status online"></span>
        </div>

        <div class="alert alert-info" style="margin-bottom: 24px;">
            <span>ℹ</span>
            <div>
                <strong>Internet-aware security</strong>
                <p style="margin: 4px 0 0; font-size: 13px;">We track IP, device, and location for each session. If your internet disconnects, sessions remain valid but financial actions require reconnection and idempotency check.</p>
            </div>
        </div>

        @if(($sessions ?? collect())->count() > 0)
            <div style="display: grid; gap: 12px;">
                @foreach($sessions as $session)
                    <div class="card" style="padding: 16px; {{ $session->is_current ? 'border-color: var(--primary); background: var(--primary-soft);' : '' }}">
                        <div style="display: flex; gap: 16px; align-items: flex-start;">
                            <div style="width: 40px; height: 40px; background: var(--bg-elevated); border-radius: 10px; display: grid; place-items: center; font-size: 20px;" aria-hidden="true">
                                {{ str_contains(strtolower($session->device_label ?? $session->user_agent ?? ''), 'mobile') ? '📱' : '💻' }}
                            </div>
                            <div style="flex: 1; min-width: 0;">
                                <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                                    <strong style="font-size: 14px;">{{ $session->device_label ?? 'Unknown Device' }}</strong>
                                    @if($session->is_current)
                                        <x-status-pill status="success" label="Current" />
                                    @endif
                                    @if($session->is_revoked)
                                        <x-status-pill status="danger" label="Revoked" />
                                    @endif
                                </div>
                                <div class="text-muted" style="font-size: 12px; margin-top: 4px; word-break: break-all;">
                                    IP: <span class="font-mono">{{ $session->ip_address ?? $session->session_id }}</span> • 
                                    Last active: {{ $session->last_active_at ? \Carbon\Carbon::parse($session->last_active_at)->diffForHumans() : 'Unknown' }}<br>
                                    Agent: {{ Str::limit($session->user_agent, 100) }}<br>
                                    @if($session->location) Location: {{ $session->location }} • @endif
                                    Expires: {{ $session->expires_at ? \Carbon\Carbon::parse($session->expires_at)->diffForHumans() : 'Session end' }}
                                </div>
                            </div>
                            <div>
                                @if(!$session->is_current && !$session->is_revoked)
                                    <form method="POST" action="{{ route('settings.sessions.revoke', $session) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-danger btn-sm" data-confirm="Revoke this session? Device will be logged out." data-require-online>Revoke</button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <div style="margin-top: 24px; display: flex; gap: 12px; flex-wrap: wrap;">
                <form method="POST" action="{{ route('settings.sessions.revokeAll') }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-danger" data-confirm="Revoke all other sessions? You will remain logged in on this device." data-require-online>Revoke All Other Sessions</button>
                </form>
                <button onclick="window.FFArena?.checkInternet()" class="btn btn-secondary" data-require-online>Refresh & Check Connection</button>
            </div>
        @else
            <x-empty-state icon="💻" title="No active sessions" text="We couldn't find any active sessions. This might happen after clearing storage or if sessions table is empty. Your current session is still valid." action="Check Internet" actionHref="#" />
            <script>document.querySelector('[action="#"]')?.addEventListener('click', e => { e.preventDefault(); window.FFArena?.checkInternet(); });</script>
        @endif

        <div class="card" style="margin-top: 24px;">
            <h3 style="margin: 0 0 12px; font-size: 16px; font-weight: 700;">How sessions work offline</h3>
            <ul style="margin: 0; padding-left: 20px; color: var(--text-muted); font-size: 13px; display: grid; gap: 8px;">
                <li>Sessions are stored in database (multi-instance safe, works without Redis)</li>
                <li>If you go offline, your session stays valid locally for up to 2 hours</li>
                <li>Financial actions (payments, tournament join) require online + idempotency key to prevent duplicates</li>
                <li>We log IP hash and device hash, not raw IP in analytics (privacy)</li>
                <li>Revoking a session immediately invalidates its token server-side</li>
            </ul>
        </div>
    </div>
</div>
@endsection
