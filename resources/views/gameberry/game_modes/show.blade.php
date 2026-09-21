@extends('layouts.app')
@section('title', 'Game Mode - ' . ($modeData['name'] ?? $mode))
@section('content')
<div class="container" style="max-width: 1000px; margin: 0 auto; padding: 24px;">
<a href="{{ route('gameberry.game_modes.index') }}" style="color: var(--primary); font-size: 13px;">← All game modes</a>
<h1 style="font-size: 28px; font-weight: 800; margin-top: 8px;">🎮 {{ $modeData['name'] ?? $mode }}</h1>
<p style="color: var(--text-muted);">{{ $modeData['description'] ?? '' }} - {{ strtoupper($mode) }} mode details</p>
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-top: 20px;">
<div class="card" style="padding: 20px;"><div style="font-size: 24px; font-weight: 800;">{{ $modeData['max_players'] ?? '1' }}</div><div style="font-size: 11px; color: var(--text-muted);">Max Players</div></div>
<div class="card" style="padding: 20px;"><div style="font-size: 24px; font-weight: 800;">+{{ $modeData['trophy_win'] ?? 0 }}</div><div style="font-size: 11px; color: var(--text-muted);">Trophy Win</div></div>
<div class="card" style="padding: 20px;"><div style="font-size: 24px; font-weight: 800;">-{{ $modeData['trophy_loss'] ?? 0 }}</div><div style="font-size: 11px; color: var(--text-muted);">Trophy Loss</div></div>
<div class="card" style="padding: 20px;"><div style="font-size: 24px; font-weight: 800;">+{{ $modeData['xp_bonus'] ?? 0 }}</div><div style="font-size: 11px; color: var(--text-muted);">XP Bonus</div></div>
<div class="card" style="padding: 20px;"><div style="font-size: 24px; font-weight: 800;">{{ $modeData['gold_multiplier'] ?? 1 }}x</div><div style="font-size: 11px; color: var(--text-muted);">Gold Multiplier</div></div>
</div>
<a href="{{ route('gameberry.private_tables.create') }}?mode={{ $mode }}" class="btn btn-primary" style="margin-top: 20px;">Create {{ $modeData['name'] ?? $mode }} Table</a>
</div>
@endsection
