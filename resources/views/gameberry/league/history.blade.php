@extends('layouts.app')

@section('title', 'League History & Titan Badges')

@section('content')
<div class="container" style="max-width: 1000px; margin: 0 auto; padding: 24px;">
    <h1 style="font-size: 28px; font-weight: 800; margin: 0 0 8px;">📜 League History & Titan Badges</h1>
    <p style="color: var(--text-muted); margin: 0 0 24px;">Season history, promotions, demotions, Titan badges weekly</p>

    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px;">
        <div class="card">
            <div style="padding: 20px;">
                <h3>Season History ({{ $history->count() }})</h3>
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>Season</th><th>League</th><th>Trophies</th><th>Rank</th><th>Result</th></tr></thead>
                        <tbody>
                            @forelse($history as $h)
                            <tr>
                                <td>{{ $h->season }}</td>
                                <td><span style="background: {{ $h->league->color_code ?? '#6c5ce7' }}; color: white; padding: 2px 8px; border-radius: 999px; font-size: 11px;">{{ $h->league->name }}</span></td>
                                <td>{{ $h->trophies }}</td>
                                <td>#{{ $h->rank }}</td>
                                <td>
                                    @if($h->was_promoted) <span style="background: #27ae60; color: white; padding: 2px 6px; border-radius: 999px; font-size: 10px;">⬆️ Promoted to {{ $h->promotionLeague->name ?? 'Next' }}</span>
                                    @elseif($h->was_demoted) <span style="background: #e74c3c; color: white; padding: 2px 6px; border-radius: 999px; font-size: 10px;">⬇️ Demoted to {{ $h->demotionLeague->name ?? 'Prev' }}</span>
                                    @else <span style="background: var(--bg-secondary); padding: 2px 6px; border-radius: 999px; font-size: 10px;">Stayed</span> @endif
                                </td>
                            </tr>
                            @empty
                            <tr><td colspan="5" style="text-align: center; color: var(--text-muted);">No history yet - Play seasons to build history</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card">
            <div style="padding: 20px;">
                <h3>Titan Badges ({{ $titanBadges->count() }}) 👑</h3>
                @forelse($titanBadges as $badge)
                <div style="background: linear-gradient(135deg, #FFD700, #FFA500); color: black; padding: 12px; border-radius: 12px; margin-bottom: 8px; text-align: center;">
                    <div style="font-size: 24px;">👑</div>
                    <div style="font-weight: 800; font-size: 14px;">Titan Badge</div>
                    <div style="font-size: 11px;">Week {{ $badge->week }}, {{ $badge->year }} - Rank #{{ $badge->rank }} - Season {{ $badge->season }}</div>
                    <div style="font-size: 10px; margin-top: 4px; opacity: 0.8;">{{ $badge->badge_type }}</div>
                </div>
                @empty
                <p style="font-size: 13px; color: var(--text-muted);">No Titan badges yet - Reach Titan league and stay top to earn weekly Titan badges</p>
                <div style="margin-top: 12px; background: var(--bg-secondary); padding: 8px; border-radius: 8px; font-size: 12px;">
                    <strong>How to get Titan Badge:</strong><br>
                    • Reach Titan league (Level 12, 5000+ trophies)<br>
                    • Stay in top 20% each week<br>
                    • Badge awarded weekly<br>
                    • Top 40 rule: Top 40 stay in Titan
                </div>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection
