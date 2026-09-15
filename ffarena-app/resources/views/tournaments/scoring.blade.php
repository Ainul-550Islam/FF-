@extends('layouts.app')
@section('title', 'Scoring Rules — ' . $tournament->name)
@section('content')
    @php
        $placementMap = $current->placement_points ?? [];
        $currentChain = $current->tieBreakers();
        // Pad the chain to 5 selectable slots for the form.
        $slots = array_merge(array_values($currentChain), array_fill(0, max(0, 5 - count($currentChain)), null));
        $slots = array_slice($slots, 0, 5);
    @endphp

    <header class="page-head">
        <nav class="breadcrumbs" aria-label="Breadcrumb">
            <li><a href="{{ route('home') }}">Home</a></li>
            <li><a href="{{ route('tournaments.show', $tournament) }}">{{ $tournament->name }}</a></li>
            <li><span aria-current="page">Scoring Rules</span></li>
        </nav>
        <h1 class="page-title">🎯 Scoring Rules</h1>
        <p class="page-subtitle">{{ $tournament->name }}</p>
    </header>

    <section class="card" aria-labelledby="current-rules">
        <h3 id="current-rules">Current Rules
            <x-status-pill status="live" :label="'v' . $current->version" />
            @if ($current->isCurrent())
                <x-status-pill status="confirmed" label="Active" />
            @endif
        </h3>
        <div class="row mb-3">
            <div>Kill points <br><strong class="tag">{{ $current->kill_points }} / kill</strong></div>
            <div>Rule set name <br><strong>{{ $current->label() }}</strong></div>
        </div>

        <div class="row" style="align-items: flex-start">
            <div>
                <div class="muted" style="font-size: .8rem; font-weight: 700; margin-bottom: 6px">PLACEMENT POINTS</div>
                <div class="table-wrap" style="max-width: 320px">
                    <table>
                        <caption class="sr-only">Placement points by finishing position</caption>
                        <thead>
                            <tr><th scope="col">Placement</th><th scope="col">Points</th></tr>
                        </thead>
                        <tbody>
                            @for ($p = 1; $p <= 12; $p++)
                                <tr>
                                    <td>#{{ $p }}</td>
                                    <td><strong>{{ $current->placementPointsFor($p) }}</strong></td>
                                </tr>
                            @endfor
                        </tbody>
                    </table>
                </div>
            </div>
            <div>
                <div class="muted" style="font-size: .8rem; font-weight: 700; margin-bottom: 6px">TIE-BREAKER ORDER</div>
                <ol>
                    @foreach ($currentChain as $key)
                        <li style="margin-bottom: 4px">{{ \App\Models\ScoringRule::TIE_BREAKER_OPTIONS[$key] ?? $key }}</li>
                    @endforeach
                </ol>
                <p class="muted mt-3" style="font-size: .8rem; max-width: 340px">
                    Historical scores keep the exact rule version they were
                    computed with — changing rules only affects <em>future</em> matches.
                </p>
            </div>
        </div>
    </section>

    <section class="card" aria-labelledby="new-version">
        <h3 id="new-version">🆕 Create a New Version</h3>
        <p class="muted mb-2" style="font-size: .85rem">
            Saving creates an immutable new version and activates it. Existing results are never rewritten.
        </p>
        <form method="POST" action="{{ route('tournaments.scoring.store', $tournament) }}">
            @csrf
            <div class="grid cols-2">
                <div class="field">
                    <label for="name">Rule set name (optional)</label>
                    <input type="text" id="name" name="name" value="{{ $current->name }}" placeholder="e.g. Finals rules">
                </div>
                <div class="field">
                    <label for="kill_points">Points per kill</label>
                    <input type="number" id="kill_points" name="kill_points" value="{{ $current->kill_points }}" min="0" max="1000" required>
                </div>
            </div>

            <fieldset>
                <legend>Placement points (1st → 12th)</legend>
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(90px, 1fr)); gap: 8px">
                    @for ($p = 1; $p <= 12; $p++)
                        <div>
                            <label for="placement-{{ $p }}" class="sr-only">Placement #{{ $p }} points</label>
                            <span class="muted" style="font-size: .7rem">#{{ $p }}</span>
                            <input type="number" id="placement-{{ $p }}" name="placement_points[{{ $p }}]"
                                   value="{{ $current->placementPointsFor($p) }}" min="0" max="1000" required>
                        </div>
                    @endfor
                </div>
            </fieldset>

            <fieldset>
                <legend>Tie-breaker order (first → last)</legend>
                <p class="help-text">
                    Total points is always the primary sort. Leave a slot as "—" to stop the chain early.
                </p>
                @for ($i = 0; $i < 5; $i++)
                    <div class="field">
                        <label for="tie-{{ $i }}" class="sr-only">Tie-breaker slot {{ $i + 1 }}</label>
                        <select id="tie-{{ $i }}" name="tie_breakers[]" style="margin-bottom: 6px">
                            <option value="">— none —</option>
                            @foreach (\App\Models\ScoringRule::TIE_BREAKER_OPTIONS as $key => $label)
                                <option value="{{ $key }}" @selected(($slots[$i] ?? null) === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                @endfor
            </fieldset>

            <button type="submit" class="btn btn-primary mt-2">Create &amp; Activate Version</button>
        </form>
    </section>

    <section class="card" aria-labelledby="version-history">
        <h3 id="version-history">📚 Version History</h3>
        @if ($rules->isEmpty())
            <p class="muted">No rule versions yet.</p>
        @else
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Scoring rule version history</caption>
                    <thead>
                        <tr>
                            <th scope="col">Version</th>
                            <th scope="col">Name</th>
                            <th scope="col">Kill Pts</th>
                            <th scope="col">Created</th>
                            <th scope="col">Status</th>
                            <th scope="col"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rules as $rule)
                            <tr>
                                <td><strong>v{{ $rule->version }}</strong></td>
                                <td>{{ $rule->label() }}</td>
                                <td>{{ $rule->kill_points }}</td>
                                <td class="muted">{{ $rule->created_at->format('d M Y, h:i A') }}</td>
                                <td>
                                    @if ($rule->isCurrent())
                                        <x-status-pill status="live" label="Active" />
                                    @else
                                        <x-status-pill status="cancelled" label="Historical" />
                                    @endif
                                </td>
                                <td>
                                    @if (! $rule->isCurrent())
                                        <form method="POST" action="{{ route('tournaments.scoring.activate', [$tournament, $rule]) }}">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-cyan">Activate</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
