@extends('layouts.app')
@section('title', 'Game Modes - Classic Master Quick Team Up')
@section('content')
<div class="container" style="max-width: 1000px; margin: 0 auto; padding: 24px;">
<h1 style="font-size: 28px; font-weight: 800;">🎮 Game Modes - LudoStar Variations</h1>
<p style="color: var(--text-muted);">Classic Master Quick Team Up - 1vs1 Team Up 4 Player Private Table & Offline - 6-step leaderboard</p>
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 16px; margin-top: 20px;">
@foreach($modes as $slug=>$mode)
<div class="card" style="padding: 20px; border-left: 4px solid var(--primary);">
<h3 style="margin: 0 0 8px;">{{ $mode['name'] }} - {{ $slug }}</h3>
<div style="font-size: 12px; color: var(--text-muted);">{{ $mode['description'] }} | Max Players {{ $mode['max_players'] }}</div>
<div style="font-size: 11px; color: var(--text-muted); margin-top: 8px;">Trophy Win {{ $mode['trophy_win'] }} Loss {{ $mode['trophy_loss'] }} | XP Bonus {{ $mode['xp_bonus'] }} | Gold Multiplier {{ $mode['gold_multiplier'] }}x</div>
<a href="{{ route('gameberry.private_tables.create') }}?mode={{ $slug }}" class="btn btn-sm btn-primary" style="margin-top: 12px; width: 100%;">Create {{ $mode['name'] }} Table</a>
</div>
@endforeach
</div>
</div>
@endsection
