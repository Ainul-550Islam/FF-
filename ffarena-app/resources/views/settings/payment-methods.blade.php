@extends('layouts.app')
@section('title', 'Payment Methods — FF Arena')
@section('content')
    <header class="page-head">
        <h1 class="page-title">Payment Methods</h1>
    </header>

    <div class="grid cols-2">
        <section class="card" aria-labelledby="methods-heading">
            <h3 id="methods-heading">Your methods</h3>
            @if ($methods->isEmpty())
                <x-empty-state title="No saved payment methods" icon="💳">
                    Add a bKash or Nagad account below to check out faster.
                </x-empty-state>
            @else
                <div class="table-wrap">
                    <table>
                        <caption class="sr-only">Your saved payment methods</caption>
                        <thead>
                            <tr>
                                <th scope="col">Label</th>
                                <th scope="col">Provider</th>
                                <th scope="col">Identifier</th>
                                <th scope="col"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($methods as $method)
                                <tr>
                                    <td>
                                        {{ $method->label }}
                                        @if ($method->is_default)
                                            <x-status-pill status="confirmed" label="Default" />
                                        @endif
                                    </td>
                                    <td class="muted">{{ $method->provider }}</td>
                                    <td class="muted">{{ $method->masked_identifier }}</td>
                                    <td>
                                        <div class="row" style="gap: 8px">
                                            <form method="POST" action="{{ route('settings.payment-methods.default', $method) }}">
                                                @csrf
                                                <button type="submit" class="btn btn-sm">Make default</button>
                                            </form>
                                            <form method="POST" action="{{ route('settings.payment-methods.destroy', $method) }}">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-danger">Remove</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="card" aria-labelledby="add-method">
            <h3 id="add-method">Add a method</h3>
            <form method="POST" action="{{ route('settings.payment-methods.store') }}" novalidate>
                @csrf
                <div class="field">
                    <label for="provider">Provider</label>
                    <select id="provider" name="provider">
                        @foreach ($providers as $provider)
                            <option value="{{ $provider['id'] }}">{{ $provider['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="label">Label</label>
                    <input type="text" id="label" name="label" value="{{ old('label') }}" placeholder="My bKash" required>
                </div>
                <div class="field">
                    <label for="identifier">Account identifier (number/account)</label>
                    <input type="text" id="identifier" name="identifier" value="{{ old('identifier') }}"
                           placeholder="01712345678" inputmode="tel" required>
                </div>
                <p class="help-text">Only the last 4 digits are ever stored.</p>
                <button type="submit" class="btn btn-primary btn-sm mt-2">Save method</button>
            </form>
        </section>
    </div>
@endsection
