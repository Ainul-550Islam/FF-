@extends('layouts.app')

@section('title', 'Video Ads - Free Gold - 5 Daily Limit')

@section('content')
<div class="container" style="max-width: 800px; margin: 0 auto; padding: 24px;">
    <h1 style="font-size: 28px; font-weight: 800; margin: 0 0 8px;">📺 Video Ads - Free Gold</h1>
    <p style="color: var(--text-muted); margin: 0 0 24px;">Watch video ads for free gold 100 + 1 gem - Daily limit 5 - 30 min cooldown - Gameberry FAQ</p>

    <div class="card" style="padding: 24px; text-align: center; margin-bottom: 24px;">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 16px; margin-bottom: 20px;">
            <div style="background: var(--bg-secondary); padding: 16px; border-radius: 12px;">
                <div style="font-size: 24px; font-weight: 800;">{{ $stats['today_count'] }}/{{ $stats['daily_limit'] }}</div>
                <div style="font-size: 12px; color: var(--text-muted);">Today Watched</div>
            </div>
            <div style="background: var(--bg-secondary); padding: 16px; border-radius: 12px;">
                <div style="font-size: 24px; font-weight: 800;">{{ $stats['remaining_today'] }}</div>
                <div style="font-size: 12px; color: var(--text-muted);">Remaining Today</div>
            </div>
            <div style="background: var(--bg-secondary); padding: 16px; border-radius: 12px;">
                <div style="font-size: 24px; font-weight: 800;">{{ $stats['total_watched'] }}</div>
                <div style="font-size: 12px; color: var(--text-muted);">Total Watched</div>
            </div>
            <div style="background: var(--bg-secondary); padding: 16px; border-radius: 12px;">
                <div style="font-size: 24px; font-weight: 800;">{{ $stats['total_gold_earned'] }}</div>
                <div style="font-size: 12px; color: var(--text-muted);">Gold Earned</div>
            </div>
        </div>

        <div style="font-size: 64px; margin: 16px 0;">📺</div>

        @if($stats['can_watch'])
        <form method="POST" action="{{ route('gameberry.economy.watch_ad') }}">
            @csrf
            <input type="hidden" name="provider" value="admob">
            <button type="submit" class="btn btn-primary" style="padding: 12px 32px; font-size: 18px;">Watch Ad - Get {{ $stats['gold_per_ad'] }} Gold + {{ $stats['gem_per_ad'] }} Gem 🎁</button>
        </form>
        @else
        <div style="background: var(--bg-secondary); padding: 12px; border-radius: 8px; font-size: 13px;">
            @if($stats['today_count'] >= $stats['daily_limit'])
            Daily limit reached ({{ $stats['daily_limit'] }}) - Come back tomorrow!
            @else
            Cooldown active - Wait {{ $stats['cooldown_remaining_minutes'] }} minutes
            @endif
        </div>
        @endif

        <div style="margin-top: 16px; font-size: 12px; color: var(--text-muted);">
            Reward: {{ $stats['gold_per_ad'] }} gold + {{ $stats['gem_per_ad'] }} gem per ad | Daily limit: {{ $stats['daily_limit'] }} | Cooldown: 30 minutes | Total gems: {{ $stats['total_gems_earned'] }}
        </div>
    </div>

    <div class="card">
        <div style="padding: 20px;">
            <h3>Video Ad History ({{ $history->count() }})</h3>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Provider</th><th>Gold</th><th>Gems</th><th>Status</th><th>Time</th></tr></thead>
                    <tbody>
                        @forelse($history as $ad)
                        <tr>
                            <td>{{ $ad->ad_provider }}</td>
                            <td>{{ $ad->gold_reward }}</td>
                            <td>{{ $ad->gem_reward }}</td>
                            <td><span class="badge" style="background: {{ $ad->status === 'rewarded' ? '#27ae60' : '#f39c12' }}; color: white;">{{ $ad->status }}</span></td>
                            <td style="font-size: 11px;">{{ $ad->created_at->diffForHumans() }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="5" style="text-align: center; color: var(--text-muted);">No ads watched yet - Watch for free gold like LudoStar</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
