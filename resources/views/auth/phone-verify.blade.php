@extends('layouts.app')
@section('title', 'Enter Code — FF Arena')
@section('content')
    <div class="card" style="max-width: 460px; margin: 50px auto">
        <h2>Enter the code</h2>
        <p class="muted">
            We sent a 6-digit code to <strong>{{ $phone ?? old('phone') }}</strong>.
        </p>

        @php
            $purpose = $purpose ?? old('purpose', 'login');
            $action = $purpose === 'link'
                ? route('settings.phone.link.verify')
                : route('phone.login.verify');
        @endphp

        <form method="POST" action="{{ $action }}" novalidate>
            @csrf
            <input type="hidden" name="phone" value="{{ $phone ?? old('phone') }}">
            <input type="hidden" name="purpose" value="{{ $purpose }}">
            <div class="field">
                <label for="code">Verification code</label>
                <input type="text" id="code" name="code" inputmode="numeric" autocomplete="one-time-code"
                       maxlength="6" required autofocus
                       @if ($errors->has('code')) aria-invalid="true" aria-describedby="code-error" @endif>
                @error('code')
                    <span class="form-error" id="code-error">{{ $message }}</span>
                @enderror
            </div>
            <button type="submit" class="btn btn-primary btn-block mt-3">Verify</button>
        </form>

        <p class="muted mt-4" style="font-size: .85rem">
            @if ($purpose === 'link')
                <a href="{{ route('settings.connected-accounts') }}">Back to connected accounts</a>
            @else
                <a href="{{ route('phone.login') }}">Use a different number</a>
            @endif
        </p>
    </div>
@endsection
