@extends('layouts.app')

@section('title', 'Private Tables - Code/Link Sharing - Team Up Mode')

@section('content')
<div class="container" style="max-width: 1280px; margin: 0 auto; padding: 24px;">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 24px;">
        <div>
            <h1 style="font-size: 28px; font-weight: 800; margin: 0;">🎮 Private Tables</h1>
            <p style="color: var(--text-muted); margin: 4px 0 0;">Code & Link sharing - Challenge button - Team Up - Classic/Master/Quick - Gold at stake</p>
        </div>
        <a href="{{ route('gameberry.private_tables.create') }}" class="btn btn-primary">+ Create Private Table</a>
    </div>

    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
        <div class="card">
            <div style="padding: 20px;">
                <h3 style="margin: 0 0 16px;">My Tables (Host) - {{ $myTables->count() }}</h3>
                @forelse($myTables as $table)
                <div style="background: var(--bg-secondary); padding: 12px; border-radius: 8px; margin-bottom: 12px; display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <div style="font-weight: 700;">Code: {{ $table->code }} - {{ $table->game_mode }} {{ $table->is_team_up ? '(Team Up)' : '' }}</div>
                        <div style="font-size: 12px; color: var(--text-muted);">Bet: {{ $table->bet_amount_minor }} gold | Players: {{ $table->participants->count() }}/{{ $table->max_players }} | {{ $table->status }}</div>
                        <div style="font-size: 11px; color: var(--text-muted); margin-top: 4px;">Link: {{ $table->link ?? url("/private-table/{$table->code}") }}</div>
                    </div>
                    <a href="{{ route('gameberry.private_tables.show', $table->code) }}" class="btn btn-sm btn-secondary">Open</a>
                </div>
                @empty
                <p style="color: var(--text-muted);">No tables hosted yet - Create one with code/link sharing</p>
                @endforelse
            </div>
        </div>

        <div class="card">
            <div style="padding: 20px;">
                <h3 style="margin: 0 0 16px;">Joined Tables - {{ $joinedTables->count() }}</h3>
                @forelse($joinedTables as $table)
                <div style="background: var(--bg-secondary); padding: 12px; border-radius: 8px; margin-bottom: 12px; display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <div style="font-weight: 700;">Code: {{ $table->code }} - Host: {{ $table->host->name ?? 'Unknown' }}</div>
                        <div style="font-size: 12px; color: var(--text-muted);">Bet: {{ $table->bet_amount_minor }} gold | Mode: {{ $table->game_mode }} | {{ $table->status }}</div>
                    </div>
                    <a href="{{ route('gameberry.private_tables.show', $table->code) }}" class="btn btn-sm btn-primary">Play</a>
                </div>
                @empty
                <p style="color: var(--text-muted);">No joined tables - Join via code or link</p>
                @endforelse
            </div>
        </div>
    </div>

    <div class="card" style="margin-top: 24px;">
        <div style="padding: 20px;">
            <h3>Gameberry Private Table Features</h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 16px; margin-top: 12px; font-size: 13px;">
                <div><strong>✅ Code Sharing:</strong> 6-char uppercase code like LudoStar</div>
                <div><strong>✅ Link Sharing:</strong> Shareable URL for private table</div>
                <div><strong>✅ Challenge Button:</strong> Challenge friends to private table</div>
                <div><strong>✅ Team Up Mode:</strong> 2v2 team play</div>
                <div><strong>✅ Game Variations:</strong> Classic / Master / Quick</div>
                <div><strong>✅ Gold at Stake:</strong> Bet gold, win opponent gold</div>
                <div><strong>✅ Auto Mode:</strong> Auto-play on disconnect</div>
                <div><strong>✅ Chat & Emojis:</strong> In-table chat with emojis</div>
            </div>
        </div>
    </div>
</div>
@endsection
