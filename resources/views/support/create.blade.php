@extends('layouts.app')
@section('title','Create Support Ticket')
@section('content')
<div style="max-width: 600px; margin: 0 auto;"><h1>Create Ticket</h1><div class="card" style="margin-top: 16px;"><form method="POST" action="{{ route('support.store') }}">@csrf<div class="form-group"><label class="form-label required">Subject</label><input type="text" name="subject" class="form-input" required></div><div class="form-group"><label class="form-label required">Body</label><textarea name="body" class="form-textarea" required></textarea></div><button type="submit" class="btn btn-primary" data-require-online>Submit</button><span data-internet-status class="internet-status online" style="margin-left: 12px;"></span></form></div></div>
@endsection
