@extends('layouts.app')

@section('content')
    <div class="card" style="max-width: 460px; margin: 50px auto">
        <h2>Login</h2>

        <form method="POST" action="{{ route('login') }}" novalidate>
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

            <div class="field">
                <label for="password">Password</label>
                <input type="password" id="password" name="password"
                       autocomplete="current-password" required
                       @if ($errors->has('password')) aria-invalid="true" aria-describedby="password-error" @endif>
                @error('password')
                    <span class="form-error" id="password-error">{{ $message }}</span>
                @enderror
            </div>

            <div class="field">
                <label class="checkbox">
                    <input type="checkbox" name="remember" value="1" @checked(old('remember'))>
                    Remember me
                </label>
            </div>

            <button type="submit" class="btn btn-primary btn-block">Login</button>
        </form>

        <p class="divider-block" aria-hidden="true"></p>
        <p class="muted text-center mb-2">or</p>

        <div class="stack">
            @if (config('services.google.client_id') && config('services.google.client_secret'))
                <a href="{{ route('google.redirect') }}" class="btn btn-block">Continue with Google</a>
            @endif
            <a href="{{ route('phone.login') }}" class="btn btn-block">Login with phone</a>
        </div>

        <p class="muted mt-4" style="font-size: .85rem">
            <a href="{{ route('password.request') }}">Forgot password?</a> ·
            No account? <a href="{{ route('register') }}">Register here</a>
        </p>
    </div>
@endsection
