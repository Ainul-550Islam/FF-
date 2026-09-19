@extends('layouts.app')
@section('title','Support')
@section('content')
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;"><h1 style="margin: 0; font-size: 28px; font-weight: 800;">Support Tickets</h1><a href="{{ route('support.create') }}" class="btn btn-primary">New Ticket</a></div>
<x-empty-state title="No tickets" text="Create support ticket for help" />
@endsection
