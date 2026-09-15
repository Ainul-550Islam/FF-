@extends('layouts.app')
@section('title', 'Active Sessions — FF Arena')
@section('content')
    <header class="page-head">
        <h1 class="page-title">Active Sessions</h1>
    </header>

    <section class="card" aria-labelledby="sessions-heading">
        <h3 id="sessions-heading" class="sr-only">Your active sessions</h3>
        @if ($sessions->isEmpty())
            <x-empty-state title="No active sessions found" icon="🖥️">
                Your signed-in devices will appear here.
            </x-empty-state>
        @else
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Devices currently signed in</caption>
                    <thead>
                        <tr>
                            <th scope="col">Device</th>
                            <th scope="col">Last activity</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($sessions as $session)
                            <tr>
                                <td>
                                    {{ $session['device_label'] }}
                                    @if ($session['is_current'])
                                        <x-status-pill status="live" label="This device" />
                                    @endif
                                </td>
                                <td class="muted">{{ \Illuminate\Support\Carbon::createFromTimestamp($session['last_activity'])->diffForHumans() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="row mt-4">
                <form method="POST" action="{{ route('settings.sessions.revokeOthers') }}">
                    @csrf
                    <button type="submit" class="btn btn-sm">Sign out other devices</button>
                </form>
                <form method="POST" action="{{ route('settings.sessions.revokeAll') }}">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-danger">Sign out everywhere</button>
                </form>
            </div>
        @endif
    </section>
@endsection
