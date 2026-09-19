@extends('layouts.app')
@section('title', 'Level System - Level 4 Bronze Unlock')
@section('content')
<div class="container" style="max-width: 800px; margin: 0 auto; padding: 24px;">
<h1 style="font-size: 28px; font-weight: 800;">📊 Level System - Level 4 Bronze Unlock</h1>
<p style="color: var(--text-muted);">Gameberry level system - Level 4 to reach Bronze League - XP progression - Unlock features</p>
<div class="card" style="padding: 20px; margin-top: 20px; text-align: center;">
<div style="font-size: 64px;">⭐</div>
<h2 style="font-size: 48px; font-weight: 900; margin: 12px 0;">Level {{ $level->level }}</h2>
<div style="font-size: 14px; color: var(--text-muted);">XP {{ $level->xp }}/{{ $level->xp_to_next_level }} - {{ $stats['progress_percent'] }}% to next level</div>
<div style="background: var(--bg-secondary); height: 12px; border-radius: 999px; overflow: hidden; margin: 16px 0;"><div style="height: 100%; background: var(--primary); width: {{ $stats['progress_percent'] }}%;"></div></div>
<div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-top: 20px;">
<div style="background: var(--bg-secondary); padding: 12px; border-radius: 8px;"><div style="font-size: 20px; font-weight: 800;">{{ $stats['total_wins'] }}</div><div style="font-size: 11px; color: var(--text-muted);">Wins</div></div>
<div style="background: var(--bg-secondary); padding: 12px; border-radius: 8px;"><div style="font-size: 20px; font-weight: 800;">{{ $stats['total_losses'] }}</div><div style="font-size: 11px; color: var(--text-muted);">Losses</div></div>
<div style="background: var(--bg-secondary); padding: 12px; border-radius: 8px;"><div style="font-size: 20px; font-weight: 800;">{{ $stats['total_games'] }}</div><div style="font-size: 11px; color: var(--text-muted);">Games</div></div>
<div style="background: var(--bg-secondary); padding: 12px; border-radius: 8px;"><div style="font-size: 20px; font-weight: 800;">{{ $stats['win_rate'] }}%</div><div style="font-size: 11px; color: var(--text-muted);">Win Rate</div></div>
</div>
<div style="margin-top: 20px; display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 8px; font-size: 12px;">
<div style="background: {{ $stats['can_bronze'] ? '#27ae60' : '#95a5a6' }}; color: white; padding: 8px; border-radius: 8px;">Bronze League: {{ $stats['can_bronze'] ? '✅ Unlocked Level 4' : '🔒 Need Level 4' }}</div>
<div style="background: {{ $stats['can_titan'] ? '#FFD700' : '#95a5a6' }}; color: {{ $stats['can_titan'] ? 'black' : 'white' }}; padding: 8px; border-radius: 8px;">Titan League: {{ $stats['can_titan'] ? '👑 Unlocked Level 12' : '🔒 Need Level 12' }}</div>
</div>
<div style="margin-top: 16px; font-size: 11px; color: var(--text-muted);">Unlocked Features: {{ json_encode($stats['unlocked_features']) }}</div>
</div>
</div>
@endsection
