@extends('layouts.app')
@section('title', 'Support Analytics — FF Arena')
@section('content')
    <header class="page-head">
        <h1 class="page-title">🎫 Support Analytics</h1>
    </header>

    <div class="grid cols-2" style="grid-template-columns: repeat(auto-fit, minmax(170px, 1fr))">
        <div class="stat"><div class="muted">Open</div><div class="num" style="color: var(--amber)">{{ $metrics['open'] }}</div></div>
        <div class="stat"><div class="muted">Pending</div><div class="num">{{ $metrics['pending'] }}</div></div>
        <div class="stat"><div class="muted">Resolved</div><div class="num" style="color: var(--green)">{{ $metrics['resolved'] }}</div></div>
        <div class="stat"><div class="muted">Closed</div><div class="num">{{ $metrics['closed'] }}</div></div>
        <div class="stat"><div class="muted">Reopened</div><div class="num" style="color: var(--amber)">{{ $metrics['reopened'] }}</div></div>
        <div class="stat"><div class="muted">Avg resolution</div><div class="num">{{ $metrics['avg_resolution_seconds'] !== null ? round($metrics['avg_resolution_seconds'] / 3600, 1) . 'h' : '—' }}</div></div>
    </div>

    <section class="card mt-4" style="padding: 0" aria-labelledby="by-category-heading">
        <h3 id="by-category-heading">By category</h3>
        <div class="table-wrap">
            <table>
                <caption class="sr-only">Tickets by category</caption>
                <thead>
                    <tr>
                        <th scope="col">Category</th>
                        <th scope="col">Tickets</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($metrics['by_category'] as $category => $count)
                        <tr><td>{{ ucfirst($category) }}</td><td>{{ $count }}</td></tr>
                    @empty
                        <tr><td colspan="2" class="muted">No tickets.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="card mt-4" style="padding: 0" aria-labelledby="by-priority-heading">
        <h3 id="by-priority-heading">Open by priority</h3>
        <div class="table-wrap">
            <table>
                <caption class="sr-only">Open tickets by priority</caption>
                <thead>
                    <tr>
                        <th scope="col">Priority</th>
                        <th scope="col">Open</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($metrics['by_priority_open'] as $priority => $count)
                        <tr><td>{{ ucfirst($priority) }}</td><td>{{ $count }}</td></tr>
                    @empty
                        <tr><td colspan="2" class="muted">No open tickets.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="card mt-4" style="padding: 0" aria-labelledby="staff-workload-heading">
        <h3 id="staff-workload-heading">Staff workload</h3>
        <div class="table-wrap">
            <table>
                <caption class="sr-only">Staff workload</caption>
                <thead>
                    <tr>
                        <th scope="col">Staff</th>
                        <th scope="col">Open tickets</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($metrics['staff_workload'] as $row)
                        <tr><td>{{ $row['staff'] }}</td><td>{{ $row['open'] }}</td></tr>
                    @empty
                        <tr><td colspan="2" class="muted">No assignments.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
