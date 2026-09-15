@extends('layouts.app')
@section('title', 'Settlement — ' . $tournament->name . ' — FF Arena Admin')
@section('content')
    <header class="page-head">
        <div class="row-between">
            <h1 class="page-title">🧾 Settlement: {{ $tournament->name }}</h1>
            <div class="row" style="gap: 8px; align-items: center">
                <x-status-pill :status="$tournament->status" :label="strtoupper($tournament->status)" />
                <a class="btn btn-sm" href="{{ route('admin.settlements.index') }}">← All settlements</a>
            </div>
        </div>
        <p class="muted">
            <a href="{{ route('tournaments.show', $tournament) }}">View tournament</a>
        </p>
    </header>

    {{-- Reconciliation summary --}}
    <div class="grid cols-2" style="grid-template-columns: repeat(auto-fit, minmax(170px, 1fr))">
        <div class="stat"><div class="muted">Gross collected</div><div class="num">৳{{ number_format($summary['gross_collected_minor'] / 100, 2) }}</div></div>
        <div class="stat"><div class="muted">Refunded</div><div class="num" style="color: var(--red)">−৳{{ number_format($summary['refunded_minor'] / 100, 2) }}</div></div>
        <div class="stat"><div class="muted">Net collected</div><div class="num">৳{{ number_format($summary['net_collected_minor'] / 100, 2) }}</div></div>
        <div class="stat"><div class="muted">Prize pool (declared)</div><div class="num">৳{{ number_format($summary['prize_pool_minor'] / 100, 2) }}</div></div>
        <div class="stat"><div class="muted">Allocated prizes</div><div class="num" style="color: var(--purple)">৳{{ number_format($summary['allocated_prizes_minor'] / 100, 2) }}</div></div>
        <div class="stat"><div class="muted">Completed payouts</div><div class="num" style="color: var(--green)">৳{{ number_format($summary['completed_payouts_minor'] / 100, 2) }}</div></div>
        <div class="stat"><div class="muted">Platform revenue</div><div class="num">৳{{ number_format($summary['platform_revenue_minor'] / 100, 2) }}</div></div>
        <div class="stat"><div class="muted">Adjustments</div><div class="num">৳{{ number_format($summary['adjustments_minor'] / 100, 2) }}</div></div>
        <div class="stat">
            <div class="muted">Remaining</div>
            <div class="num" style="color: {{ $summary['remaining_minor'] >= 0 ? 'var(--green)' : 'var(--red)' }}">
                ৳{{ number_format($summary['remaining_minor'] / 100, 2) }}
            </div>
        </div>
        <div class="stat">
            <div class="muted">Reconciliation</div>
            <div class="num" style="font-size: 19px; color: var(--amber)">{{ ucfirst($summary['reconciliation_status']) }}</div>
        </div>
    </div>

    {{-- Prize configuration --}}
    <section class="card mt-4" aria-labelledby="prizes-heading">
        <h3 id="prizes-heading">🏆 Prize Configuration</h3>
        @if (! $tiersEditable)
            <p class="muted" style="font-size: .85rem">Tiers are locked — the distribution has been calculated.</p>
        @endif

        @if ($tiersEditable)
            <form method="POST" action="{{ route('admin.settlements.prizes', $tournament) }}">
                @csrf
                <div class="table-wrap">
                    <table>
                        <caption class="sr-only">Prize tiers</caption>
                        <thead>
                            <tr>
                                <th scope="col">Rank</th>
                                <th scope="col">Type</th>
                                <th scope="col">Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            @for ($i = 1; $i <= 10; $i++)
                                @php $tier = $tiers->firstWhere('position', $i); @endphp
                                <tr>
                                    <td style="width: 120px">#{{ $i }} {{ ['1st', '2nd', '3rd'][$i - 1] ?? 'th' }}</td>
                                    <td style="width: 180px">
                                        <label for="tier-type-{{ $i }}" class="sr-only">Type for rank {{ $i }}</label>
                                        <select id="tier-type-{{ $i }}" name="tiers[{{ $i }}][type]">
                                            <option value="fixed" @selected($tier && $tier->type === 'fixed')>Fixed (৳)</option>
                                            <option value="percentage" @selected($tier && $tier->type === 'percentage')>Percentage (%)</option>
                                        </select>
                                    </td>
                                    <td>
                                        <input type="hidden" name="tiers[{{ $i }}][position]" value="{{ $i }}">
                                        <label for="tier-value-{{ $i }}" class="sr-only">Value for rank {{ $i }}</label>
                                        <input type="text" id="tier-value-{{ $i }}" name="tiers[{{ $i }}][value]" placeholder="e.g. 1000 or 50"
                                               value="{{ $tier ? ($tier->type === 'percentage' ? \App\Support\Money::basisPointsToPercent($tier->percentage_bp) : \App\Support\Money::toDecimal($tier->amount_minor)) : '' }}">
                                    </td>
                                </tr>
                            @endfor
                        </tbody>
                    </table>
                </div>
                <button type="submit" class="btn btn-primary btn-sm mt-4">Save Prizes</button>
            </form>
        @else
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Prize tiers</caption>
                    <thead>
                        <tr>
                            <th scope="col">Rank</th>
                            <th scope="col">Type</th>
                            <th scope="col">Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($tiers as $tier)
                            <tr>
                                <td>#{{ $tier->position }}</td>
                                <td>{{ $tier->typeLabel() }}</td>
                                <td>
                                    @if ($tier->isFixed())
                                        ৳{{ number_format($tier->amount_minor / 100, 2) }}
                                    @else
                                        {{ \App\Support\Money::basisPointsToPercent($tier->percentage_bp) }}%
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="muted">No prize tiers configured.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    {{-- Distribution --}}
    <section class="card mt-4" aria-labelledby="distribution-heading">
        <h3 id="distribution-heading">
            🎁 Prize Distribution
            @if ($distribution)
                <x-status-pill :status="$distribution->statusPill()" :label="$distribution->statusLabel()" />
            @endif
        </h3>

        @if ($distribution && $snapshot && $snapshot->isNotEmpty())
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Prize distribution snapshot</caption>
                    <thead>
                        <tr>
                            <th scope="col">Rank</th>
                            <th scope="col">Team</th>
                            <th scope="col">Type</th>
                            <th scope="col">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($snapshot as $item)
                            <tr>
                                <td>#{{ $item->position }}</td>
                                <td><strong>{{ $item->team?->name ?? '—' }}</strong></td>
                                <td class="muted">{{ ucfirst($item->type) }}</td>
                                <td>৳{{ number_format($item->amount_minor / 100, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="muted">No distribution calculated yet.</p>
        @endif

        <div class="row mt-4" style="gap: 10px">
            @if ($tournament->status === 'finished')
                @if (! $distribution || $distribution->isTerminal())
                    <form method="POST" action="{{ route('admin.settlements.calculate', $tournament) }}">
                        @csrf
                        <button type="submit" class="btn btn-cyan btn-sm">Calculate Distribution</button>
                    </form>
                @elseif ($distribution->status === 'draft')
                    <form method="POST" action="{{ route('admin.settlements.calculate', $tournament) }}">
                        @csrf
                        <button type="submit" class="btn btn-cyan btn-sm">Calculate Distribution</button>
                    </form>
                @elseif ($distribution->status === 'calculated')
                    <form method="POST" action="{{ route('admin.settlements.approve', $tournament) }}">
                        @csrf
                        <button type="submit" class="btn btn-primary btn-sm">Approve</button>
                    </form>
                @elseif ($distribution->status === 'approved')
                    <form method="POST" action="{{ route('admin.settlements.process', $tournament) }}" onsubmit="return confirm('Process all payouts and finalize settlement?')">
                        @csrf
                        <button type="submit" class="btn btn-green btn-sm">Process Payouts</button>
                    </form>
                @endif
            @endif

            @if ($distribution && in_array($distribution->status, ['draft', 'calculated', 'approved'], true))
                <form method="POST" action="{{ route('admin.settlements.cancel', $tournament) }}">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-danger">Cancel</button>
                </form>
            @endif
        </div>
    </section>

    {{-- Payouts --}}
    <section class="card mt-4" aria-labelledby="payouts-heading">
        <h3 id="payouts-heading">💸 Payouts</h3>
        @if ($payouts->isEmpty())
            <p class="muted">No payouts yet.</p>
        @else
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Payouts for this settlement</caption>
                    <thead>
                        <tr>
                            <th scope="col">Rank</th>
                            <th scope="col">Team</th>
                            <th scope="col">Recipient</th>
                            <th scope="col">Amount</th>
                            <th scope="col">Method</th>
                            <th scope="col">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($payouts as $payout)
                            <tr>
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
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="muted mt-3" style="font-size: .85rem">
                <a href="{{ route('admin.payouts.index') }}">Manage all payouts →</a>
            </p>
        @endif
    </section>

    {{-- Adjustments --}}
    <section class="card mt-4" aria-labelledby="adjustments-heading">
        <h3 id="adjustments-heading">🔧 Financial Adjustments</h3>
        @if ($adjustments->isEmpty())
            <p class="muted">No adjustments.</p>
        @else
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Financial adjustments</caption>
                    <thead>
                        <tr>
                            <th scope="col">Type</th>
                            <th scope="col">Amount</th>
                            <th scope="col">Reason</th>
                            <th scope="col">By</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($adjustments as $adjustment)
                            <tr>
                                <td class="muted">{{ ucfirst($adjustment->type) }}</td>
                                <td style="{{ $adjustment->amount_minor < 0 ? 'color: var(--red)' : 'color: var(--green)' }}">
                                    {{ $adjustment->amount_minor < 0 ? '−' : '+' }}৳{{ number_format(abs($adjustment->amount_minor) / 100, 2) }}
                                </td>
                                <td class="muted" style="font-size: .85rem">{{ $adjustment->reason }}</td>
                                <td class="muted" style="font-size: .85rem">{{ $adjustment->actor?->name ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if (! $settlement)
            <form method="POST" action="{{ route('admin.settlements.adjust', $tournament) }}" class="mt-4">
                @csrf
                <div class="row" style="align-items: flex-end">
                    <div class="field" style="min-width: 140px">
                        <label for="adjust-amount">Amount (৳, negative to debit)</label>
                        <input type="text" id="adjust-amount" name="amount" placeholder="e.g. 100 or -100" required>
                    </div>
                    <div class="field" style="min-width: 150px">
                        <label for="adjust-type">Type</label>
                        <select id="adjust-type" name="type">
                            <option value="correction">Correction</option>
                            <option value="reversal">Reversal</option>
                        </select>
                    </div>
                    <div class="field" style="min-width: 220px">
                        <label for="adjust-reason">Reason</label>
                        <input type="text" id="adjust-reason" name="reason" required>
                    </div>
                    <button type="submit" class="btn btn-sm btn-cyan">Add Adjustment</button>
                </div>
            </form>
        @else
            <p class="muted mt-4" style="font-size: .85rem">Adjustments are frozen after finalization.</p>
        @endif
    </section>

    {{-- Finalized snapshot --}}
    @if ($settlement)
        <section class="card mt-4" aria-labelledby="finalized-heading">
            <h3 id="finalized-heading">📦 Finalized Settlement</h3>
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Finalized settlement summary</caption>
                    <thead>
                        <tr>
                            <th scope="col">Gross</th>
                            <th scope="col">Refunded</th>
                            <th scope="col">Net</th>
                            <th scope="col">Pool</th>
                            <th scope="col">Allocated</th>
                            <th scope="col">Completed payouts</th>
                            <th scope="col">Revenue</th>
                            <th scope="col">Adjustments</th>
                            <th scope="col">Result</th>
                            <th scope="col">Finalized by</th>
                            <th scope="col">Finalized at</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>৳{{ number_format($settlement->gross_collected_minor / 100, 2) }}</td>
                            <td>৳{{ number_format($settlement->refunded_minor / 100, 2) }}</td>
                            <td>৳{{ number_format($settlement->net_collected_minor / 100, 2) }}</td>
                            <td>৳{{ number_format($settlement->prize_pool_minor / 100, 2) }}</td>
                            <td>৳{{ number_format($settlement->allocated_prizes_minor / 100, 2) }}</td>
                            <td>৳{{ number_format($settlement->completed_payouts_minor / 100, 2) }}</td>
                            <td>৳{{ number_format($settlement->platform_revenue_minor / 100, 2) }}</td>
                            <td>৳{{ number_format($settlement->adjustments_minor / 100, 2) }}</td>
                            <td><x-status-pill :status="$settlement->statusPill()" :label="$settlement->statusLabel()" /></td>
                            <td class="muted">{{ $settlement->finalizedBy?->name ?? '—' }}</td>
                            <td class="muted" style="font-size: .8rem">{{ $settlement->finalized_at?->format('d M Y, h:i A') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    @endif
@endsection
