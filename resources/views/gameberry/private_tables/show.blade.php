@extends('layouts.app')

@section('title', "Private Table {$table->code} - {$table->game_mode}")

@section('content')
<div class="container" style="max-width: 1280px; margin: 0 auto; padding: 24px;">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 24px;">
        <div>
            <h1 style="font-size: 24px; font-weight: 800; margin: 0;">Table {{ $table->code }} - {{ ucfirst($table->game_mode) }} {{ $table->is_team_up ? '(Team Up)' : '' }}</h1>
            <p style="color: var(--text-muted); margin: 4px 0 0;">Link: {{ $table->link ?? url("/private-table/{$table->code}") }} | Bet: {{ $table->bet_amount_minor }} gold at stake | Status: {{ $table->status }}</p>
        </div>
        <div style="display: flex; gap: 8px;">
            <a href="{{ route('gameberry.private_tables.share', $table->code) }}" class="btn btn-secondary">Share Code/Link</a>
            <a href="{{ route('gameberry.private_tables.index') }}" class="btn btn-ghost">Back</a>
        </div>
    </div>

    <div style="display: grid; grid-template-columns: 300px 1fr 300px; gap: 20px;">
        <div class="card">
            <div style="padding: 16px;">
                <h3 style="margin: 0 0 12px;">Players ({{ $table->participants->count() }}/{{ $table->max_players }})</h3>
                @foreach($table->participants as $p)
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 8px; background: var(--bg-secondary); border-radius: 6px; margin-bottom: 8px;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <div style="width: 32px; height: 32px; border-radius: 50%; background: var(--primary); display: flex; align-items: center; justify-content: center; font-size: 12px; color: white;">{{ substr($p->user->name ?? 'U', 0, 1) }}</div>
                        <div>
                            <div style="font-size: 13px; font-weight: 600;">{{ $p->user->name ?? 'User '.$p->user_id }} @if($p->role === 'host')👑@endif</div>
                            <div style="font-size: 11px; color: var(--text-muted);">{{ $p->team ?? '' }} {{ $p->is_ready ? '✅ Ready' : '⏳ Waiting' }} {{ $p->is_in_auto_mode ? '🤖 Auto' : '' }}</div>
                        </div>
                    </div>
                    <div style="font-size: 10px;">{{ $p->is_ready ? 'Ready' : 'Not Ready' }}</div>
                </div>
                @endforeach

                <div style="margin-top: 16px; display: flex; flex-direction: column; gap: 8px;">
                    <form method="POST" action="{{ route('gameberry.private_tables.ready', $table->code) }}">
                        @csrf
                        <input type="hidden" name="is_ready" value="1">
                        <button type="submit" class="btn btn-sm btn-primary" style="width: 100%;">I'm Ready ✅</button>
                    </form>
                    @if($table->host_id === auth()->id())
                    <form method="POST" action="{{ route('gameberry.private_tables.start', $table->code) }}">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-secondary" style="width: 100%;">Start Game 🚀</button>
                    </form>
                    @endif
                    <form method="POST" action="{{ route('gameberry.private_tables.auto_mode', $table->code) }}">
                        @csrf
                        <input type="hidden" name="auto_on" value="1">
                        <input type="hidden" name="reason" value="disconnect">
                        <button type="submit" class="btn btn-sm btn-ghost" style="width: 100%;">Auto Mode ON 🤖</button>
                    </form>
                    <form method="POST" action="{{ route('gameberry.private_tables.leave', $table->code) }}">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-ghost" style="width: 100%; color: var(--danger);">Leave Table</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="card">
            <div style="padding: 16px; text-align: center; min-height: 400px; display: flex; flex-direction: column; justify-content: center;">
                <div style="font-size: 64px;">🎲</div>
                <h2>Ludo Board - {{ ucfirst($table->game_variation) }} Variation</h2>
                <p style="color: var(--text-muted);">Game Mode: {{ $table->game_mode }} | Team Up: {{ $table->is_team_up ? 'Yes 2v2' : 'No' }} | Bet: {{ $table->bet_amount_minor }} gold</p>
                <div style="background: var(--bg-secondary); padding: 12px; border-radius: 8px; margin-top: 16px; font-size: 13px;">
                    <div>Code: <strong>{{ $table->code }}</strong> | Link: {{ $table->link }}</div>
                    <div style="margin-top: 8px; color: var(--text-muted);">Share code or link with friends to join private table</div>
                </div>
                <div style="margin-top: 16px; display: flex; justify-content: center; gap: 8px; flex-wrap: wrap;">
                    <span style="background: var(--bg-secondary); padding: 6px 12px; border-radius: 999px; font-size: 12px;">Classic: 4 players</span>
                    <span style="background: var(--bg-secondary); padding: 6px 12px; border-radius: 999px; font-size: 12px;">Master: Advanced rules</span>
                    <span style="background: var(--bg-secondary); padding: 6px 12px; border-radius: 999px; font-size: 12px;">Quick: 2 players fast</span>
                    <span style="background: var(--bg-secondary); padding: 6px 12px; border-radius: 999px; font-size: 12px;">Team Up: 2v2</span>
                </div>
            </div>
        </div>

        <div class="card" style="display: flex; flex-direction: column;">
            <div style="padding: 12px; border-bottom: 1px solid var(--border);">
                <h3 style="margin: 0; font-size: 14px;">Chat & Emojis 💬</h3>
            </div>
            <div style="flex: 1; overflow-y: auto; max-height: 300px; padding: 12px; display: flex; flex-direction: column; gap: 8px;" id="chat-messages">
                @foreach($messages as $msg)
                <div style="font-size: 13px; padding: 6px 10px; border-radius: 12px; background: {{ $msg->is_system ? 'var(--bg-tertiary)' : ($msg->user_id === auth()->id() ? 'var(--primary)' : 'var(--bg-secondary)') }}; color: {{ $msg->user_id === auth()->id() && !$msg->is_system ? 'white' : 'inherit' }}; align-self: {{ $msg->user_id === auth()->id() ? 'flex-end' : 'flex-start' }}; max-width: 80%;">
                    @if(!$msg->is_system)<div style="font-size: 10px; opacity: 0.7;">{{ $msg->user->name ?? 'User' }}</div>@endif
                    <div>{{ $msg->message }}</div>
                </div>
                @endforeach
            </div>
            <div style="padding: 12px; border-top: 1px solid var(--border);">
                <div style="display: flex; gap: 4px; flex-wrap: wrap; margin-bottom: 8px;">
                    @foreach($emojis as $key => $emoji)
                    <form method="POST" action="{{ route('gameberry.chat.emoji', $table->code) }}" style="display: inline;">
                        @csrf
                        <input type="hidden" name="emoji_key" value="{{ $key }}">
                        <button type="submit" style="background: var(--bg-secondary); border: none; border-radius: 6px; padding: 4px 6px; cursor: pointer;">{{ $emoji }}</button>
                    </form>
                    @endforeach
                </div>
                <div style="display: flex; gap: 4px; flex-wrap: wrap; margin-bottom: 8px;">
                    @foreach($quickMessages as $qm)
                    <form method="POST" action="{{ route('gameberry.chat.quick', $table->code) }}" style="display: inline;">
                        @csrf
                        <input type="hidden" name="quick_message" value="{{ $qm }}">
                        <button type="submit" style="background: var(--bg-secondary); border: 1px solid var(--border); border-radius: 999px; padding: 2px 8px; font-size: 11px; cursor: pointer;">{{ $qm }}</button>
                    </form>
                    @endforeach
                </div>
                <form method="POST" action="{{ route('gameberry.chat.send', $table->code) }}" style="display: flex; gap: 8px;">
                    @csrf
                    <input type="text" name="message" placeholder="Type message..." required maxlength="200" style="flex: 1; padding: 8px 12px; border-radius: 999px; border: 1px solid var(--border); background: var(--bg-secondary);">
                    <button type="submit" class="btn btn-sm btn-primary">Send</button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
