@extends('layouts.app')
@section('title','Leaderboard')
@section('content')
<h1>Leaderboard - {{ $tournament->name ?? 'Latest' }}</h1>
<div class="card"><div class="table-wrap"><table><thead><tr><th>Rank</th><th>Team</th><th>Points</th><th>Avatar</th></tr></thead><tbody><tr><td>1</td><td>Team Alpha</td><td>120</td><td><span class="avatar avatar-sm"><span class="avatar-fallback">TA</span></span></td></tr><tr><td>2</td><td>Team Beta</td><td>100</td><td><span class="avatar avatar-sm"><span class="avatar-fallback">TB</span></span></td></tr></tbody></table></div><div style="margin-top: 12px;"><span data-internet-status class="internet-status online"></span></div></div>
@endsection
