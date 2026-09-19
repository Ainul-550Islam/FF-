@extends('layouts.app')

@section('title', $event->name . ' Leaderboard')

@section('content')
<div class="container" style="max-width: 800px; margin: 0 auto; padding: 24px;">
    <h1 style="font-size: 24px; font-weight: 800; margin: 0 0 8px;">{{ $event->name }} - Leaderboard</h1>
    <p style="color: var(--text-muted); margin: 0 0 24px;">Weekly special event - {{ $event->description }} - Rewards: {{ json_encode($event->rewards) }}</p>

    <div class="card">
        <div style="padding: 20px;">
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Rank</th><th>Player</th><th>Progress</th><th>Completed</th><th>Rewards</th></tr></thead>
                    <tbody>
                        @foreach($leaderboard as $index => $p)
                        <tr>
                            <td>#{{ $index+1 }} @if($index < 3) {{ ['🥇','🥈','🥉'][$index] }} @endif</td>
                            <td>{{ $p->user->name ?? 'User '.$p->user_id }}</td>
                            <td>{{ $p->progress }}/{{ $event->target_progress }} ({{ $event->target_progress ? round($p->progress/$event->target_progress*100,1) : 0 }}%)</td>
                            <td>{{ $p->is_completed ? '✅ Yes' : '⏳ No' }}</td>
                            <td style="font-size: 11px;">{{ $p->rewards_claimed ? json_encode($p->rewards_claimed) : '-' }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div style="margin-top: 16px; text-align: center;">
        <a href="{{ route('gameberry.events.show', $event->id) }}" class="btn btn-secondary">Back to Event</a>
        <a href="{{ route('gameberry.events.index') }}" class="btn btn-ghost">All Events</a>
    </div>
</div>
@endsection
