@extends('layouts.app')
@section('title', 'Security Settings — FF Arena')
@section('content')
    <header class="page-head">
        <h1 class="page-title">Security Settings</h1>
    </header>

    <div class="grid cols-2">
        <section class="card" aria-labelledby="account-status">
            <h3 id="account-status">Account status</h3>
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Account verification and state</caption>
                    <thead>
                        <tr>
                            <th scope="col">Check</th>
                            <th scope="col">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Email verification</td>
                            <td>
                                @if ($user->hasVerifiedEmail())
                                    <x-status-pill status="verified" />
                                @else
                                    <x-status-pill status="pending" label="Not verified" />
                                    <a href="{{ route('verification.notice') }}" class="btn btn-sm mt-1">Verify</a>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td>Phone verification</td>
                            <td>
                                @if ($identities->contains('provider', 'phone'))
                                    <x-status-pill status="verified" />
                                @else
                                    <x-status-pill status="pending" label="Not verified" />
                                    <a href="{{ route('settings.connected-accounts') }}" class="btn btn-sm mt-1">Verify</a>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td>Google account</td>
                            <td>
                                @if ($identities->contains('provider', 'google'))
                                    <x-status-pill status="confirmed" label="Linked" />
                                @else
                                    <x-status-pill status="draft" label="Not linked" />
                                    <a href="{{ route('settings.connected-accounts') }}" class="btn btn-sm mt-1">Link</a>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td>Password</td>
                            <td>
                                @if ($hasPassword)
                                    <x-status-pill status="confirmed" label="Set" />
                                @else
                                    <x-status-pill status="pending" label="Not set" />
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td>Account state</td>
                            <td><x-status-pill :status="$user->isActive() ? 'active' : 'failed'" :label="$user->account_status" /></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="card" aria-labelledby="change-password">
            <h3 id="change-password">Change password</h3>
            <form method="POST" action="{{ route('settings.password') }}" novalidate>
                @csrf
                @if ($hasPassword)
                    <div class="field">
                        <label for="current_password">Current password</label>
                        <input type="password" id="current_password" name="current_password"
                               autocomplete="current-password" required
                               @if ($errors->has('current_password')) aria-invalid="true" aria-describedby="current_password-error" @endif>
                        @error('current_password')
                            <span class="form-error" id="current_password-error">{{ $message }}</span>
                        @enderror
                    </div>
                @endif
                <div class="field">
                    <label for="password">New password (min 8 characters)</label>
                    <input type="password" id="password" name="password"
                           autocomplete="new-password" required
                           @if ($errors->has('password')) aria-invalid="true" aria-describedby="password-error" @endif>
                    @error('password')
                        <span class="form-error" id="password-error">{{ $message }}</span>
                    @enderror
                </div>
                <div class="field">
                    <label for="password_confirmation">Confirm new password</label>
                    <input type="password" id="password_confirmation" name="password_confirmation"
                           autocomplete="new-password" required>
                </div>
                <button type="submit" class="btn btn-primary mt-2">{{ $hasPassword ? 'Change password' : 'Set password' }}</button>
            </form>
        </section>
    </div>

    <section class="card" aria-labelledby="active-sessions">
        <h3 id="active-sessions">Active sessions</h3>
        <p class="muted">Review and revoke your active sessions, or sign out everywhere.</p>
        <div class="row mt-2">
            <a href="{{ route('settings.sessions') }}" class="btn btn-sm">View sessions</a>
            <form method="POST" action="{{ route('settings.sessions.revokeAll') }}">
                @csrf
                <button type="submit" class="btn btn-sm btn-danger">Sign out everywhere</button>
            </form>
        </div>
    </section>

    <section class="card" aria-labelledby="danger-zone" style="border-color: var(--red)">
        <h3 id="danger-zone" class="text-danger">Danger zone</h3>
        @if ($user->isActive())
            <div class="row">
                <form method="POST" action="{{ route('settings.deactivate') }}"
                      onsubmit="return confirm('Deactivate your account? You can reactivate it later.')">
                    @csrf
                    <button type="submit" class="btn btn-sm" style="border-color: var(--amber); color: var(--amber)">Deactivate account</button>
                </form>
                <form method="POST" action="{{ route('settings.deletion.request') }}"
                      onsubmit="return confirm('Request account deletion? This cannot be undone.')">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-danger">Request deletion</button>
                </form>
            </div>
        @elseif ($user->account_status === 'deactivated')
            <form method="POST" action="{{ route('settings.reactivate') }}">
                @csrf
                <button type="submit" class="btn btn-green btn-sm">Reactivate account</button>
            </form>
        @elseif ($user->account_status === 'deletion_pending')
            <p class="muted">Deletion requested — it will be processed shortly.</p>
            <form method="POST" action="{{ route('settings.deletion.cancel') }}">
                @csrf
                <button type="submit" class="btn btn-sm">Cancel deletion request</button>
            </form>
        @endif
    </section>
@endsection
