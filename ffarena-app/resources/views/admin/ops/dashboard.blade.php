@extends('layouts.app')
@section('title', 'Infrastructure Ops — FF Arena Admin')
@section('content')
    <header class="page-head">
        <h1 class="page-title">🛰 Infrastructure Operations</h1>
    </header>

    @if (session('status'))
        <x-alert type="success">{{ session('status') }}</x-alert>
    @endif
    @if (session('error'))
        <x-alert type="error">{{ session('error') }}</x-alert>
    @endif

    @php $h = $stats['health']; @endphp
    <section class="card" aria-labelledby="readiness-heading">
        <h3 id="readiness-heading">Readiness — <span style="color: {{ $h['status'] === 'ready' ? 'var(--green)' : 'var(--red)' }}">
            {{ strtoupper(str_replace('_', ' ', $h['status'])) }}</span></h3>
        <div class="row mt-3" style="gap: 14px">
            @foreach ($h['checks'] as $check)
                <span class="chip" style="border: 1px solid var(--line); padding: 6px 12px; border-radius: 20px;
                    color: {{ $check['ok'] ? 'var(--green)' : 'var(--red)' }}">
                    {{ $check['ok'] ? '✓' : '✗' }} {{ $check['label'] }}
                    @if (! $check['ok'] && isset($check['error']))
                        <span class="muted">({{ $check['error'] }})</span>
                    @endif
                </span>
            @endforeach
        </div>
    </section>

    <div class="grid cols-3 mt-4" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px">
        <div class="stat">
            <div class="muted">Queue pending</div>
            <div class="num">{{ $stats['queue']['pending_jobs'] }}</div>
        </div>
        <div class="stat">
            <div class="muted">Failed jobs</div>
            <div class="num" style="color: {{ $stats['queue']['failed_jobs'] > 0 ? 'var(--red)' : 'var(--green)' }}">
                {{ $stats['queue']['failed_jobs'] }}</div>
        </div>
        <div class="stat">
            <div class="muted">Oldest pending</div>
            <div class="num">{{ $stats['queue']['oldest_pending_seconds'] === null ? 'n/a' : $stats['queue']['oldest_pending_seconds'] . 's' }}</div>
        </div>
        <div class="stat">
            <div class="muted">Scheduler heartbeat</div>
            <div class="num">{{ $stats['queue']['scheduler_heartbeat_seconds_ago'] === null ? 'n/a' : $stats['queue']['scheduler_heartbeat_seconds_ago'] . 's ago' }}</div>
        </div>
        <div class="stat">
            <div class="muted">Webhook endpoints</div>
            <div class="num">{{ $stats['webhooks']['endpoints'] }}</div>
        </div>
        <div class="stat">
            <div class="muted">Webhook failures (24h)</div>
            <div class="num" style="color: {{ $stats['webhooks']['failures_24h'] > 0 ? 'var(--amber)' : 'var(--green)' }}">
                {{ $stats['webhooks']['failures_24h'] }}</div>
        </div>
    </div>

    <section class="card mt-4" aria-labelledby="storage-heading">
        <h3 id="storage-heading">Storage & backup</h3>
        <p class="muted">Private storage: {{ $stats['storage']['exists'] ? $stats['storage']['files'] . ' files, ' . number_format($stats['storage']['size_bytes']) . ' bytes' : 'missing' }}</p>
        @if ($stats['backup'])
            <p class="muted">Latest backup: <strong>{{ $stats['backup']['name'] }}</strong>
                ({{ $stats['backup']['db_driver'] }}, {{ number_format($stats['backup']['size']) }} bytes)</p>
        @else
            <p class="muted">No backups yet.</p>
        @endif

        <div class="row mt-3" style="gap: 10px">
            <form method="POST" action="{{ route('admin.ops.backup') }}">
                @csrf
                <button type="submit" class="btn btn-sm">Create backup now</button>
            </form>
            <form method="POST" action="{{ route('admin.ops.backup.verify') }}">
                @csrf
                <button type="submit" class="btn btn-sm">Verify latest backup</button>
            </form>
        </div>
    </section>

    <section class="card mt-4" aria-labelledby="config-heading">
        <h3 id="config-heading">Production configuration validation</h3>
        @if ($stats['production_issues'] === [])
            <p style="color: var(--green)">No issues detected.</p>
        @else
            <ul style="margin: 8px 0 0 18px">
                @foreach ($stats['production_issues'] as $issue)
                    <li style="margin-bottom: 6px">
                        <span style="color: {{ $issue['severity'] === 'critical' ? 'var(--red)' : 'var(--amber)' }}">
                            [{{ strtoupper($issue['severity']) }}] {{ $issue['key'] }}</span>
                        <span class="muted">— {{ $issue['message'] }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section class="card mt-4" aria-labelledby="cache-heading">
        <h3 id="cache-heading">Cache control (approved namespaces)</h3>
        <form method="POST" action="{{ route('admin.ops.cache.flush') }}">
            @csrf
            <div class="row" style="align-items: flex-end">
                <div class="field">
                    <label for="namespace">Namespace</label>
                    <select id="namespace" name="namespace">
                        <option value="providers">providers — payment provider statuses</option>
                        <option value="public">public — public read caches</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-sm">Flush</button>
            </div>
        </form>
    </section>

    <section class="card mt-4" aria-labelledby="failed-jobs-heading">
        <h3 id="failed-jobs-heading">Recent failed jobs</h3>
        @if ($failedJobs->isEmpty())
            <p class="muted">No failed jobs.</p>
        @else
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Recent failed jobs</caption>
                    <thead>
                        <tr>
                            <th scope="col">ID</th>
                            <th scope="col">Queue</th>
                            <th scope="col">Failed at</th>
                            <th scope="col"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($failedJobs as $job)
                            <tr>
                                <td>{{ \Illuminate\Support\Str::limit($job->uuid, 12) }}</td>
                                <td>{{ $job->queue }}</td>
                                <td>{{ $job->failed_at }}</td>
                                <td>
                                    <div class="row" style="gap: 8px">
                                        <form method="POST" action="{{ route('admin.ops.failed_jobs.retry', $job->uuid) }}">
                                            @csrf
                                            <button type="submit" class="btn btn-sm">Retry</button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.ops.failed_jobs.delete', $job->uuid) }}"
                                              onsubmit="return confirm('Delete this failed job record?')">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-3">
                <a href="{{ route('admin.ops.failed_jobs') }}" class="muted">View all failed jobs →</a>
            </p>
        @endif
    </section>
@endsection
