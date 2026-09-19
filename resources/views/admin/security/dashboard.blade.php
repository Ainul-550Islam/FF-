@extends('layouts.app')
@section('title','Security Dashboard')
@section('content')
<h1>Security Dashboard</h1>
<div class="grid grid-2" style="margin-top: 16px;">
<div class="card"><h3>Risk Events</h3><p class="text-muted">Recent risk detections</p></div>
<div class="card"><h3>Device & IP</h3><p class="text-muted">Device fingerprint, IP intel</p><span data-internet-status class="internet-status online"></span></div>
</div>
@endsection
