@extends('layouts.app')

@section('content')
    <div class="card" style="max-width: 520px; margin: 40px auto">
        <h2>Create your account</h2>
        <p class="muted" style="font-size: .9rem">Join as a Player or an Organizer.</p>

        <form method="POST" action="{{ route('register') }}" novalidate>
            @csrf

            <div class="field">
                <label for="name">Full name</label>
                <input type="text" id="name" name="name" value="{{ old('name') }}"
                       autocomplete="name" required
                       @if ($errors->has('name')) aria-invalid="true" aria-describedby="name-error" @endif>
                @error('name')
                    <span class="form-error" id="name-error">{{ $message }}</span>
                @enderror
            </div>

            <div class="field">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" value="{{ old('username') }}"
                       autocomplete="username" required
                       @if ($errors->has('username')) aria-invalid="true" aria-describedby="username-error" @endif>
                @error('username')
                    <span class="form-error" id="username-error">{{ $message }}</span>
                @enderror
            </div>

            <div class="field">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="{{ old('email') }}"
                       autocomplete="email" inputmode="email" required
                       @if ($errors->has('email')) aria-invalid="true" aria-describedby="email-error" @endif>
                @error('email')
                    <span class="form-error" id="email-error">{{ $message }}</span>
                @enderror
            </div>

            <div class="field">
                <label for="phone">Phone (bKash)</label>
                <input type="tel" id="phone" name="phone" value="{{ old('phone') }}"
                       autocomplete="tel" inputmode="tel">
                @error('phone')
                    <span class="form-error" id="phone-error">{{ $message }}</span>
                @enderror
            </div>

            <div class="field">
                <label for="game_uid">Free Fire UID</label>
                <input type="text" id="game_uid" name="game_uid" value="{{ old('game_uid') }}"
                       autocomplete="off" spellcheck="false">
            </div>

            <div class="field">
                <label for="role">I am a…</label>
                <select id="role" name="role">
                    <option value="player" @selected(old('role', 'player') === 'player')>Player</option>
                    <option value="organizer" @selected(old('role') === 'organizer')>Organizer</option>
                </select>
            </div>

            <div class="field">
                <label for="password">Password</label>
                <input type="password" id="password" name="password"
                       autocomplete="new-password" required
                       @if ($errors->has('password')) aria-invalid="true" aria-describedby="password-error" @endif>
                @error('password')
                    <span class="form-error" id="password-error">{{ $message }}</span>
                @enderror
            </div>

            <div class="field">
                <label for="password_confirmation">Confirm password</label>
                <input type="password" id="password_confirmation" name="password_confirmation"
                       autocomplete="new-password" required>
            </div>

            <button type="submit" class="btn btn-primary btn-block">Create Account</button>
        </form>

        <p class="muted mt-4" style="font-size: .85rem">
            Prefer to sign in with your phone? <a href="{{ route('phone.login') }}">Login with phone</a>
        </p>
    </div>
@endsection
