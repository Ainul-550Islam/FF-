@extends('layouts.app')
@section('title', 'Financial Analytics — FF Arena Admin')
@section('content')
    <header class="page-head">
        <h1 class="page-title">💸 Financial Analytics</h1>
    </header>

    <h3 class="mt-4">Payments</h3>
    <div class="grid cols-2" style="grid-template-columns: repeat(auto-fit, minmax(170px, 1fr))">
        <div class="stat"><div class="muted">Volume</div><div class="num" style="color: var(--green)">{{ \App\Support\Money::formatMinor($metrics['payments']['volume_minor']) }}</div></div>
        <div class="stat"><div class="muted">Successful</div><div class="num">{{ $metrics['payments']['successful'] }}</div></div>
        <div class="stat"><div class="muted">Failed</div><div class="num" style="color: var(--red)">{{ $metrics['payments']['failed'] }}</div></div>
        <div class="stat"><div class="muted">Refunded</div><div class="num" style="color: var(--amber)">{{ \App\Support\Money::formatMinor($metrics['payments']['refunded_minor']) }}</div></div>
    </div>

    <h3 class="mt-4">Wallets</h3>
    <div class="grid cols-2" style="grid-template-columns: repeat(auto-fit, minmax(170px, 1fr))">
        <div class="stat"><div class="muted">Total balance</div><div class="num">{{ \App\Support\Money::formatMinor($metrics['wallets']['balance_minor']) }}</div></div>
        <div class="stat"><div class="muted">Credits</div><div class="num" style="color: var(--green)">{{ \App\Support\Money::formatMinor($metrics['wallets']['credits_minor']) }}</div></div>
        <div class="stat"><div class="muted">Debits</div><div class="num" style="color: var(--red)">{{ \App\Support\Money::formatMinor($metrics['wallets']['debits_minor']) }}</div></div>
    </div>

    <h3 class="mt-4">Prize pools</h3>
    <div class="grid cols-2" style="grid-template-columns: repeat(auto-fit, minmax(170px, 1fr))">
        <div class="stat"><div class="muted">Pool (calculated)</div><div class="num">{{ \App\Support\Money::formatMinor($metrics['prizes']['pool_minor']) }}</div></div>
        <div class="stat"><div class="muted">Allocated</div><div class="num">{{ \App\Support\Money::formatMinor($metrics['prizes']['allocated_minor']) }}</div></div>
    </div>

    <h3 class="mt-4">Payouts</h3>
    <div class="grid cols-2" style="grid-template-columns: repeat(auto-fit, minmax(170px, 1fr))">
        <div class="stat"><div class="muted">Completed</div><div class="num" style="color: var(--green)">{{ \App\Support\Money::formatMinor($metrics['payouts']['completed_minor']) }}</div></div>
        <div class="stat"><div class="muted">Completed count</div><div class="num">{{ $metrics['payouts']['completed'] }}</div></div>
        <div class="stat"><div class="muted">Pending</div><div class="num" style="color: var(--amber)">{{ $metrics['payouts']['pending'] }}</div></div>
        <div class="stat"><div class="muted">Pending amount</div><div class="num">{{ \App\Support\Money::formatMinor($metrics['payouts']['pending_minor']) }}</div></div>
    </div>

    <h3 class="mt-4">Settlements</h3>
    <div class="grid cols-2" style="grid-template-columns: repeat(auto-fit, minmax(170px, 1fr))">
        <div class="stat"><div class="muted">Balanced</div><div class="num" style="color: var(--green)">{{ $metrics['settlements']['balanced'] }}</div></div>
        <div class="stat"><div class="muted">Underfunded</div><div class="num" style="color: var(--amber)">{{ $metrics['settlements']['underfunded'] }}</div></div>
        <div class="stat"><div class="muted">Overallocated</div><div class="num" style="color: var(--amber)">{{ $metrics['settlements']['overallocated'] }}</div></div>
        <div class="stat"><div class="muted">Mismatch</div><div class="num" style="color: var(--red)">{{ $metrics['settlements']['mismatch'] }}</div></div>
        <div class="stat"><div class="muted">Exceptions</div><div class="num" style="color: var(--red)">{{ $metrics['settlements']['exceptions'] }}</div></div>
    </div>
@endsection
