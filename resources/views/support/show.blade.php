@extends('layouts.app')
@section('title','Support Ticket')
@section('content')
<h1>{{ $ticket->subject }}</h1><div class="card"><p class="text-muted">Ticket #{{ $ticket->id }}</p><span data-internet-status class="internet-status online"></span></div>
@endsection
