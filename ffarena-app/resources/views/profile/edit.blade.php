@extends('layouts.app')
@section('title', 'Profile Settings — FF Arena')
@section('content')
    <header class="page-head">
        <h1 class="page-title">Profile Settings</h1>
    </header>

    <div class="grid cols-2">
        <section class="card" aria-labelledby="basic-profile">
            <h3 id="basic-profile">Basic profile</h3>
            <form method="POST" action="{{ route('profile.update') }}" novalidate>
                @csrf
                @method('PUT')
                <div class="field">
                    <label for="name">Display name</label>
                    <input type="text" id="name" name="name" value="{{ old('name', $user->name) }}"
                           autocomplete="name" required
                           @if ($errors->has('name')) aria-invalid="true" aria-describedby="name-error" @endif>
                    @error('name')
                        <span class="form-error" id="name-error">{{ $message }}</span>
                    @enderror
                </div>
                <div class="field">
                    <label for="bio">Bio</label>
                    <textarea id="bio" name="bio" rows="3" maxlength="500">{{ old('bio', $user->bio) }}</textarea>
                </div>
                <div class="field">
                    <label for="country">Country (2 letters)</label>
                    <input type="text" id="country" name="country" value="{{ old('country', $user->country) }}"
                           maxlength="2" placeholder="BD" autocomplete="country">
                </div>
                <div class="field">
                    <label for="region">Region / city</label>
                    <input type="text" id="region" name="region" value="{{ old('region', $user->region) }}" maxlength="100">
                </div>
                <div class="field">
                    <label for="avatar">Avatar URL</label>
                    <input type="url" id="avatar" name="avatar" value="{{ old('avatar', $user->avatar) }}" placeholder="https://…">
                </div>
                <button type="submit" class="btn btn-primary">Save profile</button>
            </form>
        </section>

        <div class="stack">
            <section class="card" aria-labelledby="username-heading">
                <h3 id="username-heading">Username</h3>
                <form method="POST" action="{{ route('profile.username') }}">
                    @csrf
                    @method('PUT')
                    <div class="field">
                        <label for="username">Username (3–20 chars, letters/numbers/._-)</label>
                        <input type="text" id="username" name="username" value="{{ old('username', $user->username) }}"
                               autocomplete="username" required>
                    </div>
                    <button type="submit" class="btn btn-sm">Change username</button>
                </form>
            </section>

            <section class="card" aria-labelledby="privacy-heading">
                <h3 id="privacy-heading">Privacy</h3>
                <form method="POST" action="{{ route('profile.privacy') }}">
                    @csrf
                    @method('PUT')
                    <div class="field">
                        <label for="privacy">Who can see your profile</label>
                        <select id="privacy" name="privacy">
                            @foreach (['public', 'registered', 'private'] as $privacy)
                                <option value="{{ $privacy }}" @selected($user->privacy === $privacy)>{{ ucfirst($privacy) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="btn btn-sm">Save privacy</button>
                </form>
            </section>

            <section class="card" aria-labelledby="prefs-heading">
                <h3 id="prefs-heading">Region &amp; preferences</h3>
                <form method="POST" action="{{ route('profile.preferences') }}">
                    @csrf
                    @method('PUT')
                    <div class="field">
                        <label for="pref-country">Country (2 letters)</label>
                        <input type="text" id="pref-country" name="country" value="{{ old('country', $user->country) }}" maxlength="2">
                    </div>
                    <div class="field">
                        <label for="pref-region">Region / city</label>
                        <input type="text" id="pref-region" name="region" value="{{ old('region', $user->region) }}" maxlength="100">
                    </div>
                    <div class="field">
                        <label for="language">Language</label>
                        <input type="text" id="language" name="language" value="{{ old('language', $user->language) }}" maxlength="5" placeholder="en">
                    </div>
                    <div class="field">
                        <label for="timezone">Timezone</label>
                        <input type="text" id="timezone" name="timezone" value="{{ old('timezone', $user->timezone) }}" placeholder="Asia/Dhaka">
                    </div>
                    <button type="submit" class="btn btn-sm">Save preferences</button>
                </form>
            </section>
        </div>
    </div>

    <section class="card" aria-labelledby="account-links">
        <h3 id="account-links">Account links</h3>
        <div class="row">
            <a href="{{ route('settings.security') }}" class="btn btn-sm">Security settings</a>
            <a href="{{ route('settings.connected-accounts') }}" class="btn btn-sm">Connected accounts</a>
            <a href="{{ route('settings.sessions') }}" class="btn btn-sm">Active sessions</a>
            <a href="{{ route('settings.login-history') }}" class="btn btn-sm">Login history</a>
            <a href="{{ route('settings.payment-methods') }}" class="btn btn-sm">Payment methods</a>
        </div>
    </section>
@endsection
