@extends('layouts.app')
@section('title', 'Forgot Password — FF Arena')
@section('content')
    <div class="card" style="max-width: 460px; margin: 50px auto">
        <h2>Forgot your password?</h2>
        <p class="muted">Enter your email and we will send a reset link if an account exists.</p>

        <form method="POST" action="{{ route('password.email') }}" novalidate>
            @csrf
            <div class="field">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="{{ old('email') }}"
                       autocomplete="email" inputmode="email" required autofocus
                       @if ($errors->has('email')) aria-invalid="true" aria-describedby="email-error" @endif>
                @error('email')
                    <span class="form-error" id="email-error">{{ $message }}</span>
                @enderror
            </div>
            <button type="submit" class="btn btn-primary btn-block mt-3">Send reset link</button>
        </form>

        <p class="muted mt-4" style="font-size: .85rem">
            <a href="{{ route('login') }}">Back to login</a>
        </p>
    </div>
@endsection
