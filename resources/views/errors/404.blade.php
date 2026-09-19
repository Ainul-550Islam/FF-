@extends('layouts.app')
@section('title','404 Not Found')
@section('content')
<div style="text-align: center; padding: 60px 0;">
<h1 style="font-size: 72px; font-weight: 900; margin: 0;">404</h1>
<p class="text-muted" style="font-size: 18px;">Page not found</p>
<div style="margin-top: 16px;"><a href="{{ route('home') }}" class="btn btn-primary">Go Home</a> <span data-internet-status class="internet-status online" style="margin-left: 8px;"></span></div>
</div>
@endsection
