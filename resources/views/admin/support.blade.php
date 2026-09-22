@extends('layouts.app')
@section('title', 'Support Queue — FF Arena')
@section('content')
    <header class="page-head">
        <h1 class="page-title">🎫 Support Queue</h1>
    </header>

    <section class="card" aria-labelledby="filters-heading">
        <h2 id="filters-heading" class="sr-only">Support queue filters</h2>
        <form method="GET" action="{{ route('admin.support.index') }}">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; align-items: end">
                <div class="field">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="">All</option>
                        @foreach (\App\Models\SupportTicket::STATUSES as $status)
                            <option value="{{ $status }}" {{ ($filters['status'] ?? '') === $status ? 'selected' : '' }}>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="priority">Priority</label>
                    <select id="priority" name="priority">
                        <option value="">All</option>
                        @foreach (\App\Models\SupportTicket::PRIORITIES as $priority)
                            <option value="{{ $priority }}" {{ ($filters['priority'] ?? '') === $priority ? 'selected' : '' }}>{{ ucfirst($priority) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="category">Category</label>
                    <select id="category" name="category">
                        <option value="">All</option>
                        @foreach (\App\Models\SupportTicket::CATEGORIES as $category)
                            <option value="{{ $category }}" {{ ($filters['category'] ?? '') === $category ? 'selected' : '' }}>{{ ucfirst($category) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="assigned_to">Assignee</label>
                    <select id="assigned_to" name="assigned_to">
                        <option value="">All</option>
                        @foreach ($staff as $member)
                            <option value="{{ $member->id }}" {{ (int) ($filters['assigned_to'] ?? 0) === $member->id ? 'selected' : '' }}>{{ $member->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="row" style="gap: 8px">
                    <button type="submit" class="btn btn-cyan btn-sm">Filter</button>
                    <a href="{{ route('admin.support.index') }}" class="btn btn-sm">Reset</a>
                    <a href="{{ route('admin.support.export', request()->query()) }}" class="btn btn-sm btn-green">⬇ CSV</a>
                </div>
            </div>
        </form>
    </section>

    <section class="card" style="padding: 0" aria-labelledby="tickets-heading">
        <h2 id="tickets-heading" class="sr-only">Support tickets</h2>
        <div class="table-wrap">
            <table>
                <caption class="sr-only">Support tickets matching your filters</caption>
                <thead>
                    <tr>
                        <th scope="col">ID</th>
                        <th scope="col">Subject</th>
                        <th scope="col">Requester</th>
                        <th scope="col">Category</th>
                        <th scope="col">Priority</th>
                        <th scope="col">Status</th>
                        <th scope="col">Assignee</th>
                        <th scope="col">Updated</th>
                        <th scope="col"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($tickets as $ticket)
                        <tr>
                            <td class="muted">#{{ $ticket->id }}</td>
                            <td>{{ $ticket->subject }}</td>
                            <td>{{ $ticket->user?->name ?? '—' }}</td>
                            <td class="muted">{{ $ticket->categoryLabel() }}</td>
                            <td><x-status-pill :status="$ticket->priority" :label="$ticket->priority" /></td>
                            <td><x-status-pill :status="$ticket->statusPill()" :label="$ticket->statusLabel()" /></td>
                            <td class="muted">{{ $ticket->assignee?->name ?? '—' }}</td>
                            <td class="muted">{{ optional($ticket->last_activity_at)->diffForHumans() }}</td>
                            <td><a href="{{ route('admin.support.show', $ticket) }}" class="btn btn-sm btn-cyan">Open</a></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9">
                                <x-empty-state title="No tickets match these filters" icon="🎫">
                                    Adjust the filters and try again.
                                </x-empty-state>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
    <div class="mt-4">{{ $tickets->links() }}</div>
@endsection
