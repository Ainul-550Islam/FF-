@extends('layouts.app')

@section('title', 'Contact & Support — FF Arena')

@section('content')
<section class="container" style="max-width: 720px">
    <h1>Contact &amp; Support</h1>
    <p class="muted">Tournament question, payment issue or partnership enquiry — send it here and the team will get back to you. You can also reach us directly at <a href="mailto:{{ config('marketing.contact_email') }}">{{ config('marketing.contact_email') }}</a>.</p>

    <div class="card" style="margin-top: 16px">
        @if (session('success'))
            <div class="alert alert-success" role="status">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="alert alert-error" role="alert">{{ session('error') }}</div>
        @endif

        <form method="POST" action="{{ route('marketing.contact.store') }}">
            @csrf
            <input type="hidden" name="type" value="contact">
            <div style="margin-bottom: 12px">
                <label for="contact-name">Your name</label>
                <input type="text" id="contact-name" name="name" value="{{ old('name') }}" required maxlength="120" style="width: 100%">
            </div>
            <div style="margin-bottom: 12px">
                <label for="contact-email">Email</label>
                <input type="email" id="contact-email" name="email" value="{{ old('email') }}" required style="width: 100%">
                @error('email') <div class="muted" style="color: var(--danger, #f66); font-size: .8rem">{{ $message }}</div> @enderror
            </div>
            <div style="margin-bottom: 12px">
                <label for="contact-message">Message</label>
                <textarea id="contact-message" name="message" rows="5" required maxlength="2000" style="width: 100%">{{ old('message') }}</textarea>
            </div>
            <button type="submit" class="btn btn-primary">Send message</button>
        </form>
    </div>

    <p class="muted" style="margin-top: 14px">Registered players can also open support tickets from inside the app for account-specific issues.</p>
</section>
@endsection
