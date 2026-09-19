@extends('layouts.app')
@section('title','Payments')
@section('content')
<h1>Payments</h1>
<div class="table-wrap"><table><thead><tr><th>ID</th><th>User</th><th>Provider</th><th>Amount</th><th>Status</th></tr></thead><tbody>
@foreach($payments as $p)
<tr><td>{{ $p->id }}</td><td>{{ $p->user_id }}</td><td>{{ $p->provider }}</td><td>{{ number_format($p->amount_minor/100,2) }}</td><td><x-status-pill :status="$p->status" /></td></tr>
@endforeach
</tbody></table></div>
{{ $payments->links('vendor.pagination.tailwind') }}
@endsection
