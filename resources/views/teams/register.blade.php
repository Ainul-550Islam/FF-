@extends('layouts.app')
@section('title', 'Register Team — ' . $tournament->name)
@section('content')
    <div class="card" style="max-width: 620px; margin: 40px auto">
        <h2>Register your team</h2>
        <p class="muted">
            {{ $tournament->name }} — entry fee
            <strong class="tag">৳{{ number_format($tournament->entry_fee) }}</strong>
        </p>

        <form method="POST" action="{{ route('teams.store', $tournament) }}" novalidate>
            @csrf
            <div class="field">
                <label for="name">Team name</label>
                <input type="text" id="name" name="name" value="{{ old('name') }}" required placeholder="BD Titans"
                       @if ($errors->has('name')) aria-invalid="true" aria-describedby="name-error" @endif>
                @error('name')
                    <span class="form-error" id="name-error">{{ $message }}</span>
                @enderror
            </div>
            <div class="field">
                <label for="captain_name">Captain name</label>
                <input type="text" id="captain_name" name="captain_name" value="{{ old('captain_name') }}" required autocomplete="name">
            </div>
            <div class="field">
                <label for="phone">Phone (bKash)</label>
                <input type="tel" id="phone" name="phone" value="{{ old('phone') }}" required autocomplete="tel" inputmode="tel">
            </div>
            <div class="field">
                <label for="game_uid">Captain Free Fire UID</label>
                <input type="text" id="game_uid" name="game_uid" value="{{ old('game_uid') }}" required autocomplete="off" spellcheck="false">
            </div>

            <h3 class="mt-4">Team members (optional)</h3>
            @for ($i = 0; $i < max(1, $tournament->team_size - 1); $i++)
                <div class="grid cols-2 mt-2">
                    <div class="field">
                        <label for="member-name-{{ $i }}">Player {{ $i + 2 }} name</label>
                        <input type="text" id="member-name-{{ $i }}" name="members[{{ $i }}][player_name]">
                    </div>
                    <div class="field">
                        <label for="member-uid-{{ $i }}">Player {{ $i + 2 }} UID</label>
                        <input type="text" id="member-uid-{{ $i }}" name="members[{{ $i }}][game_uid]">
                    </div>
                </div>
            @endfor

            <button type="submit" class="btn btn-primary btn-block mt-4">Continue to Payment →</button>
        </form>
    </div>
@endsection
