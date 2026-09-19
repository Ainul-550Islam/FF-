@extends('layouts.app')
@section('title','Security Users')
@section('content')
<h1>Security - Users</h1>
<div class="table-wrap"><table><thead><tr><th>ID</th><th>Avatar</th><th>Name</th><th>Risk</th></tr></thead><tbody>
@foreach($users as $user)
<tr><td>{{ $user->id }}</td><td><x-avatar :user="$user" size="sm" /></td><td>{{ $user->name }}</td><td><x-status-pill status="info" label="Low" /></td></tr>
@endforeach
</tbody></table></div>
{{ $users->links('vendor.pagination.tailwind') }}
@endsection
