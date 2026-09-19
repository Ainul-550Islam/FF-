@extends('layouts.app')
@section('title', 'Playing - Table '.$table->code)
@section('content')
<div class="container" style="max-width: 1280px; margin: 0 auto; padding: 24px;">
<h1 style="font-size: 24px; font-weight: 800;">🎲 Playing - Table {{ $table->code }} - {{ ucfirst($table->game_mode) }} {{ $table->is_team_up ? 'Team Up 2v2' : '' }} - Bet {{ $table->bet_amount_minor }} gold at stake</h1>
<div style="display: grid; grid-template-columns: 1fr 300px; gap: 20px; margin-top: 20px;">
<div class="card" style="padding: 20px; min-height: 500px; text-align: center; display: flex; flex-direction: column; justify-content: center;">
<div style="font-size: 80px;">🎲</div>
<h2>Ludo Board - {{ ucfirst($table->game_variation) }}</h2>
<p style="color: var(--text-muted);">Gold at stake - Auto mode on disconnect enabled - Chat & emojis available</p>
<div style="margin-top: 20px; display: flex; gap: 8px; justify-content: center; flex-wrap: wrap;">
<form method="POST" action="{{ route('gameberry.private_tables.auto_mode', $table->code) }}">@csrf<input type="hidden" name="auto_on" value="1"><input type="hidden" name="reason" value="disconnect"><button type="submit" class="btn btn-sm btn-secondary">Auto Mode ON 🤖</button></form>
<form method="POST" action="{{ route('gameberry.private_tables.leave', $table->code) }}">@csrf<button type="submit" class="btn btn-sm btn-ghost" style="color: var(--danger);">Leave</button></form>
</div>
</div>
<div class="card" style="padding: 16px;">
<h3 style="margin: 0 0 12px;">Players</h3>
@foreach($table->participants as $p)
<div style="background: var(--bg-secondary); padding: 8px; border-radius: 6px; margin-bottom: 6px; font-size: 13px; display: flex; justify-content: space-between;">
<span>{{ $p->user->name ?? 'User '.$p->user_id }} {{ $p->team ?? '' }}</span>
<span>{{ $p->is_in_auto_mode ? '🤖 Auto' : '🎮 Playing' }}</span>
</div>
@endforeach
<h3 style="margin: 16px 0 8px;">Chat</h3>
<div style="max-height: 200px; overflow-y: auto; display: flex; flex-direction: column; gap: 6px;">
@foreach($messages ?? [] as $msg)
<div style="font-size: 12px; background: var(--bg-secondary); padding: 6px 8px; border-radius: 8px;">{{ $msg->user->name ?? 'System' }}: {{ $msg->message }}</div>
@endforeach
</div>
<form method="POST" action="{{ route('gameberry.chat.send', $table->code) }}" style="display: flex; gap: 6px; margin-top: 12px;">@csrf<input type="text" name="message" placeholder="Chat..." required maxlength="200" style="flex: 1; padding: 6px 10px; border-radius: 999px; border: 1px solid var(--border); background: var(--bg-secondary);"><button type="submit" class="btn btn-sm btn-primary">Send</button></form>
</div>
</div>
</div>
@endsection
