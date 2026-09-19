@extends('layouts.app')
@section('title', 'Wallet - Gold & Gem Wallets')
@section('content')
<div class="container" style="max-width: 1000px; margin: 0 auto; padding: 24px;">
<h1 style="font-size: 28px; font-weight: 800;">💰 Wallet - Gold & Gem Wallets Reconciliation</h1>
<p style="color: var(--text-muted);">Gold wallets gem wallets transactions - Financial totals must reconcile - G1 constraint</p>
<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px;">
<div class="card" style="padding: 20px; border: 2px solid #f1c40f;">
<h3>🪙 Gold Wallet - {{ number_format($goldWallet->gold_balance) }} Gold</h3>
<div style="font-size: 12px; color: var(--text-muted);">Earned {{ $goldWallet->total_earned }} Spent {{ $goldWallet->total_spent }} Won {{ $goldWallet->total_won }} Lost {{ $goldWallet->total_lost }}</div>
<div style="margin-top: 8px; padding: 6px 10px; border-radius: 999px; background: {{ $goldReconcile['is_balanced'] ? '#27ae60' : '#e74c3c' }}; color: white; font-size: 11px; display: inline-block;">{{ $goldReconcile['is_balanced'] ? '✅ Reconciled' : '❌ Mismatch '.$goldReconcile['difference'] }}</div>
</div>
<div class="card" style="padding: 20px; border: 2px solid #9b59b6;">
<h3>💎 Gem Wallet - {{ number_format($gemWallet->gem_balance) }} Gems</h3>
<div style="font-size: 12px; color: var(--text-muted);">Earned {{ $gemWallet->total_earned }} Spent {{ $gemWallet->total_spent }} Purchased {{ $gemWallet->total_purchased }}</div>
<div style="margin-top: 8px; padding: 6px 10px; border-radius: 999px; background: {{ $gemReconcile['is_balanced'] ? '#27ae60' : '#e74c3c' }}; color: white; font-size: 11px; display: inline-block;">{{ $gemReconcile['is_balanced'] ? '✅ Reconciled' : '❌ Mismatch '.$gemReconcile['difference'] }}</div>
</div>
</div>
<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px;">
<div class="card" style="padding: 16px;"><h4>Gold History ({{ $goldHistory->count() }})</h4><div class="table-wrap"><table class="table"><thead><tr><th>Type</th><th>Amount</th><th>Balance</th><th>Date</th></tr></thead><tbody>@foreach($goldHistory->take(10) as $tx)<tr><td>{{ $tx->type }}</td><td style="color: {{ $tx->amount>0 ? '#27ae60' : '#e74c3c' }};">{{ $tx->amount }}</td><td>{{ $tx->balance_after }}</td><td style="font-size: 11px;">{{ $tx->created_at->diffForHumans() }}</td></tr>@endforeach</tbody></table></div></div>
<div class="card" style="padding: 16px;"><h4>Gem History ({{ $gemHistory->count() }})</h4><div class="table-wrap"><table class="table"><thead><tr><th>Type</th><th>Amount</th><th>Balance</th><th>Date</th></tr></thead><tbody>@foreach($gemHistory->take(10) as $tx)<tr><td>{{ $tx->type }}</td><td style="color: {{ $tx->amount>0 ? '#9b59b6' : '#e74c3c' }};">{{ $tx->amount }}</td><td>{{ $tx->balance_after }}</td><td style="font-size: 11px;">{{ $tx->created_at->diffForHumans() }}</td></tr>@endforeach</tbody></table></div></div>
</div>
</div>
@endsection
