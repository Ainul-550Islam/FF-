@extends('layouts.app')
@section('title', 'Dispute Analytics — FF Arena')
@section('content')
    <header class="page-head">
        <h1 class="page-title">⚖️ Dispute Analytics</h1>
    </header>

    <div class="grid cols-2" style="grid-template-columns: repeat(auto-fit, minmax(170px, 1fr))">
        <div class="stat"><div class="muted">Open</div><div class="num" style="color: var(--amber)">{{ $metrics['open'] }}</div></div>
        <div class="stat"><div class="muted">Resolved</div><div class="num" style="color: var(--green)">{{ $metrics['resolved'] }}</div></div>
        <div class="stat"><div class="muted">Rejected</div><div class="num" style="color: var(--red)">{{ $metrics['rejected'] }}</div></div>
        <div class="stat"><div class="muted">Cancelled</div><div class="num">{{ $metrics['cancelled'] }}</div></div>
        <div class="stat"><div class="muted">Avg resolution</div><div class="num">{{ $metrics['avg_resolution_seconds'] !== null ? round($metrics['avg_resolution_seconds'] / 3600, 1) . 'h' : '—' }}</div></div>
        <div class="stat"><div class="muted">Evidence items</div><div class="num">{{ $metrics['evidence_volume'] }}</div></div>
    </div>

    <section class="card mt-4" style="padding: 0" aria-labelledby="by-category-heading">
        <h3 id="by-category-heading">Open by category</h3>
        <div class="table-wrap">
            <table>
                <caption class="sr-only">Open disputes by category</caption>
                <thead>
                    <tr>
                        <th scope="col">Category</th>
                        <th scope="col">Open</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($metrics['by_category_open'] as $category => $count)
                        <tr><td>{{ ucwords(str_replace('_', ' ', $category)) }}</td><td>{{ $count }}</td></tr>
                    @empty
                        <tr><td colspan="2" class="muted">No open disputes.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="card mt-4" style="padding: 0" aria-labelledby="workload-heading">
        <h3 id="workload-heading">Moderator workload</h3>
        <div class="table-wrap">
            <table>
                <caption class="sr-only">Moderator workload</caption>
                <thead>
                    <tr>
                        <th scope="col">Staff</th>
                        <th scope="col">Open disputes</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($metrics['moderator_workload'] as $row)
                        <tr><td>{{ $row['staff'] }}</td><td>{{ $row['open'] }}</td></tr>
                    @empty
                        <tr><td colspan="2" class="muted">No open assignments.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
