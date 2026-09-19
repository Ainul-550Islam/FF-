@extends('layouts.app')
@section('title','Live Poll')
@section('content')
<h1>Live Updates</h1>
<div class="card"><p>Cursor: {{ $cursor }} • Online: {{ $online ? 'Yes' : 'No' }}</p><div style="margin-top: 12px;"><span data-internet-status class="internet-status online"></span></div><div id="live-events" style="margin-top: 16px;">@foreach($events as $e)<div class="alert alert-info">{{ $e['message'] }}</div>@endforeach</div></div>
@endsection
