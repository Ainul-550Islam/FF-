@extends('layouts.app')

@section('title', 'League Leaderboard - Top 100 - Top 20% Promotion')

@section('content')
<div class="container" style="max-width: 1000px; margin: 0 auto; padding: 24px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
        <div>
            <h1 style="font-size: 28px; font-weight: 800; margin: 0;">🏆 {{ $league->name ?? 'League' }} Leaderboard</h1>
            <p style="color: var(--text-muted); margin: 4px 0 0;">Top 100 - Top 20% promotion - Bottom 40% demotion - Titan badges - Level 4 Bronze unlock</p>
        </div>
        <a href="{{ route('gameberry.league.index') }}" class="btn btn-ghost">Back to Leagues</a>
    </div>

    <div class="card">
        <div style="padding: 20px;">
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Rank</th><th>Player</th><th>Trophies</th><th>W/L</th><th>Win Rate</th><th>Games</th><th>Status</th></tr></thead>
                    <tbody>
                        @forelse($leaderboard as $index => $entry)
                        <tr style="{{ $entry->user_id === auth()->id() ? 'background: var(--bg-secondary); font-weight: 700;' : '' }}">
                            <td>#{{ $index+1 }} @if($index < 3) {{ ['🥇','🥈','🥉'][$index] }} @endif</td>
                            <td>{{ $entry->user->name ?? 'User '.$entry->user_id }} @if($entry->user_id === auth()->id()) (You) @endif</td>
                            <td>{{ $entry->trophies }} 🏆</td>
                            <td>{{ $entry->wins }}W / {{ $entry->losses }}L</td>
                            <td>{{ $entry->winRate() }}%</td>
                            <td>{{ $entry->games_played }}</td>
                            <td>
                                @if($index < ceil($leaderboard->count()*0.2))
                                <span style="background: #27ae60; color: white; padding: 2px 6px; border-radius: 999px; font-size: 10px;">⬆️ Top 20% Promoted to {{ $league->nextLeague->name ?? 'Next' }}</span>
                                @elseif($index >= $leaderboard->count() - ceil($leaderboard->count()*0.4))
                                <span style="background: #e74c3c; color: white; padding: 2px 6px; border-radius: 999px; font-size: 10px;">⬇️ Bottom 40% Demoted</span>
                                @else
                                <span style="background: var(--bg-secondary); padding: 2px 6px; border-radius: 999px; font-size: 10px;">Stays</span>
                                @endif
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="7" style="text-align: center; color: var(--text-muted);">No players in leaderboard - Be first! Level 4 required for Bronze</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
