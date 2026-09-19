@extends('layouts.app')
@section('title','Payment')
@section('content')
<h1>Payment {{ $payment->external_id ?? $payment->id }}</h1>
<div class="card"><div>Provider: {{ $payment->provider }} • Status: <x-status-pill :status="$payment->status" /> • Amount: {{ number_format($payment->amount_minor/100,2) }} {{ $payment->currency }}</div><div style="margin-top: 12px;"><span data-internet-status class="internet-status online"></span> <button onclick="window.FFArena?.checkInternet()" class="btn btn-secondary btn-sm">Check Status</button></div></div>
@endsection
