@extends('layouts.app')
@section('title','Team')
@section('content')
<h1>Team {{ $team->name ?? $team->id }}</h1>
<div class="card"><p class="text-muted">Team details, roster, avatar group</p><div class="avatar-group"><span class="avatar avatar-sm"><span class="avatar-fallback">A</span></span><span class="avatar avatar-sm"><span class="avatar-fallback">B</span></span></div><span data-internet-status class="internet-status online"></span></div>
@endsection
