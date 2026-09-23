@extends('layouts.app')

@section('title', 'Lifecycle Automations — FF Arena Admin')

@section('content')
<header class="page-head">
    <h1 class="page-title">⚙️ Lifecycle Automations</h1>
</header>

@if (session('success'))
    <div class="alert alert-success" role="status">{{ session('success') }}</div>
@endif

<section class="card">
    @if ($automations->isEmpty())
        <p class="muted">No automations defined yet.</p>
    @else
        <table>
            <thead>
                <tr><th>Key</th><th>Name</th><th>Trigger</th><th>Cooldown</th><th>Last run</th><th>State</th><th></th></tr>
            </thead>
            <tbody>
                @foreach ($automations as $automation)
                    <tr>
                        <td><code>{{ $automation->key }}</code></td>
                        <td>{{ $automation->name }}</td>
                        <td><code>{{ $automation->trigger }}</code></td>
                        <td>{{ $automation->cooldown_hours }}h</td>
                        <td>{{ $automation->last_run_at?->isoFormat('D MMM Y, HH:mm') ?? '—' }}</td>
                        <td>
                            @if ($automation->enabled)
                                <strong>Enabled</strong>
                            @else
                                <span class="muted">Disabled</span>
                            @endif
                        </td>
                        <td>
                            <form method="POST" action="{{ route('admin.marketing.automations.toggle', $automation) }}">
                                @csrf
                                <input type="hidden" name="enabled" value="{{ $automation->enabled ? '0' : '1' }}">
                                <button type="submit" class="btn btn-sm {{ $automation->enabled ? '' : 'btn-cyan' }}">
                                    {{ $automation->enabled ? 'Disable' : 'Enable' }}
                                </button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</section>
@endsection
