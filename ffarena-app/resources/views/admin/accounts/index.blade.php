@extends('layouts.app')
@section('title', 'Accounts — FF Arena')
@section('content')
    <header class="page-head">
        <h1 class="page-title">👥 Account Administration</h1>
    </header>

    <section class="card" aria-labelledby="filters-heading">
        <h2 id="filters-heading" class="sr-only">Account filters</h2>
        <form method="GET" action="{{ route('admin.accounts.index') }}">
            <div class="row" style="align-items: flex-end">
                <div class="field grow" style="min-width: 220px">
                    <label for="q">Search (name / username / email)</label>
                    <input type="text" id="q" name="q" value="{{ $q }}" placeholder="Search accounts…">
                </div>
                <div class="field">
                    <label for="role">Role</label>
                    <select id="role" name="role">
                        <option value="">All roles</option>
                        @foreach (['player', 'organizer', 'moderator', 'admin'] as $roleValue)
                            <option value="{{ $roleValue }}" @selected($roleValue === $role)>{{ ucfirst($roleValue) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="">All statuses</option>
                        @foreach (['active', 'deactivated', 'deletion_pending', 'deleted'] as $statusValue)
                            <option value="{{ $statusValue }}" @selected($statusValue === $status)>{{ ucwords(str_replace('_', ' ', $statusValue)) }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="btn btn-cyan btn-sm">Filter</button>
            </div>
        </form>
    </section>

    <section class="card" style="padding: 0" aria-labelledby="accounts-heading">
        <h2 id="accounts-heading" class="sr-only">Accounts</h2>
        <div class="table-wrap">
            <table>
                <caption class="sr-only">Accounts matching your filters</caption>
                <thead>
                    <tr>
                        <th scope="col">ID</th>
                        <th scope="col">Name</th>
                        <th scope="col">Username</th>
                        <th scope="col">Role</th>
                        <th scope="col">Status</th>
                        <th scope="col">Email verified</th>
                        <th scope="col"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($users as $user)
                        <tr>
                            <td class="muted">{{ $user->id }}</td>
                            <td>{{ $user->name }}</td>
                            <td class="muted">{{ $user->username }}</td>
                            <td><x-status-pill :status="$user->role === 'admin' ? 'live' : ($user->role === 'moderator' ? 'pending' : 'draft')" :label="$user->role" /></td>
                            <td><x-status-pill :status="$user->account_status === 'active' ? 'confirmed' : 'failed'" :label="$user->account_status" /></td>
                            <td>{{ $user->hasVerifiedEmail() ? '✅' : '—' }}</td>
                            <td><a href="{{ route('admin.accounts.show', $user) }}" class="btn btn-sm">View</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $users->links() }}</div>
    </section>
@endsection
