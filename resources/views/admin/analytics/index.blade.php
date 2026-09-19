@extends('layouts.app')
@section('title','Analytics')
@section('content')
<h1>Analytics Dashboard</h1>
<div class="grid grid-3" style="margin-top: 16px;">
<a href="{{ route('admin.analytics.tournaments') }}" class="card card-hover"><h3>Tournaments</h3><p class="text-muted" style="font-size: 13px;">Tournament analytics</p></a>
<a href="{{ route('admin.analytics.financial') }}" class="card card-hover"><h3>Financial</h3><p class="text-muted" style="font-size: 13px;">Revenue, payouts</p></a>
<a href="{{ route('admin.analytics.security') }}" class="card card-hover"><h3>Security</h3><p class="text-muted" style="font-size: 13px;">Fraud, risk</p></a>
<a href="{{ route('admin.analytics.disputes') }}" class="card card-hover"><h3>Disputes</h3><p class="text-muted" style="font-size: 13px;">Dispute trends</p></a>
<a href="{{ route('admin.analytics.support') }}" class="card card-hover"><h3>Support</h3><p class="text-muted" style="font-size: 13px;">Support tickets</p></a>
</div>
@endsection
