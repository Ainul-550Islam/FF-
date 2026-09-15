@extends('layouts.app')
@section('title', $tournament->name . ' Analytics — FF Arena')
@section('content')
    <header class="page-head">
        <h1 class="page-title">📈 {{ $tournament->name }}</h1>
        <p class="muted">Operational metrics — {{ strtoupper($tournament->status) }}</p>
    </header>

    <h3 class="mt-4">Registration</h3>
    <div class="grid cols-2" style="grid-template-columns: repeat(auto-fit, minmax(170px, 1fr))">
        <div class="stat"><div class="muted">Teams</div><div class="num">{{ $metrics['teams']['total'] }}</div></div>
        <div class="stat"><div class="muted">Confirmed</div><div class="num" style="color: var(--green)">{{ $metrics['teams']['confirmed'] }}</div></div>
        <div class="stat"><div class="muted">Waitlisted</div><div class="num" style="color: var(--amber)">{{ $metrics['teams']['waitlisted'] }}</div></div>
        <div class="stat"><div class="muted">Withdrawn</div><div class="num">{{ $metrics['teams']['withdrawn'] }}</div></div>
        <div class="stat"><div class="muted">No-shows</div><div class="num" style="color: var(--red)">{{ $metrics['teams']['no_show'] }}</div></div>
        <div class="stat"><div class="muted">Checked in</div><div class="num" style="color: var(--green)">{{ $metrics['teams']['checked_in'] }}</div></div>
    </div>

    <h3 class="mt-4">Rates</h3>
    <div class="grid cols-2" style="grid-template-columns: repeat(auto-fit, minmax(170px, 1fr))">
        <div class="stat"><div class="muted">Check-in rate</div><div class="num">{{ $metrics['rates']['check_in'] }}%</div></div>
        <div class="stat"><div class="muted">No-show rate</div><div class="num" style="color: var(--red)">{{ $metrics['rates']['no_show'] }}%</div></div>
        <div class="stat"><div class="muted">Match completion</div><div class="num">{{ $metrics['rates']['match_completion'] }}%</div></div>
    </div>

    <h3 class="mt-4">Matches</h3>
    <div class="grid cols-2" style="grid-template-columns: repeat(auto-fit, minmax(170px, 1fr))">
        <div class="stat"><div class="muted">Total</div><div class="num">{{ $metrics['matches']['total'] }}</div></div>
        <div class="stat"><div class="muted">Completed</div><div class="num" style="color: var(--green)">{{ $metrics['matches']['completed'] }}</div></div>
        <div class="stat"><div class="muted">Live</div><div class="num" style="color: var(--cyan)">{{ $metrics['matches']['live'] }}</div></div>
        <div class="stat"><div class="muted">Disputed</div><div class="num" style="color: var(--red)">{{ $metrics['matches']['disputed'] }}</div></div>
    </div>
@endsection
