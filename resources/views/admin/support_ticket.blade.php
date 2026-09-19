@extends('layouts.app')
@section('title','Support Ticket')
@section('content')
<h1>Ticket #{{ $ticket->id ?? 1 }}</h1>
<div class="card"><p class="text-muted">Ticket details</p></div>
@endsection
