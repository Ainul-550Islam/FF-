@extends('layouts.app')
@section('title','Settlement')
@section('content')
<h1>Settlement {{ $settlement->id ?? 'N/A' }}</h1>
<div class="card"><p class="text-muted">Settlement details</p><span data-internet-status class="internet-status online"></span></div>
@endsection
