@extends('layouts.app')
@section('title', 'Edit Tournament — FF Arena')
@section('content')
    <div class="card" style="max-width: 640px; margin: 40px auto">
        <h2>Edit: {{ $tournament->name }}</h2>

        <form method="POST" action="{{ route('tournaments.update', $tournament) }}" novalidate>
            @csrf
            @method('PUT')
            <div class="field">
                <label for="name">Tournament name</label>
                <input type="text" id="name" name="name" value="{{ $tournament->name }}" required>
            </div>

            <div class="grid cols-2 mt-1">
                <div class="field">
                    <label for="game_mode">Game mode</label>
                    <select id="game_mode" name="game_mode">
                        @foreach (['squad', 'duo', 'solo'] as $m)
                            <option value="{{ $m }}" @selected($tournament->game_mode === $m)>{{ ucfirst($m) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="map">Map</label>
                    <input type="text" id="map" name="map" value="{{ $tournament->map }}" required>
                </div>
                <div class="field">
                    <label for="entry_fee">Entry fee (৳)</label>
                    <input type="number" id="entry_fee" name="entry_fee" value="{{ $tournament->entry_fee }}" min="0" required>
                </div>
                <div class="field">
                    <label for="prize_pool">Prize pool (৳)</label>
                    <input type="number" id="prize_pool" name="prize_pool" value="{{ $tournament->prize_pool }}" min="0" required>
                </div>
                <div class="field">
                    <label for="team_slots">Team slots</label>
                    <select id="team_slots" name="team_slots">
                        @foreach ([8, 16, 32] as $s)
                            <option value="{{ $s }}" @selected($tournament->team_slots == $s)>{{ $s }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="team_size">Players per team</label>
                    <input type="number" id="team_size" name="team_size" value="{{ $tournament->team_size }}" min="1" max="6" required>
                </div>
                <div class="field">
                    <label for="format">Bracket format</label>
                    <select id="format" name="format">
                        @foreach (\App\Models\Tournament::FORMATS as $f)
                            <option value="{{ $f }}" @selected($tournament->format === $f)>
                                {{ $f === 'double_elim' ? 'Double Elimination' : 'Single Elimination' }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="field">
                <label for="starts_at">Start date &amp; time</label>
                <input type="datetime-local" id="starts_at" name="starts_at" value="{{ optional($tournament->starts_at)->format('Y-m-d\TH:i') }}" required>
            </div>

            <div class="grid cols-2 mt-1">
                <div class="field">
                    <label for="check_in_starts_at">Check-in opens (optional)</label>
                    <input type="datetime-local" id="check_in_starts_at" name="check_in_starts_at" value="{{ optional($tournament->check_in_starts_at)->format('Y-m-d\TH:i') }}">
                </div>
                <div class="field">
                    <label for="check_in_ends_at">Check-in closes (optional)</label>
                    <input type="datetime-local" id="check_in_ends_at" name="check_in_ends_at" value="{{ optional($tournament->check_in_ends_at)->format('Y-m-d\TH:i') }}">
                </div>
            </div>

            <div class="field">
                <label for="dispute_window_hours">Dispute window (hours)</label>
                <input type="number" id="dispute_window_hours" name="dispute_window_hours" value="{{ $tournament->disputeWindowHours() }}" min="0" max="720">
                <p class="help-text">0 disables participant disputes. Staff always bypass the window.</p>
            </div>

            <div class="field">
                <label for="rules">Rules</label>
                <textarea id="rules" name="rules" rows="4">{{ $tournament->rules }}</textarea>
            </div>

            <button type="submit" class="btn btn-primary btn-block mt-4">Save Changes</button>
        </form>
    </div>
@endsection
