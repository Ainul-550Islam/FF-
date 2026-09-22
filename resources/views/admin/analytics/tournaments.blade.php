@extends('layouts.app')
@section('title', 'Tournament Analytics — FF Arena Admin')
@section('content')
    <header class="page-head">
        <h1 class="page-title">🏆 Tournament & Match Analytics</h1>
    </header>

    <section class="card" aria-labelledby="filters-heading">
        <h2 id="filters-heading" class="sr-only">Date range filters</h2>
        <form method="GET">
            <div class="row" style="align-items: flex-end">
                <div class="field">
                    <label for="from">From</label>
                    <input type="date" id="from" name="from" value="{{ $from ?? '' }}">
                </div>
                <div class="field">
                    <label for="to">To</label>
                    <input type="date" id="to" name="to" value="{{ $to ?? '' }}">
                </div>
                <button type="submit" class="btn btn-cyan btn-sm">Apply</button>
                <a href="{{ route('admin.analytics.tournaments') }}" class="btn btn-sm">Reset</a>
                <a href="{{ route('admin.analytics.export') }}" class="btn btn-sm btn-green">⬇ Export CSV</a>
            </div>
        </form>
    </section>

    <h3 class="mt-4">Tournaments</h3>
    <div class="grid cols-2" style="grid-template-columns: repeat(auto-fit, minmax(170px, 1fr))">
        <div class="stat"><div class="muted">Total</div><div class="num">{{ $overview['tournaments']['total'] }}</div></div>
        <div class="stat"><div class="muted">Created (range)</div><div class="num">{{ $overview['tournaments']['created'] }}</div></div>
        <div class="stat"><div class="muted">Open</div><div class="num">{{ $overview['tournaments']['open'] }}</div></div>
        <div class="stat"><div class="muted">Live</div><div class="num" style="color: var(--green)">{{ $overview['tournaments']['live'] }}</div></div>
        <div class="stat"><div class="muted">Finished</div><div class="num">{{ $overview['tournaments']['finished'] }}</div></div>
        <div class="stat"><div class="muted">Cancelled</div><div class="num" style="color: var(--red)">{{ $overview['tournaments']['cancelled'] }}</div></div>
    </div>

    <h3 class="mt-4">Matches</h3>
    <div class="grid cols-2" style="grid-template-columns: repeat(auto-fit, minmax(170px, 1fr))">
        <div class="stat"><div class="muted">Scheduled</div><div class="num">{{ $matches['scheduled'] }}</div></div>
        <div class="stat"><div class="muted">Completed</div><div class="num" style="color: var(--green)">{{ $matches['completed'] }}</div></div>
        <div class="stat"><div class="muted">Live</div><div class="num" style="color: var(--cyan)">{{ $matches['live'] }}</div></div>
        <div class="stat"><div class="muted">Disputed</div><div class="num" style="color: var(--red)">{{ $matches['disputed'] }}</div></div>
        <div class="stat"><div class="muted">Unresolved</div><div class="num" style="color: var(--amber)">{{ $matches['unresolved'] }}</div></div>
        <div class="stat"><div class="muted">Avg completion</div><div class="num">{{ $matches['avg_completion_seconds'] !== null ? round($matches['avg_completion_seconds'] / 3600, 1) . 'h' : '—' }}</div></div>
        <div class="stat"><div class="muted">Scores submitted</div><div class="num">{{ $matches['scoring']['scores_submitted'] }}</div></div>
        <div class="stat"><div class="muted">Avg kills</div><div class="num">{{ $matches['scoring']['avg_kills'] }}</div></div>
    </div>

    <h3 class="mt-4">Players</h3>
    <div class="grid cols-2" style="grid-template-columns: repeat(auto-fit, minmax(170px, 1fr))">
        <div class="stat"><div class="muted">Total users</div><div class="num">{{ $players['total_users'] }}</div></div>
        <div class="stat"><div class="muted">New (range)</div><div class="num">{{ $players['new_users'] }}</div></div>
        <div class="stat"><div class="muted">Organizers</div><div class="num">{{ $players['organizers'] }}</div></div>
        <div class="stat"><div class="muted">Participants</div><div class="num">{{ $players['participants'] }}</div></div>
        <div class="stat"><div class="muted">Repeat participants</div><div class="num">{{ $players['repeat_participants'] }}</div></div>
    </div>

    <h3 class="mt-4">Tournaments</h3>
    <section class="card" style="padding: 0" aria-labelledby="tournament-table-heading">
        <h2 id="tournament-table-heading" class="sr-only">Tournament list</h2>
        <div class="table-wrap">
            <table>
                <caption class="sr-only">Tournaments with metrics</caption>
                <thead>
                    <tr>
                        <th scope="col">Name</th>
                        <th scope="col">Status</th>
                        <th scope="col">Teams</th>
                        <th scope="col">Matches</th>
                        <th scope="col">Completed</th>
                        <th scope="col"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($tournaments as $t)
                        <tr>
                            <td>{{ $t->name }}</td>
                            <td><x-status-pill :status="$t->status" :label="strtoupper($t->status)" /></td>
                            <td>{{ $t->teams_count }}</td>
                            <td>{{ $t->matches_count }}</td>
                            <td>{{ $t->completed_matches_count }}</td>
                            <td><a href="{{ route('tournaments.analytics', $t) }}" class="btn btn-sm btn-cyan">Metrics</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
    <div class="mt-4">{{ $tournaments->links() }}</div>
@endsection
