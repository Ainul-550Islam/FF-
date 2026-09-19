@extends('layouts.app')
@section('title','Failed Jobs')
@section('content')
<h1>Failed Jobs</h1>
<div class="table-wrap"><table><thead><tr><th>UUID</th><th>Queue</th><th>Failed At</th></tr></thead><tbody>
@foreach($jobs as $job)
<tr><td class="font-mono" style="font-size: 12px;">{{ $job->uuid }}</td><td>{{ $job->queue }}</td><td>{{ $job->failed_at }}</td></tr>
@endforeach
</tbody></table></div>
{{ $jobs->links('vendor.pagination.tailwind') }}
@endsection
