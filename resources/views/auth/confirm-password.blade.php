@extends('layouts.app')

@section('title', 'Confirm password - FF Arena')

@section('content')
<div style="max-width: 420px; margin: 40px auto;">
    <div style="text-align: center; margin-bottom: 24px;">
        <div class="brand-icon" style="width: 56px; height: 56px; margin: 0 auto 16px; font-size: 24px;">FF</div>
        <h1 style="margin: 0 0 8px; font-size: 24px; font-weight: 800;">Confirm your password</h1>
        <p class="text-muted" style="font-size: 14px;">
            This is a sensitive action, so we need you to re-enter your password before continuing.
        </p>
    </div>

    <div class="card">
        @if (session('status'))
            <div class="alert alert-info" role="status">{{ session('status') }}</div>
        @endif

        <form method="POST" action="{{ route('password.confirm.store') }}">
            @csrf

            <div class="form-group">
                <label for="password" class="form-label required">Password</label>
                <div style="position: relative;">
                    <input type="password" id="password" name="password"
                           class="form-input @error('password') is-invalid @enderror"
                           required autocomplete="current-password" autofocus style="padding-right: 70px;">
                    <button type="button" data-password-toggle data-target="#password"
                            style="position: absolute; right: 8px; top: 50%; transform: translateY(-50%); background: none; border: none; color: var(--text-muted); cursor: pointer; font-size: 12px; font-weight: 600;">Show</button>
                </div>
                @error('password') <div class="form-error">{{ $message }}</div> @enderror
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%;" data-require-online>Confirm password</button>
        </form>

        <p class="text-muted" style="font-size: 13px; margin-top: 16px; margin-bottom: 0;">
            Confirmation lasts for {{ intdiv((int) config('auth.password_timeout', 10800), 60) }} minutes.
            <a href="{{ route('password.request') }}" style="color: var(--primary); text-decoration: none;">Forgot your password?</a>
        </p>
    </div>
</div>
@endsection
