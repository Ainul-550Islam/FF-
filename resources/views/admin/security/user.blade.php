@extends('layouts.app')
@section('title','Security User')
@section('content')
<div style="display: flex; gap: 16px; align-items: center;"><x-avatar :user="$user" size="lg" /><div><h1 style="margin: 0;">{{ $user->name }}</h1><p class="text-muted">{{ $user->email }}</p></div></div>
<div class="card" style="margin-top: 16px;"><p class="text-muted">User security profile, login history, device graph</p><span data-internet-status class="internet-status online"></span></div>
@endsection
