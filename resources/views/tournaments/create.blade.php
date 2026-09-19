@extends('layouts.app')
@section('title','Create Tournament')
@section('content')
<div style="max-width: 600px; margin: 0 auto;">
<h1 style="font-size: 24px; font-weight: 800;">Create Tournament</h1>
<div class="card" style="margin-top: 16px;">
<form method="POST" action="{{ route('admin.tournaments.store') }}">
@csrf
<div class="form-group"><label class="form-label required">Name</label><input type="text" name="name" class="form-input" required></div>
<div class="grid grid-2"><div class="form-group"><label class="form-label required">Entry Fee (minor)</label><input type="number" name="entry_fee_minor" class="form-input" value="0" required></div><div class="form-group"><label class="form-label required">Prize Pool (minor)</label><input type="number" name="prize_pool_minor" class="form-input" value="100000" required></div></div>
<div class="form-group"><label class="form-label required">Max Teams</label><input type="number" name="max_teams" class="form-input" value="16" required></div>
<button type="submit" class="btn btn-primary" data-require-online>Create</button>
</form>
</div>
</div>
@endsection
