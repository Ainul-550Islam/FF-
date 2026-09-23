@extends('layouts.app')

@section('title', 'Privacy Policy — FF Arena')

@section('content')
<section class="container" style="max-width: 820px">
    <h1>Privacy Policy</h1>
    <p class="muted">Effective: {{ now()->format('F Y') }} · Applies to {{ config('app.name', 'FF Arena') }} (web &amp; mobile app)</p>

    <div class="card" style="margin-top: 16px">
        <h2 style="margin-top: 0">What we collect</h2>
        <ul>
            <li><strong>Account data:</strong> name, username, email, optional phone and Free Fire game UID.</li>
            <li><strong>Tournament data:</strong> teams, registrations, match results and rankings.</li>
            <li><strong>Payment data:</strong> transaction records with our providers. We never store your bKash/Nagad/Rocket PIN or full card numbers.</li>
            <li><strong>Security data:</strong> device and login observations used to protect your account and detect fraud.</li>
            <li><strong>Measurement data:</strong> first-party analytics about which pages and campaigns bring players in (see Cookies below).</li>
        </ul>
    </div>

    <div class="card">
        <h2 style="margin-top: 0">How we use it</h2>
        <ul>
            <li>Run tournaments, verify entries and pay out prizes.</li>
            <li>Secure accounts and stop cheating, fraud and abuse.</li>
            <li>Send service notifications (payment, payout, tournament updates).</li>
            <li>Measure and improve the product. Marketing emails are opt-in only, and every one carries an unsubscribe link.</li>
        </ul>
    </div>

    <div class="card">
        <h2 style="margin-top: 0">Cookies &amp; measurement</h2>
        <p>We use a small set of first-party cookies to keep you signed in and to measure our own funnel (which campaign or page led you to us). Third-party advertising/analytics tags (for example Google Analytics or Meta Pixel) only load <em>after</em> you allow them in the cookie banner, and you can change or withdraw that choice anytime from the “Cookie settings” link.</p>
        <p><a href="#" id="consent-settings-link">Cookie settings</a></p>
    </div>

    <div class="card">
        <h2 style="margin-top: 0">Sharing &amp; retention</h2>
        <p>We share data only with processors needed to run the service (payment providers, SMS/e-mail delivery, hosting) and with tournament organizers only as required to run their events (team name, captain contact). We never sell personal data. Account data is retained while your account is active; measurement data is retained for a bounded window and then deleted or anonymised.</p>
    </div>

    <div class="card">
        <h2 style="margin-top: 0">Your rights</h2>
        <p>You can access, correct or export your data from your profile, and delete your account from Account settings. For anything else, <a href="{{ route('marketing.contact') }}">contact us</a>.</p>
    </div>
</section>
@endsection
