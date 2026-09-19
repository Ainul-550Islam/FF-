@extends('layouts.app')
@section('title','Payment Pending')
@section('content')
<h1>Payment Pending</h1>
<div class="card"><p>Your payment is being processed. We will query provider automatically.</p><div class="alert alert-info"><span>ℹ</span><span>Do not close page - internet required. If offline, we will reconcile when back online.</span></div><span data-internet-status class="internet-status online"></span></div>
@endsection
