@extends('layouts.app')
@section('title', 'Upcoming Weekly Events')
@section('content')
<div class="container" style="max-width: 1000px; margin: 0 auto; padding: 24px;">
<h1 style="font-size: 28px; font-weight: 800;">⏰ Upcoming Weekly Events</h1>
<p style="color: var(--text-muted);">Gameberry weekly special events - Evolving engaging social rewarding</p>
<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 16px; margin-top: 20px;">
@forelse($upcoming as $event)
<div class="card" style="padding: 16px; border-left: 4px solid #f39c12;">
<h3 style="margin: 0 0 8px;">{{ $event->name }}</h3>
<div style="font-size: 12px; color: var(--text-muted);">{{ $event->description }}</div>
<div style="font-size: 11px; color: var(--text-muted); margin-top: 8px;">Starts {{ $event->starts_at->diffForHumans() }} | Ends {{ $event->ends_at->diffForHumans() }} | Target {{ $event->target_progress }} | Rewards {{ json_encode($event->rewards) }}</div>
</div>
@empty
<p style="color: var(--text-muted);">No upcoming events - Check back for weekly special events evolving engaging social rewarding</p>
@endforelse
</div>
</div>
@endsection
