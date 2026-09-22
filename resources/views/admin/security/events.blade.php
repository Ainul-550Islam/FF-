@extends('layouts.app')
@section('title', 'Risk Events — FF Arena Admin')
@section('content')
    <header class="page-head">
        <h1 class="page-title">📜 Risk Events</h1>
    </header>

    <section class="card" aria-labelledby="filters-heading">
        <h2 id="filters-heading" class="sr-only">Risk event filters</h2>
        <form method="GET" action="{{ route('admin.security.events') }}">
            <div class="row" style="align-items: flex-end">
                <div class="field" style="min-width: 160px">
                    <label for="severity">Severity</label>
                    <select id="severity" name="severity">
                        <option value="">All severities</option>
                        @foreach (\App\Models\RiskEvent::SEVERITIES as $s)
                            <option value="{{ $s }}" @selected($severity === $s)>{{ ucfirst($s) }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="btn btn-sm btn-cyan">Filter</button>
            </div>
        </form>
    </section>

    <section class="card" style="padding: 0" aria-labelledby="events-heading">
        <h2 id="events-heading" class="sr-only">Risk events</h2>
        @if ($events->isEmpty())
            <x-empty-state title="No risk events match your filters" icon="📜">
                Adjust the filters and try again.
            </x-empty-state>
        @else
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Risk events matching your filters</caption>
                    <thead>
                        <tr>
                            <th scope="col">#</th>
                            <th scope="col">User</th>
                            <th scope="col">Type</th>
                            <th scope="col">Severity</th>
                            <th scope="col">Score</th>
                            <th scope="col">Source</th>
                            <th scope="col">Tournament</th>
                            <th scope="col">When</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($events as $event)
                            <tr>
                                <td><strong>#{{ $event->id }}</strong></td>
                                <td>{{ $event->user?->name ?? '—' }}</td>
                                <td class="muted" style="font-size: .8rem">{{ $event->type }}</td>
                                <td><x-status-pill :status="in_array($event->severity, ['high', 'critical'], true) ? 'failed' : ($event->severity === 'medium' ? 'pending' : 'draft')" :label="strtoupper($event->severity)" /></td>
                                <td>+{{ $event->score_contribution }}</td>
                                <td class="muted" style="font-size: .8rem">{{ $event->source }}</td>
                                <td class="muted" style="font-size: .8rem">{{ $event->tournament?->name ?? '—' }}</td>
                                <td class="muted" style="font-size: .8rem">{{ $event->created_at?->format('d M, h:i A') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $events->links() }}</div>
        @endif
    </section>
@endsection
