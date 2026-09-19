@extends('layouts.app')

@section('title', '6-Step League - Bronze to Titan - Top 20% Promotion')

@section('content')
<div class="container" style="max-width: 1280px; margin: 0 auto; padding: 24px;">
    <div style="margin-bottom: 24px;">
        <h1 style="font-size: 28px; font-weight: 800; margin: 0;">🏆 6-Step League System</h1>
        <p style="color: var(--text-muted); margin: 4px 0 0;">Bronze → Silver → Gold → Platinum → Diamond → Titan | Top 20% Promotion | Top 40 Demotion | Titan Badges | Level 4 Bronze Unlock</p>
    </div>

    @if($userLeague)
    <div class="card" style="margin-bottom: 24px; border: 2px solid {{ $userLeague->league->color_code ?? 'var(--primary)' }};">
        <div style="padding: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
            <div style="display: flex; align-items: center; gap: 16px;">
                <div style="width: 64px; height: 64px; border-radius: 50%; background: {{ $userLeague->league->color_code ?? '#6c5ce7' }}; display: flex; align-items: center; justify-content: center; font-size: 28px; font-weight: 800; color: white;">{{ substr($userLeague->league->name, 0, 1) }}</div>
                <div>
                    <h2 style="margin: 0; font-size: 22px;">{{ $userLeague->league->name }} League</h2>
                    <div style="color: var(--text-muted);">Level {{ $levelNumber }} | Trophies: {{ $userLeague->trophies }} | Rank: #{{ $userLeague->rank ?? 'Unranked' }}</div>
                    <div style="font-size: 12px; margin-top: 4px;">Wins: {{ $userLeague->wins }} | Losses: {{ $userLeague->losses }} | Win Rate: {{ $userLeague->winRate() }}%</div>
                </div>
            </div>
            <div style="text-align: right;">
                <div style="font-size: 13px; color: var(--text-muted);">Season {{ $currentSeason }}</div>
                <div style="font-size: 12px; color: var(--text-muted);">Top 20% promoted, Bottom 40% demoted</div>
                <a href="{{ route('gameberry.league.history') }}" class="btn btn-sm btn-secondary" style="margin-top: 8px;">History & Titan Badges</a>
            </div>
        </div>
    </div>
    @else
    <div class="alert alert-warning">You haven't started league yet - Play games to enter Bronze (Level 4 required)</div>
    @endif

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 24px;">
        @foreach($leagues as $league)
        <a href="{{ route('gameberry.league.show', $league->slug) }}" style="text-decoration: none; color: inherit;">
            <div class="card" style="text-align: center; padding: 16px; border-left: 4px solid {{ $league->color_code ?? '#6c5ce7' }}; transition: transform 0.2s;">
                <div style="width: 48px; height: 48px; border-radius: 50%; background: {{ $league->color_code ?? '#6c5ce7' }}; margin: 0 auto 8px; display: flex; align-items: center; justify-content: center; font-weight: 800; color: white;">{{ $league->level }}</div>
                <h3 style="margin: 0; font-size: 16px;">{{ $league->name }}</h3>
                <div style="font-size: 12px; color: var(--text-muted);">Level {{ $league->level }}</div>
                <div style="font-size: 11px; color: var(--text-muted); margin-top: 4px;">{{ $league->min_trophies }} - {{ $league->max_trophies ?? '∞' }} 🏆</div>
                @if($league->slug === 'titan')<div style="font-size: 10px; background: gold; color: black; padding: 2px 6px; border-radius: 999px; margin-top: 6px; display: inline-block;">Titan Badges</div>@endif
            </div>
        </a>
        @endforeach
    </div>

    <div class="card">
        <div style="padding: 20px;">
            <h3>League Progression Rules (Gameberry FAQ)</h3>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>League</th><th>Level</th><th>Trophies</th><th>Next</th><th>Prev</th><th>Access</th></tr></thead>
                    <tbody>
                        @foreach($progression as $p)
                        <tr>
                            <td><strong>{{ $p['name'] }}</strong></td>
                            <td>{{ $p['level'] }}</td>
                            <td>{{ $p['min_trophies'] }} - {{ $p['max_trophies'] ?? '∞' }}</td>
                            <td>{{ $p['next'] ?? '-' }}</td>
                            <td>{{ $p['prev'] ?? '-' }}</td>
                            <td>Level {{ $p['slug'] === 'bronze' ? 4 : ($p['slug'] === 'gold' ? 6 : ($p['slug'] === 'titan' ? 12 : 4)) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div style="margin-top: 16px; font-size: 13px; color: var(--text-muted);">
                <p>✅ <strong>Promotion:</strong> Top 20% players promoted to next league each season</p>
                <p>✅ <strong>Demotion:</strong> Bottom 40% demoted to previous league (Top 40 stay in Titan)</p>
                <p>✅ <strong>Titan Badges:</strong> Awarded weekly for staying in Titan league</p>
                <p>✅ <strong>Level Gate:</strong> Level 4 to reach Bronze League (Gameberry FAQ)</p>
            </div>
        </div>
    </div>
</div>
@endsection
