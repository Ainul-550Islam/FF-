@extends('layouts.app')

@section('title', "Share Table {$table->code}")

@section('content')
<div class="container" style="max-width: 600px; margin: 0 auto; padding: 24px; text-align: center;">
    <h1 style="font-size: 28px; font-weight: 800; margin: 0 0 8px;">Share Private Table</h1>
    <p style="color: var(--text-muted); margin: 0 0 24px;">Code & Link sharing - Challenge button - Team Up</p>

    <div class="card" style="padding: 24px;">
        <div style="background: var(--bg-secondary); padding: 20px; border-radius: 16px; margin-bottom: 20px;">
            <div style="font-size: 14px; color: var(--text-muted); margin-bottom: 8px;">TABLE CODE</div>
            <div style="font-size: 48px; font-weight: 900; letter-spacing: 8px; color: var(--primary);">{{ $table->code }}</div>
            <div style="font-size: 12px; color: var(--text-muted); margin-top: 8px;">Share this 6-char code with friends</div>
        </div>

        <div style="background: var(--bg-secondary); padding: 16px; border-radius: 12px; margin-bottom: 20px; text-align: left;">
            <div style="font-size: 12px; color: var(--text-muted); margin-bottom: 4px;">SHAREABLE LINK</div>
            <div style="font-size: 14px; word-break: break-all; background: var(--bg-tertiary); padding: 8px 12px; border-radius: 8px;">{{ $shareData['link'] }}</div>
            <div style="font-size: 11px; color: var(--text-muted); margin-top: 8px;">Click link to join directly - like LudoStar private table link</div>
        </div>

        <div style="background: var(--bg-secondary); padding: 16px; border-radius: 12px; margin-bottom: 20px; text-align: left; font-size: 13px;">
            <div><strong>Game Mode:</strong> {{ ucfirst($table->game_mode) }} {{ $table->is_team_up ? '(Team Up 2v2)' : '' }}</div>
            <div><strong>Bet Amount:</strong> {{ $table->bet_amount_minor }} gold at stake</div>
            <div><strong>Max Players:</strong> {{ $table->max_players }}</div>
            <div><strong>Status:</strong> {{ $table->status }}</div>
            <div><strong>Expires:</strong> {{ $table->expires_at->diffForHumans() }}</div>
        </div>

        <div style="background: var(--bg-secondary); padding: 12px; border-radius: 8px; margin-bottom: 20px; font-size: 13px; text-align: left;">
            <strong>Share Text (Copy):</strong>
            <div style="background: var(--bg-tertiary); padding: 8px; border-radius: 6px; margin-top: 6px; font-size: 12px;">Join my Ludo table! Code: {{ $table->code }} Link: {{ $shareData['link'] }} Bet: {{ $shareData['bet_amount'] }} gold - Team Up: {{ $table->is_team_up ? 'Yes' : 'No' }} - Classic/Master/Quick</div>
        </div>

        <div style="display: flex; gap: 12px; justify-content: center;">
            <a href="{{ route('gameberry.private_tables.show', $table->code) }}" class="btn btn-primary">Go to Table 🎮</a>
            <a href="{{ route('gameberry.private_tables.index') }}" class="btn btn-secondary">My Tables</a>
        </div>
    </div>
</div>
@endsection
