@extends('layouts.app')
@section('title', 'Login History — FF Arena')
@section('content')
    <header class="page-head">
        <h1 class="page-title">Login History</h1>
    </header>

    <section class="card" aria-labelledby="history-heading">
        <h3 id="history-heading" class="sr-only">Your recent security events</h3>
        @if ($events->isEmpty())
            <x-empty-state title="No security events recorded yet" icon="🔐">
                Login attempts and account changes will appear here.
            </x-empty-state>
        @else
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Recent login and security events</caption>
                    <thead>
                        <tr>
                            <th scope="col">Event</th>
                            <th scope="col">Status</th>
                            <th scope="col">Device</th>
                            <th scope="col">When</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($events as $event)
                            <tr>
                                <td>{{ ucwords(str_replace(['.', '_'], ' ', $event->event)) }}</td>
                                <td>
                                    <x-status-pill :status="$event->status === 'success' ? 'confirmed' : 'failed'" :label="$event->status" />
                                </td>
                                <td class="muted">{{ $event->device_label ?? '—' }}</td>
                                <td class="muted">{{ $event->created_at?->diffForHumans() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            {{ $events->links() }}
        @endif
    </section>
@endsection
