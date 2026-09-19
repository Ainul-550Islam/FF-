@extends('layouts.app')

@section('title', 'FF Arena - Home')
@section('description', 'FF Arena competitive gaming tournaments - Join, compete, win')

@section('content')
<div style="text-align: center; padding: 40px 0 32px;">
    <h1 style="font-size: 48px; font-weight: 900; margin: 0 0 16px; line-height: 1.1;">Compete. Win. <span style="background: linear-gradient(135deg, #6c5ce7, #a29bfe); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">Dominate.</span></h1>
    <p class="text-muted" style="font-size: 18px; max-width: 600px; margin: 0 auto 24px;">Join the ultimate Free Fire tournament platform. Secure wallet, anti-cheat, real payouts via bKash, Nagad, Rocket.</p>
    <div style="display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; align-items: center;">
        <a href="{{ route('tournaments.index') }}" class="btn btn-primary btn-lg">Browse Tournaments</a>
        <a href="{{ route('register') }}" class="btn btn-secondary btn-lg">Create Account</a>
        <span data-internet-status class="internet-status online"></span>
    </div>
    <div style="margin-top: 16px; display: flex; gap: 16px; justify-content: center; flex-wrap: wrap; font-size: 13px; color: var(--text-muted);">
        <span>✓ Offline-aware</span>
        <span>✓ Avatar profiles</span>
        <span>✓ Internet check</span>
        <span>✓ Secure wallet</span>
    </div>
</div>

<div class="grid grid-3" style="margin-top: 32px;">
    <div class="card">
        <div style="font-size: 32px; margin-bottom: 12px;" aria-hidden="true">🏆</div>
        <h3 style="margin: 0 0 8px; font-size: 18px; font-weight: 700;">Tournaments</h3>
        <p class="text-muted" style="font-size: 14px; margin: 0 0 16px;">Daily scrims, weekly championships, monthly majors. Entry via wallet, prize auto-distributed.</p>
        <a href="{{ route('tournaments.index') }}" class="btn btn-ghost btn-sm">Explore →</a>
    </div>
    <div class="card">
        <div style="font-size: 32px; margin-bottom: 12px;" aria-hidden="true">👤</div>
        <h3 style="margin: 0 0 8px; font-size: 18px; font-weight: 700;">Profile & Avatar</h3>
        <p class="text-muted" style="font-size: 14px; margin: 0 0 16px;">Custom avatar, bio, stats, login history, connected accounts. Private storage, authenticated serving.</p>
        <a href="{{ route('profile.show') }}" class="btn btn-ghost btn-sm">View Profile →</a>
    </div>
    <div class="card">
        <div style="font-size: 32px; margin-bottom: 12px;" aria-hidden="true">🔒</div>
        <h3 style="margin: 0 0 8px; font-size: 18px; font-weight: 700;">Security & Settings</h3>
        <p class="text-muted" style="font-size: 14px; margin: 0 0 16px;">2FA, sessions, login history, payment methods, internet status monitoring, offline safety.</p>
        <a href="{{ route('settings.security') }}" class="btn btn-ghost btn-sm">Security →</a>
    </div>
</div>

<div class="card" style="margin-top: 24px; display: flex; gap: 16px; align-items: center; flex-wrap: wrap;">
    <div style="flex: 1;">
        <h3 style="margin: 0 0 8px; font-size: 16px; font-weight: 700;">Internet Connection Monitor</h3>
        <p class="text-muted" style="font-size: 13px; margin: 0;">We continuously check your connection. If offline, financial actions are blocked to prevent duplicate charges. Idempotency ensures safety when you reconnect.</p>
    </div>
    <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
        <span data-internet-status class="internet-status online"></span>
        <button onclick="window.FFArena?.checkInternet()" class="btn btn-secondary btn-sm" data-require-online>Test Connection</button>
    </div>
</div>

@if(($tournaments ?? collect())->count() > 0)
    <div style="margin-top: 32px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h2 style="margin: 0; font-size: 22px; font-weight: 800;">Featured Tournaments</h2>
            <a href="{{ route('tournaments.index') }}" class="btn btn-ghost btn-sm">View All</a>
        </div>
        <div class="grid grid-3">
            @foreach($tournaments as $tournament)
                <div class="card card-hover">
                    <div style="display: flex; justify-content: space-between; gap: 12px; margin-bottom: 12px;">
                        <h3 style="margin: 0; font-size: 16px; font-weight: 700;">{{ $tournament->name }}</h3>
                        <x-status-pill :status="$tournament->status" />
                    </div>
                    <div class="text-muted" style="font-size: 13px;">Prize: {{ number_format($tournament->prize_pool_minor / 100, 2) }} BDT • {{ $tournament->max_teams }} teams</div>
                    <div style="margin-top: 12px;">
                        <a href="{{ route('tournaments.show', $tournament) }}" class="btn btn-secondary btn-sm" style="width: 100%;">View Details</a>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@else
    <div class="card" style="margin-top: 32px; text-align: center; padding: 32px;">
        <div style="font-size: 48px; margin-bottom: 16px;" aria-hidden="true">🎮</div>
        <h3 style="margin: 0 0 8px;">No tournaments yet</h3>
        <p class="text-muted" style="font-size: 14px;">Tournaments will appear here once created by admins. Check back soon!</p>
    </div>
@endif
@endsection
