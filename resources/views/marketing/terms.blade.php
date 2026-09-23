@extends('layouts.app')

@section('title', 'Terms of Service — FF Arena')

@section('content')
<section class="container" style="max-width: 820px">
    <h1>Terms of Service</h1>
    <p class="muted">Effective: {{ now()->format('F Y') }}</p>

    <div class="card" style="margin-top: 16px">
        <h2 style="margin-top: 0">1. Accounts</h2>
        <p>One account per person. You must provide accurate details and are responsible for your credentials. Organizers must be 18+ or have a guardian's consent; players must follow the age rules of their Free Fire account.</p>
    </div>

    <div class="card">
        <h2 style="margin-top: 0">2. Tournaments &amp; fair play</h2>
        <ul>
            <li>Register only with your own team and real player details.</li>
            <li>No hacking, scripted play, account sharing, smurfing or match-fixing. We run anti-cheat checks and identity verification; violations mean forfeited prizes and restrictions.</li>
            <li>Organizers must publish rules, run events as advertised and settle prizes honestly.</li>
        </ul>
    </div>

    <div class="card">
        <h2 style="margin-top: 0">3. Payments &amp; prizes</h2>
        <p>Entry fees are processed through our supported providers (bKash and others). Prizes are paid to verified wallets after the organizer settles results. Disputed results follow the dispute process inside the app, and financial records are reconciled — totals must balance.</p>
    </div>

    <div class="card">
        <h2 style="margin-top: 0">4. Content &amp; conduct</h2>
        <p>Keep names, team badges and messages clean. No harassment, hate speech, scam links or impersonation of staff. We may remove content or restrict accounts that break these rules.</p>
    </div>

    <div class="card">
        <h2 style="margin-top: 0">5. The service</h2>
        <p>{{ config('app.name', 'FF Arena') }} is provided as-is while it grows. We may change or discontinue features; where a paid tournament cannot run as advertised, affected entries are refunded. To the extent permitted by law we are not liable for indirect losses. These terms are governed by the laws of Bangladesh.</p>
        <p>Questions? <a href="{{ route('marketing.contact') }}">Contact us</a> · See also our <a href="{{ route('marketing.privacy') }}">Privacy Policy</a> and <a href="{{ route('marketing.faq') }}">FAQ</a>.</p>
    </div>
</section>
@endsection
