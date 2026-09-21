@extends('layouts.app')
@section('title', 'Level Profile')
@section('content')
<div class="container" style="max-width: 800px; margin: 0 auto; padding: 24px;">
<a href="{{ route('gameberry.level.index') }}" style="color: var(--primary); font-size: 13px;">← My level</a>
<div class="card" style="padding: 20px; margin-top: 16px; text-align: center;">
<div style="font-size: 64px;">⭐</div>
<h2 style="font-size: 48px; font-weight: 900; margin: 12px 0;">Level {{ $level->level }}</h2>
<div style="font-size: 14px; color: var(--text-muted);">XP {{ $level->xp }}/{{ $level->xp_to_next_level }} - {{ $stats['progress_percent'] }}% to next level</div>
<div style="background: var(--bg-secondary); height: 12px; border-radius: 999px; overflow: hidden; margin: 16px 0;"><div style="height: 100%; background: var(--primary); width: {{ $stats['progress_percent'] }}%;"></div></div>
<div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-top: 20px;">
<div style="background: var(--bg-secondary); padding: 12px; border-radius: 8px;"><div style="font-size: 20px; font-weight: 800;">{{ $stats['total_wins'] }}</div><div style="font-size: 11px; color: var(--text-muted);">Wins</div></div>
<div style="background: var(--bg-secondary); padding: 12px; border-radius: 8px;"><div style="font-size: 20px; font-weight: 800;">{{ $stats['total_losses'] }}</div><div style="font-size: 11px; color: var(--text-muted);">Losses</div></div>
<div style="background: var(--bg-secondary); padding: 12px; border-radius: 8px;"><div style="font-size: 20px; font-weight: 800;">{{ $stats['total_games'] }}</div><div style="font-size: 11px; color: var(--text-muted);">Games</div></div>
<div style="background: var(--bg-secondary); padding: 12px; border-radius: 8px;"><div style="font-size: 20px; font-weight: 800;">{{ $level->level >= 4 ? 'Unlocked' : 'Locked' }}</div><div style="font-size: 11px; color: var(--text-muted);">Bronze League (Lv 4)</div></div>
</div>
</div>
</div>
@endsection
