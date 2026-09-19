@extends('layouts.app')
@section('title','Notifications')
@section('content')
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;"><h1 style="margin: 0; font-size: 28px; font-weight: 800;">Notifications</h1><span data-internet-status class="internet-status online"></span></div>
@if($notifications->count())
<div style="display: grid; gap: 12px;">
@foreach($notifications as $n)
<div class="card" style="padding: 16px;"><div style="font-weight: 600;">{{ $n->data['title'] ?? 'Notification' }}</div><div class="text-muted" style="font-size: 13px;">{{ $n->data['message'] ?? '' }}</div><div class="text-muted" style="font-size: 11px; margin-top: 4px;">{{ $n->created_at->diffForHumans() }}</div></div>
@endforeach
</div>
{{ $notifications->links('vendor.pagination.tailwind') }}
@else
<x-empty-state title="No notifications" text="You're all caught up" />
@endif
@endsection
