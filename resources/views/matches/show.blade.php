@extends('layouts.app')
@section('title','Match')
@section('content')
<h1>Match {{ $match->id }}</h1>
<div class="card"><p>Status: <x-status-pill :status="$match->status" /></p><span data-internet-status class="internet-status online"></span></div>
@endsection
