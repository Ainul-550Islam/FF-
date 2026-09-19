@extends('layouts.app')

@section('title', 'Lucky Dice - Gem Rewards - 52 Max')

@section('content')
<div class="container" style="max-width: 1000px; margin: 0 auto; padding: 24px;">
    <h1 style="font-size: 28px; font-weight: 800; margin: 0 0 8px;">🍀 Lucky Dice - Gem Rewards</h1>
    <p style="color: var(--text-muted); margin: 0 0 24px;">Lucky dice patterns - three_same 10 gems, three_different 5 gems, sequence 15 gems - Max 52 - Facebook exchange</p>

    <div class="card" style="margin-bottom: 24px; padding: 20px;">
        <h3>Unrolled Lucky Dice ({{ $unrolled->count() }}) - Roll for Gems</h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px; margin-top: 12px;">
            @forelse($unrolled as $ld)
            <div style="background: linear-gradient(135deg, #f1c40f, #f39c12); color: black; padding: 16px; border-radius: 12px; text-align: center;">
                <div style="font-size: 32px;">🎲</div>
                <div style="font-weight: 700; font-size: 13px;">{{ $ld->dice->name ?? 'Dice #'.$ld->dice_id }}</div>
                <div style="font-size: 11px; opacity: 0.8;">From: {{ $ld->sender->name ?? 'System' }} @if($ld->sender_id) (Facebook) @endif</div>
                <div style="font-size: 10px; margin-top: 4px;">Received {{ $ld->created_at->diffForHumans() }}</div>
                <form method="POST" action="{{ route('gameberry.dice.lucky.roll', $ld->id) }}" style="margin-top: 12px;">
                    @csrf
                    <button type="submit" class="btn btn-sm" style="background: black; color: #f1c40f; width: 100%;">Roll for Gems 🎲</button>
                </form>
            </div>
            @empty
            <p style="color: var(--text-muted); font-size: 13px; grid-column: 1/-1;">No unrolled lucky dice - Get lucky dice from friends Facebook exchange, max 52</p>
            @endforelse
        </div>
    </div>

    <div class="card">
        <div style="padding: 20px;">
            <h3>All Lucky Dice ({{ $luckyDices->count() }}) - Patterns & Gem Rewards</h3>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Dice</th><th>Sender</th><th>Pattern</th><th>Gem Reward</th><th>Rolled</th><th>Date</th></tr></thead>
                    <tbody>
                        @foreach($luckyDices as $ld)
                        <tr>
                            <td>{{ $ld->dice->name ?? 'Dice #'.$ld->dice_id }}</td>
                            <td>{{ $ld->sender->name ?? 'System' }}</td>
                            <td>@if($ld->pattern) <span class="badge" style="background: {{ $ld->pattern === 'three_same' ? '#27ae60' : ($ld->pattern === 'sequence' ? '#9b59b6' : '#3498db') }}; color: white;">{{ $ld->pattern }}</span> @else <span style="color: var(--text-muted); font-size: 11px;">Not rolled</span> @endif</td>
                            <td>@if($ld->gem_reward) +{{ $ld->gem_reward }} 💎 @else - @endif</td>
                            <td>{{ $ld->is_rolled ? '✅ Rolled' : '⏳ Unrolled' }}</td>
                            <td style="font-size: 11px;">{{ $ld->created_at->diffForHumans() }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div style="margin-top: 16px; background: var(--bg-secondary); padding: 12px; border-radius: 8px; font-size: 13px;">
                <strong>Lucky Dice Gem Rewards (Gameberry FAQ):</strong>
                <ul style="margin: 8px 0 0; padding-left: 18px; color: var(--text-muted);">
                    <li>three_same pattern = 10 gems</li>
                    <li>three_different pattern = 5 gems</li>
                    <li>sequence pattern = 15 gems</li>
                    <li>Max 52 lucky dice per user</li>
                    <li>Facebook-only dice exchange</li>
                </ul>
            </div>
        </div>
    </div>
</div>
@endsection
