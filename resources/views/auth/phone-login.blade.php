@extends('layouts.app')
@section('title','Phone Login')
@section('content')
<div style="max-width: 420px; margin: 40px auto;">
    <h1 style="font-size: 24px; font-weight: 800;">Login with phone</h1>
    <p class="text-muted">Enter phone to receive OTP. <span data-internet-status class="internet-status online"></span></p>
    <div class="card" style="margin-top: 16px;">
        <form method="POST" action="{{ route('auth.phone.request') }}">
            @csrf
            <div class="form-group"><label class="form-label required">Phone</label><input type="tel" name="phone" class="form-input" placeholder="+8801XXXXXXXXX" required></div>
            <button type="submit" class="btn btn-primary" style="width: 100%;" data-require-online>Send OTP</button>
        </form>
    </div>
</div>
@endsection
