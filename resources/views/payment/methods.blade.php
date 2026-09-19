@extends('layouts.app')
@section('title','Payment Methods')
@section('content')
<h1>Payment Methods</h1>
<p class="text-muted">Choose provider - internet required for online providers <span data-internet-status class="internet-status online"></span></p>
<div class="grid grid-2" style="margin-top: 16px;">
@foreach($methods as $m)
<div class="card"><h3>{{ $m['name'] }}</h3><p class="text-muted" style="font-size: 13px;">{{ $m['type'] }} • Online: {{ in_array($m['id'],['bkash','nagad'])?'Yes':'Manual' }}</p><button class="btn btn-primary btn-sm" data-require-online>Pay with {{ $m['name'] }}</button></div>
@endforeach
</div>
@endsection
