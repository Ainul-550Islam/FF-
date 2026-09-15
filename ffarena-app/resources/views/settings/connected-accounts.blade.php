@extends('layouts.app')
@section('title', 'Connected Accounts — FF Arena')
@section('content')
    <header class="page-head">
        <h1 class="page-title">Connected Accounts</h1>
    </header>

    <section class="card" aria-labelledby="password-heading">
        <h3 id="password-heading">Password</h3>
        @if ($hasPassword)
            <p class="muted">A password is set on this account. You can change it in
                <a href="{{ route('settings.security') }}">security settings</a>.</p>
        @else
            <p class="muted">No password set. <a href="{{ route('settings.security') }}">Set one</a> so you can sign in
                even if you unlink a provider.</p>
        @endif
    </section>

    <section class="card" aria-labelledby="google-heading">
        <h3 id="google-heading">Google</h3>
        @if ($identities->contains('provider', 'google'))
            <p>✅ <x-status-pill status="confirmed" label="Connected" /></p>
            <form method="POST" action="{{ route('settings.google.unlink') }}">
                @csrf
                <button type="submit" class="btn btn-sm btn-danger">Disconnect Google</button>
            </form>
        @else
            @if ($googleConfigured)
                <p class="muted">Connect Google to sign in with one click.</p>
                <a href="{{ route('settings.google.link') }}" class="btn btn-primary btn-sm">Connect Google</a>
            @else
                <p class="muted">Google Sign-In is not configured.</p>
            @endif
        @endif
    </section>

    <section class="card" aria-labelledby="phone-heading">
        <h3 id="phone-heading">Phone</h3>
        @if ($identities->contains('provider', 'phone'))
            @php($phoneIdentity = $identities->firstWhere('provider', 'phone'))
            <p>✅ <x-status-pill status="verified" /> <span class="muted">{{ $phoneIdentity->provider_subject }}</span></p>
            <form method="POST" action="{{ route('settings.phone.unlink') }}">
                @csrf
                <button type="submit" class="btn btn-sm btn-danger">Disconnect phone</button>
            </form>
        @else
            @if ($phoneConfigured)
                <p class="muted">Verify your phone number to enable phone sign-in.</p>
                <form method="POST" action="{{ route('settings.phone.link') }}">
                    @csrf
                    <div class="field">
                        <label for="phone">Mobile number</label>
                        <input type="tel" id="phone" name="phone" value="{{ old('phone', $user->phone) }}"
                               placeholder="01712345678" autocomplete="tel" inputmode="tel" required>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm mt-2">Verify phone</button>
                </form>
            @else
                <p class="muted">Phone verification is not configured.</p>
            @endif
        @endif
    </section>
@endsection
