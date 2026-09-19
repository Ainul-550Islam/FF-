@extends('layouts.app')
@section('title','Reset Password')
@section('content')
<div style="max-width: 420px; margin: 40px auto;">
    <h1 style="font-size: 24px; font-weight: 800;">Reset password</h1>
    <div class="card" style="margin-top: 16px;">
        <form method="POST" action="{{ route('password.update') }}">
            @csrf
            <input type="hidden" name="token" value="{{ $token ?? '' }}">
            <div class="form-group"><label class="form-label required">Email</label><input type="email" name="email" value="{{ old('email') }}" class="form-input" required></div>
            <div class="form-group"><label class="form-label required">New Password</label><input type="password" name="password" class="form-input" required></div>
            <div class="form-group"><label class="form-label required">Confirm</label><input type="password" name="password_confirmation" class="form-input" required></div>
            <button type="submit" class="btn btn-primary" style="width: 100%;" data-require-online>Reset Password</button>
        </form>
    </div>
</div>
@endsection
