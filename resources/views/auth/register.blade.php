@extends('layouts.app')

@section('title', 'Register - FF Arena')

@section('content')
<div style="max-width: 460px; margin: 40px auto;">
    <div style="text-align: center; margin-bottom: 24px;">
        <div class="brand-icon" style="width: 56px; height: 56px; margin: 0 auto 16px; font-size: 24px;">FF</div>
        <h1 style="margin: 0 0 8px; font-size: 24px; font-weight: 800;">Create account</h1>
        <p class="text-muted" style="font-size: 14px;">Join FF Arena tournaments. Avatar, secure wallet, anti-cheat. <span data-internet-status class="internet-status online"></span></p>
    </div>

    <div class="card">
        <form method="POST" action="{{ route('register') }}" enctype="multipart/form-data">
            @csrf

            <div class="form-group" style="text-align: center; padding-bottom: 16px; border-bottom: 1px solid var(--border); margin-bottom: 20px;">
                <div style="display: inline-block;">
                    <x-avatar size="xl" :editable="true" initials="??" />
                </div>
                <div style="margin-top: 8px; font-size: 12px; color: var(--text-muted);">Optional avatar - can add later in profile</div>
            </div>

            <div class="grid grid-2">
                <div class="form-group">
                    <label for="name" class="form-label required">Full Name</label>
                    <input type="text" id="name" name="name" value="{{ old('name') }}" class="form-input @error('name') is-invalid @enderror" required autocomplete="name" maxlength="255">
                    @error('name') <div class="form-error">{{ $message }}</div> @enderror
                </div>
                <div class="form-group">
                    <label for="username" class="form-label">Username</label>
                    <input type="text" id="username" name="username" value="{{ old('username') }}" class="form-input @error('username') is-invalid @enderror" placeholder="gamer123" maxlength="30" pattern="[a-zA-Z0-9_\.]+">
                    <div class="form-hint">Optional, 3-30 chars</div>
                    @error('username') <div class="form-error">{{ $message }}</div> @enderror
                </div>
            </div>

            <div class="form-group">
                <label for="email" class="form-label required">Email</label>
                <input type="email" id="email" name="email" value="{{ old('email') }}" class="form-input @error('email') is-invalid @enderror" required autocomplete="email">
                @error('email') <div class="form-error">{{ $message }}</div> @enderror
            </div>

            <div class="form-group">
                <label for="phone" class="form-label">Phone (optional)</label>
                <input type="tel" id="phone" name="phone" value="{{ old('phone') }}" class="form-input @error('phone') is-invalid @enderror" placeholder="+8801XXXXXXXXX" autocomplete="tel">
                <div class="form-hint">For OTP login and payment verification</div>
                @error('phone') <div class="form-error">{{ $message }}</div> @enderror
            </div>

            <div class="grid grid-2">
                <div class="form-group">
                    <label for="password" class="form-label required">Password</label>
                    <input type="password" id="password" name="password" class="form-input @error('password') is-invalid @enderror" required autocomplete="new-password" minlength="8">
                    @error('password') <div class="form-error">{{ $message }}</div> @enderror
                </div>
                <div class="form-group">
                    <label for="password_confirmation" class="form-label required">Confirm Password</label>
                    <input type="password" id="password_confirmation" name="password_confirmation" class="form-input" required autocomplete="new-password">
                </div>
            </div>

            <div class="form-group">
                <label style="display: flex; gap: 8px; align-items: flex-start; cursor: pointer; font-size: 13px;">
                    <input type="checkbox" name="terms" value="1" required style="margin-top: 3px; width: 16px; height: 16px;">
                    <span>I agree to <a href="#" style="color: var(--primary);">Terms</a> and <a href="#" style="color: var(--primary);">Privacy Policy</a>. I understand avatar and profile data handling.</span>
                </label>
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%;" data-require-online>Create Account</button>

            <div style="text-align: center; margin-top: 16px; font-size: 13px; color: var(--text-muted);">
                Already have an account? <a href="{{ route('login') }}" style="color: var(--primary); font-weight: 600; text-decoration: none;">Login</a>
            </div>

            <div style="margin-top: 16px; padding: 12px; background: var(--bg-elevated); border-radius: 8px; border: 1px solid var(--border); display: flex; gap: 8px; align-items: center;">
                <span data-internet-status class="internet-status online"></span>
                <span style="font-size: 11px; color: var(--text-muted);">Registration requires internet. We check connectivity before submit to prevent duplicate accounts (idempotency).</span>
            </div>
        </form>
    </div>
</div>
@endsection
