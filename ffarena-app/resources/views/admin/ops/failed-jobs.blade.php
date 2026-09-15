@extends('layouts.app')
@section('title', 'Failed Jobs — FF Arena Admin')
@section('content')
    <header class="page-head">
        <h1 class="page-title">🧨 Failed Jobs</h1>
    </header>

    @if (session('status'))
        <x-alert type="success">{{ session('status') }}</x-alert>
    @endif
    @if (session('error'))
        <x-alert type="error">{{ session('error') }}</x-alert>
    @endif

    <div class="row mb-4" style="gap: 10px">
        <a href="{{ route('admin.ops.dashboard') }}" class="btn btn-sm">← Ops dashboard</a>
        <form method="POST" action="{{ route('admin.ops.failed_jobs.retry_all') }}"
              onsubmit="return confirm('Retry all failed jobs?')">
            @csrf
            <button type="submit" class="btn btn-sm">Retry all</button>
        </form>
    </div>

    @if ($failedJobs->isEmpty())
        <div class="card"><p class="muted">No failed jobs.</p></div>
    @else
        <section class="card" style="padding: 0" aria-labelledby="jobs-heading">
            <h2 id="jobs-heading" class="sr-only">Failed jobs</h2>
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Failed jobs</caption>
                    <thead>
                        <tr>
                            <th scope="col">ID</th>
                            <th scope="col">Connection</th>
                            <th scope="col">Queue</th>
                            <th scope="col">Failed at</th>
                            <th scope="col"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($failedJobs as $job)
                            <tr>
                                <td>{{ \Illuminate\Support\Str::limit($job->uuid, 12) }}</td>
                                <td>{{ $job->connection }}</td>
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
        </section>
        <div class="mt-4">{{ $failedJobs->links() }}</div>
    @endif
@endsection
