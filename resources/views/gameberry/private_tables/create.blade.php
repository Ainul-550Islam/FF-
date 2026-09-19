@extends('layouts.app')

@section('title', 'Create Private Table - Code/Link Sharing')

@section('content')
<div class="container" style="max-width: 600px; margin: 0 auto; padding: 24px;">
    <h1 style="font-size: 28px; font-weight: 800; margin: 0 0 8px;">Create Private Table</h1>
    <p style="color: var(--text-muted); margin: 0 0 24px;">Code & Link sharing - Team Up Mode - Classic/Master/Quick - Gold at stake</p>

    <div class="card">
        <div style="padding: 24px;">
            <form method="POST" action="{{ route('gameberry.private_tables.store') }}">
                @csrf

                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 6px;">Game Mode</label>
                    <select name="game_mode" required style="width: 100%; padding: 10px 12px; border-radius: 8px; border: 1px solid var(--border); background: var(--bg-secondary);">
                        <option value="classic">Classic - 4 Players - Traditional Ludo</option>
                        <option value="master">Master - 4 Players - Advanced Rules</option>
                        <option value="quick">Quick - 2 Players - Fast Game</option>
                        <option value="team_up">Team Up - 4 Players - 2v2 Team Mode</option>
                    </select>
                    <div style="font-size: 11px; color: var(--text-muted); margin-top: 4px;">LudoStar variations: Classic Master Quick + Team Up</div>
                </div>

                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 6px;">Game Variation</label>
                    <select name="variation" style="width: 100%; padding: 10px 12px; border-radius: 8px; border: 1px solid var(--border); background: var(--bg-secondary);">
                        <option value="classic">Classic Variation</option>
                        <option value="master">Master Variation</option>
                        <option value="quick">Quick Variation</option>
                    </select>
                </div>

                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 6px;">Bet Amount (Gold at Stake)</label>
                    <input type="number" name="bet_amount" value="100" min="100" max="100000" required style="width: 100%; padding: 10px 12px; border-radius: 8px; border: 1px solid var(--border); background: var(--bg-secondary);">
                    <div style="font-size: 11px; color: var(--text-muted); margin-top: 4px;">Min 100, Max 100000 - Gold at stake, win opponent gold like LudoStar</div>
                </div>

                <div style="margin-bottom: 20px;">
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" name="is_team_up" value="1">
                        <span style="font-weight: 600;">Enable Team Up Mode (2v2)</span>
                    </label>
                    <div style="font-size: 11px; color: var(--text-muted); margin-top: 4px; margin-left: 24px;">Team Up mode - Play with partner as team</div>
                </div>

                <div style="background: var(--bg-secondary); padding: 12px; border-radius: 8px; margin-bottom: 20px; font-size: 13px;">
                    <strong>Private Table Features:</strong>
                    <ul style="margin: 8px 0 0; padding-left: 18px; color: var(--text-muted);">
                        <li>✅ 6-char uppercase code generated</li>
                        <li>✅ Shareable link generated</li>
                        <li>✅ Code & Link sharing with friends</li>
                        <li>✅ Challenge button for buddies</li>
                        <li>✅ Gold at stake - bet gold</li>
                        <li>✅ Auto mode on disconnect</li>
                        <li>✅ Chat & emojis in table</li>
                        <li>✅ Expires in 2 hours if not started</li>
                    </ul>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; padding: 12px; font-size: 16px; font-weight: 700;">Create Table - Get Code & Link 🎮</button>
            </form>
        </div>
    </div>
</div>
@endsection
