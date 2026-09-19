@extends('layouts.app')

@section('title', 'Spin2Win - Free Spins - Gold Gems Dice')

@section('content')
<div class="container" style="max-width: 800px; margin: 0 auto; padding: 24px; text-align: center;">
    <h1 style="font-size: 28px; font-weight: 800; margin: 0 0 8px;">🎡 Spin2Win</h1>
    <p style="color: var(--text-muted); margin: 0 0 24px;">Daily free spin + 100 gold per spin - Win gold, gems, dice, jackpot</p>

    <div class="card" style="padding: 24px; margin-bottom: 24px;">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 16px; margin-bottom: 24px;">
            <div style="background: var(--bg-secondary); padding: 16px; border-radius: 12px;">
                <div style="font-size: 24px; font-weight: 800;">{{ $stats['total_spins'] }}</div>
                <div style="font-size: 12px; color: var(--text-muted);">Total Spins</div>
            </div>
            <div style="background: var(--bg-secondary); padding: 16px; border-radius: 12px;">
                <div style="font-size: 24px; font-weight: 800;">{{ $stats['today_spins'] }}/{{ 10 }}</div>
                <div style="font-size: 12px; color: var(--text-muted);">Today</div>
            </div>
            <div style="background: var(--bg-secondary); padding: 16px; border-radius: 12px;">
                <div style="font-size: 24px; font-weight: 800;">{{ $stats['free_spins_remaining'] }}</div>
                <div style="font-size: 12px; color: var(--text-muted);">Free Spins Left</div>
            </div>
            <div style="background: var(--bg-secondary); padding: 16px; border-radius: 12px;">
                <div style="font-size: 24px; font-weight: 800;">{{ $stats['jackpots'] }}</div>
                <div style="font-size: 12px; color: var(--text-muted);">Jackpots</div>
            </div>
        </div>

        <div style="font-size: 120px; margin: 20px 0;">🎡</div>

        <div style="display: flex; gap: 12px; justify-content: center; flex-wrap: wrap;">
            <form method="POST" action="{{ route('gameberry.spin.spin') }}">
                @csrf
                <input type="hidden" name="use_free" value="1">
                <button type="submit" class="btn btn-primary" style="padding: 12px 32px; font-size: 18px;" {{ $stats['free_spins_remaining'] <= 0 ? 'disabled' : '' }}>
                    🎁 Free Spin ({{ $stats['free_spins_remaining'] }} left)
                </button>
            </form>
            <form method="POST" action="{{ route('gameberry.spin.spin') }}">
                @csrf
                <input type="hidden" name="use_free" value="0">
                <button type="submit" class="btn btn-secondary" style="padding: 12px 32px; font-size: 18px;" {{ !$stats['can_spin'] ? 'disabled' : '' }}>
                    Spin 100 Gold 🪙
                </button>
            </form>
        </div>

        <div style="margin-top: 16px; font-size: 13px; color: var(--text-muted);">
            <div>Total Gold Won: {{ $stats['total_gold_won'] }} | Total Gems Won: {{ $stats['total_gems_won'] }}</div>
            <div>Spin Cost: {{ $stats['spin_cost'] }} gold | Daily Limit: 10 spins | Free: 1 daily</div>
        </div>
    </div>

    <div class="card" style="text-align: left;">
        <div style="padding: 20px;">
            <h3>Recent Spins</h3>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Result</th><th>Gold</th><th>Gems</th><th>Cost</th><th>Time</th></tr></thead>
                    <tbody>
                        @forelse($history as $spin)
                        <tr>
                            <td><span class="badge" style="background: {{ $spin->result === 'jackpot' ? 'gold' : 'var(--bg-secondary)' }}; color: {{ $spin->result === 'jackpot' ? 'black' : 'inherit' }};">{{ $spin->result }}</span></td>
                            <td>{{ $spin->gold_amount }}</td>
                            <td>{{ $spin->gem_amount }}</td>
                            <td>{{ $spin->gold_cost }}</td>
                            <td style="font-size: 12px;">{{ $spin->spun_at->diffForHumans() }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="5" style="text-align: center; color: var(--text-muted);">No spins yet</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
