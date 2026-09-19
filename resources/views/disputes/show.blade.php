@extends('layouts.app')
@section('title','Dispute')
@section('content')
<h1>Dispute #{{ $dispute->id }}</h1><div class="card"><p class="text-muted">Dispute details</p><span data-internet-status class="internet-status online"></span></div>
@endsection
