@extends('layouts.app')
@section('title', 'Gold & Gem Wallets - Reconciliation')
@section('content')
<div class="container" style="max-width: 1000px; margin: 0 auto; padding: 24px;">
<h1 style="font-size: 28px; font-weight: 800;">💰 Gold & Gem Wallets - Reconciliation</h1>
<p style="color: var(--text-muted);">Gold wallets gem wallets transactions - Financial totals must reconcile - Gold at stake, magic chest, video ads, gems, lucky dice, spin2win</p>
<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px;">
<div class="card" style="padding: 20px; border: 2px solid #f1c40f;">
<h3>🪙 Gold Wallet</h3>
<div style="font-size: 32px; font-weight: 800;">{{ number_format($goldWallet->gold_balance) }} Gold</div>
<div style="font-size: 12px; color: var(--text-muted); margin-top: 8px;">
Total Earned: {{ number_format($goldWallet->total_earned) }} | Spent: {{ number_format($goldWallet->total_spent) }} | Won: {{ number_format($goldWallet->total_won) }} | Lost: {{ number_format($goldWallet->total_lost) }}
</div>
<div style="margin-top: 12px; font-size: 12px; padding: 8px; border-radius: 8px; background: {{ $goldReconcile['is_balanced'] ? '#27ae60' : '#e74c3c' }}; color: white;">
{{ $goldReconcile['is_balanced'] ? '✅ Reconciled' : '❌ Mismatch difference '.$goldReconcile['difference'] }} | Wallet: {{ $goldReconcile['wallet_balance'] }} | Computed: {{ $goldReconcile['computed_balance'] }} | Tx: {{ $goldReconcile['total_transactions'] }}
</div>
<a href="{{ route('gameberry.economy.gold_history') }}" class="btn btn-sm btn-secondary" style="margin-top: 12px;">Gold History</a>
</div>
<div class="card" style="padding: 20px; border: 2px solid #9b59b6;">
<h3>💎 Gem Wallet</h3>
<div style="font-size: 32px; font-weight: 800;">{{ number_format($gemWallet->gem_balance) }} Gems</div>
<div style="font-size: 12px; color: var(--text-muted); margin-top: 8px;">
Earned: {{ number_format($gemWallet->total_earned) }} | Spent: {{ number_format($gemWallet->total_spent) }} | Purchased: {{ number_format($gemWallet->total_purchased) }}
</div>
<div style="margin-top: 12px; font-size: 12px; padding: 8px; border-radius: 8px; background: {{ $gemReconcile['is_balanced'] ? '#27ae60' : '#e74c3c' }}; color: white;">
{{ $gemReconcile['is_balanced'] ? '✅ Reconciled' : '❌ Mismatch difference '.$gemReconcile['difference'] }} | Wallet: {{ $gemReconcile['wallet_balance'] }} | Computed: {{ $gemReconcile['computed_balance'] }}
</div>
<a href="{{ route('gameberry.economy.gem_history') }}" class="btn btn-sm btn-secondary" style="margin-top: 12px;">Gem History</a>
</div>
</div>
<div class="card" style="margin-top: 20px; padding: 20px;">
<h3>Reconciliation Details - Financial Totals Must Reconcile (G1)</h3>
<p style="font-size: 13px; color: var(--text-muted);">Gold and gem wallets must reconcile with transaction sums. If differences STOP, do not declare complete. Initial gold 5000, initial gems 10, plus credits minus debits must equal wallet balance. This ensures no money leak.</p>
<div class="table-wrap">
<table class="table">
<thead><tr><th>Wallet</th><th>Balance</th><th>Computed</th><th>Difference</th><th>Balanced</th></tr></thead>
<tbody>
<tr><td>Gold</td><td>{{ $goldReconcile['wallet_balance'] }}</td><td>{{ $goldReconcile['computed_balance'] }}</td><td>{{ $goldReconcile['difference'] }}</td><td>{{ $goldReconcile['is_balanced'] ? '✅ Yes' : '❌ No STOP' }}</td></tr>
<tr><td>Gem</td><td>{{ $gemReconcile['wallet_balance'] }}</td><td>{{ $gemReconcile['computed_balance'] }}</td><td>{{ $gemReconcile['difference'] }}</td><td>{{ $gemReconcile['is_balanced'] ? '✅ Yes' : '❌ No STOP' }}</td></tr>
</tbody>
</table>
</div>
</div>
</div>
@endsection
