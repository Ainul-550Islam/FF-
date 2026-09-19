@extends('layouts.app')
@section('title', 'Active Weekly Events')
@section('content')
<div class="container" style="max-width: 1000px; margin: 0 auto; padding: 24px;">
<h1 style="font-size: 28px; font-weight: 800;">🔥 Active Weekly Events</h1>
<p style="color: var(--text-muted);">Join active weekly special events - Gold gems rewards - Leaderboard</p>
<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 16px; margin-top: 20px;">
@forelse($active as $event)
@php $progress = collect($userProgress)->firstWhere('event.id', $event->id); @endphp
<div class="card" style="padding: 16px; border-left: 4px solid #27ae60;">
<h3 style="margin: 0 0 8px;">{{ $event->name }} 🔥</h3>
<div style="font-size: 12px; color: var(--text-muted);">{{ $event->description }}</div>
<div style="font-size: 11px; color: var(--text-muted); margin-top: 8px;">Ends {{ $event->ends_at->diffForHumans() }} | Target {{ $event->target_progress }} | Participants {{ $event->participants()->count() }}</div>
@if($progress)
<div style="margin-top: 8px; background: var(--bg-tertiary); height: 8px; border-radius: 999px; overflow: hidden;"><div style="height: 100%; background: var(--primary); width: {{ $progress['percent'] }}%;"></div></div>
<div style="font-size: 11px; margin-top: 4px;">Progress {{ $progress['progress'] }}/{{ $event->target_progress }} ({{ $progress['percent'] }}%) @if($progress['is_completed'])✅ Completed @endif</div>
@endif
<div style="margin-top: 12px; display: flex; gap: 6px;">
<a href="{{ route('gameberry.events.show', $event->id) }}" class="btn btn-sm btn-secondary">View</a>
@if(!$progress)<form method="POST" action="{{ route('gameberry.events.join', $event->id) }}">@csrf<button type="submit" class="btn btn-sm btn-primary">Join</button></form>
@elseif($progress['is_completed'] && empty($progress['rewards_claimed']))<form method="POST" action="{{ route('gameberry.events.claim', $event->id) }}">@csrf<button type="submit" class="btn btn-sm btn-primary">Claim</button></form>@endif
</div>
</div>
@empty
<p style="color: var(--text-muted);">No active events - Weekly special events evolving engaging social rewarding</p>
@endforelse
</div>
</div>
@endsection
