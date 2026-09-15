@extends('layouts.app')
@section('title', 'My Support Tickets — FF Arena')
@section('content')
    <header class="page-head">
        <div class="row-between">
            <h1 class="page-title">🎫 My Support Tickets</h1>
            <a href="{{ route('support.create') }}" class="btn btn-primary">New ticket</a>
        </div>
    </header>

    <section class="card" style="padding: 0" aria-labelledby="tickets-heading">
        <h2 id="tickets-heading" class="sr-only">Your support tickets</h2>
        <div class="table-wrap">
            <table>
                <caption class="sr-only">Your support tickets</caption>
                <thead>
                    <tr>
                        <th scope="col">ID</th>
                        <th scope="col">Subject</th>
                        <th scope="col">Category</th>
                        <th scope="col">Priority</th>
                        <th scope="col">Status</th>
                        <th scope="col">Updated</th>
                        <th scope="col"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($tickets as $ticket)
                        <tr>
                            <td class="muted">#{{ $ticket->id }}</td>
                            <td>{{ $ticket->subject }}</td>
                            <td class="muted">{{ $ticket->categoryLabel() }}</td>
                            <td><x-status-pill :status="$ticket->priority" :label="$ticket->priority" /></td>
                            <td><x-status-pill :status="$ticket->statusPill()" :label="$ticket->statusLabel()" /></td>
                            <td class="muted">{{ optional($ticket->last_activity_at)->diffForHumans() }}</td>
                            <td><a href="{{ route('support.tickets.show', $ticket) }}" class="btn btn-sm btn-cyan">View</a></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7">
                                <x-empty-state title="You have no support tickets yet" icon="🎫">
                                    Need help? Create a ticket and our team will get back to you.
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
