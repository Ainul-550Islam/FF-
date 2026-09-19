@extends('layouts.app')
@section('title', 'Spin Wheel - Spin2Win')
@section('content')
<div class="container" style="max-width: 600px; margin: 0 auto; padding: 24px; text-align: center;">
<h1 style="font-size: 28px; font-weight: 800;">🎡 Spin Wheel - Spin2Win</h1>
<p style="color: var(--text-muted);">Spin2Win - Daily free spin + 100 gold per spin - Gold gems dice jackpot</p>
<div class="card" style="padding: 24px; margin-top: 20px;">
<div style="font-size: 100px; margin: 20px 0;">🎡</div>
<div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin-bottom: 20px; font-size: 12px;">
<div style="background: var(--bg-secondary); padding: 8px; border-radius: 8px;">🪙 Gold 200<br><small>40% chance</small></div>
<div style="background: var(--bg-secondary); padding: 8px; border-radius: 8px;">💎 Gems 5<br><small>30% chance</small></div>
<div style="background: var(--bg-secondary); padding: 8px; border-radius: 8px;">🎲 Dice<br><small>20% chance</small></div>
<div style="background: gold; color: black; padding: 8px; border-radius: 8px; font-weight: 800;">💰 Jackpot 1000 gold + 20 gems<br><small>10% chance</small></div>
</div>
<div style="display: flex; gap: 12px; justify-content: center;">
<form method="POST" action="{{ route('gameberry.spin.spin') }}">@csrf<input type="hidden" name="use_free" value="1"><button type="submit" class="btn btn-primary" style="padding: 12px 24px;">🎁 Free Spin ({{ $stats['free_spins_remaining'] ?? 1 }})</button></form>
<form method="POST" action="{{ route('gameberry.spin.spin') }}">@csrf<input type="hidden" name="use_free" value="0"><button type="submit" class="btn btn-secondary" style="padding: 12px 24px;">Spin 100 Gold 🪙</button></form>
</div>
<div style="margin-top: 16px; font-size: 12px; color: var(--text-muted);">Total Spins {{ $stats['total_spins'] ?? 0 }} | Today {{ $stats['today_spins'] ?? 0 }}/10 | Gold Won {{ $stats['total_gold_won'] ?? 0 }} | Gems Won {{ $stats['total_gems_won'] ?? 0 }} | Jackpots {{ $stats['jackpots'] ?? 0 }}</div>
</div>
</div>
@endsection
