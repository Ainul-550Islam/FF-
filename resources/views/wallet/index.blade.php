@extends('layouts.app')
@section('title','Wallet')
@section('content')
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 12px;">
<div><h1 style="margin: 0; font-size: 28px; font-weight: 800;">Wallet</h1><p class="text-muted">Balance, ledger, payouts <span data-internet-status class="internet-status online"></span></p></div>
<a href="{{ route('payment.methods') }}" class="btn btn-primary" data-require-online>Deposit</a>
</div>
<div class="grid grid-3">
@foreach($wallets ?? [] as $wallet)
<div class="card"><div class="text-muted" style="font-size: 12px; text-transform: uppercase;">{{ $wallet->currency }} Wallet</div><div style="font-size: 28px; font-weight: 800;">{{ number_format($wallet->balance_minor/100,2) }} {{ $wallet->currency }}</div><div style="margin-top: 8px;"><x-status-pill :status="$wallet->is_locked ? 'danger' : 'success'" :label="$wallet->is_locked ? 'Locked' : 'Active'" /></div></div>
@endforeach
@if(empty($wallets) || $wallets->count()==0)
<div class="card"><div class="text-muted">No wallet yet - will be created on first deposit</div></div>
@endif
</div>
<div class="card" style="margin-top: 24px;">
<div class="card-header"><h2 class="card-title">Recent Ledger</h2><span data-internet-status class="internet-status online"></span></div>
@if(($ledger ?? collect())->count())
<div class="table-wrap"><table><thead><tr><th>Date</th><th>Type</th><th>Amount</th><th>Balance After</th><th>Ref</th></tr></thead><tbody>
@foreach($ledger as $entry)
<tr><td>{{ $entry->created_at->format('M d, H:i') }}</td><td><x-status-pill :status="$entry->direction" :label="ucfirst($entry->direction)" /></td><td class="{{ $entry->direction==='credit'?'text-success':'text-danger' }}">{{ $entry->direction==='credit'?'+':'-' }}{{ number_format($entry->amount_minor/100,2) }}</td><td>{{ number_format($entry->balance_after_minor/100,2) }}</td><td class="font-mono" style="font-size: 12px;">{{ $entry->reference_type }}:{{ $entry->reference_id }}</td></tr>
@endforeach
</tbody></table></div>
@else
<x-empty-state title="No transactions" text="Ledger entries will appear after deposits, payouts, tournament fees" />
@endif
</div>
@endsection
