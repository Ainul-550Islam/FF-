@extends('layouts.app')
@section('title', 'Moderation Queue — FF Arena')
@section('content')
    <header class="page-head">
        <h1 class="page-title">🛡 Dispute Moderation Queue</h1>
    </header>

    <section class="card" aria-labelledby="filters-heading">
        <h2 id="filters-heading" class="sr-only">Filters</h2>
        <form method="GET" action="{{ route('moderation.index') }}">
            <div class="row" style="align-items: flex-end">
                <div class="field grow" style="min-width: 180px">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="">All statuses</option>
                        @foreach (\App\Models\Dispute::STATUSES as $s)
                            <option value="{{ $s }}" @selected($status === $s)>{{ ucwords(str_replace('_', ' ', $s)) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field grow" style="min-width: 220px">
                    <label for="tournament_id">Tournament</label>
                    <select id="tournament_id" name="tournament_id">
                        <option value="">All tournaments</option>
                        @foreach ($tournaments as $t)
                            <option value="{{ $t->id }}" @selected($tournamentId === $t->id)>{{ $t->name }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="btn btn-sm btn-cyan">Filter</button>
            </div>
        </form>
    </section>

    <section class="card" aria-labelledby="queue-heading">
        <h2 id="queue-heading" class="sr-only">Disputes</h2>
        @if ($disputes->isEmpty())
            <x-empty-state title="No disputes match your filters" icon="🛡">
                Adjust the filters or check back later.
            </x-empty-state>
        @else
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Disputes awaiting moderation</caption>
                    <thead>
                        <tr>
                            <th scope="col">#</th>
                            <th scope="col">Tournament</th>
                            <th scope="col">Match</th>
                            <th scope="col">Team</th>
                            <th scope="col">Category</th>
                            <th scope="col">Status</th>
                            <th scope="col">Reviewer</th>
                            <th scope="col">Opened</th>
                            <th scope="col"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($disputes as $dispute)
                            <tr>
                                <td><strong>#{{ $dispute->id }}</strong></td>
                                <td>{{ $dispute->match?->tournament?->name ?? '—' }}</td>
                                <td>M#{{ $dispute->match_id }}</td>
                                <td>{{ $dispute->team?->name ?? '—' }}</td>
                                <td class="muted" style="font-size: .85rem">{{ $dispute->categoryLabel() }}</td>
                                <td><x-status-pill :status="$dispute->statusPill()" :label="$dispute->statusLabel()" /></td>
                                <td>{{ $dispute->assignee?->name ?? '—' }}</td>
                                <td class="muted" style="font-size: .8rem">{{ $dispute->created_at->format('d M, h:i A') }}</td>
                                <td>
                                    @if ($dispute->match && $dispute->match->tournament)
                                        <a href="{{ route('matches.disputes.show', [$dispute->match->tournament, $dispute->match, $dispute]) }}" class="btn btn-sm">Review</a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $disputes->links() }}</div>
        @endif
    </section>
@endsection
