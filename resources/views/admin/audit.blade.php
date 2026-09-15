@extends('layouts.app')
@section('title', 'Audit Log — FF Arena Admin')
@section('content')
    <header class="page-head">
        <h1 class="page-title">🧾 Audit Log</h1>
    </header>

    <section class="card" aria-labelledby="filters-heading">
        <h2 id="filters-heading" class="sr-only">Filters</h2>
        <form method="GET" action="{{ route('admin.audit.index') }}">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 10px; align-items: end">
                <div class="field">
                    <label for="action">Action</label>
                    <select id="action" name="action">
                        <option value="">All actions</option>
                        @foreach ($actions as $action)
                            <option value="{{ $action }}" {{ ($filters['action'] ?? '') === $action ? 'selected' : '' }}>{{ $action }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="entity_type">Entity type</label>
                    <select id="entity_type" name="entity_type">
                        <option value="">All</option>
                        @foreach ($entityTypes as $type)
                            <option value="{{ $type }}" {{ ($filters['entity_type'] ?? '') === $type ? 'selected' : '' }}>{{ $type }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="tournament_id">Tournament</label>
                    <select id="tournament_id" name="tournament_id">
                        <option value="">All</option>
                        @foreach ($tournaments as $t)
                            <option value="{{ $t->id }}" {{ (int) ($filters['tournament_id'] ?? 0) === $t->id ? 'selected' : '' }}>{{ $t->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="entity_id">Entity ID</label>
                    <input type="number" id="entity_id" name="entity_id" value="{{ $filters['entity_id'] ?? '' }}" placeholder="123">
                </div>
                <div class="field">
                    <label for="target_user_id">Target user ID</label>
                    <input type="number" id="target_user_id" name="target_user_id" value="{{ $filters['target_user_id'] ?? '' }}" placeholder="123">
                </div>
                <div class="field">
                    <label for="actor_user_id">Actor user ID</label>
                    <input type="number" id="actor_user_id" name="actor_user_id" value="{{ $filters['actor_user_id'] ?? '' }}" placeholder="123">
                </div>
                <div class="field">
                    <label for="from">From</label>
                    <input type="date" id="from" name="from" value="{{ $filters['from'] ?? '' }}">
                </div>
                <div class="field">
                    <label for="to">To</label>
                    <input type="date" id="to" name="to" value="{{ $filters['to'] ?? '' }}">
                </div>
                <div class="field">
                    <label for="direction">Order</label>
                    <select id="direction" name="direction">
                        <option value="desc" {{ ($filters['direction'] ?? 'desc') === 'desc' ? 'selected' : '' }}>Newest first</option>
                        <option value="asc" {{ ($filters['direction'] ?? '') === 'asc' ? 'selected' : '' }}>Oldest first</option>
                    </select>
                </div>
                <div class="row" style="gap: 8px">
                    <button type="submit" class="btn btn-cyan btn-sm">Filter</button>
                    <a href="{{ route('admin.audit.index') }}" class="btn btn-sm">Reset</a>
                </div>
            </div>
        </form>
    </section>

    <div class="row-between" style="margin-bottom: 12px">
        <span class="muted">{{ $logs->total() }} record(s). Append-only — rows cannot be edited or deleted.</span>
        <a href="{{ route('admin.audit.export', request()->query()) }}" class="btn btn-sm btn-green">⬇ Export CSV</a>
    </div>

    <section class="card" style="padding: 0" aria-labelledby="logs-heading">
        <h2 id="logs-heading" class="sr-only">Audit records</h2>
        <div class="table-wrap">
            <table>
                <caption class="sr-only">Audit log records</caption>
                <thead>
                    <tr>
                        <th scope="col">ID</th>
                        <th scope="col">When</th>
                        <th scope="col">Actor</th>
                        <th scope="col">Action</th>
                        <th scope="col">Entity</th>
                        <th scope="col">Tournament</th>
                        <th scope="col">Target</th>
                        <th scope="col">Before</th>
                        <th scope="col">After</th>
                        <th scope="col">Source</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($logs as $log)
                        <tr>
                            <td class="muted">{{ $log->id }}</td>
                            <td class="muted">{{ optional($log->created_at)->format('d M y H:i') }}</td>
                            <td>{{ $log->actor?->name ?? '—' }} <span class="muted">{{ $log->actor?->role }}</span></td>
                            <td><code>{{ $log->action }}</code></td>
                            <td class="muted">{{ $log->entity_type }}#{{ $log->entity_id }}</td>
                            <td class="muted">{{ $log->tournament?->name ?? '—' }}</td>
                            <td class="muted">{{ $log->targetUser?->name ?? '—' }}</td>
                            <td class="muted" style="max-width: 180px; overflow: hidden; text-overflow: ellipsis">{{ $log->before ? json_encode($log->before) : '—' }}</td>
                            <td class="muted" style="max-width: 180px; overflow: hidden; text-overflow: ellipsis">{{ $log->after ? json_encode($log->after) : '—' }}</td>
                            <td class="muted" style="max-width: 160px; overflow: hidden; text-overflow: ellipsis">{{ $log->source }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10">
                                <x-empty-state title="No audit records match your filters" icon="🧾">
                                    Audit records appear here as actions are performed.
                                </x-empty-state>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <div class="mt-4">{{ $logs->links() }}</div>
@endsection
