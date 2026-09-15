@extends('layouts.app')
@section('title', 'Verify Email — FF Arena')
@section('content')
    <div class="card" style="max-width: 520px; margin: 50px auto">
        <h2>Verify your email</h2>
        <p class="muted">
            A verification link was sent to <strong>{{ auth()->user()->email }}</strong>.
            Click the link in the email to verify your address.
        </p>
        <form method="POST" action="{{ route('verification.resend') }}">
            @csrf
            <button type="submit" class="btn btn-cyan mt-2">Resend verification link</button>
        </form>
        <p class="muted mt-4" style="font-size: .85rem">
            <a href="{{ route('home') }}">Back to home</a>
        </p>
    </div>
@endsection
