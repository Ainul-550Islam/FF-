@extends('layouts.app')
@section('title', 'Security Review — FF Arena')
@section('content')
    <header class="page-head">
        <h1 class="page-title">🛡 Security Review</h1>
    </header>

    <section class="card" aria-labelledby="flagged-heading">
        <h3 id="flagged-heading">⚠️ Flagged Accounts</h3>
        @if ($flaggedUsers->isEmpty())
            <x-empty-state title="No accounts flagged for review" icon="✅">
                When risk scores cross the review threshold, accounts will appear here.
            </x-empty-state>
        @else
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Accounts flagged for security review</caption>
                    <thead>
                        <tr>
                            <th scope="col">User</th>
                            <th scope="col">Risk level</th>
                            <th scope="col">Score</th>
                            <th scope="col">Review</th>
                            <th scope="col">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($flaggedUsers as $profile)
                            <tr>
                                <td><strong>{{ $profile->user?->name ?? '—' }}</strong></td>
                                <td><x-status-pill :status="$profile->levelPill()" :label="strtoupper($profile->risk_level)" /></td>
                                <td>{{ $profile->risk_score }}/100</td>
                                <td>{{ $profile->manual_review_required ? '⚠️ required' : '—' }}</td>
                                <td class="muted" style="font-size: .8rem">{{ $profile->status }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="card" aria-labelledby="incidents-heading">
        <h3 id="incidents-heading">🎮 Open Anti-cheat Incidents</h3>
        @if ($openIncidents->isEmpty())
            <x-empty-state title="No open incidents" icon="🛡️">
                Open anti-cheat incidents will appear here.
            </x-empty-state>
        @else
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Open anti-cheat incidents</caption>
                    <thead>
                        <tr>
                            <th scope="col">#</th>
                            <th scope="col">Tournament</th>
                            <th scope="col">Team</th>
                            <th scope="col">Category</th>
                            <th scope="col">Severity</th>
                            <th scope="col">Status</th>
                            <th scope="col"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($openIncidents as $incident)
                            <tr>
                                <td><strong>#{{ $incident->id }}</strong></td>
                                <td>{{ $incident->tournament?->name ?? '—' }}</td>
                                <td>{{ $incident->team?->name ?? '—' }}</td>
                                <td class="muted">{{ ucwords(str_replace('_', ' ', $incident->category)) }}</td>
                                <td class="muted">{{ $incident->severity }}</td>
                                <td><x-status-pill :status="$incident->statusPill()" :label="$incident->statusLabel()" /></td>
                                <td>
                                    <a class="btn btn-sm" href="{{ route('security.incidents.index') }}">Open queue</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
