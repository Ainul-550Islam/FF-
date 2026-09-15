@extends('layouts.app')
@section('title', 'Suspicious Users — FF Arena Admin')
@section('content')
    <header class="page-head">
        <h1 class="page-title">🔍 Suspicious Users</h1>
    </header>

    <section class="card" aria-labelledby="filters-heading">
        <h2 id="filters-heading" class="sr-only">User filters</h2>
        <form method="GET" action="{{ route('admin.security.users') }}">
            <div class="row" style="align-items: flex-end">
                <div class="field" style="min-width: 160px">
                    <label for="level">Risk level</label>
                    <select id="level" name="level">
                        <option value="">All levels</option>
                        @foreach (\App\Models\RiskProfile::LEVELS as $l)
                            <option value="{{ $l }}" @selected($level === $l)>{{ ucfirst($l) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <div class="checkbox">
                        <input type="checkbox" id="review" name="review" value="1" @checked(request('review') === '1')>
                        <label for="review">Review required only</label>
                    </div>
                </div>
                <button type="submit" class="btn btn-sm btn-cyan">Filter</button>
            </div>
        </form>
    </section>

    <section class="card" style="padding: 0" aria-labelledby="profiles-heading">
        <h2 id="profiles-heading" class="sr-only">Suspicious accounts</h2>
        @if ($profiles->isEmpty())
            <x-empty-state title="No accounts match your filters" icon="🔍">
                Adjust the filters and try again.
            </x-empty-state>
        @else
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Accounts matching your filters</caption>
                    <thead>
                        <tr>
                            <th scope="col">User</th>
                            <th scope="col">Email</th>
                            <th scope="col">Risk level</th>
                            <th scope="col">Score</th>
                            <th scope="col">Review</th>
                            <th scope="col">Status</th>
                            <th scope="col"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($profiles as $profile)
                            <tr>
                                <td><strong>{{ $profile->user?->name ?? '—' }}</strong></td>
                                <td class="muted" style="font-size: .8rem">{{ $profile->user?->email ?? '—' }}</td>
                                <td><x-status-pill :status="$profile->levelPill()" :label="strtoupper($profile->risk_level)" /></td>
                                <td>{{ $profile->risk_score }}/100</td>
                                <td>{{ $profile->manual_review_required ? '⚠️ yes' : '—' }}</td>
                                <td class="muted" style="font-size: .8rem">{{ $profile->status }}</td>
                                <td>
                                    @if ($profile->user)
                                        <a class="btn btn-sm btn-cyan" href="{{ route('admin.security.user', $profile->user) }}">Investigate</a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $profiles->links() }}</div>
        @endif
    </section>
@endsection
