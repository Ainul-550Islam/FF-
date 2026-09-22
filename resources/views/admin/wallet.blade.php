@extends('layouts.app')
@section('title', 'Wallet — ' . $user->name . ' · FF Arena Admin')
@section('content')
    <header class="page-head">
        <h1 class="page-title">👛 Wallet: {{ $user->name }}</h1>
        <p class="muted">{{ $user->email }}</p>
    </header>

    <div class="grid cols-2" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr))">
        <div class="stat">
            <div class="muted">Balance</div>
            <div class="num" style="color: var(--green)">৳{{ number_format($wallet->balance_minor / 100, 2) }}</div>
        </div>
        <div class="stat">
            <div class="muted">Ledger reconciliation</div>
            <div class="num" style="color: {{ $delta === 0 ? 'var(--green)' : 'var(--red)' }}">
                {{ $delta === 0 ? '✓ Consistent' : 'Δ ' . $delta }}
            </div>
        </div>
    </div>

    <div class="grid cols-2 mt-4">
        <section class="card" aria-labelledby="adjust-heading">
            <h3 id="adjust-heading">➕ Credit / ➖ Debit</h3>
            <form method="POST" action="{{ route('admin.wallet.credit', $user) }}">
                @csrf
                <div class="row" style="align-items: flex-end">
                    <div class="field grow">
                        <label for="credit-amount">Credit amount (৳)</label>
                        <input type="text" id="credit-amount" name="amount" pattern="\d+(\.\d{1,2})?" placeholder="100.00" required>
                    </div>
                    <div class="field grow" style="flex: 2">
                        <label for="credit-description">Description</label>
                        <input type="text" id="credit-description" name="description" placeholder="e.g. Prize credit" maxlength="255" required>
                    </div>
                    <button type="submit" class="btn btn-green btn-sm">Credit</button>
                </div>
            </form>

            <form method="POST" action="{{ route('admin.wallet.debit', $user) }}" class="mt-4">
                @csrf
                <div class="row" style="align-items: flex-end">
                    <div class="field grow">
                        <label for="debit-amount">Debit amount (৳)</label>
                        <input type="text" id="debit-amount" name="amount" pattern="\d+(\.\d{1,2})?" placeholder="50.00" required>
                    </div>
                    <div class="field grow" style="flex: 2">
                        <label for="debit-description">Description</label>
                        <input type="text" id="debit-description" name="description" placeholder="e.g. Fee correction" maxlength="255" required>
                    </div>
                    <button type="submit" class="btn btn-sm btn-danger">Debit</button>
                </div>
            </form>
        </section>

        <section class="card" aria-labelledby="summary-heading">
            <h3 id="summary-heading">📊 Summary</h3>
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Wallet summary</caption>
                    <tbody>
                        <tr>
                            <th scope="row">Credits</th>
                            <td style="color: var(--green)">{{ $ledger->where('direction', 'credit')->count() }} entries</td>
                        </tr>
                        <tr>
                            <th scope="row">Debits</th>
                            <td style="color: var(--red)">{{ $ledger->where('direction', 'debit')->count() }} entries</td>
                        </tr>
                        <tr>
                            <th scope="row">Status</th>
                            <td><x-status-pill :status="$wallet->status" :label="strtoupper($wallet->status)" /></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <section class="card" aria-labelledby="ledger-heading">
        <h3 id="ledger-heading">🧾 Ledger</h3>
        @if ($ledger->isEmpty())
            <p class="muted">No ledger entries.</p>
        @else
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Wallet ledger entries</caption>
                    <thead>
                        <tr>
                            <th scope="col">Date</th>
                            <th scope="col">Direction</th>
                            <th scope="col">Type</th>
                            <th scope="col">Amount</th>
                            <th scope="col">Balance After</th>
                            <th scope="col">Actor</th>
                            <th scope="col">Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($ledger as $entry)
                            <tr>
                                <td class="muted" style="font-size: .8rem">{{ $entry->created_at->format('d M, h:i A') }}</td>
                                <td><x-status-pill :status="$entry->isCredit() ? 'confirmed' : 'finished'" :label="strtoupper($entry->direction)" /></td>
                                <td class="muted" style="font-size: .85rem">{{ $entry->type }}</td>
                                <td style="{{ $entry->isCredit() ? 'color: var(--green)' : 'color: var(--red)' }}">
                                    {{ $entry->isCredit() ? '+' : '−' }}৳{{ number_format($entry->amount_minor / 100, 2) }}
                                </td>
                                <td>৳{{ number_format($entry->balance_after / 100, 2) }}</td>
                                <td class="muted" style="font-size: .85rem">{{ $entry->actor?->name ?? '—' }}</td>
                                <td class="muted" style="font-size: .85rem">{{ $entry->description }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
