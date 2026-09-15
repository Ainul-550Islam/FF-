@extends('layouts.app')
@section('title', 'Security Analytics — FF Arena Admin')
@section('content')
    <header class="page-head">
        <h1 class="page-title">🛡 Security Analytics</h1>
    </header>

    <h3 class="mt-4">Risk levels</h3>
    <div class="grid cols-2" style="grid-template-columns: repeat(auto-fit, minmax(170px, 1fr))">
        <div class="stat"><div class="muted">Under review</div><div class="num" style="color: var(--purple)">{{ $metrics['accounts_under_review'] }}</div></div>
        <div class="stat"><div class="muted">Low</div><div class="num">{{ $metrics['risk_levels']['low'] }}</div></div>
        <div class="stat"><div class="muted">Medium</div><div class="num">{{ $metrics['risk_levels']['medium'] }}</div></div>
        <div class="stat"><div class="muted">High</div><div class="num" style="color: var(--amber)">{{ $metrics['risk_levels']['high'] }}</div></div>
        <div class="stat"><div class="muted">Critical</div><div class="num" style="color: var(--red)">{{ $metrics['risk_levels']['critical'] }}</div></div>
    </div>

    <h3 class="mt-4">Restrictions</h3>
    <div class="grid cols-2" style="grid-template-columns: repeat(auto-fit, minmax(170px, 1fr))">
        <div class="stat"><div class="muted">Active</div><div class="num" style="color: var(--red)">{{ $metrics['restrictions']['active'] }}</div></div>
        <div class="stat"><div class="muted">Lifted</div><div class="num">{{ $metrics['restrictions']['lifted'] }}</div></div>
    </div>

    <h3 class="mt-4">Anti-cheat incidents</h3>
    <div class="grid cols-2" style="grid-template-columns: repeat(auto-fit, minmax(170px, 1fr))">
        <div class="stat"><div class="muted">Flagged</div><div class="num" style="color: var(--amber)">{{ $metrics['anti_cheat']['flagged'] }}</div></div>
        <div class="stat"><div class="muted">Under review</div><div class="num">{{ $metrics['anti_cheat']['under_review'] }}</div></div>
        <div class="stat"><div class="muted">Confirmed</div><div class="num" style="color: var(--red)">{{ $metrics['anti_cheat']['confirmed'] }}</div></div>
        <div class="stat"><div class="muted">Restricted</div><div class="num" style="color: var(--red)">{{ $metrics['anti_cheat']['restricted'] }}</div></div>
        <div class="stat"><div class="muted">Cleared</div><div class="num" style="color: var(--green)">{{ $metrics['anti_cheat']['cleared'] }}</div></div>
    </div>

    <h3 class="mt-4">Reviews</h3>
    <div class="grid cols-2" style="grid-template-columns: repeat(auto-fit, minmax(170px, 1fr))">
        <div class="stat"><div class="muted">Ban-evasion reviews</div><div class="num">{{ $metrics['ban_evasion_reviews'] }}</div></div>
        <div class="stat"><div class="muted">Identity reviews</div><div class="num">{{ $metrics['identity_reviews'] }}</div></div>
    </div>
@endsection
