@extends('layouts.app')
@section('title', 'Payment Pending — FF Arena')
@section('content')
    <div class="card text-center" style="max-width: 460px; margin: 50px auto">
        <div style="font-size: 44px" aria-hidden="true">⏳</div>
        <h2>Payment under review</h2>
        <p class="muted">
            Your payment (৳{{ number_format($payment->amount, 2) }}, TrxID
            <strong>{{ $payment->trx_id }}</strong>) is being verified by the organizer.
        </p>
        <div class="mt-3 mb-3">
            <x-status-pill :status="$payment->statusPill()" :label="strtoupper($payment->status)" />
        </div>
        @if (in_array($payment->status, ['pending', 'processing'], true))
            <p class="muted" style="font-size: .85rem">
                Once verified, team <strong>{{ $team->name }}</strong> will be confirmed automatically.
            </p>
        @elseif ($payment->isSuccessful())
            <p class="text-success" style="font-size: .85rem">✓ Payment verified — your team is confirmed.</p>
        @elseif ($payment->status === 'refunded')
            <p class="muted" style="font-size: .85rem">This payment has been refunded to your wallet.</p>
        @endif
        <div class="row" style="justify-content: center; margin-top: 12px">
            <a href="{{ route('tournaments.show', $tournament) }}" class="btn btn-sm">Back to tournament</a>
            <a href="{{ route('wallet.index') }}" class="btn btn-sm">My Wallet</a>
        </div>
    </div>
@endsection
