@extends('layouts.app')
@section('title','Ops Dashboard')
@section('content')
<h1>Ops Dashboard</h1>
<div class="grid grid-2" style="margin-top: 16px;">
<div class="card"><h3>Jobs</h3><p>Queued: {{ $jobs ?? 0 }}</p></div>
<div class="card"><h3>Failed Jobs</h3><p>Failed: {{ $failed ?? 0 }}</p><a href="{{ route('admin.ops.failed-jobs') }}" class="btn btn-secondary btn-sm">View Failed</a></div>
</div>
<div class="card" style="margin-top: 16px;"><h3>Internet & Health</h3><span data-internet-status class="internet-status online"></span> <span class="status-pill success">System OK</span></div>
@endsection
