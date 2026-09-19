@extends('layouts.app')
@section('title', 'Friend Notifications - Online Alerts')
@section('content')
<div class="container" style="max-width: 800px; margin: 0 auto; padding: 24px;">
<h1 style="font-size: 28px; font-weight: 800;">🔔 Friend Notifications</h1>
<p style="color: var(--text-muted);">Notify friends online - Gameberry FAQ - Get notified when buddies come online</p>
<div class="card" style="padding: 20px; margin-top: 20px;">
@forelse($notifications as $notif)
<div style="background: var(--bg-secondary); padding: 12px; border-radius: 8px; margin-bottom: 8px; display: flex; justify-content: space-between; align-items: center;">
<div>
<div style="font-weight: 600; font-size: 13px;">{{ $notif->type }} - Friend {{ $notif->friend->name ?? $notif->friend_id }} is online 🟢</div>
<div style="font-size: 11px; color: var(--text-muted);">{{ $notif->created_at->diffForHumans() }} | Payload: {{ json_encode($notif->payload) }}</div>
</div>
@if(!$notif->is_read)<span style="background: var(--primary); color: white; padding: 2px 8px; border-radius: 999px; font-size: 10px;">New</span>@endif
</div>
@empty
<p style="color: var(--text-muted); font-size: 13px;">No notifications - Enable notify friends online to get alerts when buddies come online - Hide online status respected</p>
@endforelse
</div>
</div>
@endsection
