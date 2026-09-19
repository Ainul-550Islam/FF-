@extends('layouts.app')
@section('title','Admin Dashboard')
@section('content')
<h1 style="font-size: 28px; font-weight: 800;">Admin Dashboard</h1>
<div class="grid grid-4" style="margin-top: 16px;">
<div class="card"><div class="text-muted" style="font-size: 12px; text-transform: uppercase;">Users</div><div style="font-size: 24px; font-weight: 800;">{{ $stats['users'] ?? 0 }}</div></div>
<div class="card"><div class="text-muted" style="font-size: 12px; text-transform: uppercase;">Tournaments</div><div style="font-size: 24px; font-weight: 800;">{{ $stats['tournaments'] ?? 0 }}</div></div>
<div class="card"><div class="text-muted" style="font-size: 12px; text-transform: uppercase;">Payments</div><div style="font-size: 24px; font-weight: 800;">{{ $stats['payments'] ?? 0 }}</div></div>
<div class="card"><div class="text-muted" style="font-size: 12px; text-transform: uppercase;">Payouts</div><div style="font-size: 24px; font-weight: 800;">{{ $stats['payouts'] ?? 0 }}</div></div>
</div>
<div class="grid grid-2" style="margin-top: 24px;">
<div class="card"><h3>Quick Links</h3><div style="display: grid; gap: 8px; margin-top: 12px;"><a href="{{ route('admin.accounts.index') }}" class="btn btn-secondary btn-sm">Accounts</a><a href="{{ route('admin.payments') }}" class="btn btn-secondary btn-sm">Payments</a><a href="{{ route('admin.analytics.index') }}" class="btn btn-secondary btn-sm">Analytics</a><a href="{{ route('admin.ops.dashboard') }}" class="btn btn-secondary btn-sm">Ops</a></div></div>
<div class="card"><h3>System Status</h3><div style="margin-top: 12px; display: flex; gap: 8px; flex-wrap: wrap;"><span data-internet-status class="internet-status online"></span><span class="status-pill success">DB OK</span><span class="status-pill success">Cache OK</span></div><p class="text-muted" style="font-size: 13px; margin-top: 12px;">Internet check active - admin actions require connectivity for safety</p></div>
</div>
@endsection
