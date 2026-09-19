@extends('layouts.app')

@section('title', 'Gold & Gem Economy - Magic Chest - Video Ads - Spin2Win')

@section('content')
<div class="container" style="max-width: 1280px; margin: 0 auto; padding: 24px;">
    <h1 style="font-size: 28px; font-weight: 800; margin: 0 0 8px;">💰 Gold & Gem Economy</h1>
    <p style="color: var(--text-muted); margin: 0 0 24px;">Gold at stake, Magic Chest, Video Ads Free Gold, Gems, Lucky Dice Gem Reward, Spin2Win, Reconciliation</p>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-bottom: 24px;">
        <div class="card" style="border: 2px solid #f1c40f;">
            <div style="padding: 20px; text-align: center;">
                <div style="font-size: 48px;">🪙</div>
                <h2 style="margin: 8px 0;">{{ number_format($goldWallet->gold_balance) }} Gold</h2>
                <div style="font-size: 13px; color: var(--text-muted);">Total Earned: {{ number_format($goldStats['total_earned']) }} | Spent: {{ number_format($goldStats['total_spent']) }}</div>
                <div style="font-size: 13px; color: var(--text-muted);">Won: {{ number_format($goldStats['total_won']) }} | Lost: {{ number_format($goldStats['total_lost']) }} | Win Rate: {{ $goldStats['win_rate'] }}%</div>
                <div style="margin-top: 12px; font-size: 12px; background: {{ $goldReconcile['is_balanced'] ? '#27ae60' : '#e74c3c' }}; color: white; padding: 4px 8px; border-radius: 999px; display: inline-block;">
                    {{ $goldReconcile['is_balanced'] ? '✅ Reconciled' : '❌ Mismatch '.$goldReconcile['difference'] }}
                </div>
                <div style="margin-top: 12px;">
                    <a href="{{ route('gameberry.economy.gold_history') }}" class="btn btn-sm btn-secondary">Gold History</a>
                </div>
            </div>
        </div>

        <div class="card" style="border: 2px solid #9b59b6;">
            <div style="padding: 20px; text-align: center;">
                <div style="font-size: 48px;">💎</div>
                <h2 style="margin: 8px 0;">{{ number_format($gemWallet->gem_balance) }} Gems</h2>
                <div style="font-size: 13px; color: var(--text-muted);">Earned: {{ number_format($gemStats['total_earned']) }} | Spent: {{ number_format($gemStats['total_spent']) }} | Purchased: {{ number_format($gemStats['total_purchased']) }}</div>
                <div style="margin-top: 12px; font-size: 12px; background: {{ $gemReconcile['is_balanced'] ? '#27ae60' : '#e74c3c' }}; color: white; padding: 4px 8px; border-radius: 999px; display: inline-block;">
                    {{ $gemReconcile['is_balanced'] ? '✅ Reconciled' : '❌ Mismatch '.$gemReconcile['difference'] }}
                </div>
                <div style="margin-top: 12px;">
                    <a href="{{ route('gameberry.economy.gem_history') }}" class="btn btn-sm btn-secondary">Gem History</a>
                </div>
            </div>
        </div>

        <div class="card">
            <div style="padding: 20px;">
                <h3 style="margin: 0 0 12px;">📺 Video Ads Free Gold</h3>
                <div style="font-size: 13px;">
                    <div>Today: {{ $videoStats['today_count'] }}/{{ $videoStats['daily_limit'] }} | Remaining: {{ $videoStats['remaining_today'] }}</div>
                    <div>Can Watch: {{ $videoStats['can_watch'] ? 'Yes' : 'No - Cooldown '.$videoStats['cooldown_remaining_minutes'].'m' }}</div>
                    <div>Total Earned: {{ $videoStats['total_gold_earned'] }} gold, {{ $videoStats['total_gems_earned'] }} gems</div>
                    <div>Reward: {{ $videoStats['gold_per_ad'] }} gold + {{ $videoStats['gem_per_ad'] }} gem per ad</div>
                </div>
                <div style="margin-top: 12px;">
                    <form method="POST" action="{{ route('gameberry.economy.watch_ad') }}">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-primary" {{ !$videoStats['can_watch'] ? 'disabled' : '' }}>Watch Ad +100 Gold</button>
                    </form>
                </div>
                <a href="{{ route('gameberry.economy.video_ads') }}" style="font-size: 12px; margin-top: 8px; display: inline-block;">View History</a>
            </div>
        </div>
    </div>

    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
        <div class="card">
            <div style="padding: 20px;">
                <h3>🎁 Magic Chests - {{ $availableChests->count() }} Available</h3>
                @forelse($availableChests as $chest)
                <div style="background: var(--bg-secondary); padding: 12px; border-radius: 8px; margin-bottom: 8px; display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <div style="font-weight: 600;">{{ ucfirst($chest->type) }} Chest</div>
                        <div style="font-size: 12px; color: var(--text-muted);">{{ $chest->gold_reward }} gold, {{ $chest->gem_reward }} gems @if(!empty($chest->dice_rewards))+{{ count($chest->dice_rewards) }} dice @endif</div>
                    </div>
                    <form method="POST" action="{{ route('gameberry.economy.open_chest', $chest->id) }}">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-primary">Open</button>
                    </form>
                </div>
                @empty
                <p style="color: var(--text-muted); font-size: 13px;">No available chests - Win games to get magic chest (Bronze/Silver/Gold/Magic)</p>
                @endforelse
                <a href="{{ route('gameberry.economy.magic_chests') }}" class="btn btn-sm btn-ghost" style="margin-top: 12px;">View All Chests</a>
            </div>
        </div>

        <div class="card">
            <div style="padding: 20px;">
                <h3>Gameberry Economy Features</h3>
                <ul style="font-size: 13px; color: var(--text-muted); margin: 0; padding-left: 18px; line-height: 1.8;">
                    <li>✅ Gold at stake - win opponent gold</li>
                    <li>✅ Magic chest - random gold/gems/dice rewards</li>
                    <li>✅ Video ads - free gold 100 per ad, 5 daily limit</li>
                    <li>✅ Gems - premium currency</li>
                    <li>✅ Lucky dice gem reward - roll patterns for gems</li>
                    <li>✅ Spin2Win - 100 gold spin for rewards</li>
                    <li>✅ Gold wallets & Gem wallets transactions</li>
                    <li>✅ Reconciliation - financial totals must reconcile</li>
                    <li>✅ Daily bonus, referral bonus, scratch cards</li>
                </ul>
            </div>
        </div>
    </div>
</div>
@endsection
