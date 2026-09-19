@extends('layouts.app')
@section('title','Verify Email')
@section('content')
<div style="max-width: 420px; margin: 40px auto; text-align: center;">
    <h1 style="font-size: 24px; font-weight: 800;">Verify your email</h1>
    <p class="text-muted">We sent verification link to your email. <span data-internet-status class="internet-status online"></span></p>
    <div class="card" style="margin-top: 16px;">
        <form method="POST" action="{{ route('verification.send') ?? '#' }}">
            @csrf
            <button type="submit" class="btn btn-primary" data-require-online>Resend Verification Email</button>
        </form>
    </div>
</div>
@endsection
