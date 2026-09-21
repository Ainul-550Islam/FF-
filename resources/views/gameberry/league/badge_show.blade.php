@extends('layouts.app')
@section('title', 'Titan Badge Detail')
@section('content')
<div class="container" style="max-width: 800px; margin: 0 auto; padding: 24px;">
<a href="{{ route('gameberry.league.badges') }}" style="color: var(--primary); font-size: 13px;">← My badges</a>
<div class="card" style="padding: 40px; text-align: center; margin-top: 16px;">
<div style="font-size: 72px;">👑</div>
<h1 style="font-size: 28px; font-weight: 900; margin: 12px 0 4px;">{{ $badge->name }}</h1>
<p style="color: var(--text-muted); margin: 0 0 24px;">{{ $badge->league?->name ?? 'Titan League' }} - Titan badge</p>
<div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px;">
<div style="background: var(--bg-secondary); padding: 16px; border-radius: 8px;"><div style="font-size: 18px; font-weight: 800;">{{ $badge->tier ?? 'titan' }}</div><div style="font-size: 11px; color: var(--text-muted);">Tier</div></div>
<div style="background: var(--bg-secondary); padding: 16px; border-radius: 8px;"><div style="font-size: 18px; font-weight: 800;">{{ $badge->season ?? '-' }}</div><div style="font-size: 11px; color: var(--text-muted);">Season</div></div>
<div style="background: var(--bg-secondary); padding: 16px; border-radius: 8px;"><div style="font-size: 18px; font-weight: 800;">{{ $badge->awarded_at?->format('Y-m-d') ?? '-' }}</div><div style="font-size: 11px; color: var(--text-muted);">Awarded</div></div>
</div>
<p style="font-size: 12px; color: var(--text-muted); margin-top: 24px;">Titan badges are awarded weekly for staying in the Titan league - Top 20% promotion, Top 40 stay rule - Level 12 required.</p>
</div>
</div>
@endsection
