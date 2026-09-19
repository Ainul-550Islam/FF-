@extends('layouts.app')
@section('title',$tournament->name)
@section('content')
<div class="card" style="margin-bottom: 24px;">
    <div style="display: flex; justify-content: space-between; gap: 16px; flex-wrap: wrap;">
        <div><h1 style="margin: 0 0 8px; font-size: 28px; font-weight: 800;">{{ $tournament->name }}</h1><p class="text-muted">{{ $tournament->slug }} • <x-status-pill :status="$tournament->status" /> <span data-internet-status class="internet-status online" style="margin-left: 8px;"></span></p></div>
        <div style="display: flex; gap: 8px;"><a href="{{ route('leaderboard.show',$tournament) }}" class="btn btn-secondary">Leaderboard</a><a href="{{ route('teams.register.form',$tournament) }}" class="btn btn-primary" data-require-online>Register Team</a></div>
    </div>
    <div class="grid grid-3" style="margin-top: 24px;">
        <div><div class="text-muted" style="font-size: 12px; text-transform: uppercase;">Prize Pool</div><div style="font-size: 20px; font-weight: 800;">{{ number_format($tournament->prize_pool_minor/100,2) }} BDT</div></div>
        <div><div class="text-muted" style="font-size: 12px; text-transform: uppercase;">Entry Fee</div><div style="font-size: 20px; font-weight: 800;">{{ number_format($tournament->entry_fee_minor/100,2) }} BDT</div></div>
        <div><div class="text-muted" style="font-size: 12px; text-transform: uppercase;">Max Teams</div><div style="font-size: 20px; font-weight: 800;">{{ $tournament->max_teams }}</div></div>
    </div>
</div>
<div class="grid grid-2">
    <div class="card"><h3 style="margin: 0 0 12px;">Details</h3><p class="text-muted" style="font-size: 14px;">Tournament details, rules, and bracket will appear here. Internet check ensures registration is safe.</p><div style="margin-top: 12px; display: flex; gap: 8px;"><span data-internet-status class="internet-status online"></span><button onclick="window.FFArena?.checkInternet()" class="btn btn-ghost btn-sm">Check Connection</button></div></div>
    <div class="card"><h3 style="margin: 0 0 12px;">Live Updates</h3><div id="live-poll" class="text-muted" style="font-size: 13px;">Live poll active - internet required for real-time updates</div></div>
</div>
@endsection
