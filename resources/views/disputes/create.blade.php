@extends('layouts.app')
@section('title','Create Dispute')
@section('content')
<div style="max-width: 600px; margin: 0 auto;"><h1>Dispute Match {{ $match->id }}</h1><div class="card" style="margin-top: 16px;"><form method="POST" action="{{ route('disputes.store') }}">@csrf<input type="hidden" name="match_id" value="{{ $match->id }}"><div class="form-group"><label class="form-label required">Reason</label><textarea name="reason" class="form-textarea" required></textarea></div><button type="submit" class="btn btn-primary" data-require-online>Submit Dispute</button></form></div></div>
@endsection
