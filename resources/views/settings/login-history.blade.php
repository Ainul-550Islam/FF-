@extends('layouts.app')

@section('title', 'Login History')

@section('content')
<div class="settings-layout">
    @include('settings._nav')

    <div class="settings-content">
        <div style="margin-bottom: 24px; display: flex; justify-content: space-between; gap: 16px; flex-wrap: wrap;">
            <div>
                <h1 style="margin: 0 0 8px; font-size: 24px; font-weight: 800;">Login History</h1>
                <p class="text-muted">Recent login events, including failed attempts, device changes, and location. Check regularly for unauthorized access.</p>
            </div>
            <span data-internet-status class="internet-status online"></span>
        </div>

        <div class="card" style="margin-bottom: 24px; padding: 16px; background: var(--bg-elevated);">
            <div style="display: flex; gap: 16px; align-items: center; flex-wrap: wrap;">
                <div>
                    <div style="font-weight: 700; font-size: 14px;">Security Tip</div>
                    <div class="text-muted" style="font-size: 12px;">If you see a login you don't recognize, change password and revoke sessions immediately.</div>
                </div>
                <div style="margin-left: auto; display: flex; gap: 8px;">
                    <button onclick="window.FFArena?.checkInternet()" class="btn btn-secondary btn-sm">Check Connection</button>
                    <a href="{{ route('settings.security') }}" class="btn btn-ghost btn-sm">Security Settings</a>
                </div>
            </div>
        </div>

        @if(($events ?? collect())->count() > 0)
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Event</th>
                            <th>Device / Location</th>
                            <th>IP</th>
                            <th>Time</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($events as $event)
                            <tr>
                                <td>
                                    <strong>{{ ucfirst($event->event) }}</strong>
                                    @if($event->event === 'failed')
                                        <div class="text-muted" style="font-size: 11px;">Failed attempt</div>
                                    @endif
                                </td>
                                <td>
                                    <div style="font-size: 13px; font-weight: 500;">{{ $event->device_label ?? 'Unknown Device' }}</div>
                                    <div class="text-muted" style="font-size: 11px; max-width: 250px; overflow: hidden; text-overflow: ellipsis;">{{ Str::limit($event->user_agent, 60) }}</div>
                                    @if($event->location)
                                        <div class="text-muted" style="font-size: 11px;">📍 {{ $event->location }}</div>
                                    @endif
                                </td>
                                <td class="font-mono" style="font-size: 12px;">{{ $event->ip_address ?? substr($event->ip_hash ?? '', 0, 12) . '...' }}</td>
                                <td style="font-size: 13px;">
                                    <div>{{ $event->created_at->format('M d, H:i') }}</div>
                                    <div class="text-muted" style="font-size: 11px;">{{ $event->created_at->diffForHumans() }}</div>
                                </td>
                                <td>
                                    <x-status-pill :status="$event->successful ? 'success' : 'danger'" :label="$event->successful ? 'Success' : 'Failed'" />
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if(method_exists($events, 'links'))
                <div style="margin-top: 16px;">
                    {{ $events->links('vendor.pagination.tailwind') }}
                </div>
            @endif
        @else
            <x-empty-state icon="📜" title="No login history yet" text="Login events will appear here after you log in from different devices or locations. This helps you detect suspicious activity.">
                <div style="margin-top: 16px; display: flex; gap: 8px; justify-content: center; flex-wrap: wrap;">
                    <button onclick="window.FFArena?.checkInternet()" class="btn btn-secondary btn-sm">Check Connection</button>
                    <span data-internet-status class="internet-status online"></span>
                </div>
            </x-empty-state>
        @endif

        <div class="card" style="margin-top: 24px;">
            <h3 style="margin: 0 0 12px; font-size: 14px; font-weight: 700;">What we track and why</h3>
            <div class="grid grid-2" style="font-size: 13px; color: var(--text-muted);">
                <div>
                    <strong style="color: var(--text);">Tracked (hashed for privacy):</strong>
                    <ul style="margin: 8px 0 0; padding-left: 18px; display: grid; gap: 4px;">
                        <li>IP hash (SHA256, not raw IP in logs)</li>
                        <li>Device hash (user-agent + screen)</li>
                        <li>Device label (iPhone, Windows, etc)</li>
                        <li>Event type (login, logout, failed)</li>
                        <li>Timestamp and success boolean</li>
                    </ul>
                </div>
                <div>
                    <strong style="color: var(--text);">Not tracked:</strong>
                    <ul style="margin: 8px 0 0; padding-left: 18px; display: grid; gap: 4px;">
                        <li>Raw passwords or OTP codes</li>
                        <li>Full user-agent in analytics (only hashed)</li>
                        <li>Payment secrets or tokens</li>
                        <li>Precise GPS (only city-level if available)</li>
                        <li>Private messages or dispute evidence</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
