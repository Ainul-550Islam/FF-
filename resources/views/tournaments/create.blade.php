@extends('layouts.app')
@section('title', 'Create Tournament — FF Arena')
@section('content')
    <div class="card" style="max-width: 640px; margin: 40px auto">
        <h2>🎮 Create a Tournament</h2>

        <form method="POST" action="{{ route('tournaments.store') }}" novalidate>
            @csrf
            <div class="field">
                <label for="name">Tournament name</label>
                <input type="text" id="name" name="name" value="{{ old('name') }}" placeholder="Squad Showdown 32 Teams" required
                       @if ($errors->has('name')) aria-invalid="true" aria-describedby="name-error" @endif>
                @error('name')
                    <span class="form-error" id="name-error">{{ $message }}</span>
                @enderror
            </div>

            <div class="grid cols-2 mt-1">
                <div class="field">
                    <label for="game_mode">Game mode</label>
                    <select id="game_mode" name="game_mode">
                        <option value="squad">Squad</option>
                        <option value="duo">Duo</option>
                        <option value="solo">Solo</option>
                    </select>
                </div>
                <div class="field">
                    <label for="map">Map</label>
                    <select id="map" name="map">
                        <option>Bermuda</option>
                        <option>Purgatory</option>
                        <option>Kalahari</option>
                        <option>Alpine</option>
                    </select>
                </div>
                <div class="field">
                    <label for="entry_fee">Entry fee (৳ per team)</label>
                    <input type="number" id="entry_fee" name="entry_fee" value="{{ old('entry_fee', 100) }}" min="0" required>
                </div>
                <div class="field">
                    <label for="prize_pool">Prize pool (৳)</label>
                    <input type="number" id="prize_pool" name="prize_pool" value="{{ old('prize_pool', 5000) }}" min="0" required>
                </div>
                <div class="field">
                    <label for="team_slots">Team slots</label>
                    <select id="team_slots" name="team_slots">
                        <option value="8">8</option>
                        <option value="16" selected>16</option>
                        <option value="32">32</option>
                    </select>
                </div>
                <div class="field">
                    <label for="team_size">Players per team</label>
                    <input type="number" id="team_size" name="team_size" value="{{ old('team_size', 4) }}" min="1" max="6" required>
                </div>
                <div class="field">
                    <label for="format">Bracket format</label>
                    <select id="format" name="format">
                        <option value="single_elim" @selected(old('format', 'single_elim') === 'single_elim')>Single Elimination</option>
                        <option value="double_elim" @selected(old('format') === 'double_elim')>Double Elimination</option>
                    </select>
                    <p class="help-text">Double elimination requires a full power-of-two field (8, 16 or 32 eligible teams).</p>
                </div>
            </div>

            <div class="field">
                <label for="starts_at">Start date &amp; time</label>
                <input type="datetime-local" id="starts_at" name="starts_at" value="{{ old('starts_at') }}" required>
            </div>

            <div class="grid cols-2 mt-1">
                <div class="field">
                    <label for="check_in_starts_at">Check-in opens (optional)</label>
                    <input type="datetime-local" id="check_in_starts_at" name="check_in_starts_at" value="{{ old('check_in_starts_at') }}">
                </div>
                <div class="field">
                    <label for="check_in_ends_at">Check-in closes (optional)</label>
                    <input type="datetime-local" id="check_in_ends_at" name="check_in_ends_at" value="{{ old('check_in_ends_at') }}">
                </div>
            </div>
            <p class="help-text">Leave check-in empty to skip check-in (all confirmed teams enter the bracket).</p>

            <div class="field">
                <label for="dispute_window_hours">Dispute window (hours)</label>
                <input type="number" id="dispute_window_hours" name="dispute_window_hours" value="{{ old('dispute_window_hours', 24) }}" min="0" max="720">
                <p class="help-text">How long participants have to dispute a result after a match ends. 0 disables participant disputes.</p>
            </div>

            <div class="field">
                <label for="rules">Rules</label>
                <textarea id="rules" name="rules" rows="4" placeholder="1. No hacks — instant ban.&#10;2. Screenshot mandatory.">{{ old('rules') }}</textarea>
            </div>

            <button type="submit" class="btn btn-primary btn-block mt-4">Create Tournament</button>
        </form>
    </div>
@endsection
