@extends('layouts.app')
@section('title','Forgot Password')
@section('content')
<div style="max-width: 420px; margin: 40px auto;">
    <h1 style="font-size: 24px; font-weight: 800;">Forgot password</h1>
    <p class="text-muted">Enter email to receive reset link. <span data-internet-status class="internet-status online"></span></p>
    <div class="card" style="margin-top: 16px;">
        <form method="POST" action="{{ route('password.email') }}">
            @csrf
            <div class="form-group">
                <label class="form-label required">Email</label>
                <input type="email" name="email" value="{{ old('email') }}" class="form-input" required>
            </div>
            <button type="submit" class="btn btn-primary" style="width: 100%;" data-require-online>Send Reset Link</button>
        </form>
    </div>
</div>
@endsection
