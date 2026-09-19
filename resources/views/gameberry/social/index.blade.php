@extends('layouts.app')

@section('title', 'Game Buddies - Max 25 - Hide Online Status - Notify Friends')

@section('content')
<div class="container" style="max-width: 1280px; margin: 0 auto; padding: 24px;">
    <h1 style="font-size: 28px; font-weight: 800; margin: 0 0 8px;">👥 Game Buddies & Social</h1>
    <p style="color: var(--text-muted); margin: 0 0 24px;">Max 25 buddies, Hide online status, Notify friends online, Challenge button, Auto mode on disconnect, Team-up mode</p>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px;">
        <div class="card" style="text-align: center; padding: 16px;">
            <div style="font-size: 32px; font-weight: 800;">{{ $stats['total_buddies'] }}/{{ $stats['max_buddies'] }}</div>
            <div style="font-size: 13px; color: var(--text-muted);">Buddies</div>
            <div style="font-size: 11px; color: {{ $stats['can_add_more'] ? '#27ae60' : '#e74c3c' }};">{{ $stats['can_add_more'] ? 'Can add more' : 'Max reached' }}</div>
        </div>
        <div class="card" style="text-align: center; padding: 16px;">
            <div style="font-size: 32px; font-weight: 800;">{{ $stats['online_buddies'] }}</div>
            <div style="font-size: 13px; color: var(--text-muted);">Online Buddies</div>
        </div>
        <div class="card" style="text-align: center; padding: 16px;">
            <div style="font-size: 32px; font-weight: 800;">{{ $stats['pending_requests'] }}</div>
            <div style="font-size: 13px; color: var(--text-muted);">Pending Requests</div>
        </div>
        <div class="card" style="text-align: center; padding: 16px;">
            <div style="font-size: 32px; font-weight: 800;">{{ $stats['unread_notifications'] }}</div>
            <div style="font-size: 13px; color: var(--text-muted);">Unread Notifications</div>
        </div>
    </div>

    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
        <div class="card">
            <div style="padding: 20px;">
                <h3>Online Status Settings (Gameberry FAQ)</h3>
                <div style="background: var(--bg-secondary); padding: 12px; border-radius: 8px; margin-bottom: 12px; font-size: 13px;">
                    <div>Current: {{ $onlineStatus->is_online ? '🟢 Online' : '🔴 Offline' }} | Hide: {{ $onlineStatus->hide_online_status ? 'Yes Hidden' : 'No Visible' }} | Notify Friends: {{ $onlineStatus->notify_friends_online ? 'Yes' : 'No' }} | Auto Mode: {{ $onlineStatus->is_in_auto_mode ? '🤖 ON' : 'OFF' }}</div>
                </div>
                <div style="display: flex; flex-direction: column; gap: 8px;">
                    <form method="POST" action="{{ route('gameberry.social.hide_status') }}" style="display: flex; gap: 8px;">
                        @csrf
                        <input type="hidden" name="hide" value="{{ $onlineStatus->hide_online_status ? 0 : 1 }}">
                        <button type="submit" class="btn btn-sm btn-secondary" style="flex: 1;">{{ $onlineStatus->hide_online_status ? 'Show Online Status' : 'Hide Online Status 🙈' }}</button>
                    </form>
                    <form method="POST" action="{{ route('gameberry.social.notify_friends') }}" style="display: flex; gap: 8px;">
                        @csrf
                        <input type="hidden" name="notify" value="{{ $onlineStatus->notify_friends_online ? 0 : 1 }}">
                        <button type="submit" class="btn btn-sm btn-secondary" style="flex: 1;">{{ $onlineStatus->notify_friends_online ? 'Disable Notify Friends' : 'Enable Notify Friends Online 🔔' }}</button>
                    </form>
                    <form method="POST" action="{{ route('gameberry.social.auto_mode') }}" style="display: flex; gap: 8px;">
                        @csrf
                        <input type="hidden" name="auto_on" value="{{ $onlineStatus->is_in_auto_mode ? 0 : 1 }}">
                        <input type="hidden" name="reason" value="manual">
                        <button type="submit" class="btn btn-sm {{ $onlineStatus->is_in_auto_mode ? 'btn-ghost' : 'btn-primary' }}" style="flex: 1;">{{ $onlineStatus->is_in_auto_mode ? 'Disable Auto Mode' : 'Enable Auto Mode on Disconnect 🤖' }}</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="card">
            <div style="padding: 20px;">
                <h3>Add Buddy (Max 25)</h3>
                <form method="POST" action="{{ route('gameberry.social.add_buddy') }}" style="display: flex; gap: 8px; margin-bottom: 16px;">
                    @csrf
                    <input type="number" name="buddy_id" placeholder="Buddy User ID" required style="flex: 1; padding: 8px 12px; border-radius: 8px; border: 1px solid var(--border); background: var(--bg-secondary);">
                    <button type="submit" class="btn btn-sm btn-primary">Add Buddy</button>
                </form>

                <h4 style="margin: 16px 0 8px;">Pending Requests ({{ $pending->count() }})</h4>
                @forelse($pending as $p)
                <div style="display: flex; justify-content: space-between; align-items: center; background: var(--bg-secondary); padding: 8px; border-radius: 6px; margin-bottom: 6px;">
                    <span style="font-size: 13px;">{{ $p->user->name ?? 'User '.$p->user_id }}</span>
                    <div style="display: flex; gap: 4px;">
                        <form method="POST" action="{{ route('gameberry.social.accept_buddy', $p->user_id) }}">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-primary">Accept</button>
                        </form>
                    </div>
                </div>
                @empty
                <p style="font-size: 12px; color: var(--text-muted);">No pending requests</p>
                @endforelse
            </div>
        </div>
    </div>

    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px;">
        <div class="card">
            <div style="padding: 20px;">
                <h3>My Buddies ({{ $buddies->count() }}) - Challenge Button</h3>
                @forelse($buddies as $buddy)
                <div style="display: flex; justify-content: space-between; align-items: center; background: var(--bg-secondary); padding: 10px; border-radius: 8px; margin-bottom: 8px;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <div style="width: 32px; height: 32px; border-radius: 50%; background: var(--primary); display: flex; align-items: center; justify-content: center; color: white; font-size: 12px;">{{ substr($buddy->buddy->name ?? 'U', 0, 1) }}</div>
                        <div>
                            <div style="font-size: 13px; font-weight: 600;">{{ $buddy->buddy->name ?? 'User '.$buddy->buddy_id }}</div>
                            <div style="font-size: 11px; color: var(--text-muted);">{{ $buddy->buddy->onlineStatus->is_online ?? false ? '🟢 Online' : '🔴 Offline' }} @if($buddy->buddy->onlineStatus->hide_online_status ?? false)🙈 Hidden @endif</div>
                        </div>
                    </div>
                    <div style="display: flex; gap: 4px;">
                        <form method="POST" action="{{ route('gameberry.social.challenge') }}">
                            @csrf
                            <input type="hidden" name="buddy_id" value="{{ $buddy->buddy_id }}">
                            <input type="hidden" name="bet_amount" value="100">
                            <button type="submit" class="btn btn-sm btn-primary">Challenge 🎯</button>
                        </form>
                        <form method="POST" action="{{ route('gameberry.social.remove_buddy', $buddy->buddy_id) }}">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-ghost">Remove</button>
                        </form>
                    </div>
                </div>
                @empty
                <p style="font-size: 13px; color: var(--text-muted);">No buddies yet - Add up to 25</p>
                @endforelse
            </div>
        </div>

        <div class="card">
            <div style="padding: 20px;">
                <h3>Online Buddies ({{ $onlineBuddies->count() }})</h3>
                @forelse($onlineBuddies as $status)
                <div style="background: var(--bg-secondary); padding: 8px; border-radius: 6px; margin-bottom: 6px; font-size: 13px; display: flex; justify-content: space-between;">
                    <span>{{ $status->user->name ?? 'User '.$status->user_id }} 🟢</span>
                    <span style="font-size: 11px; color: var(--text-muted);">{{ $status->current_game ?? 'Lobby' }} @if($status->current_table_code) Table {{ $status->current_table_code }} @endif</span>
                </div>
                @empty
                <p style="font-size: 12px; color: var(--text-muted);">No online buddies - Enable notify friends online to get notified</p>
                @endforelse

                <h4 style="margin: 16px 0 8px;">Friend Notifications</h4>
                @forelse($notifications->take(5) as $notif)
                <div style="font-size: 12px; background: var(--bg-secondary); padding: 6px; border-radius: 4px; margin-bottom: 4px;">
                    {{ $notif->type }} - Friend {{ $notif->friend_id }} @if(!$notif->is_read) <span style="background: var(--primary); color: white; padding: 1px 4px; border-radius: 999px; font-size: 10px;">New</span> @endif
                </div>
                @empty
                <p style="font-size: 11px; color: var(--text-muted);">No notifications</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection
