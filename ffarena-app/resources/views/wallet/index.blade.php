@extends('layouts.app')
@section('title', 'My Wallet — FF Arena')
@section('content')
    <header class="page-head">
        <h1 class="page-title">👛 My Wallet</h1>
    </header>

    <div class="grid cols-2" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr))">
        <div class="stat">
            <div class="muted">Balance</div>
            <div class="num" style="color: var(--green)">৳{{ number_format($wallet->balance_minor / 100, 2) }}</div>
        </div>
        <div class="stat">
            <div class="muted">Currency</div>
            <div class="num">{{ $wallet->currency }}</div>
        </div>
    </div>

    <section class="card" aria-labelledby="identity-heading">
        <h3 id="identity-heading">🪪 Identity Verification</h3>
        <div class="row-between">
            <div>
                <x-status-pill :status="$identity->statusPill()" :label="$identity->statusLabel()" />
                @if ($identity->expires_at && $identity->status === 'verified')
                    <span class="muted" style="font-size: .8rem"> · expires {{ $identity->expires_at->format('d M Y') }}</span>
                @endif
                @if ($identity->notes)
                    <p class="muted mt-1" style="font-size: .8rem">{{ $identity->notes }}</p>
                @endif
            </div>
            @if (in_array($identity->status, ['unverified', 'rejected', 'expired'], true))
                <form method="POST" action="{{ route('security.identity.request') }}">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-cyan">Request Verification</button>
                </form>
            @endif
        </div>
    </section>

    <div class="grid cols-2">
        <section class="card" aria-labelledby="transactions-heading">
            <h3 id="transactions-heading">🧾 Wallet Transactions</h3>
            @if ($ledger->isEmpty())
                <x-empty-state title="No wallet transactions yet" icon="🧾">
                    Deposits, entry payments and prize payouts will appear here.
                </x-empty-state>
            @else
                <div class="table-wrap">
                    <table>
                        <caption class="sr-only">Wallet ledger entries</caption>
                        <thead>
                            <tr>
                                <th scope="col">Date</th>
                                <th scope="col">Type</th>
                                <th scope="col">Amount</th>
                                <th scope="col">Description</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($ledger as $entry)
                                <tr>
                                    <td class="muted" style="font-size: .8rem">{{ $entry->created_at->format('d M, h:i A') }}</td>
                                    <td><x-status-pill :status="$entry->isCredit() ? 'confirmed' : 'finished'" :label="strtoupper($entry->type)" /></td>
                                    <td style="{{ $entry->isCredit() ? 'color: var(--green)' : 'color: var(--red)' }}">
                                        {{ $entry->isCredit() ? '+' : '−' }}৳{{ number_format($entry->amount_minor / 100, 2) }}
                                    </td>
                                    <td class="muted" style="font-size: .85rem">{{ $entry->description }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="card" aria-labelledby="payments-heading">
            <h3 id="payments-heading">💳 Payment History</h3>
            @if ($payments->isEmpty())
                <x-empty-state title="No payments yet" icon="💳">
                    Entry-fee payments will appear here.
                </x-empty-state>
            @else
                <div class="table-wrap">
                    <table>
                        <caption class="sr-only">Entry-fee payments</caption>
                        <thead>
                            <tr>
                                <th scope="col">Tournament</th>
                                <th scope="col">Team</th>
                                <th scope="col">Amount</th>
                                <th scope="col">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($payments as $payment)
                                <tr>
                                    <td>{{ $payment->tournament?->name ?? '—' }}</td>
                                    <td>{{ $payment->team?->name ?? '—' }}</td>
                                    <td>৳{{ number_format($payment->amount_minor / 100, 2) }}</td>
                                    <td>
                                        <x-status-pill :status="$payment->statusPill()" :label="strtoupper($payment->status)" />
                                        @if ($payment->refund)
                                            <span class="muted" style="font-size: .8rem">(refunded)</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="card" aria-labelledby="payouts-heading">
            <h3 id="payouts-heading">🏆 Prize Payouts</h3>
            @if ($payouts->isEmpty())
                <x-empty-state title="No prize payouts yet" icon="🏆">
                    Winnings will appear here after tournaments settle.
                </x-empty-state>
            @else
                <div class="table-wrap">
                    <table>
                        <caption class="sr-only">Prize payouts</caption>
                        <thead>
                            <tr>
                                <th scope="col">Tournament</th>
                                <th scope="col">Rank</th>
                                <th scope="col">Amount</th>
                                <th scope="col">Status</th>
                                <th scope="col">Paid</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($payouts as $payout)
                                <tr>
                                    <td>{{ $payout->tournament?->name ?? '—' }}</td>
                                    <td>#{{ $payout->rank }}</td>
                                    <td style="color: var(--green)">+৳{{ number_format($payout->amount_minor / 100, 2) }}</td>
                                    <td><x-status-pill :status="$payout->statusPill()" :label="$payout->statusLabel()" /></td>
                                    <td class="muted" style="font-size: .8rem">{{ $payout->processed_at?->format('d M, h:i A') ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
@endsection
