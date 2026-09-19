@extends('layouts.app')

@section('title', 'Gold Transaction History - Reconciliation')

@section('content')
<div class="container" style="max-width: 1000px; margin: 0 auto; padding: 24px;">
    <h1 style="font-size: 28px; font-weight: 800; margin: 0 0 8px;">🪙 Gold Transaction History</h1>
    <p style="color: var(--text-muted); margin: 0 0 24px;">Gold wallets transactions - Gold at stake, win, refund, video ad, magic chest, spin2win, daily bonus, referral, scratch card - Financial reconciliation must hold</p>

    <div class="card">
        <div style="padding: 20px;">
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Date</th><th>Type</th><th>Amount</th><th>Balance After</th><th>Reference</th><th>Description</th></tr></thead>
                    <tbody>
                        @forelse($transactions as $tx)
                        <tr>
                            <td style="font-size: 11px;">{{ $tx->created_at->format('Y-m-d H:i') }}</td>
                            <td><span class="badge" style="background: {{ $tx->amount > 0 ? '#27ae60' : '#e74c3c' }}; color: white;">{{ $tx->type }}</span></td>
                            <td style="font-weight: 700; color: {{ $tx->amount > 0 ? '#27ae60' : '#e74c3c' }};">{{ $tx->amount > 0 ? '+' : '' }}{{ $tx->amount }}</td>
                            <td>{{ $tx->balance_after }}</td>
                            <td style="font-size: 11px;">{{ $tx->reference_type }} {{ $tx->reference_id }}</td>
                            <td style="font-size: 11px;">{{ $tx->description }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="6" style="text-align: center; color: var(--text-muted);">No gold transactions yet - Initial 5000 gold + earn via games, video ads, magic chests, spin2win, referral BGI20</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div style="margin-top: 16px; text-align: center;">
        <a href="{{ route('gameberry.economy.index') }}" class="btn btn-secondary">Back to Economy</a>
    </div>
</div>
@endsection
