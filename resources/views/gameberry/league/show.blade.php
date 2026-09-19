@extends('layouts.app')

@section('title', $league->name . ' League - Leaderboard')

@section('content')
<div class="container" style="max-width: 1000px; margin: 0 auto; padding: 24px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
        <div style="display: flex; align-items: center; gap: 16px;">
            <div style="width: 56px; height: 56px; border-radius: 50%; background: {{ $league->color_code ?? '#6c5ce7' }}; display: flex; align-items: center; justify-content: center; font-size: 24px; font-weight: 800; color: white;">{{ $league->level }}</div>
            <div>
                <h1 style="font-size: 24px; font-weight: 800; margin: 0;">{{ $league->name }} League</h1>
                <p style="color: var(--text-muted); margin: 4px 0 0; font-size: 13px;">{{ $league->description }} | Level {{ $levelNumber }} | Top 20% promotion Top 40 demotion</p>
            </div>
        </div>
        <a href="{{ route('gameberry.league.index') }}" class="btn btn-ghost">Back to Leagues</a>
    </div>

    @if($userLeague && $userLeague->league_id === $league->id)
    <div class="card" style="margin-bottom: 20px; padding: 16px; background: var(--bg-secondary);">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <div>Your Rank: <strong>#{{ $userLeague->rank ?? 'Unranked' }}</strong> | Trophies: <strong>{{ $userLeague->trophies }}</strong> | Win Rate: {{ $userLeague->winRate() }}%</div>
            <div style="font-size: 12px; color: var(--text-muted);">{{ $userLeague->isInTopPercent() ? '🏆 Top 20% - Promotion zone!' : 'Keep playing to reach top 20%' }}</div>
        </div>
    </div>
    @endif

    <div class="card">
        <div style="padding: 20px;">
            <h3 style="margin: 0 0 16px;">Leaderboard - Top 100 ({{ $leaderboard->count() }} players)</h3>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Rank</th><th>Player</th><th>Trophies</th><th>W/L</th><th>Win Rate</th><th>Top 20%</th></tr></thead>
                    <tbody>
                        @forelse($leaderboard as $index => $entry)
                        <tr style="{{ $entry->user_id === auth()->id() ? 'background: var(--bg-secondary); font-weight: 700;' : '' }}">
                            <td>#{{ $index + 1 }} @if($index < 3) {{ ['🥇','🥈','🥉'][$index] }} @endif</td>
                            <td>{{ $entry->user->name ?? 'User '.$entry->user_id }} @if($entry->user_id === auth()->id()) (You) @endif</td>
                            <td>{{ $entry->trophies }} 🏆</td>
                            <td>{{ $entry->wins }}W / {{ $entry->losses }}L</td>
                            <td>{{ $entry->winRate() }}%</td>
                            <td>@if($index < ceil($leaderboard->count()*0.2)) <span style="background: #27ae60; color: white; padding: 2px 6px; border-radius: 999px; font-size: 10px;">Top 20% Promoted</span> @elseif($index >= $leaderboard->count() - ceil($leaderboard->count()*0.4)) <span style="background: #e74c3c; color: white; padding: 2px 6px; border-radius: 999px; font-size: 10px;">Bottom 40% Demoted</span> @else <span style="font-size: 10px; color: var(--text-muted);">Stays</span> @endif</td>
                        </tr>
                        @empty
                        <tr><td colspan="6" style="text-align: center; color: var(--text-muted);">No players in this league yet - Be the first! Level 4 required for Bronze</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
