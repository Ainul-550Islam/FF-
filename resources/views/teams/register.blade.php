@extends('layouts.app')
@section('title','Register Team')
@section('content')
<div style="max-width: 600px; margin: 0 auto;">
<h1>Register for {{ $tournament->name }}</h1>
<div class="card" style="margin-top: 16px;">
<form method="POST" action="{{ route('teams.register',$tournament) }}">
@csrf
<div class="form-group"><label class="form-label required">Team Name</label><input type="text" name="team_name" class="form-input" required></div>
<button type="submit" class="btn btn-primary" data-require-online>Register ({{ number_format($tournament->entry_fee_minor/100,2) }} BDT)</button>
<div style="margin-top: 12px; display: flex; gap: 8px; align-items: center;"><span data-internet-status class="internet-status online"></span><span class="text-muted" style="font-size: 12px;">Requires internet - idempotency prevents duplicate registration</span></div>
</form>
</div>
</div>
@endsection
