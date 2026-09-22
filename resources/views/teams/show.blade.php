@extends('layouts.app')
@section('title', $team->name . ' — FF Arena')
@section('content')
    <header class="page-head">
        <nav class="breadcrumbs" aria-label="Breadcrumb">
            <li><a href="{{ route('home') }}">Home</a></li>
            <li><a href="{{ route('tournaments.show', $tournament) }}">{{ $tournament->name }}</a></li>
            <li><span aria-current="page">{{ $team->name }}</span></li>
        </nav>
        <h1 class="page-title">{{ $team->name }}</h1>
        <div class="row muted">
            {{ $tournament->name }}
            <x-status-pill :status="$team->status" />
            @if ($locked)
                <x-status-pill status="withdrawn" label="Roster locked" />
            @endif
            @if ($team->isWaitlisted())
                <x-status-pill status="waitlisted" :label="'Waitlist #' . $team->waitlistPosition()" />
            @elseif ($team->isCheckedIn())
                <x-status-pill status="checked" label="Checked in" />
            @endif
        </div>
    </header>

    @if ($tournament->hasCheckIn())
        <section class="card" aria-labelledby="checkin-heading">
            <h3 id="checkin-heading">📋 Check-in</h3>
            @if ($team->isCheckedIn())
                <p class="text-success"><strong>✓ Checked in</strong>
                    <span class="muted">at {{ $team->checked_in_at->format('d M, h:i A') }}</span></p>
            @elseif ($team->isWaitlisted())
                <p class="muted">Waitlisted teams check in after they are promoted and confirmed.</p>
            @elseif (! $team->isConfirmed())
                <p class="muted">Only confirmed teams can check in. Complete your payment first.</p>
            @elseif ($tournament->checkInIsOpen())
                <form method="POST" action="{{ route('teams.checkin', [$tournament, $team]) }}">
                    @csrf
                    <button type="submit" class="btn btn-green">✅ Check In Now</button>
                </form>
            @elseif ($tournament->checkInHasClosed())
                <p class="text-danger">The check-in window has closed.</p>
            @else
                <p class="muted">Check-in opens {{ $tournament->check_in_starts_at->format('d M, h:i A') }}.</p>
            @endif
        </section>
    @endif

    <div class="grid cols-2">
        <section class="card" aria-labelledby="team-info">
            <h3 id="team-info">🪪 Team Info</h3>
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Team details</caption>
                    <tbody>
                        <tr><th scope="row">Captain</th><td>{{ $team->captain_name }}</td></tr>
                        <tr><th scope="row">Captain UID</th><td><strong class="tag">{{ $team->game_uid }}</strong></td></tr>
                        <tr><th scope="row">Phone</th><td>{{ $team->phone }}</td></tr>
                        <tr><th scope="row">Roster size</th><td>{{ $team->rosterSize() }} / {{ $tournament->team_size }} players</td></tr>
                        <tr><th scope="row">Status</th><td>{{ ucfirst($team->status) }}</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="card" aria-labelledby="roster-heading">
            <h3 id="roster-heading">👥 Roster</h3>
            @if ($team->members->isEmpty())
                <x-empty-state title="No members added yet" icon="👥">
                    The captain is the only player on this team.
                </x-empty-state>
            @else
                <div class="table-wrap">
                    <table>
                        <caption class="sr-only">Roster members</caption>
                        <thead>
                            <tr>
                                <th scope="col">#</th>
                                <th scope="col">Player</th>
                                <th scope="col">Free Fire UID</th>
                                @if ($canEdit)<th scope="col"><span class="sr-only">Actions</span></th>@endif
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($team->members as $m)
                                <tr>
                                    <td>{{ $loop->iteration + 1 }}</td>
                                    <td>{{ $m->player_name }}</td>
                                    <td><strong class="tag">{{ $m->game_uid }}</strong></td>
                                    @if ($canEdit)
                                        <td>
                                            <form method="POST" action="{{ route('teams.members.remove', [$tournament, $team, $m]) }}">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-danger"
                                                        onclick="return confirm('Remove {{ $m->player_name }} from the roster?')">Remove</button>
                                            </form>
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>

    @if ($canEdit)
        <div class="grid cols-2">
            <section class="card" aria-labelledby="add-member">
                <h3 id="add-member">➕ Add Member</h3>
                @if ($slotsLeft > 0)
                    <p class="muted mb-2" style="font-size: .85rem">{{ $slotsLeft }} roster slot(s) remaining.</p>
                    <form method="POST" action="{{ route('teams.members.store', [$tournament, $team]) }}">
                        @csrf
                        <div class="field">
                            <label for="player_name">Player name</label>
                            <input type="text" id="player_name" name="player_name" required placeholder="Player name">
                        </div>
                        <div class="field">
                            <label for="member_game_uid">Free Fire UID</label>
                            <input type="text" id="member_game_uid" name="game_uid" required placeholder="e.g. 1234567890">
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm">Add to Roster</button>
                    </form>
                @else
                    <p class="muted">Roster is full ({{ $tournament->team_size }} players maximum).</p>
                @endif
            </section>

            <section class="card" aria-labelledby="edit-team">
                <h3 id="edit-team">✏️ Edit Team Info</h3>
                <form method="POST" action="{{ route('teams.update', [$tournament, $team]) }}">
                    @csrf
                    @method('PUT')
                    <div class="field">
                        <label for="edit-name">Team name</label>
                        <input type="text" id="edit-name" name="name" value="{{ $team->name }}" required>
                    </div>
                    <div class="field">
                        <label for="edit-captain_name">Captain name</label>
                        <input type="text" id="edit-captain_name" name="captain_name" value="{{ $team->captain_name }}" required>
                    </div>
                    <div class="field">
                        <label for="edit-phone">Phone (bKash)</label>
                        <input type="tel" id="edit-phone" name="phone" value="{{ $team->phone }}" required>
                    </div>
                    <div class="field">
                        <label for="edit-game_uid">Captain Free Fire UID</label>
                        <input type="text" id="edit-game_uid" name="game_uid" value="{{ $team->game_uid }}" required>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">Save Changes</button>
                </form>
            </section>
        </div>
    @elseif ($locked && ! $canEdit)
        <div class="card">
            <p class="muted">🔒 This roster is locked. Registration has closed, so the roster can no longer be changed.</p>
        </div>
    @endif
@endsection
