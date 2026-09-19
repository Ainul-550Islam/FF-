@extends('layouts.app')

@section('title', 'Login - FF Arena')

@section('content')
<div style="max-width: 420px; margin: 40px auto;">
    <div style="text-align: center; margin-bottom: 24px;">
        <div class="brand-icon" style="width: 56px; height: 56px; margin: 0 auto 16px; font-size: 24px;">FF</div>
        <h1 style="margin: 0 0 8px; font-size: 24px; font-weight: 800;">Welcome back</h1>
        <p class="text-muted" style="font-size: 14px;">Login to your FF Arena account. <span data-internet-status class="internet-status online" style="margin-left: 8px;"></span></p>
    </div>

    <div class="card">
        <form method="POST" action="{{ route('login') }}">
            @csrf

            <div class="form-group">
                <label for="email" class="form-label required">Email</label>
                <input type="email" id="email" name="email" value="{{ old('email') }}" class="form-input @error('email') is-invalid @enderror" required autocomplete="email" autofocus>
                @error('email') <div class="form-error">{{ $message }}</div> @enderror
            </div>

            <div class="form-group">
                <label for="password" class="form-label required">Password</label>
                <div style="position: relative;">
                    <input type="password" id="password" name="password" class="form-input @error('password') is-invalid @enderror" required autocomplete="current-password" style="padding-right: 70px;">
                    <button type="button" data-password-toggle data-target="#password" style="position: absolute; right: 8px; top: 50%; transform: translateY(-50%); background: none; border: none; color: var(--text-muted); cursor: pointer; font-size: 12px; font-weight: 600;">Show</button>
                </div>
                @error('password') <div class="form-error">{{ $message }}</div> @enderror
            </div>

            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 12px;">
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 14px;">
                    <input type="checkbox" name="remember" value="1" {{ old('remember') ? 'checked' : '' }} style="width: 16px; height: 16px;">
                    Remember me
                </label>
                <a href="{{ route('password.request') }}" style="font-size: 13px; color: var(--primary); text-decoration: none;">Forgot password?</a>
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%;" data-require-online>Login</button>

            <div style="text-align: center; margin-top: 16px; font-size: 13px; color: var(--text-muted);">
                Don't have an account? <a href="{{ route('register') }}" style="color: var(--primary); text-decoration: none; font-weight: 600;">Register</a>
            </div>

            <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid var(--border);">
                <div style="text-align: center; font-size: 12px; color: var(--text-muted); margin-bottom: 12px;">Or continue with</div>
                <div style="display: grid; gap: 8px;">
                    <a href="{{ route('auth.google.redirect') }}" class="btn btn-secondary" style="width: 100%;" data-require-online>
                        <span aria-hidden="true">G</span> Continue with Google
                    </a>
                    <a href="{{ route('auth.phone') }}" class="btn btn-ghost" style="width: 100%;" data-require-online>
                        <span aria-hidden="true">📱</span> Login with Phone OTP
                    </a>
                </div>
            </div>
        </form>

        <div style="margin-top: 16px; padding: 12px; background: var(--bg-elevated); border-radius: 8px; border: 1px solid var(--border); display: flex; gap: 10px; align-items: center;">
            <span data-internet-status class="internet-status online"></span>
            <span style="font-size: 12px; color: var(--text-muted);">Login requires internet. Offline detection active - if offline, login is blocked for security.</span>
        </div>
    </div>
</div>
@endsection
