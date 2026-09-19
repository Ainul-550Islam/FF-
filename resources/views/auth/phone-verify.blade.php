@extends('layouts.app')
@section('title','Verify Phone')
@section('content')
<div style="max-width: 420px; margin: 40px auto;">
    <h1 style="font-size: 24px; font-weight: 800;">Verify OTP</h1>
    <p class="text-muted">Enter code sent to your phone. Demo: 123456 <span data-internet-status class="internet-status online"></span></p>
    <div class="card" style="margin-top: 16px;">
        <form method="POST" action="{{ route('auth.phone.verify') }}">
            @csrf
            <div class="form-group"><label class="form-label required">Phone</label><input type="tel" name="phone" class="form-input" required></div>
            <div class="form-group"><label class="form-label required">Code</label><input type="text" name="code" class="form-input" placeholder="123456" required></div>
            <button type="submit" class="btn btn-primary" style="width: 100%;" data-require-online>Verify</button>
        </form>
    </div>
</div>
@endsection
