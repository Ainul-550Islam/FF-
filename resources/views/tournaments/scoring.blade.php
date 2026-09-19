@extends('layouts.app')
@section('title','Scoring - '.$tournament->name)
@section('content')
<h1>Scoring - {{ $tournament->name }}</h1>
<div class="card"><p class="text-muted">Scoring rules and submission</p><span data-internet-status class="internet-status online"></span></div>
@endsection
