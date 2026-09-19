@extends('layouts.app')
@section('title','Settlements')
@section('content')
<h1>Settlements</h1>
<div class="table-wrap"><table><thead><tr><th>ID</th><th>Tournament</th><th>Amount</th><th>Status</th></tr></thead><tbody>
@foreach($settlements as $s)
<tr><td>{{ $s->id }}</td><td>{{ $s->tournament_id }}</td><td>{{ number_format($s->total_amount_minor/100,2) }}</td><td><x-status-pill :status="$s->status" /></td></tr>
@endforeach
</tbody></table></div>
{{ $settlements->links('vendor.pagination.tailwind') }}
@endsection
