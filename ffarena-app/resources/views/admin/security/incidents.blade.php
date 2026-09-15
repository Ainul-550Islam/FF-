@extends('layouts.app')
@section('title', 'Anti-cheat Incidents — FF Arena')
@section('content')
    <header class="page-head">
        <h1 class="page-title">🎮 Anti-cheat Incidents</h1>
    </header>

    @can('create', \App\Models\AntiCheatIncident::class)
        <section class="card" aria-labelledby="open-incident-heading">
            <h3 id="open-incident-heading">Open an Incident</h3>
            <form method="POST" action="{{ route('security.incidents.open') }}">
                @csrf
                <div class="row" style="align-items: flex-end">
                    <div class="field" style="min-width: 200px">
                        <label for="tournament_id">Tournament</label>
                        <select id="tournament_id" name="tournament_id" required>
                            <option value="">Select…</option>
                            @foreach ($tournaments as $t)
                                <option value="{{ $t->id }}">{{ $t->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field" style="min-width: 140px">
                        <label for="category">Category</label>
                        <select id="category" name="category">
                            @foreach (\App\Services\AntiCheatService::CATEGORIES as $c)
                                <option value="{{ $c }}">{{ ucwords(str_replace('_', ' ', $c)) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field" style="min-width: 130px">
                        <label for="severity">Severity</label>
                        <select id="severity" name="severity">
                            @foreach (['low', 'medium', 'high', 'critical'] as $s)
                                <option value="{{ $s }}">{{ ucfirst($s) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field" style="min-width: 180px">
                        <label for="accused_user_id">Accused user ID (optional)</label>
                        <input type="number" id="accused_user_id" name="accused_user_id" placeholder="user id">
                    </div>
                    <div class="field" style="min-width: 240px">
                        <label for="description">Description</label>
                        <input type="text" id="description" name="description" placeholder="What was observed?">
                    </div>
                    <button type="submit" class="btn btn-sm btn-cyan">Open Incident</button>
                </div>
            </form>
        </section>
    @endcan

    <section class="card" style="padding: 0" aria-labelledby="incidents-heading">
        <h2 id="incidents-heading" class="sr-only">Incidents</h2>
        @if ($incidents->isEmpty())
            <x-empty-state title="No incidents" icon="🎮">
                Anti-cheat incidents will appear here.
            </x-empty-state>
        @else
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Anti-cheat incidents</caption>
                    <thead>
                        <tr>
                            <th scope="col">#</th>
                            <th scope="col">Tournament</th>
                            <th scope="col">Team</th>
                            <th scope="col">Accused</th>
                            <th scope="col">Category</th>
                            <th scope="col">Severity</th>
                            <th scope="col">Status</th>
                            <th scope="col">Reviewer</th>
                            <th scope="col">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($incidents as $incident)
                            <tr>
                                <td><strong>#{{ $incident->id }}</strong></td>
                                <td>{{ $incident->tournament?->name ?? '—' }}</td>
                                <td>{{ $incident->team?->name ?? '—' }}</td>
                                <td class="muted" style="font-size: .85rem">{{ $incident->accusedUser?->name ?? '—' }}</td>
                                <td class="muted">{{ ucwords(str_replace('_', ' ', $incident->category)) }}</td>
                                <td class="muted">{{ $incident->severity }}</td>
                                <td><x-status-pill :status="$incident->statusPill()" :label="$incident->statusLabel()" /></td>
                                <td class="muted" style="font-size: .85rem">{{ $incident->reviewer?->name ?? '—' }}</td>
                                <td>
                                    @if ($incident->status === 'flagged')
                                        <form method="POST" action="{{ route('security.incidents.review', $incident) }}">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-cyan">Review</button>
                                        </form>
                                    @elseif ($incident->status === 'under_review')
                                        <form method="POST" action="{{ route('security.incidents.resolve', $incident) }}">
                                            @csrf
                                            <div class="row" style="gap: 6px; align-items: center">
                                                <label for="resolution-{{ $incident->id }}" class="sr-only">Resolution</label>
                                                <select id="resolution-{{ $incident->id }}" name="resolution">
                                                    @foreach (\App\Models\AntiCheatIncident::RESOLUTIONS as $r)
                                                        <option value="{{ $r }}">{{ ucfirst($r) }}</option>
                                                    @endforeach
                                                </select>
                                                <label for="resolution-text-{{ $incident->id }}" class="sr-only">Resolution reason</label>
                                                <input type="text" id="resolution-text-{{ $incident->id }}" name="resolution_text" placeholder="Resolution reason" style="max-width: 140px">
                                                <button type="submit" class="btn btn-green btn-sm">Resolve</button>
                                            </div>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $incidents->links() }}</div>
        @endif
    </section>
@endsection
