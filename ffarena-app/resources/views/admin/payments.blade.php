@extends('layouts.app')
@section('title', 'Payments — FF Arena Admin')
@section('content')
    <header class="page-head">
        <h1 class="page-title">💸 Payments</h1>
    </header>

    <section class="card" aria-labelledby="filters-heading">
        <h2 id="filters-heading" class="sr-only">Payment filters</h2>
        <form method="GET" action="{{ route('admin.payments.index') }}">
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

    <section class="card" style="padding: 0" aria-labelledby="payments-heading">
        <h2 id="payments-heading" class="sr-only">Payments</h2>
        @if ($payments->isEmpty())
            <x-empty-state title="No payments match your filters" icon="💸">
                Adjust the filters and try again.
            </x-empty-state>
        @else
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Payments matching your filters</caption>
                    <thead>
                        <tr>
                            <th scope="col">ID</th>
                            <th scope="col">Tournament</th>
                            <th scope="col">Team</th>
                            <th scope="col">Payer</th>
                            <th scope="col">Amount</th>
                            <th scope="col">TrxID</th>
                            <th scope="col">Status</th>
                            <th scope="col">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($payments as $payment)
                            <tr>
                                <td><strong>#{{ $payment->id }}</strong></td>
                                <td>{{ $payment->tournament?->name ?? '—' }}</td>
                                <td>{{ $payment->team?->name ?? '—' }}</td>
                                <td class="muted" style="font-size: .85rem">{{ $payment->payer?->name ?? '—' }}</td>
                                <td>৳{{ number_format($payment->amount_minor / 100, 2) }}</td>
                                <td class="muted" style="font-size: .8rem">{{ $payment->trx_id }}</td>
                                <td><x-status-pill :status="$payment->statusPill()" :label="$payment->statusLabel()" /></td>
                                <td>
                                    @if (in_array($payment->status, ['pending', 'processing'], true))
                                        <div class="row" style="gap: 8px">
                                            <form method="POST" action="{{ route('admin.payments.verify', $payment) }}">
                                                @csrf
                                                <button type="submit" class="btn btn-green btn-sm">Verify</button>
                                            </form>
                                            <form method="POST" action="{{ route('admin.payments.fail', $payment) }}">
                                                @csrf
                                                <button type="submit" class="btn btn-sm">Reject</button>
                                            </form>
                                        </div>
                                    @elseif ($payment->isRefundable())
                                        <form method="POST" action="{{ route('admin.payments.refund', $payment) }}">
                                            @csrf
                                            <div class="row" style="gap: 6px; align-items: center">
                                                <label for="refund-reason-{{ $payment->id }}" class="sr-only">Refund reason</label>
                                                <input type="text" id="refund-reason-{{ $payment->id }}" name="reason" placeholder="Refund reason" required style="max-width: 140px">
                                                <button type="submit" class="btn btn-sm" style="border-color: var(--amber); color: var(--amber)">Refund</button>
                                            </div>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $payments->links() }}</div>
        @endif
    </section>
@endsection
