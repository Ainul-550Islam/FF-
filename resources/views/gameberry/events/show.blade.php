@extends('layouts.app')

@section('title', $event->name . ' - Weekly Event')

@section('content')
<div class="container" style="max-width: 800px; margin: 0 auto; padding: 24px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
        <div>
            <h1 style="font-size: 28px; font-weight: 800; margin: 0;">{{ $event->name }}</h1>
            <p style="color: var(--text-muted); margin: 4px 0 0;">{{ $event->description }} | {{ $event->type }} | Ends {{ $event->ends_at->diffForHumans() }}</p>
        </div>
        <a href="{{ route('gameberry.events.index') }}" class="btn btn-ghost">Back</a>
    </div>

    <div class="card" style="margin-bottom: 20px;">
        <div style="padding: 20px;">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 16px; text-align: center;">
                <div style="background: var(--bg-secondary); padding: 12px; border-radius: 8px;">
                    <div style="font-size: 20px; font-weight: 800;">{{ $event->target_progress }}</div>
                    <div style="font-size: 11px; color: var(--text-muted);">Target Progress</div>
                </div>
                <div style="background: var(--bg-secondary); padding: 12px; border-radius: 8px;">
                    <div style="font-size: 20px; font-weight: 800;">{{ $event->participants()->count() }}/{{ $event->max_participants ?: '∞' }}</div>
                    <div style="font-size: 11px; color: var(--text-muted);">Participants</div>
                </div>
                <div style="background: var(--bg-secondary); padding: 12px; border-radius: 8px;">
                    <div style="font-size: 14px; font-weight: 700;">{{ json_encode($event->rewards) }}</div>
                    <div style="font-size: 11px; color: var(--text-muted);">Rewards</div>
                </div>
            </div>

            @if($userParticipant)
            <div style="margin-top: 16px;">
                <div style="display: flex; justify-content: space-between; font-size: 13px; margin-bottom: 4px;">
                    <span>Your Progress: {{ $userParticipant->progress }}/{{ $event->target_progress }}</span>
                    <span>{{ $event->target_progress ? round(($userParticipant->progress/$event->target_progress)*100,1) : 0 }}%</span>
                </div>
                <div style="background: var(--bg-tertiary); height: 12px; border-radius: 999px; overflow: hidden;">
                    <div style="height: 100%; background: var(--primary); width: {{ $event->target_progress ? min(100, ($userParticipant->progress/$event->target_progress)*100) : 0 }}%;"></div>
                </div>
                <div style="margin-top: 8px; font-size: 12px;">
                    @if($userParticipant->is_completed) ✅ Completed! @if(empty($userParticipant->rewards_claimed))
                    <form method="POST" action="{{ route('gameberry.events.claim', $event->id) }}" style="display: inline;">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-primary">Claim Reward</button>
                    </form>
                    @else Rewards claimed: {{ json_encode($userParticipant->rewards_claimed) }} @endif
                    @else ⏳ In progress @endif
                </div>
            </div>
            @else
            <div style="margin-top: 16px; text-align: center;">
                <form method="POST" action="{{ route('gameberry.events.join', $event->id) }}">
                    @csrf
                    <button type="submit" class="btn btn-primary">Join Weekly Special Event 🎉</button>
                </form>
            </div>
            @endif
        </div>
    </div>

    <div class="card">
        <div style="padding: 20px;">
            <h3>Leaderboard - Top 100</h3>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Rank</th><th>Player</th><th>Progress</th><th>Completed</th></tr></thead>
                    <tbody>
                        @forelse($leaderboard as $index => $p)
                        <tr style="{{ $p->user_id === auth()->id() ? 'background: var(--bg-secondary); font-weight: 700;' : '' }}">
                            <td>#{{ $index+1 }}</td>
                            <td>{{ $p->user->name ?? 'User '.$p->user_id }} @if($p->user_id === auth()->id()) (You) @endif</td>
                            <td>{{ $p->progress }}/{{ $event->target_progress }}</td>
                            <td>{{ $p->is_completed ? '✅' : '⏳' }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="4" style="text-align: center; color: var(--text-muted);">No participants yet - Be first to join weekly special event!</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <a href="{{ route('gameberry.events.leaderboard', $event->id) }}" class="btn btn-sm btn-ghost" style="margin-top: 12px;">Full Leaderboard</a>
        </div>
    </div>
</div>
@endsection
