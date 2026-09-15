@extends('layouts.app')
@section('title', 'Phone Login — FF Arena')
@section('content')
    <div class="card" style="max-width: 460px; margin: 50px auto">
        <h2>Login with your phone</h2>
        <p class="muted">Enter your Bangladeshi mobile number. We will send a verification code.</p>

        <form method="POST" action="{{ route('phone.request') }}" novalidate>
            @csrf
            <div class="field">
                <label for="phone">Mobile number</label>
                <input type="tel" id="phone" name="phone" value="{{ old('phone') }}"
                       placeholder="01712345678" autocomplete="tel" inputmode="tel" required autofocus
                       @if ($errors->has('phone')) aria-invalid="true" aria-describedby="phone-error" @endif>
                @error('phone')
                    <span class="form-error" id="phone-error">{{ $message }}</span>
                @enderror
            </div>
            <button type="submit" class="btn btn-primary btn-block mt-3">Send verification code</button>
        </form>

        <p class="muted mt-4" style="font-size: .85rem">
            <a href="{{ route('login') }}">Login with email instead</a>
        </p>
    </div>
@endsection
