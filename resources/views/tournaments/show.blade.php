@extends('layouts.app')

@section('content')
    <header class="page-head">
        <nav class="breadcrumbs" aria-label="Breadcrumb">
            <li><a href="{{ route('home') }}">Home</a></li>
            <li><a href="{{ route('tournaments.index') }}">Tournaments</a></li>
            <li><span aria-current="page">{{ $tournament->name }}</span></li>
        </nav>

        <div class="row-between">
            <div>
                <h1 class="page-title">{{ $tournament->name }}</h1>
                <p class="page-subtitle">
                    by {{ $tournament->organizer->name ?? 'Organizer' }} ·
                    {{ strtoupper($tournament->game_mode) }} · {{ $tournament->map }} ·
                    starts {{ optional($tournament->starts_at)->format('d M Y, h:i A') ?? 'TBA' }}
                </p>
            </div>
            <div class="row">
                @if ($tournament->hasCheckIn())
                    @if ($tournament->checkInIsOpen())
                        <x-status-pill status="checked" label="Check-in open" />
                    @elseif ($tournament->checkInHasClosed())
                        <x-status-pill status="no_show" label="Check-in closed" />
                    @else
                        <x-status-pill status="pending" label="Check-in not open" />
                    @endif
                @endif
                <x-status-pill :status="$tournament->status" />
            </div>
        </div>
    </header>

    {{-- Phase 12 — live updates feed (polling; server-side visibility) --}}
    @include('live.poll', ['tournament' => $tournament])

    <div class="grid cols-2">
        <section class="card" aria-labelledby="prize-heading">
            <h3 id="prize-heading">Prize &amp; Entry</h3>
            <dl class="row">
                <div class="stat">
                    <dt class="label">Entry fee</dt>
                    <dd class="num">৳{{ number_format($tournament->entry_fee) }}</dd>
                </div>
                <div class="stat">
                    <dt class="label">Prize pool</dt>
                    <dd class="num">৳{{ number_format($tournament->prize_pool) }}</dd>
                </div>
                <div class="stat">
                    <dt class="label">Slots left</dt>
                    <dd class="num">{{ $tournament->slotsLeft() }}/{{ $tournament->team_slots }}</dd>
                </div>
            </dl>
            <p class="muted mt-3" style="font-size: .85rem">Players per team: {{ $tournament->team_size }}</p>
            @if ($tournament->hasCheckIn())
                <p class="muted mt-1" style="font-size: .85rem">
                    Check-in: {{ $tournament->check_in_starts_at->format('d M, h:i A') }} —
                    {{ $tournament->check_in_ends_at->format('d M, h:i A') }}
                </p>
            @endif
        </section>

        <section class="card" aria-labelledby="rules-heading">
            <h3 id="rules-heading">Rules</h3>
            <div style="white-space: pre-wrap; font-size: .9rem; color: var(--muted)">
                {{ $tournament->rules ?: 'No rules set.' }}
            </div>
        </section>
    </div>

    @auth
        @if ((auth()->user()->isOrganizer() && auth()->user()->id === $tournament->organizer_id) || auth()->user()->isAdmin())
            <section class="card" aria-labelledby="organizer-controls">
                <h3 id="organizer-controls">🎛 Organizer Controls</h3>
                <div class="row">
                    @if ($tournament->status === 'draft')
                        <form method="POST" action="{{ route('tournaments.publish', $tournament) }}">
                            @csrf
                            <button class="btn btn-green btn-sm">Publish (open registration)</button>
                        </form>
                    @endif
                    @if ($tournament->status === 'open')
                        <form method="POST" action="{{ route('tournaments.close', $tournament) }}">
                            @csrf
                            <button class="btn btn-sm">Close registration</button>
                        </form>
                    @endif
                    @if (in_array($tournament->status, ['closed', 'open'], true))
                        <form method="POST" action="{{ route('tournaments.bracket', $tournament) }}">
                            @csrf
                            <button class="btn btn-primary btn-sm">⚡ Generate Bracket</button>
                        </form>
                    @endif
                    @if ($tournament->status === 'live')
                        <form method="POST" action="{{ route('tournaments.complete', $tournament) }}">
                            @csrf
                            <button class="btn btn-green btn-sm" onclick="return confirm('Finish this tournament? Make sure all matches are completed.')">🏁 Finish Tournament</button>
                        </form>
                    @endif
                    @if ($tournament->hasCheckIn() && $tournament->checkInHasClosed())
                        <form method="POST" action="{{ route('tournaments.noshows', $tournament) }}">
                            @csrf
                            <button class="btn btn-sm" onclick="return confirm('Mark unchecked-in teams as no-show and promote from the waitlist?')">🚫 Mark No-shows</button>
                        </form>
                    @endif
                    @if ($tournament->acceptsRegistration() && $waitlist && $waitlist->isNotEmpty())
                        <form method="POST" action="{{ route('tournaments.waitlist.promote', $tournament) }}">
                            @csrf
                            <button class="btn btn-sm btn-cyan">⬆ Promote Next Waitlisted</button>
                        </form>
                    @endif
                    <a href="{{ route('tournaments.edit', $tournament) }}" class="btn btn-sm">Edit</a>
                    <a href="{{ route('tournaments.scoring.show', $tournament) }}" class="btn btn-sm btn-cyan">Scoring Rules</a>
                    <a href="{{ route('leaderboard.show', $tournament) }}" class="btn btn-sm btn-cyan">Leaderboard</a>
                    @if (in_array($tournament->status, ['draft', 'open', 'closed'], true))
                        <form method="POST" action="{{ route('tournaments.cancel', $tournament) }}">
                            @csrf
                            <button class="btn btn-sm btn-danger" onclick="return confirm('Cancel this tournament?')">Cancel</button>
                        </form>
                    @endif
                </div>
            </section>
        @endif

        @if ($myTeam && ! $myTeam->isWithdrawn())
            <section class="card" aria-labelledby="my-team-heading">
                <h3 id="my-team-heading">🎽 Your Team</h3>
                <div class="row-between">
                    <div>
                        <strong>{{ $myTeam->name }}</strong>
                        <x-status-pill :status="$myTeam->status" />
                        @if ($myTeam->isWaitlisted())
                            <x-status-pill status="waitlisted" :label="'Waitlist #' . $myTeam->waitlistPosition()" />
                        @elseif ($myTeam->isCheckedIn())
                            <x-status-pill status="checked" label="Checked in" />
                        @endif
                        <p class="muted mt-1" style="font-size: .85rem">Roster: {{ $myTeam->rosterSize() }} / {{ $tournament->team_size }} players</p>
                    </div>
                    <div class="row">
                        @if ($myTeam->isConfirmed() && $tournament->hasCheckIn() && ! $myTeam->isCheckedIn() && $tournament->checkInIsOpen())
                            <form method="POST" action="{{ route('teams.checkin', [$tournament, $myTeam]) }}">
                                @csrf
                                <button class="btn btn-green btn-sm">✅ Check In</button>
                            </form>
                        @endif
                        <a href="{{ route('teams.show', [$tournament, $myTeam]) }}" class="btn btn-sm btn-cyan">Manage Team</a>
                        @if (in_array($tournament->status, ['draft', 'open', 'closed'], true))
                            <form method="POST" action="{{ route('teams.withdraw', [$tournament, $myTeam]) }}">
                                @csrf
                                <button class="btn btn-sm btn-danger" onclick="return confirm('Withdraw your team from this tournament?')">Withdraw Team</button>
                            </form>
                        @endif
                    </div>
                </div>
            </section>
        @endif
    @endauth

    @if ($tournament->acceptsRegistration() && ! $tournament->isFull())
        <section class="card text-center" aria-labelledby="register-cta">
            <h3 id="register-cta">Ready to fight? 🎯</h3>
            <a href="{{ route('teams.register', $tournament) }}" class="btn btn-primary">Register Your Team</a>
        </section>
    @elseif ($tournament->acceptsRegistration() && $tournament->isFull())
        <section class="card text-center" aria-labelledby="full-cta">
            <h3 id="full-cta">⏳ Tournament full</h3>
            <p class="muted">All slots are taken, but you can still join the waitlist.</p>
            <a href="{{ route('teams.register', $tournament) }}" class="btn btn-primary">Join Waitlist</a>
        </section>
    @endif

    @if ($tournament->status === 'live' || $tournament->status === 'finished')
        <section class="card" aria-labelledby="bracket-heading">
            <h2 id="bracket-heading">🏆 Bracket
                <span class="muted" style="font-size: .85rem; font-weight: 400">
                    — {{ $tournament->isDoubleElim() ? 'Double Elimination' : 'Single Elimination' }}
                    @if ($tournament->bracket_size) ({{ $tournament->bracket_size }}-team bracket) @endif
                </span>
            </h2>
            @if ($tournament->matches->isEmpty())
                <p class="muted">Bracket not generated yet.</p>
            @elseif ($tournament->isDoubleElim())
                @php
                    $sides = [
                        'winners' => ['label' => 'Winners Bracket', 'brackets' => ['winners']],
                        'losers' => ['label' => 'Losers Bracket', 'brackets' => ['losers']],
                        'grand_final' => ['label' => 'Grand Final', 'brackets' => ['grand_final']],
                    ];
                @endphp
                @foreach ($sides as $side)
                    @php $sideMatches = $tournament->matches->where('bracket', $side['brackets'][0]); @endphp
                    @if ($sideMatches->isNotEmpty())
                        <div class="mt-4">
                            <h3 class="muted" style="font-size: .85rem; font-weight: 800; text-transform: uppercase; letter-spacing: .5px">{{ $side['label'] }}</h3>
                            <div class="bracket-col">
                                @foreach ($sideMatches->groupBy('round')->sortKeys() as $round => $roundMatches)
                                    <div class="bracket-round">
                                        <div class="muted" style="font-size: .8rem; font-weight: 700">
                                            {{ $roundMatches->first()->roundLabel() }}
                                        </div>
                                        @foreach ($roundMatches as $m)
                                            @include('matches._bracket_card', ['match' => $m])
                                        @endforeach
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endforeach
            @else
                @php $rounds = $tournament->matches->groupBy('round')->sortKeys(); $lastRound = $rounds->keys()->last(); @endphp
                <div class="bracket-col">
                    @foreach ($rounds as $round => $matches)
                        <div class="bracket-round">
                            <div class="muted" style="font-size: .8rem; font-weight: 700">
                                {{ $round == $lastRound ? '🏁 FINAL' : 'Round ' . $round }}
                            </div>
                            @foreach ($matches as $m)
                                @include('matches._bracket_card', ['match' => $m])
                            @endforeach
                        </div>
                    @endforeach
                </div>
            @endif
        </section>
    @endif

    <section class="card" aria-labelledby="teams-heading">
        <h3 id="teams-heading">👥 Registered Teams</h3>
        @if ($tournament->confirmedTeams->isEmpty())
            <p class="muted">No confirmed teams yet.</p>
        @else
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Confirmed teams and their check-in status</caption>
                    <thead>
                        <tr>
                            <th scope="col">#</th>
                            <th scope="col">Team</th>
                            <th scope="col">Captain</th>
                            <th scope="col">Status</th>
                            @if ($tournament->hasCheckIn())<th scope="col">Check-in</th>@endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($tournament->confirmedTeams as $team)
                            <tr>
                                <td>{{ $loop->iteration }}</td>
                                <td><strong>{{ $team->name }}</strong></td>
                                <td class="muted">{{ $team->captain_name }}</td>
                                <td><x-status-pill status="confirmed" /></td>
                                @if ($tournament->hasCheckIn())
                                    <td>
                                        @if ($team->isCheckedIn())
                                            <x-status-pill status="checked" />
                                        @else
                                            <x-status-pill status="pending" label="Not checked in" />
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    @if ($waitlist && $waitlist->isNotEmpty())
        <section class="card" aria-labelledby="waitlist-heading">
            <h3 id="waitlist-heading">⏳ Waitlist</h3>
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Waitlisted teams in FIFO order</caption>
                    <thead>
                        <tr>
                            <th scope="col">#</th>
                            <th scope="col">Team</th>
                            <th scope="col">Captain</th>
                            <th scope="col">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($waitlist as $wt)
                            <tr>
                                <td>{{ $loop->iteration }}</td>
                                <td><strong>{{ $wt->name }}</strong></td>
                                <td class="muted">{{ $wt->captain_name }}</td>
                                <td><x-status-pill status="waitlisted" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
@endsection
