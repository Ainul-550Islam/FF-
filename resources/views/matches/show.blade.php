@extends('layouts.app')
@section('title', 'Match — ' . $tournament->name)
@section('content')
    <header class="page-head">
        <nav class="breadcrumbs" aria-label="Breadcrumb">
            <li><a href="{{ route('home') }}">Home</a></li>
            <li><a href="{{ route('tournaments.show', $tournament) }}">{{ $tournament->name }}</a></li>
            <li><span aria-current="page">Match #{{ $match->match_no }}</span></li>
        </nav>
        <h1 class="page-title">{{ $match->roundLabel() }} — Match #{{ $match->match_no }}</h1>
        <p class="page-subtitle">{{ $match->bracketLabel() }}</p>
    </header>

    <div class="grid cols-2">
        <section class="card" aria-labelledby="matchup-heading">
            <h3 id="matchup-heading">⚔️ Matchup</h3>
            @if ($match->isBye())
                @php $byeTeam = $match->team1 ?: $match->team2; @endphp
                <div class="bracket-team win">
                    <strong>{{ $byeTeam->name ?? 'TBD' }}</strong>
                </div>
                <p class="muted text-center mt-3 mb-3">— bye, automatically advanced —</p>
            @else
                <div class="bracket-team {{ $match->winner_team_id === $match->team1_id ? 'win' : '' }}">
                    <strong>{{ $match->team1?->name ?? 'TBD' }}</strong>
                </div>
                <div class="text-center" style="color: var(--purple); font-weight: 800; padding: 4px 0">VS</div>
                <div class="bracket-team {{ $match->winner_team_id === $match->team2_id ? 'win' : '' }}">
                    <strong>{{ $match->team2?->name ?? 'TBD' }}</strong>
                </div>
                @if ($match->winner)
                    <div class="muted mt-3" style="font-size: .85rem">
                        Winner: <strong class="tag">{{ $match->winner->name }}</strong>
                    </div>
                @endif
            @endif
            <div class="muted mt-3" style="font-size: .85rem">
                Status: <x-status-pill :status="$match->statusPill()" :label="strtoupper($match->status)" />
            </div>
            @if ($match->nextMatch)
                <div class="muted mt-2" style="font-size: .85rem">
                    Next: <a href="{{ route('matches.show', [$tournament, $match->nextMatch]) }}">
                        {{ $match->nextMatch->roundLabel() }} #{{ $match->nextMatch->match_no }}
                        @if ($match->next_slot) (slot {{ $match->next_slot }}) @endif
                    </a>
                </div>
            @endif
            @if ($match->loserNextMatch)
                <div class="muted mt-1" style="font-size: .85rem">
                    Loser drops to: <a href="{{ route('matches.show', [$tournament, $match->loserNextMatch]) }}">
                        {{ $match->loserNextMatch->roundLabel() }} #{{ $match->loserNextMatch->match_no }}
                        @if ($match->loser_slot) (slot {{ $match->loser_slot }}) @endif
                    </a>
                </div>
            @endif
        </section>

        <section class="card" aria-labelledby="room-heading">
            <h3 id="room-heading">🎟 Room Info</h3>
            @if ($match->room_id)
                <div style="font-size: 15px">
                    Room ID: <strong class="tag">{{ $match->room_id }}</strong><br>
                    Password: <strong class="tag">{{ $match->room_pass }}</strong><br>
                    Time: {{ optional($match->scheduled_at)->format('d M, h:i A') }}
                </div>
            @else
                <p class="muted">Room details not published yet.</p>
            @endif

            @auth
                @if (auth()->user()->isAdmin() || auth()->user()->isOrganizer())
                    <form method="POST" action="{{ route('matches.room', [$tournament, $match]) }}" class="mt-3">
                        @csrf
                        <div class="grid cols-2">
                            <div class="field">
                                <label for="room_id">Room ID</label>
                                <input type="text" id="room_id" name="room_id" required>
                            </div>
                            <div class="field">
                                <label for="room_pass">Password</label>
                                <input type="text" id="room_pass" name="room_pass" required>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-sm btn-cyan mt-2">Publish Room &amp; Start Match</button>
                    </form>
                @endif
            @endauth
        </section>
    </div>

    <div class="grid cols-2">
        <section class="card" aria-labelledby="scores-heading">
            <h3 id="scores-heading">📊 Submitted Scores</h3>
            @if ($match->scores->isEmpty())
                <p class="muted">No scores submitted yet.</p>
            @else
                <div class="table-wrap">
                    <table>
                        <caption class="sr-only">Scores submitted for this match</caption>
                        <thead>
                            <tr>
                                <th scope="col">Team</th>
                                <th scope="col">Kills</th>
                                <th scope="col">Place</th>
                                <th scope="col">Place Pts</th>
                                <th scope="col">Kill Pts</th>
                                <th scope="col">Adj</th>
                                <th scope="col">Total</th>
                                <th scope="col">Proof</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($match->scores as $score)
                                <tr>
                                    <td><strong>{{ $score->team->name }}</strong></td>
                                    <td>{{ $score->kills }}</td>
                                    <td>#{{ $score->placement }}</td>
                                    <td>{{ $score->placement_points }}</td>
                                    <td>{{ $score->kill_points }}</td>
                                    <td>
                                        @if ($score->adjustments->isEmpty())
                                            <span class="muted">—</span>
                                        @else
                                            @foreach ($score->adjustments as $adj)
                                                <x-status-pill :status="$adj->isBonus() ? 'confirmed' : 'finished'"
                                                    :label="($adj->isBonus() ? '+' : '−') . $adj->points" />
                                            @endforeach
                                        @endif
                                    </td>
                                    <td><strong class="tag">{{ $score->points }}</strong></td>
                                    <td>
                                        @if ($score->screenshot_path)
                                            <a href="{{ asset('storage/' . $score->screenshot_path) }}" target="_blank" class="btn btn-sm">View</a>
                                        @else
                                            <span class="muted">—</span>
                                        @endif
                                    </td>
                                </tr>
                                @if ($score->adjustments->isNotEmpty())
                                    <tr>
                                        <td colspan="8" style="background: var(--panel2); font-size: .8rem">
                                            <span class="muted">Adjustments:</span>
                                            @foreach ($score->adjustments as $adj)
                                                <span class="muted">{{ $adj->isBonus() ? 'Bonus' : 'Penalty' }} {{ $adj->points }}pt — "{{ $adj->reason }}"</span>
                                                @if (! $loop->last) · @endif
                                            @endforeach
                                        </td>
                                    </tr>
                                @endif
                                @if ($score->scoringRule)
                                    <tr>
                                        <td colspan="8" style="background: var(--panel2); font-size: .8rem">
                                            <span class="muted">Scoring rules: {{ $score->scoringRule->label() }} (v{{ $score->scoringRule->version }})</span>
                                        </td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="card" aria-labelledby="submit-score">
            <h3 id="submit-score">📝 Submit Score</h3>
            @if ($match->acceptsScoreSubmission())
                <form method="POST" action="{{ route('matches.score', [$tournament, $match]) }}" enctype="multipart/form-data">
                    @csrf
                    <div class="field">
                        <label for="team_id">Your team</label>
                        <select id="team_id" name="team_id" required>
                            @if ($match->team1) <option value="{{ $match->team1->id }}">{{ $match->team1->name }}</option> @endif
                            @if ($match->team2) <option value="{{ $match->team2->id }}">{{ $match->team2->name }}</option> @endif
                        </select>
                    </div>
                    <div class="grid cols-2">
                        <div class="field">
                            <label for="kills">Kills</label>
                            <input type="number" id="kills" name="kills" min="0" value="0" required>
                        </div>
                        <div class="field">
                            <label for="placement">Placement</label>
                            <input type="number" id="placement" name="placement" min="1" max="{{ \App\Models\ScoringRule::MAX_PLACEMENT }}" value="1" required>
                        </div>
                    </div>
                    <div class="field">
                        <label for="screenshot">Screenshot (proof)</label>
                        <input type="file" id="screenshot" name="screenshot" accept="image/*">
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm mt-2">Submit Score</button>
                </form>
            @else
                <p class="muted">Score submission is not open for this match.</p>
            @endif

            @auth
                @if (auth()->user()->isAdmin() || (auth()->user()->isOrganizer() && auth()->user()->id === $tournament->organizer_id))
                    <hr style="border-color: var(--line); margin: 16px 0">

                    @if ($match->acceptsScoreSubmission() && $match->scores->isNotEmpty())
                        <h4>⚖ Score Adjustment (bonus / penalty)</h4>
                        <form method="POST" action="{{ route('matches.adjustment', [$tournament, $match]) }}">
                            @csrf
                            <div class="field">
                                <label for="adjust-team_id">Team</label>
                                <select id="adjust-team_id" name="team_id" required>
                                    @foreach ($match->scores as $score)
                                        <option value="{{ $score->team_id }}">{{ $score->team->name }} ({{ $score->points }} pts)</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="grid cols-2">
                                <div class="field">
                                    <label for="adjust-type">Type</label>
                                    <select id="adjust-type" name="type" required>
                                        <option value="bonus">Bonus (+)</option>
                                        <option value="penalty">Penalty (−)</option>
                                    </select>
                                </div>
                                <div class="field">
                                    <label for="adjust-points">Points</label>
                                    <input type="number" id="adjust-points" name="points" min="1" max="1000" value="1" required>
                                </div>
                            </div>
                            <div class="field">
                                <label for="adjust-reason">Reason (required, audited)</label>
                                <input type="text" id="adjust-reason" name="reason" placeholder="e.g. Booyah bonus" maxlength="255" required>
                            </div>
                            <button type="submit" class="btn btn-sm btn-cyan mt-1">Apply Adjustment</button>
                        </form>
                        <hr style="border-color: var(--line); margin: 16px 0">
                    @endif

                    @if ($match->acceptsScoreSubmission())
                        <h4>✅ Set Winner</h4>
                        <form method="POST" action="{{ route('matches.winner', [$tournament, $match]) }}">
                            @csrf
                            <div class="field">
                                <label for="winner_team_id">Winner team</label>
                                <select id="winner_team_id" name="winner_team_id" required>
                                    @if ($match->team1) <option value="{{ $match->team1->id }}">{{ $match->team1->name }}</option> @endif
                                    @if ($match->team2) <option value="{{ $match->team2->id }}">{{ $match->team2->name }}</option> @endif
                                </select>
                            </div>
                            <button type="submit" class="btn btn-green btn-sm mt-1">Confirm Winner (advances bracket)</button>
                        </form>
                    @endif

                    @if ($match->status === 'completed')
                        <form method="POST" action="{{ route('matches.dispute', [$tournament, $match]) }}">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-danger mt-2"
                                onclick="return confirm('Mark this completed match as disputed? Advancement will be blocked until resolved.')">
                                ⚠ Mark Disputed
                            </button>
                        </form>
                    @endif

                    @if ($match->status === 'disputed')
                        <h4 class="mt-3">⚖ Resolve Dispute</h4>
                        <p class="muted" style="font-size: .85rem">Choose the correct winner. The bracket will be re-advanced.</p>
                        <form method="POST" action="{{ route('matches.resolve', [$tournament, $match]) }}">
                            @csrf
                            <div class="field">
                                <label for="resolve-winner_team_id">Winner team</label>
                                <select id="resolve-winner_team_id" name="winner_team_id" required>
                                    @if ($match->team1) <option value="{{ $match->team1->id }}" @selected($match->winner_team_id === $match->team1_id)>{{ $match->team1->name }}</option> @endif
                                    @if ($match->team2) <option value="{{ $match->team2->id }}" @selected($match->winner_team_id === $match->team2_id)>{{ $match->team2->name }}</option> @endif
                                </select>
                            </div>
                            <button type="submit" class="btn btn-green btn-sm mt-1">Resolve Dispute</button>
                        </form>
                    @endif
                @endif
            @endauth
        </section>
    </div>

    <section class="card" aria-labelledby="disputes-heading">
        <h3 id="disputes-heading">🚩 Disputes</h3>
        @if ($match->disputes->isEmpty())
            <p class="muted">No disputes for this match.</p>
        @else
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Disputes for this match</caption>
                    <thead>
                        <tr>
                            <th scope="col">#</th>
                            <th scope="col">Category</th>
                            <th scope="col">Status</th>
                            <th scope="col">Opened by</th>
                            <th scope="col">Opened</th>
                            <th scope="col"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($match->disputes as $dispute)
                            <tr>
                                <td><strong>#{{ $dispute->id }}</strong></td>
                                <td>{{ $dispute->categoryLabel() }}</td>
                                <td><x-status-pill :status="$dispute->statusPill()" :label="$dispute->statusLabel()" /></td>
                                <td>{{ $dispute->opener?->name ?? 'System' }}</td>
                                <td class="muted" style="font-size: .8rem">{{ $dispute->created_at->format('d M, h:i A') }}</td>
                                <td>
                                    <a href="{{ route('matches.disputes.show', [$tournament, $match, $dispute]) }}" class="btn btn-sm">View</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @auth
            @if ($canOpenDispute)
                <a href="{{ route('matches.disputes.create', [$tournament, $match]) }}" class="btn btn-sm btn-danger mt-3">🚩 Open Dispute</a>
            @endif
        @endauth
    </section>
@endsection
