@extends('layouts.app')
@section('title', 'Payouts — FF Arena Admin')
@section('content')
    <header class="page-head">
        <h1 class="page-title">💸 Payouts</h1>
    </header>

    <section class="card" aria-labelledby="filters-heading">
        <h2 id="filters-heading" class="sr-only">Payout filters</h2>
        <form method="GET" action="{{ route('admin.payouts.index') }}">
            <div class="row" style="align-items: flex-end">
                <div class="field" style="min-width: 160px">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="">All statuses</option>
                        @foreach ($statuses as $s)
                            <option value="{{ $s }}" @selected($status === $s)>{{ ucfirst($s) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field" style="min-width: 220px">
                    <label for="tournament_id">Tournament</label>
                    <select id="tournament_id" name="tournament_id">
                        <option value="">All tournaments</option>
                        @foreach ($tournaments as $t)
                            <option value="{{ $t->id }}" @selected($tournamentId === $t->id)>{{ $t->name }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="btn btn-sm btn-cyan">Filter</button>
            </div>
        </form>
    </section>

    <section class="card" style="padding: 0" aria-labelledby="payouts-heading">
        <h2 id="payouts-heading" class="sr-only">Payouts</h2>
        @if ($payouts->isEmpty())
            <x-empty-state title="No payouts match your filters" icon="💸">
                Adjust the filters and try again.
            </x-empty-state>
        @else
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Payouts matching your filters</caption>
                    <thead>
                        <tr>
                            <th scope="col">ID</th>
                            <th scope="col">Tournament</th>
                            <th scope="col">Rank</th>
                            <th scope="col">Team</th>
                            <th scope="col">Recipient</th>
                            <th scope="col">Amount</th>
                            <th scope="col">Method</th>
                            <th scope="col">Status</th>
                            <th scope="col">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($payouts as $payout)
                            <tr>
                                <td><strong>#{{ $payout->id }}</strong></td>
                                <td>{{ $payout->tournament?->name ?? '—' }}</td>
                                <td>#{{ $payout->rank }}</td>
                                <td>{{ $payout->team?->name ?? '—' }}</td>
                                <td class="muted" style="font-size: .85rem">{{ $payout->recipient?->name ?? '—' }}</td>
                                <td>৳{{ number_format($payout->amount_minor / 100, 2) }}</td>
                                <td class="muted">{{ $payout->payout_method }}</td>
                                <td>
                                    <x-status-pill :status="$payout->statusPill()" :label="$payout->statusLabel()" />
                                    @if ($payout->failure_reason)
                                        <div class="muted" style="font-size: .8rem">{{ $payout->failure_reason }}</div>
                                    @endif
                                </td>
                                <td>
                                    <div class="row" style="gap: 8px; align-items: center">
                                        @if ($payout->status === 'pending')
                                            <form method="POST" action="{{ route('admin.payouts.approve', $payout) }}">
                                                @csrf
                                                <button type="submit" class="btn btn-green btn-sm">Approve</button>
                                            </form>
                                        @elseif ($payout->status === 'approved')
                                            <form method="POST" action="{{ route('admin.payouts.process', $payout) }}">
                                                @csrf
                                                <button type="submit" class="btn btn-green btn-sm">Process</button>
                                            </form>
                                        @elseif ($payout->status === 'processing' && $payout->payout_method === 'manual')
                                            <form method="POST" action="{{ route('admin.payouts.complete', $payout) }}">
                                                @csrf
                                                <label for="external-ref-{{ $payout->id }}" class="sr-only">External reference</label>
                                                <input type="text" id="external-ref-{{ $payout->id }}" name="reference" placeholder="External ref" style="max-width: 120px">
                                                <button type="submit" class="btn btn-green btn-sm">Complete</button>
                                            </form>
                                        @endif

                                        @if (in_array($payout->status, ['pending', 'approved'], true))
                                            <form method="POST" action="{{ route('admin.payouts.cancel', $payout) }}">
                                                @csrf
                                                <button type="submit" class="btn btn-sm">Cancel</button>
                                            </form>
                                        @endif

                                        @if (in_array($payout->status, ['pending', 'approved', 'processing'], true))
                                            <form method="POST" action="{{ route('admin.payouts.fail', $payout) }}">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-danger">Fail</button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $payouts->links() }}</div>
        @endif
    </section>
@endsection
