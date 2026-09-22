@extends('layouts.app')
@section('title', 'Choose Payment Method — FF Arena')
@section('content')
    <div class="card" style="max-width: 620px; margin: 40px auto">
        <h2>Pay entry fee</h2>
        <p class="muted">
            Tournament <strong>{{ $tournament->name }}</strong> · Team <strong>{{ $team->name }}</strong>
        </p>
        <div class="stat mt-3 mb-3">
            <div class="muted">Amount due</div>
            <div class="num">{{ \App\Support\Money::formatMinor($amountMinor) }}</div>
        </div>

        @forelse ($providers as $provider)
            <article class="card" style="padding: 14px; margin-bottom: 10px">
                <div class="row-between">
                    <div>
                        <strong>{{ $provider['label'] }}</strong>
                        @if (! $provider['configured'])
                            <x-status-pill status="pending" label="Not configured" />
                        @elseif ($provider['mode'] === 'sandbox')
                            <x-status-pill status="live" label="Sandbox" />
                        @endif
                    </div>
                    @if (in_array($provider['id'], ['bkash', 'nagad', 'rocket', 'bank'], true))
                        <form method="POST" action="{{ route('payment.initiate', [$tournament, $team]) }}">
                            @csrf
                            <input type="hidden" name="provider" value="{{ $provider['id'] }}">
                            <div class="row" style="gap: 8px">
                                <label for="trx-{{ $provider['id'] }}" class="sr-only">Transaction ID for {{ $provider['label'] }}</label>
                                <input type="text" id="trx-{{ $provider['id'] }}" name="trx_id" placeholder="Transaction ID" style="max-width: 190px">
                                <button type="submit" class="btn btn-primary btn-sm">Submit</button>
                            </div>
                        </form>
                    @else
                        <form method="POST" action="{{ route('payment.initiate', [$tournament, $team]) }}">
                            @csrf
                            <input type="hidden" name="provider" value="{{ $provider['id'] }}">
                            <button type="submit" class="btn btn-primary btn-sm" @disabled(! $provider['configured'])>
                                Pay with {{ $provider['label'] }}
                            </button>
                        </form>
                    @endif
                </div>
                @if (! $provider['configured'])
                    <p class="muted mt-1" style="font-size: .8rem">Provider is not configured — payments are not possible with this method yet.</p>
                @endif
            </article>
        @empty
            <p class="muted">No payment methods are currently enabled.</p>
        @endforelse

        <p class="muted mt-3" style="font-size: .8rem">
            🔒 Payments are verified server-side. Your team is confirmed only after the payment is verified.
        </p>
        <p class="mt-2"><a href="{{ route('teams.show', [$tournament, $team]) }}">← Back to team</a></p>
    </div>
@endsection
