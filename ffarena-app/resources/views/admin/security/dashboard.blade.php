@extends('layouts.app')
@section('title', 'Security — FF Arena Admin')
@section('content')
    <header class="page-head">
        <h1 class="page-title">🛡 Security Dashboard</h1>
    </header>

    <nav class="card row" aria-label="Security navigation">
        <a href="{{ route('admin.security.users') }}" class="btn btn-sm">Suspicious Users</a>
        <a href="{{ route('admin.security.events') }}" class="btn btn-sm">Risk Events</a>
        <a href="{{ route('security.incidents.index') }}" class="btn btn-sm btn-cyan">Anti-cheat Incidents</a>
    </nav>

    <div class="grid cols-2" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr))">
        <div class="stat"><div class="muted">Critical risk</div><div class="num" style="color: var(--red)">{{ $stats['critical'] }}</div></div>
        <div class="stat"><div class="muted">High risk</div><div class="num" style="color: var(--amber)">{{ $stats['high'] }}</div></div>
        <div class="stat"><div class="muted">Medium risk</div><div class="num">{{ $stats['medium'] }}</div></div>
        <div class="stat"><div class="muted">Manual review required</div><div class="num" style="color: var(--purple)">{{ $stats['review_required'] }}</div></div>
        <div class="stat"><div class="muted">Active restrictions</div><div class="num" style="color: var(--red)">{{ $stats['active_restrictions'] }}</div></div>
        <div class="stat"><div class="muted">Open incidents</div><div class="num" style="color: var(--amber)">{{ $stats['open_incidents'] }}</div></div>
        <div class="stat"><div class="muted">Match anomalies</div><div class="num">{{ $stats['anomalies'] }}</div></div>
        <div class="stat"><div class="muted">Risk events</div><div class="num">{{ $stats['events'] }}</div></div>
    </div>

    <div class="grid cols-2 mt-4">
        <section class="card" aria-labelledby="review-queue-heading">
            <h3 id="review-queue-heading">🔍 Review Queue</h3>
            @if ($reviewQueue->isEmpty())
                <p class="muted">No accounts flagged for review.</p>
            @else
                <div class="table-wrap">
                    <table>
                        <caption class="sr-only">Accounts flagged for review</caption>
                        <thead>
                            <tr>
                                <th scope="col">User</th>
                                <th scope="col">Risk</th>
                                <th scope="col">Score</th>
                                <th scope="col"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($reviewQueue as $profile)
                                <tr>
                                    <td>{{ $profile->user?->name ?? '—' }}</td>
                                    <td><x-status-pill :status="$profile->levelPill()" :label="strtoupper($profile->risk_level)" /></td>
                                    <td>{{ $profile->risk_score }}/100</td>
                                    <td>
                                        @if ($profile->user)
                                            <a class="btn btn-sm" href="{{ route('admin.security.user', $profile->user) }}">Investigate</a>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="card" aria-labelledby="recent-events-heading">
            <h3 id="recent-events-heading">📜 Recent Risk Events</h3>
            @if ($recentEvents->isEmpty())
                <p class="muted">No risk events yet.</p>
            @else
                <div class="table-wrap">
                    <table>
                        <caption class="sr-only">Recent risk events</caption>
                        <thead>
                            <tr>
                                <th scope="col">User</th>
                                <th scope="col">Type</th>
                                <th scope="col">Severity</th>
                                <th scope="col">When</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($recentEvents as $event)
                                <tr>
                                    <td>{{ $event->user?->name ?? '—' }}</td>
                                    <td class="muted" style="font-size: .8rem">{{ $event->type }}</td>
                                    <td><x-status-pill :status="$event->severity === 'critical' || $event->severity === 'high' ? 'failed' : ($event->severity === 'medium' ? 'pending' : 'draft')" :label="strtoupper($event->severity)" /></td>
                                    <td class="muted" style="font-size: .8rem">{{ $event->created_at?->format('d M, h:i A') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
@endsection
