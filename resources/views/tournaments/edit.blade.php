@extends('layouts.app')
@section('title','Edit Tournament')
@section('content')
<div style="max-width: 600px; margin: 0 auto;">
<h1 style="font-size: 24px; font-weight: 800;">Edit {{ $tournament->name }}</h1>
<div class="card" style="margin-top: 16px;">
<form method="POST" action="{{ route('admin.tournaments.update',$tournament) }}">
@csrf @method('PUT')
<div class="form-group"><label class="form-label required">Name</label><input type="text" name="name" value="{{ $tournament->name }}" class="form-input" required></div>
<div class="form-group"><label class="form-label required">Status</label><select name="status" class="form-select"><option value="draft" {{ $tournament->status==='draft'?'selected':'' }}>Draft</option><option value="open" {{ $tournament->status==='open'?'selected':'' }}>Open</option><option value="ongoing" {{ $tournament->status==='ongoing'?'selected':'' }}>Ongoing</option><option value="completed" {{ $tournament->status==='completed'?'selected':'' }}>Completed</option></select></div>
<button type="submit" class="btn btn-primary" data-require-online>Update</button>
</form>
</div>
</div>
@endsection
