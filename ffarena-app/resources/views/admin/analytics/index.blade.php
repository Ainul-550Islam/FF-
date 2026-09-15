@extends('layouts.app')
@section('title', 'Analytics — FF Arena Admin')
@section('content')
    <header class="page-head">
        <h1 class="page-title">📊 Analytics</h1>
    </header>

    <nav class="card row" aria-label="Analytics navigation">
        <a href="{{ route('admin.analytics.tournaments') }}" class="btn btn-sm">Tournaments & Matches</a>
        <a href="{{ route('admin.analytics.financial') }}" class="btn btn-sm btn-cyan">Financial</a>
        <a href="{{ route('admin.analytics.security') }}" class="btn btn-sm">Security</a>
        <a href="{{ route('admin.analytics.disputes') }}" class="btn btn-sm">Disputes</a>
        <a href="{{ route('admin.analytics.support') }}" class="btn btn-sm">Support</a>
    </nav>

    <h3 class="mt-4">Operations</h3>
    <div class="grid cols-2" style="grid-template-columns: repeat(auto-fit, minmax(170px, 1fr))">
        <div class="stat"><div class="muted">Users</div><div class="num">{{ $overview['users'] }}</div></div>
        <div class="stat"><div class="muted">Tournaments</div><div class="num">{{ $overview['tournaments']['total'] }}</div></div>
        <div class="stat"><div class="muted">Live now</div><div class="num" style="color: var(--green)">{{ $overview['tournaments']['live'] }}</div></div>
        <div class="stat"><div class="muted">Finished</div><div class="num">{{ $overview['tournaments']['finished'] }}</div></div>
        <div class="stat"><div class="muted">Teams</div><div class="num">{{ $overview['teams'] }}</div></div>
        <div class="stat"><div class="muted">Matches</div><div class="num">{{ $overview['matches'] }}</div></div>
        <div class="stat"><div class="muted">Open disputes</div><div class="num" style="color: var(--amber)">{{ $overview['open_disputes'] }}</div></div>
        <div class="stat"><div class="muted">Open tickets</div><div class="num" style="color: var(--amber)">{{ $overview['open_tickets'] }}</div></div>
    </div>

    <h3 class="mt-4">Financial (admin only)</h3>
    <div class="grid cols-2" style="grid-template-columns: repeat(auto-fit, minmax(170px, 1fr))">
        <div class="stat"><div class="muted">Payment volume</div><div class="num" style="color: var(--green)">{{ \App\Support\Money::formatMinor($financial['payments']['volume_minor']) }}</div></div>
        <div class="stat"><div class="muted">Successful payments</div><div class="num">{{ $financial['payments']['successful'] }}</div></div>
        <div class="stat"><div class="muted">Refunded</div><div class="num" style="color: var(--red)">{{ \App\Support\Money::formatMinor($financial['payments']['refunded_minor']) }}</div></div>
        <div class="stat"><div class="muted">Wallet balances</div><div class="num">{{ \App\Support\Money::formatMinor($financial['wallets']['balance_minor']) }}</div></div>
        <div class="stat"><div class="muted">Payouts completed</div><div class="num" style="color: var(--green)">{{ \App\Support\Money::formatMinor($financial['payouts']['completed_minor']) }}</div></div>
        <div class="stat"><div class="muted">Pending payouts</div><div class="num" style="color: var(--amber)">{{ $financial['payouts']['pending'] }}</div></div>
    </div>

    <h3 class="mt-4">Security (admin only)</h3>
    <div class="grid cols-2" style="grid-template-columns: repeat(auto-fit, minmax(170px, 1fr))">
        <div class="stat"><div class="muted">Accounts under review</div><div class="num" style="color: var(--purple)">{{ $security['accounts_under_review'] }}</div></div>
        <div class="stat"><div class="muted">Active restrictions</div><div class="num" style="color: var(--red)">{{ $security['restrictions']['active'] }}</div></div>
        <div class="stat"><div class="muted">Open anti-cheat</div><div class="num" style="color: var(--amber)">{{ $security['anti_cheat']['flagged'] + $security['anti_cheat']['under_review'] }}</div></div>
        <div class="stat"><div class="muted">Identity reviews</div><div class="num">{{ $security['identity_reviews'] }}</div></div>
    </div>

    <h3 class="mt-4">Support</h3>
    <div class="grid cols-2" style="grid-template-columns: repeat(auto-fit, minmax(170px, 1fr))">
        <div class="stat"><div class="muted">Open tickets</div><div class="num" style="color: var(--amber)">{{ $support['open'] }}</div></div>
        <div class="stat"><div class="muted">Pending</div><div class="num">{{ $support['pending'] }}</div></div>
        <div class="stat"><div class="muted">Resolved</div><div class="num" style="color: var(--green)">{{ $support['resolved'] }}</div></div>
    </div>
@endsection
