@extends('layouts.app')
@section('title', 'Admin Dashboard — FF Arena')
@section('content')
    <header class="page-head">
        <h1 class="page-title">🛡 Admin Dashboard</h1>
    </header>

    <nav class="card row" aria-label="Admin navigation">
        <span class="muted">Operations:</span>
        <a href="{{ route('admin.analytics.index') }}" class="btn btn-sm">Analytics</a>
        <a href="{{ route('admin.audit.index') }}" class="btn btn-sm">Audit</a>
        <a href="{{ route('admin.accounts.index') }}" class="btn btn-sm btn-cyan">Accounts</a>
        <a href="{{ route('admin.support.index') }}" class="btn btn-sm btn-cyan">Support</a>
        <span class="muted">Financials:</span>
        <a href="{{ route('admin.payments.index') }}" class="btn btn-sm">Payments</a>
        <a href="{{ route('admin.settlements.index') }}" class="btn btn-sm btn-cyan">Settlements</a>
        <a href="{{ route('admin.payouts.index') }}" class="btn btn-sm">Payouts</a>
        <span class="muted">Security:</span>
        <a href="{{ route('admin.security.dashboard') }}" class="btn btn-sm">Security</a>
    </nav>

    <div class="grid cols-2" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr))">
        <div class="stat"><div class="muted">Tournaments</div><div class="num">{{ $stats['tournaments'] }}</div></div>
        <div class="stat"><div class="muted">Teams</div><div class="num">{{ $stats['teams'] }}</div></div>
        <div class="stat"><div class="muted">Verified payments</div><div class="num">{{ $stats['verified_payments'] }}</div></div>
        <div class="stat"><div class="muted">Collected (৳)</div><div class="num">{{ number_format($stats['revenue']) }}</div></div>
        <div class="stat"><div class="muted">Platform commission (8%)</div><div class="num" style="color: var(--green)">৳{{ number_format($stats['commission']) }}</div></div>
    </div>

    <section class="card" aria-labelledby="moderators-heading">
        <h3 id="moderators-heading">🛡 Moderators</h3>
        <form method="POST" action="{{ route('admin.users.moderate') }}">
            @csrf
            <div class="row" style="align-items: flex-end">
                <div class="field grow" style="max-width: 320px">
                    <label for="promote-email">Promote a user to moderator (by email)</label>
                    <input type="email" id="promote-email" name="email" placeholder="user@example.com" required>
                </div>
                <button type="submit" class="btn btn-cyan btn-sm">Promote</button>
            </div>
        </form>
        @if ($moderators->isEmpty())
            <p class="muted mt-3">No moderators yet.</p>
        @else
            <div class="table-wrap mt-3">
                <table>
                    <caption class="sr-only">Moderators</caption>
                    <thead>
                        <tr>
                            <th scope="col">Name</th>
                            <th scope="col">Email</th>
                            <th scope="col"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($moderators as $moderator)
                            <tr>
                                <td>{{ $moderator->name }}</td>
                                <td class="muted">{{ $moderator->email }}</td>
                                <td>
                                    <form method="POST" action="{{ route('admin.users.unmoderate', $moderator) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-danger">Demote</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="card" aria-labelledby="pending-heading">
        <h3 id="pending-heading">💸 Pending Payments</h3>
        @if ($pendingPayments->isEmpty())
            <p class="muted">No pending payments.</p>
        @else
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Payments awaiting verification</caption>
                    <thead>
                        <tr>
                            <th scope="col">Tournament</th>
                            <th scope="col">Team</th>
                            <th scope="col">Amount</th>
                            <th scope="col">TrxID</th>
                            <th scope="col">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($pendingPayments as $p)
                            <tr>
                                <td>{{ $p->tournament->name }}</td>
                                <td>{{ $p->team->name }}</td>
                                <td>৳{{ number_format($p->amount_minor / 100, 2) }}</td>
                                <td>{{ $p->trx_id }}</td>
                                <td>
                                    <div class="row" style="gap: 8px">
                                        <form method="POST" action="{{ route('admin.payments.verify', $p) }}">
                                            @csrf
                                            <button type="submit" class="btn btn-green btn-sm">Verify</button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.payments.fail', $p) }}">
                                            @csrf
                                            <button type="submit" class="btn btn-sm">Reject</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="muted mt-3" style="font-size: .85rem">
                <a href="{{ route('admin.payments.index') }}">View all payments &amp; refunds →</a>
            </p>
        @endif
    </section>
@endsection
