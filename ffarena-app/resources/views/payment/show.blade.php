@extends('layouts.app')
@section('title', 'Pay Entry Fee — FF Arena')
@section('content')
    <div class="card text-center" style="max-width: 460px; margin: 50px auto">
        <div style="font-size: 44px" aria-hidden="true">💳</div>
        <h2>Pay Entry Fee</h2>
        <p class="muted">Team <strong>{{ $team->name }}</strong> · {{ $tournament->name }}</p>
        <div class="stat mt-3 mb-3">
            <div class="muted" style="font-size: .85rem">Amount to pay</div>
            <div class="num" style="color: var(--green)">৳{{ number_format($tournament->entry_fee, 2) }}</div>
        </div>

        @if ((float) $tournament->entry_fee <= 0)
            <form method="POST" action="{{ route('payment.verify', [$tournament, $team]) }}">
                @csrf
                <input type="hidden" name="bkash_number" value="{{ $team->phone }}">
                <input type="hidden" name="trx_id" value="FREE{{ $team->id }}">
                <button type="submit" class="btn btn-green btn-block">Confirm Free Registration</button>
            </form>
        @else
            <p class="muted mb-3" style="font-size: .85rem; text-align: left">
                Send <strong>৳{{ number_format($tournament->entry_fee, 2) }}</strong> to the organizer's bKash number,
                then enter your Transaction ID below.
            </p>
            <form method="POST" action="{{ route('payment.verify', [$tournament, $team]) }}" novalidate>
                @csrf
                <div class="field" style="text-align: left">
                    <label for="bkash_number">Your bKash number</label>
                    <input type="tel" id="bkash_number" name="bkash_number" value="{{ $team->phone }}"
                           autocomplete="tel" inputmode="tel" required>
                </div>
                <div class="field" style="text-align: left">
                    <label for="trx_id">bKash Transaction ID (TrxID)</label>
                    <input type="text" id="trx_id" name="trx_id" placeholder="e.g. 9H7K2L1M3N" required>
                </div>
                <button type="submit" class="btn btn-primary btn-block">Verify Payment</button>
            </form>
        @endif

        <p class="muted mt-4" style="font-size: .8rem">
            Prefer another method?
            <a href="{{ route('payment.methods', [$tournament, $team]) }}">Choose from bKash, Nagad, Rocket &amp; more →</a>
        </p>
        <p class="muted mt-1" style="font-size: .8rem">
            Payments are reviewed by the organizer/admin before your slot is confirmed.
        </p>
    </div>
@endsection
