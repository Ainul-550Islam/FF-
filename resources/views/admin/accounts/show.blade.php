@extends('layouts.app')
@section('title','User Details')
@section('content')
<div style="display: flex; gap: 24px; align-items: flex-start; margin-bottom: 24px;">
<x-avatar :user="$user" size="xl" />
<div><h1 style="margin: 0;">{{ $user->display_name_or_name }}</h1><p class="text-muted">{{ $user->email }} • @{{ $user->username ?? 'no username' }}</p><div style="margin-top: 8px;"><x-status-pill :status="$user->is_active ? 'active' : 'banned'" /> @if($user->is_admin) <x-status-pill status="info" label="Admin" /> @endif</div></div>
</div>
<div class="grid grid-2">
<div class="card"><h3>Profile</h3><div style="margin-top: 12px; display: grid; gap: 8px; font-size: 14px;"><div>Bio: {{ $user->bio ?? '—' }}</div><div>Phone: {{ $user->phone ?? '—' }}</div><div>Country: {{ $user->country ?? '—' }}</div><div>Avatar: {{ $user->hasAvatar() ? 'Yes' : 'No' }} • Path: <span class="font-mono" style="font-size: 11px;">{{ $user->avatar_path ?? 'none' }}</span></div></div></div>
<div class="card"><h3>Security</h3><p class="text-muted" style="font-size: 13px;">Login history, sessions, connected accounts</p><div style="margin-top: 12px;"><span data-internet-status class="internet-status online"></span></div></div>
</div>
@endsection
