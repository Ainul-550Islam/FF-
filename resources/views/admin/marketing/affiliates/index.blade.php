@extends('layouts.app')

@section('title', 'Affiliate Payouts — FF Arena Admin')

@section('content')
<section class="container" style="max-width: 1040px">
    <header class="page-head" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h1 class="page-title" style="margin: 0">🤝 Partner Affiliate Payouts</h1>
        <div>
            <a href="{{ route('admin.marketing.analytics.export', ['type' => 'affiliates']) }}" class="btn btn-sm btn-cyan">📥 Export Payouts CSV</a>
        </div>
    </header>

    @if (session('success'))
        <div class="alert alert-success" role="status">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-error" role="alert">{{ session('error') }}</div>
    @endif

    <div class="row" style="display: flex; gap: 16px; margin-bottom: 24px; flex-wrap: wrap">
        <div class="card" style="flex: 1 1 200px">
            <span class="muted" style="font-size: 0.85rem">Pending Requests</span>
            <h2 style="margin: 4px 0 0; color: #f59e0b">{{ $summary['pending_count'] }}</h2>
            <p class="muted" style="margin: 4px 0 0; font-size: 0.85rem">৳{{ number_format($summary['pending_minor'] / 100, 2) }}</p>
        </div>
        <div class="card" style="flex: 1 1 200px">
            <span class="muted" style="font-size: 0.85rem">Settled Payouts</span>
            <h2 style="margin: 4px 0 0; color: #10b981">৳{{ number_format($summary['completed_minor'] / 100, 2) }}</h2>
            <p class="muted" style="margin: 4px 0 0; font-size: 0.85rem">Total disbursed</p>
        </div>
        <div class="card" style="flex: 1 1 200px">
            <span class="muted" style="font-size: 0.85rem">Total Records</span>
            <h2 style="margin: 4px 0 0">{{ $summary['total_payouts'] }}</h2>
            <p class="muted" style="margin: 4px 0 0; font-size: 0.85rem">{{ $summary['rejected_count'] }} rejected</p>
        </div>
    </div>

    <div class="card">
        <div class="row" style="display: flex; gap: 10px; margin-bottom: 16px; flex-wrap: wrap">
            <a href="{{ route('admin.marketing.affiliates.payouts.index') }}" class="btn btn-sm {{ empty($status) ? 'btn-cyan' : '' }}">All</a>
            <a href="{{ route('admin.marketing.affiliates.payouts.index', ['status' => 'pending']) }}" class="btn btn-sm {{ $status === 'pending' ? 'btn-cyan' : '' }}">Pending</a>
            <a href="{{ route('admin.marketing.affiliates.payouts.index', ['status' => 'completed']) }}" class="btn btn-sm {{ $status === 'completed' ? 'btn-cyan' : '' }}">Completed</a>
            <a href="{{ route('admin.marketing.affiliates.payouts.index', ['status' => 'rejected']) }}" class="btn btn-sm {{ $status === 'rejected' ? 'btn-cyan' : '' }}">Rejected</a>
        </div>

        @if ($payouts->isEmpty())
            <p class="muted" style="text-align: center; padding: 24px 0">No affiliate payout records found.</p>
        @else
            <div style="overflow-x: auto">
                <table style="width: 100%; border-collapse: collapse">
                    <thead>
                        <tr style="text-align: left; border-bottom: 1px solid rgba(255,255,255,0.1)">
                            <th style="padding: 10px">ID</th>
                            <th style="padding: 10px">Partner / Code</th>
                            <th style="padding: 10px">Recipient</th>
                            <th style="padding: 10px">Amount</th>
                            <th style="padding: 10px">Status</th>
                            <th style="padding: 10px">Requested</th>
                            <th style="padding: 10px">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($payouts as $payout)
                            <tr style="border-bottom: 1px solid rgba(255,255,255,0.05)">
                                <td style="padding: 10px">#{{ $payout->id }}</td>
                                <td style="padding: 10px">
                                    <strong>{{ $payout->affiliate?->name ?? 'Partner' }}</strong><br>
                                    <code style="font-size: 0.85rem">{{ $payout->affiliate?->code }}</code>
                                </td>
                                <td style="padding: 10px">{{ $payout->user?->name ?? 'User #'.$payout->user_id }}</td>
                                <td style="padding: 10px"><strong>৳{{ number_format($payout->amount_minor / 100, 2) }}</strong></td>
                                <td style="padding: 10px">
                                    @if ($payout->status === 'pending')
                                        <span style="color: #f59e0b">● Pending</span>
                                    @elseif ($payout->status === 'completed')
                                        <span style="color: #10b981">✓ Completed</span>
                                    @elseif ($payout->status === 'rejected')
                                        <span style="color: #ef4444">✕ Rejected</span>
                                    @else
                                        <span>{{ ucfirst($payout->status) }}</span>
                                    @endif
                                </td>
                                <td style="padding: 10px" class="muted">{{ $payout->requested_at?->format('d M Y, H:i') }}</td>
                                <td style="padding: 10px">
                                    <a href="{{ route('admin.marketing.affiliates.payouts.show', $payout) }}" class="btn btn-sm">Inspect &rarr;</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div style="margin-top: 16px">
                {{ $payouts->withQueryString()->links() }}
            </div>
        @endif
    </div>
</section>
@endsection
