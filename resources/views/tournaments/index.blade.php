@extends('layouts.app')
@section('title','Tournaments')
@section('content')
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 12px;">
    <div><h1 style="margin: 0; font-size: 28px; font-weight: 800;">Tournaments</h1><p class="text-muted">Browse and join tournaments <span data-internet-status class="internet-status online"></span></p></div>
    @auth @if(auth()->user()->isAdmin())<a href="{{ route('admin.tournaments.create') }}" class="btn btn-primary">Create Tournament</a>@endif @endauth
</div>
@if($tournaments->count())
<div class="grid grid-3">
@foreach($tournaments as $tournament)
<div class="card card-hover"><h3 style="margin: 0 0 8px;">{{ $tournament->name }}</h3><div class="text-muted" style="font-size: 13px;">{{ $tournament->max_teams }} teams • {{ number_format($tournament->prize_pool_minor/100,2) }} BDT</div><div style="margin-top: 12px; display: flex; gap: 8px;"><x-status-pill :status="$tournament->status" /><span data-internet-status class="internet-status online" style="margin-left: auto;"></span></div><a href="{{ route('tournaments.show',$tournament) }}" class="btn btn-secondary btn-sm" style="width: 100%; margin-top: 12px;">View</a></div>
@endforeach
</div>
<div style="margin-top: 16px;">{{ $tournaments->links('vendor.pagination.tailwind') }}</div>
@else
<x-empty-state title="No tournaments" text="No tournaments available yet" />
@endif
@endsection
