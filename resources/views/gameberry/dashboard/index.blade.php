@extends('layouts.app')

@section('title', 'Gameberry Dashboard - LudoStar Style - 250+ Dice, 6-Step League, Private Tables')

@section('content')
<div class="container" style="max-width: 1280px; margin: 0 auto; padding: 24px;">
    <h1 style="font-size: 32px; font-weight: 900; margin: 0 0 8px;">🎮 Gameberry Dashboard</h1>
    <p style="color: var(--text-muted); margin: 0 0 24px;">LudoStar features: 250+ dice, 6-step league Bronze→Titan Top 20% Top 40, private tables code/link, gold at stake, magic chest, video ads, gems, lucky dice, spin2win, auto mode, hide online status, notify friends, Level 4 Bronze unlock, referral BGI20 ₹25, scratch cards, reconciliation</p>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin-bottom: 24px;">
        <div class="card" style="padding: 20px; border-left: 4px solid #f1c40f;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <div style="font-size: 13px; color: var(--text-muted);">Gold Balance</div>
                    <div style="font-size: 28px; font-weight: 800;">{{ number_format($goldBalance ?? 0) }} 🪙</div>
                    <div style="font-size: 11px; color: var(--text-muted);">Reconciled: {{ ($goldReconcile['is_balanced'] ?? true) ? '✅' : '❌' }}</div>
                </div>
                <div style="font-size: 40px;">🪙</div>
            </div>
            <a href="{{ route('gameberry.economy.index') }}" class="btn btn-sm btn-secondary" style="margin-top: 12px; width: 100%;">Economy →</a>
        </div>

        <div class="card" style="padding: 20px; border-left: 4px solid #9b59b6;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <div style="font-size: 13px; color: var(--text-muted);">Gem Balance</div>
                    <div style="font-size: 28px; font-weight: 800;">{{ number_format($gemBalance ?? 0) }} 💎</div>
                    <div style="font-size: 11px; color: var(--text-muted);">Reconciled: {{ ($gemReconcile['is_balanced'] ?? true) ? '✅' : '❌' }}</div>
                </div>
                <div style="font-size: 40px;">💎</div>
            </div>
            <a href="{{ route('gameberry.economy.index') }}" class="btn btn-sm btn-secondary" style="margin-top: 12px; width: 100%;">Gems →</a>
        </div>

        <div class="card" style="padding: 20px; border-left: 4px solid var(--primary);">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <div style="font-size: 13px; color: var(--text-muted);">Dice Collection</div>
                    <div style="font-size: 28px; font-weight: 800;">{{ $diceStats['owned'] ?? 0 }}/{{ $diceStats['total'] ?? 250 }} 🎲</div>
                    <div style="font-size: 11px; color: var(--text-muted);">{{ $diceStats['percent'] ?? 0 }}% Complete - Max 52 per type</div>
                </div>
                <div style="font-size: 40px;">🎲</div>
            </div>
            <a href="{{ route('gameberry.dice.index') }}" class="btn btn-sm btn-secondary" style="margin-top: 12px; width: 100%;">Dice →</a>
        </div>

        <div class="card" style="padding: 20px; border-left: 4px solid #27ae60;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <div style="font-size: 13px; color: var(--text-muted);">League</div>
                    <div style="font-size: 20px; font-weight: 800;">{{ $userLeague->league->name ?? 'No League' }} 🏆</div>
                    <div style="font-size: 11px; color: var(--text-muted);">Level {{ $userLevel->level ?? 1 }} | Trophies {{ $userLeague->trophies ?? 0 }} | Rank #{{ $userLeague->rank ?? '-' }}</div>
                </div>
                <div style="font-size: 40px;">🏆</div>
            </div>
            <a href="{{ route('gameberry.league.index') }}" class="btn btn-sm btn-secondary" style="margin-top: 12px; width: 100%;">League →</a>
        </div>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">
        <div class="card" style="padding: 20px;">
            <h3 style="margin: 0 0 12px;">🎮 Private Tables - Code/Link Sharing</h3>
            <div style="font-size: 13px; color: var(--text-muted); margin-bottom: 12px;">Classic Master Quick Team-up Gold at stake Auto mode Chat emojis</div>
            <div style="display: flex; gap: 8px;">
                <a href="{{ route('gameberry.private_tables.index') }}" class="btn btn-sm btn-primary">My Tables ({{ $privateTablesCount ?? 0 }})</a>
                <a href="{{ route('gameberry.private_tables.create') }}" class="btn btn-sm btn-secondary">Create Table</a>
            </div>
        </div>

        <div class="card" style="padding: 20px;">
            <h3 style="margin: 0 0 12px;">👥 Game Buddies - Max 25</h3>
            <div style="font-size: 13px; color: var(--text-muted); margin-bottom: 12px;">Hide online status Notify friends Challenge button Auto mode Team-up</div>
            <div style="display: flex; gap: 8px;">
                <a href="{{ route('gameberry.social.index') }}" class="btn btn-sm btn-primary">Buddies ({{ $buddiesCount ?? 0 }}/25)</a>
                <a href="{{ route('gameberry.social.challenges') }}" class="btn btn-sm btn-secondary">Challenges</a>
            </div>
        </div>

        <div class="card" style="padding: 20px;">
            <h3 style="margin: 0 0 12px;">🎉 Weekly Special Events</h3>
            <div style="font-size: 13px; color: var(--text-muted); margin-bottom: 12px;">Evolving engaging social rewarding - Active {{ $activeEventsCount ?? 0 }} Upcoming {{ $upcomingEventsCount ?? 0 }}</div>
            <a href="{{ route('gameberry.events.index') }}" class="btn btn-sm btn-primary" style="width: 100%;">Events →</a>
        </div>

        <div class="card" style="padding: 20px;">
            <h3 style="margin: 0 0 12px;">🎁 Economy - Magic Chest Video Ads Spin2Win</h3>
            <div style="font-size: 13px; color: var(--text-muted); margin-bottom: 12px;">Gold at stake Magic chest Video ads free gold Gems Lucky dice gem reward Spin2Win Wallets Reconciliation</div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                <a href="{{ route('gameberry.economy.magic_chests') }}" class="btn btn-sm btn-secondary">Magic Chests</a>
                <a href="{{ route('gameberry.economy.video_ads') }}" class="btn btn-sm btn-secondary">Video Ads</a>
                <a href="{{ route('gameberry.spin.index') }}" class="btn btn-sm btn-secondary">Spin2Win</a>
                <a href="{{ route('gameberry.economy.gold_history') }}" class="btn btn-sm btn-secondary">Gold History</a>
            </div>
        </div>

        <div class="card" style="padding: 20px;">
            <h3 style="margin: 0 0 12px;">🎟️ Referral BGI20 ₹25 + Scratch Cards</h3>
            <div style="font-size: 13px; color: var(--text-muted); margin-bottom: 12px;">BGI20 style code Share for ₹25 bonus 2500 minor + 10 gems + scratch card Khiladi Adda</div>
            <a href="{{ route('gameberry.referral.index') }}" class="btn btn-sm btn-primary" style="width: 100%;">Referral → Code: {{ $referralCode ?? 'No Code' }}</a>
        </div>

        <div class="card" style="padding: 20px;">
            <h3 style="margin: 0 0 12px;">📊 Level System - Level 4 Bronze Unlock</h3>
            <div style="font-size: 13px; color: var(--text-muted); margin-bottom: 12px;">Level {{ $userLevel->level ?? 1 }} XP {{ $userLevel->xp ?? 0 }}/{{ $userLevel->xp_to_next_level ?? 1000 }} Wins {{ $userLevel->total_wins ?? 0 }} Games {{ $userLevel->total_games ?? 0 }} Win Rate {{ $userLevel->winRate() ?? 0 }}%</div>
            <div style="background: var(--bg-secondary); height: 8px; border-radius: 999px; overflow: hidden; margin-bottom: 12px;">
                <div style="height: 100%; background: var(--primary); width: {{ $userLevel ? min(100, ($userLevel->xp / max(1,$userLevel->xp_to_next_level))*100) : 0 }}%;"></div>
            </div>
            <div style="font-size: 11px; color: var(--text-muted);">Bronze unlock Level 4 | Gold Level 6 | Titan Level 12 | Features: {{ json_encode($userLevel->unlocked_features ?? []) }}</div>
        </div>
    </div>
</div>
@endsection
