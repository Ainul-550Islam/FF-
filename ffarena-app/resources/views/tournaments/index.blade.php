@extends('layouts.app')

@section('content')
    <header class="page-head">
        <nav class="breadcrumbs" aria-label="Breadcrumb">
            <li><a href="{{ route('home') }}">Home</a></li>
            <li><span aria-current="page">Tournaments</span></li>
        </nav>
        <h1 class="page-title">Tournaments</h1>
        <p class="page-subtitle">All Free Fire tournaments on FF Arena.</p>
    </header>

    {{-- Discovery filters (GET — shareable, crawl-safe, no state) --}}
    <form method="GET" action="{{ route('tournaments.index') }}" class="card" role="search" aria-label="Filter tournaments">
        <div class="row">
            <div class="field grow" style="min-width: 220px">
                <label for="filter-q">Search</label>
                <input type="search" id="filter-q" name="q" value="{{ $search }}"
                       placeholder="Tournament name or map" autocomplete="off">
            </div>
            <div class="field">
                <label for="filter-status">Status</label>
                <select id="filter-status" name="status">
                    <option value="">All statuses</option>
                    @foreach (['open' => 'Open', 'live' => 'Live', 'closed' => 'Closed', 'finished' => 'Finished'] as $value => $label)
                        <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="filter-mode">Game mode</label>
                <select id="filter-mode" name="game_mode">
                    <option value="">All modes</option>
                    @foreach (['squad' => 'Squad', 'duo' => 'Duo', 'solo' => 'Solo'] as $value => $label)
                        <option value="{{ $value }}" @selected($gameMode === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <span class="sr-only">Apply filters</span>
                <button type="submit" class="btn btn-primary">Filter</button>
                <a href="{{ route('tournaments.index') }}" class="btn btn-ghost">Clear</a>
            </div>
        </div>
    </form>

    <div class="grid cols-3">
        @forelse ($tournaments as $t)
            <article class="card">
                <div class="row-between">
                    <x-status-pill :status="$t->status" />
                    <span class="muted">{{ strtoupper($t->game_mode) }} · {{ $t->map }}</span>
                </div>
                <h3 class="mt-3">
                    <a href="{{ route('tournaments.show', $t) }}">{{ $t->name }}</a>
                </h3>
                <p class="muted mb-3" style="font-size: .85rem">
                    by {{ $t->organizer->name ?? 'Organizer' }} ·
                    starts {{ optional($t->starts_at)->format('d M, h:i A') ?? 'TBA' }}
                </p>
                <dl class="row">
                    <div class="stat">
                        <dt class="label">Entry</dt>
                        <dd class="num">৳{{ number_format($t->entry_fee) }}</dd>
                    </div>
                    <div class="stat">
                        <dt class="label">Prize</dt>
                        <dd class="num">৳{{ number_format($t->prize_pool) }}</dd>
                    </div>
                    <div class="stat">
                        <dt class="label">Teams</dt>
                        <dd class="num">{{ $t->confirmed_teams_count }}/{{ $t->team_slots }}</dd>
                    </div>
                </dl>
                <div class="mt-3">
                    <a href="{{ route('tournaments.show', $t) }}"
                       class="btn btn-sm {{ $t->status === 'open' ? 'btn-green' : '' }}">View</a>
                </div>
            </article>
        @empty
            <x-empty-state title="No tournaments found" icon="🔍">
                Try a different search term or filter, or check back soon for new tournaments.
            </x-empty-state>
        @endforelse
    </div>

    {{ $tournaments->links() }}
@endsection
