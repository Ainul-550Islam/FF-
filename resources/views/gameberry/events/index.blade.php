@extends('layouts.app')

@section('title', 'Weekly Special Events - LudoStar')

@section('content')
<div class="container" style="max-width: 1280px; margin: 0 auto; padding: 24px;">
    <h1 style="font-size: 28px; font-weight: 800; margin: 0 0 8px;">🎉 Weekly Special Events</h1>
    <p style="color: var(--text-muted); margin: 0 0 24px;">Evolving, engaging, social, rewarding - Gameberry style weekly events</p>

    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
        <div class="card">
            <div style="padding: 20px;">
                <h3 style="margin: 0 0 16px;">🔥 Active Events ({{ $active->count() }})</h3>
                @forelse($active as $event)
                <div style="background: var(--bg-secondary); padding: 16px; border-radius: 12px; margin-bottom: 12px; border-left: 4px solid var(--primary);">
                    <div style="display: flex; justify-content: space-between; align-items: start;">
                        <div>
                            <h4 style="margin: 0; font-size: 16px;">{{ $event->name }}</h4>
                            <div style="font-size: 12px; color: var(--text-muted); margin: 4px 0;">{{ $event->description }}</div>
                            <div style="font-size: 11px; color: var(--text-muted);">Ends: {{ $event->ends_at->diffForHumans() }} | Target: {{ $event->target_progress }} | Rewards: {{ json_encode($event->rewards) }}</div>
                            @php $progress = collect($userProgress)->firstWhere('event.id', $event->id); @endphp
                            @if($progress)
                            <div style="margin-top: 8px;">
                                <div style="background: var(--bg-tertiary); height: 8px; border-radius: 999px; overflow: hidden;">
                                    <div style="height: 100%; background: var(--primary); width: {{ $progress['percent'] }}%;"></div>
                                </div>
                                <div style="font-size: 11px; margin-top: 4px;">Progress: {{ $progress['progress'] }}/{{ $event->target_progress }} ({{ $progress['percent'] }}%) @if($progress['is_completed'])✅ Completed @endif</div>
                            </div>
                            @endif
                        </div>
                        <div style="display: flex; flex-direction: column; gap: 4px;">
                            <a href="{{ route('gameberry.events.show', $event->id) }}" class="btn btn-sm btn-secondary">View</a>
                            @if(!$progress)
                            <form method="POST" action="{{ route('gameberry.events.join', $event->id) }}">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-primary">Join</button>
                            </form>
                            @elseif($progress['is_completed'] && empty($progress['rewards_claimed']))
                            <form method="POST" action="{{ route('gameberry.events.claim', $event->id) }}">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-primary">Claim Reward</button>
                            </form>
                            @endif
                        </div>
                    </div>
                </div>
                @empty
                <p style="color: var(--text-muted); font-size: 13px;">No active events - Check back for weekly special events</p>
                @endforelse
            </div>
        </div>

        <div class="card">
            <div style="padding: 20px;">
                <h3 style="margin: 0 0 16px;">⏰ Upcoming Events ({{ $upcoming->count() }})</h3>
                @forelse($upcoming as $event)
                <div style="background: var(--bg-secondary); padding: 12px; border-radius: 8px; margin-bottom: 8px;">
                    <div style="font-weight: 600; font-size: 14px;">{{ $event->name }}</div>
                    <div style="font-size: 12px; color: var(--text-muted);">Starts: {{ $event->starts_at->diffForHumans() }} | {{ $event->description }}</div>
                </div>
                @empty
                <p style="color: var(--text-muted); font-size: 13px;">No upcoming events scheduled</p>
                @endforelse

                <div style="margin-top: 24px; background: var(--bg-secondary); padding: 12px; border-radius: 8px; font-size: 13px;">
                    <h4 style="margin: 0 0 8px;">Gameberry Event Features</h4>
                    <ul style="margin: 0; padding-left: 18px; color: var(--text-muted);">
                        <li>✅ Weekly special events - evolving</li>
                        <li>✅ Engaging & social rewards</li>
                        <li>✅ Gold & gems rewards</li>
                        <li>✅ Leaderboard competition</li>
                        <li>✅ Progress tracking</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
