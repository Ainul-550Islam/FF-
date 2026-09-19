@extends('layouts.app')
@section('title','Accounts')
@section('content')
<h1>Accounts</h1>
<div class="table-wrap" style="margin-top: 16px;"><table><thead><tr><th>ID</th><th>Avatar</th><th>Name</th><th>Email</th><th>Status</th><th>Action</th></tr></thead><tbody>
@foreach($users as $user)
<tr><td>{{ $user->id }}</td><td><x-avatar :user="$user" size="sm" /></td><td>{{ $user->display_name_or_name }}</td><td>{{ $user->email }}</td><td><x-status-pill :status="$user->is_active ? 'active' : 'banned'" /></td><td><a href="{{ route('admin.accounts.show',$user) }}" class="btn btn-ghost btn-sm">View</a></td></tr>
@endforeach
</tbody></table></div>
{{ $users->links('vendor.pagination.tailwind') }}
@endsection
