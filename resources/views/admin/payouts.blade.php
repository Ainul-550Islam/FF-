@extends('layouts.app')
@section('title','Payouts')
@section('content')
<h1>Payouts</h1>
<div class="table-wrap"><table><thead><tr><th>ID</th><th>User</th><th>Amount</th><th>Status</th></tr></thead><tbody>
@foreach($payouts as $p)
<tr><td>{{ $p->id }}</td><td>{{ $p->user_id }}</td><td>{{ number_format($p->amount_minor/100,2) }}</td><td><x-status-pill :status="$p->status" /></td></tr>
@endforeach
</tbody></table></div>
{{ $payouts->links('vendor.pagination.tailwind') }}
@endsection
