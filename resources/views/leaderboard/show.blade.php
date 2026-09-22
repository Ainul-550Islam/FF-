@extends('layouts.app')

@section('content')
    <header class="page-head">
        <nav class="breadcrumbs" aria-label="Breadcrumb">
            <li><a href="{{ route('home') }}">Home</a></li>
            <li><a href="{{ route('tournaments.index') }}">Tournaments</a></li>
            <li><a href="{{ route('tournaments.show', $tournament) }}">{{ $tournament->name }}</a></li>
            <li><span aria-current="page">Leaderboard</span></li>
        </nav>
        <h1 class="page-title">🏅 Leaderboard</h1>
        <p class="page-subtitle">{{ $tournament->name }}</p>
    </header>

    {{-- Phase 12 — live updates feed (polling; server-side visibility) --}}
    @include('live.poll', ['tournament' => $tournament])

    <section class="card" aria-labelledby="standings-heading">
        <h3 id="standings-heading">Standings</h3>

        @if ($leaderboard->isEmpty())
            <x-empty-state title="No results yet" icon="📊">
                Standings appear here as soon as matches are scored.
            </x-empty-state>
        @else
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Tournament standings ordered by rank</caption>
                    <thead>
                        <tr>
                            <th scope="col">Rank</th>
                            <th scope="col">Team</th>
                            <th scope="col">Matches</th>
                            <th scope="col">Kills</th>
                            <th scope="col">Place Pts</th>
                            <th scope="col">Kill Pts</th>
                            <th scope="col">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($leaderboard as $row)
                            <tr>
                                <td>
                                    <strong>#{{ $row->rank }}</strong>
                                </td>
                                <td><strong>{{ $row->team->name }}</strong></td>
                                <td>{{ $row->matches_played }}</td>
                                <td>{{ $row->kills }}</td>
                                <td>{{ $row->placement_points }}</td>
                                <td>{{ $row->kill_points }}</td>
                                <td><strong class="tag">{{ $row->points }}</strong></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="muted mt-3" style="font-size: .8rem">
                Deterministic ordering — identical results always rank identically.
            </p>
        @endif
    </section>
@endsection
