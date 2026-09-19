@extends('layouts.app')

@section('title', 'League Season - Top 20% Promotion Top 40 Demotion')

@section('content')
<div class="container" style="max-width: 1000px; margin: 0 auto; padding: 24px;">
    <h1 style="font-size: 28px; font-weight: 800; margin: 0 0 8px;">📅 League Season {{ $season ?? 'Current' }}</h1>
    <p style="color: var(--text-muted); margin: 0 0 24px;">Season progression - Top 20% promotion - Bottom 40% demotion - Titan Top 40 stay rule - Titan badges weekly</p>

    <div class="card" style="padding: 20px; margin-bottom: 20px;">
        <h3>Season Rules (Gameberry FAQ)</h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 16px; margin-top: 12px; font-size: 13px;">
            <div style="background: var(--bg-secondary); padding: 12px; border-radius: 8px;">
                <strong>✅ Promotion:</strong> Top 20% players promoted to next league each season end. Example: 100 players → top 20 promoted.
            </div>
            <div style="background: var(--bg-secondary); padding: 12px; border-radius: 8px;">
                <strong>✅ Demotion:</strong> Bottom 40% demoted to previous league. Example: 100 players → bottom 40 demoted.
            </div>
            <div style="background: var(--bg-secondary); padding: 12px; border-radius: 8px;">
                <strong>✅ Titan Rule:</strong> In Titan league, Top 40 stay (special). Bottom demoted, top stays with Titan badges weekly.
            </div>
            <div style="background: var(--bg-secondary); padding: 12px; border-radius: 8px;">
                <strong>✅ Titan Badges:</strong> Awarded weekly for staying in Titan league - Week/Year/Rank badge.
            </div>
            <div style="background: var(--bg-secondary); padding: 12px; border-radius: 8px;">
                <strong>✅ Level Gate:</strong> Level 4 to reach Bronze League. Level 6 Gold, Level 8 Platinum, Level 10 Diamond, Level 12 Titan.
            </div>
            <div style="background: var(--bg-secondary); padding: 12px; border-radius: 8px;">
                <strong>✅ Season:</strong> Season = YearWeek YW format (e.g., 202642). Current: {{ $currentSeason ?? date('YW') }}. Season end processes promotion/demotion.
            </div>
        </div>
    </div>

    <div class="card">
        <div style="padding: 20px;">
            <h3>Season History</h3>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Season</th><th>League</th><th>Trophies</th><th>Rank</th><th>Promoted</th><th>Demoted</th><th>Rewards</th></tr></thead>
                    <tbody>
                        @forelse($history ?? [] as $h)
                        <tr>
                            <td>{{ $h->season }}</td>
                            <td>{{ $h->league->name ?? 'League' }}</td>
                            <td>{{ $h->trophies }}</td>
                            <td>#{{ $h->rank }}</td>
                            <td>{{ $h->was_promoted ? '⬆️ Yes to '.($h->promotionLeague->name ?? 'Next') : 'No' }}</td>
                            <td>{{ $h->was_demoted ? '⬇️ Yes to '.($h->demotionLeague->name ?? 'Prev') : 'No' }}</td>
                            <td style="font-size: 11px;">{{ json_encode($h->rewards) }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="7" style="text-align: center; color: var(--text-muted);">No season history yet - Play to build history</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
