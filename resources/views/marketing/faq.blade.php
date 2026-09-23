@extends('layouts.app')

@section('title', 'FAQ — FF Arena')

@section('content')
@php
    $faqs = [
        ['q' => 'How do I join a Free Fire tournament?', 'a' => 'Create a free account, browse open tournaments, and register your team before slots fill up. Entry is confirmed once your entry fee is verified (for paid events).'],
        ['q' => 'How much does it cost?', 'a' => 'Accounts are free. Some tournaments are free to enter; paid events list the entry fee (in ৳) on the tournament page before you register.'],
        ['q' => 'How do I pay the entry fee?', 'a' => 'Payments are handled through bKash and other supported providers at checkout. Your team is confirmed as soon as the payment is verified — you can track the status from your wallet page.'],
        ['q' => 'When and how do I get my prize?', 'a' => 'After the organizer settles results, prize payouts are credited to your verified wallet or sent through the payout method on file. Every financial movement is reconciled against the ledger — if totals do not balance, processing stops.'],
        ['q' => 'What if a match result is wrong?', 'a' => 'Open a dispute from the match page within the dispute window. A staff member reviews the evidence and resolves it; the decision and any corrections are recorded.'],
        ['q' => 'Is my account safe?', 'a' => 'We monitor logins for suspicious devices, support identity verification for payouts, and run anti-cheat reviews. Turn on a strong password and keep your game UID accurate.'],
        ['q' => 'Can I organize tournaments here?', 'a' => 'Yes — register with an organizer account to create tournaments, configure scoring rules, publish brackets and settle prizes. Contact us if you want help running your first event.'],
        ['q' => 'Is this official Garena/Free Fire?', 'a' => 'No. FF Arena is an independent tournament platform for the Bangladesh Free Fire community and is not affiliated with Garena.'],
    ];
@endphp
<section class="container" style="max-width: 820px">
    <h1>Frequently Asked Questions</h1>
    <p class="muted">Everything about joining, paying, competing and getting paid.</p>

    <div style="margin-top: 16px">
        @foreach ($faqs as $item)
            <div class="card">
                <h2 style="margin-top: 0; font-size: 1.05rem">{{ $item['q'] }}</h2>
                <p style="margin-bottom: 0">{{ $item['a'] }}</p>
            </div>
        @endforeach
    </div>

    <p class="muted">Still stuck? <a href="{{ route('marketing.contact') }}">Contact support</a>.</p>
</section>

@php app(\App\Support\Seo::class)->jsonLd([
    '@context' => 'https://schema.org',
    '@type' => 'FAQPage',
    'mainEntity' => collect($faqs)->map(fn ($f) => [
        '@type' => 'Question',
        'name' => $f['q'],
        'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['a']],
    ])->values()->all(),
]) @endphp
@endsection
