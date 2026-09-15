@extends('layouts.app')
@section('title', 'Reset Password — FF Arena')
@section('content')
    <div class="card" style="max-width: 460px; margin: 50px auto">
        <h2>Reset your password</h2>

        <form method="POST" action="{{ route('password.update') }}" novalidate>
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">

            <div class="field">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="{{ old('email', $email) }}"
                       autocomplete="email" inputmode="email" required
                       @if ($errors->has('email')) aria-invalid="true" aria-describedby="email-error" @endif>
                @error('email')
                    <span class="form-error" id="email-error">{{ $message }}</span>
                @enderror
            </div>

            <div class="field">
                <label for="password">New password</label>
                <input type="password" id="password" name="password"
                       autocomplete="new-password" required
                       @if ($errors->has('password')) aria-invalid="true" aria-describedby="password-error" @endif>
                @error('password')
                    <span class="form-error" id="password-error">{{ $message }}</span>
                @enderror
            </div>

            <div class="field">
                <label for="password_confirmation">Confirm new password</label>
                <input type="password" id="password_confirmation" name="password_confirmation"
                       autocomplete="new-password" required>
            </div>

            <button type="submit" class="btn btn-primary btn-block mt-3">Reset password</button>
        </form>
    </div>
@endsection
