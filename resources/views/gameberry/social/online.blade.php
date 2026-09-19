@extends('layouts.app')
@section('title', 'Online Status - Hide Online Status - Notify Friends')
@section('content')
<div class="container" style="max-width: 800px; margin: 0 auto; padding: 24px;">
<h1 style="font-size: 28px; font-weight: 800;">🟢 Online Status Settings</h1>
<p style="color: var(--text-muted);">Gameberry FAQ: Hide online status, Notify friends online, Auto mode on disconnect</p>
<div class="card" style="padding: 20px; margin-top: 20px;">
<div style="background: var(--bg-secondary); padding: 16px; border-radius: 12px; margin-bottom: 16px;">
<div style="display: flex; justify-content: space-between; font-size: 14px; margin-bottom: 8px;"><span>Current Status:</span><strong>{{ $onlineStatus->is_online ? '🟢 Online' : '🔴 Offline' }}</strong></div>
<div style="display: flex; justify-content: space-between; font-size: 14px; margin-bottom: 8px;"><span>Hide Online Status:</span><strong>{{ $onlineStatus->hide_online_status ? '🙈 Hidden' : '👁️ Visible' }}</strong></div>
<div style="display: flex; justify-content: space-between; font-size: 14px; margin-bottom: 8px;"><span>Notify Friends Online:</span><strong>{{ $onlineStatus->notify_friends_online ? '🔔 Yes' : '🔕 No' }}</strong></div>
<div style="display: flex; justify-content: space-between; font-size: 14px;"><span>Auto Mode:</span><strong>{{ $onlineStatus->is_in_auto_mode ? '🤖 ON' : 'OFF' }}</strong></div>
</div>
<div style="display: flex; flex-direction: column; gap: 12px;">
<form method="POST" action="{{ route('gameberry.social.online_status') }}">@csrf<input type="hidden" name="is_online" value="{{ $onlineStatus->is_online ? 0 : 1 }}"><button type="submit" class="btn {{ $onlineStatus->is_online ? 'btn-ghost' : 'btn-primary' }}" style="width: 100%;">{{ $onlineStatus->is_online ? 'Go Offline 🔴' : 'Go Online 🟢' }}</button></form>
<form method="POST" action="{{ route('gameberry.social.hide_status') }}">@csrf<input type="hidden" name="hide" value="{{ $onlineStatus->hide_online_status ? 0 : 1 }}"><button type="submit" class="btn btn-secondary" style="width: 100%;">{{ $onlineStatus->hide_online_status ? 'Show Online Status 👁️' : 'Hide Online Status 🙈 - Gameberry FAQ' }}</button></form>
<form method="POST" action="{{ route('gameberry.social.notify_friends') }}">@csrf<input type="hidden" name="notify" value="{{ $onlineStatus->notify_friends_online ? 0 : 1 }}"><button type="submit" class="btn btn-secondary" style="width: 100%;">{{ $onlineStatus->notify_friends_online ? 'Disable Notify Friends 🔕' : 'Enable Notify Friends Online 🔔 - Gameberry FAQ' }}</button></form>
<form method="POST" action="{{ route('gameberry.social.auto_mode') }}">@csrf<input type="hidden" name="auto_on" value="{{ $onlineStatus->is_in_auto_mode ? 0 : 1 }}"><input type="hidden" name="reason" value="manual"><button type="submit" class="btn {{ $onlineStatus->is_in_auto_mode ? 'btn-ghost' : 'btn-primary' }}" style="width: 100%;">{{ $onlineStatus->is_in_auto_mode ? 'Disable Auto Mode' : 'Enable Auto Mode on Disconnect 🤖 - Gameberry FAQ' }}</button></form>
</div>
<div style="margin-top: 20px; background: var(--bg-secondary); padding: 12px; border-radius: 8px; font-size: 12px;">
<strong>Gameberry FAQ Features:</strong><br>
• Hide online status - Hide your online presence from friends<br>
• Notify friends online - Get notified when buddies come online<br>
• Auto mode on disconnect - Auto-play if you disconnect during game<br>
• Online status visible only if not hidden
</div>
</div>
</div>
@endsection
