@extends('layouts.app')
@section('title', 'Waiting Room - Private Table')
@section('content')
<div class="container" style="max-width: 800px; margin: 0 auto; padding: 24px; text-align: center;">
<h1 style="font-size: 28px; font-weight: 800;">⏳ Waiting Room - Table {{ $table->code }}</h1>
<p style="color: var(--text-muted);">Waiting for players - Code {{ $table->code }} Link {{ $table->link }} - Gold at stake {{ $table->bet_amount_minor }} - Mode {{ $table->game_mode }} Team Up {{ $table->is_team_up ? 'Yes' : 'No' }}</p>
<div class="card" style="padding: 20px; margin-top: 20px;">
<h3>Players {{ $table->participants->count() }}/{{ $table->max_players }}</h3>
@foreach($table->participants as $p)
<div style="background: var(--bg-secondary); padding: 10px; border-radius: 8px; margin-bottom: 8px; display: flex; justify-content: space-between;">
<span>{{ $p->user->name ?? 'User '.$p->user_id }} @if($p->role==='host')👑 Host @endif {{ $p->team ?? '' }}</span>
<span>{{ $p->is_ready ? '✅ Ready' : '⏳ Waiting' }} {{ $p->is_in_auto_mode ? '🤖 Auto' : '' }}</span>
</div>
@endforeach
<div style="margin-top: 16px; display: flex; gap: 8px; justify-content: center; flex-wrap: wrap;">
<form method="POST" action="{{ route('gameberry.private_tables.ready', $table->code) }}">@csrf<input type="hidden" name="is_ready" value="1"><button type="submit" class="btn btn-primary">I'm Ready</button></form>
@if($table->host_id===auth()->id())
<form method="POST" action="{{ route('gameberry.private_tables.start', $table->code) }}">@csrf<button type="submit" class="btn btn-secondary">Start Game</button></form>
@endif
<a href="{{ route('gameberry.private_tables.share', $table->code) }}" class="btn btn-ghost">Share Code/Link</a>
</div>
</div>
</div>
@endsection
