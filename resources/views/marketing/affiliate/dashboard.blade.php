@extends('layouts.app')

@section('title', 'Affiliate Dashboard — FF Arena')

@section('content')
<section class="container" style="max-width: 860px">
    <h1>Affiliate Dashboard</h1>

    @if (session('success'))
        <div class="alert alert-success" role="status">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-error" role="alert">{{ session('error') }}</div>
    @endif

    @if ($affiliate === null)
        <div class="card" style="margin-top: 16px">
            <h2 style="margin-top: 0">Become an FF Arena affiliate</h2>
            <p class="muted">Share your referral link, bring new players to the arena, and watch your signups grow. Pick a custom code or let us generate one for you.</p>

            <form method="POST" action="{{ route('marketing.affiliate.store') }}" style="margin-top: 12px">
                @csrf
                <div class="field" style="max-width: 320px">
                    <label for="af-code">Custom code (optional)</label>
                    <input type="text" id="af-code" name="code" value="{{ old('code') }}" maxlength="24" pattern="[A-Za-z0-9]{4,24}">
                    @error('code') <div class="muted" style="color: var(--danger, #f66); font-size: .8rem">{{ $message }}</div> @enderror
                </div>
                <div class="field" style="max-width: 480px">
                    <label for="af-name">Public name (optional)</label>
                    <input type="text" id="af-name" name="name" value="{{ old('name') }}" maxlength="120">
                </div>
                <div class="field" style="max-width: 480px">
                    <label for="af-landing">Landing URL (optional — where your link sends visitors)</label>
                    <input type="url" id="af-landing" name="landing_url" value="{{ old('landing_url') }}" maxlength="500" placeholder="https://example.com/my-page">
                    @error('landing_url') <div class="muted" style="color: var(--danger, #f66); font-size: .8rem">{{ $message }}</div> @enderror
                </div>
                <button type="submit" class="btn btn-cyan">Create my affiliate account</button>
            </form>
        </div>
    @else
        <div class="card" style="margin-top: 16px">
            <h2 style="margin-top: 0">{{ $affiliate->name ?? 'Your affiliate account' }}</h2>
            <p>Status: <strong>{{ ucfirst($affiliate->status) }}</strong></p>

            <h3>Your referral link</h3>
            <p><code>{{ route('marketing.referral.click', ['code' => $affiliate->code]) }}</code></p>

            <div class="row" style="gap: 24px; flex-wrap: wrap">
                <div><strong>{{ number_format($stats['clicks']) }}</strong><br><span class="muted">Referral clicks</span></div>
                <div><strong>{{ number_format($stats['signups']) }}</strong><br><span class="muted">Signups credited</span></div>
                <div><strong>{{ number_format($stats['conversion_rate'] * 100, 1) }}%</strong><br><span class="muted">Conversion rate</span></div>
            </div>
        </div>

        <div class="card" style="margin-top: 16px">
            <h3 style="margin-top: 0">Recent referrals</h3>
            @if ($stats['latest']->isEmpty())
                <p class="muted">No referral activity yet — share your link to get started.</p>
            @else
                <table>
                    <thead>
                        <tr><th>Visitor</th><th>Status</th><th>First click</th><th>Signed up</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($stats['latest'] as $referral)
                            <tr>
                                <td>{{ $referral->referredUser?->name ?? 'Anonymous visitor' }}</td>
                                <td>{{ ucfirst($referral->status) }}</td>
                                <td>{{ $referral->clicked_at?->isoFormat('D MMM Y, HH:mm') }}</td>
                                <td>{{ $referral->signed_up_at?->isoFormat('D MMM Y, HH:mm') ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @endif
</section>
@endsection
