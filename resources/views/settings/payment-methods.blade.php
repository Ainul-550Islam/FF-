@extends('layouts.app')

@section('title', 'Payment Methods')

@section('content')
<div class="settings-layout">
    @include('settings._nav')

    <div class="settings-content">
        <div style="margin-bottom: 24px; display: flex; justify-content: space-between; gap: 16px; flex-wrap: wrap;">
            <div>
                <h1 style="margin: 0 0 8px; font-size: 24px; font-weight: 800;">Payment Methods</h1>
                <p class="text-muted">Saved payment methods for faster deposits. We store masked identifiers only, never full account numbers.</p>
            </div>
            <div style="display: flex; gap: 8px; align-items: center;">
                <span data-internet-status class="internet-status online"></span>
                <a href="{{ route('payment.methods') }}" class="btn btn-primary btn-sm">Add New</a>
            </div>
        </div>

        <div class="alert alert-info" style="margin-bottom: 24px;">
            <span>🔒</span>
            <div>
                <strong>How we store payment methods</strong>
                <p style="margin: 4px 0 0; font-size: 13px;">Full identifier is hashed (SHA256 + salt) for duplicate detection. Only masked version (e.g. 01******1234) is stored for display. No raw bKash/Nagad credentials ever touch our database - provider tokenization only.</p>
            </div>
        </div>

        @if(($methods ?? collect())->count() > 0)
            <div class="grid" style="gap: 12px;">
                @foreach($methods as $method)
                    <div class="card" style="display: flex; gap: 16px; align-items: center; padding: 16px;">
                        <div style="width: 48px; height: 48px; background: var(--bg-elevated); border: 1px solid var(--border); border-radius: 12px; display: grid; place-items: center; font-weight: 800; font-size: 12px;" aria-hidden="true">
                            {{ strtoupper(substr($method->provider, 0, 2)) }}
                        </div>
                        <div style="flex: 1;">
                            <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                                <strong style="font-size: 14px;">{{ $method->label ?? ucfirst($method->provider) }}</strong>
                                <x-status-pill :status="$method->provider" :label="ucfirst($method->provider)" />
                                @if($method->is_default) <x-status-pill status="success" label="Default" /> @endif
                                @if($method->is_verified) <x-status-pill status="success" label="Verified" /> @else <x-status-pill status="warning" label="Unverified" /> @endif
                            </div>
                            <div class="font-mono" style="font-size: 13px; margin-top: 4px;">{{ $method->masked_identifier ?? '••••' }}</div>
                            <div class="text-muted" style="font-size: 11px; margin-top: 2px;">Added {{ $method->created_at->diffForHumans() }} • {{ $method->is_default ? 'Default for deposits' : '' }}</div>
                        </div>
                        <div style="display: flex; gap: 8px;">
                            @if(!$method->is_default)
                                <form method="POST" action="{{ route('settings.payment-methods.default', $method) }}">
                                    @csrf
                                    @method('PUT')
                                    <button type="submit" class="btn btn-secondary btn-sm" data-require-online>Set Default</button>
                                </form>
                            @endif
                            <form method="POST" action="{{ route('settings.payment-methods.destroy', $method) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-ghost btn-sm" data-confirm="Remove this payment method? You can add it again later." data-require-online>Remove</button>
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <x-empty-state icon="💳" title="No saved payment methods" text="Add bKash, Nagad, Rocket, or manual methods for faster checkout. We never store full account numbers, only masked identifiers.">
                <div style="margin-top: 16px; display: flex; gap: 8px; justify-content: center; flex-wrap: wrap;">
                    <a href="{{ route('payment.methods') }}" class="btn btn-primary btn-sm" data-require-online>Add Payment Method</a>
                    <button onclick="window.FFArena?.checkInternet()" class="btn btn-secondary btn-sm">Check Connection</button>
                </div>
            </x-empty-state>
        @endif

        <div class="card" style="margin-top: 24px;">
            <h3 style="margin: 0 0 12px; font-size: 14px; font-weight: 700;">Supported Providers & Internet Requirements</h3>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Provider</th>
                            <th>Type</th>
                            <th>Internet</th>
                            <th>Verification</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>bKash</strong></td>
                            <td>Mobile Wallet</td>
                            <td><x-status-pill status="success" label="Online Required" /></td>
                            <td>OTP + Tokenized API</td>
                        </tr>
                        <tr>
                            <td><strong>Nagad</strong></td>
                            <td>Mobile Wallet</td>
                            <td><x-status-pill status="success" label="Online Required" /></td>
                            <td>RSA + Signature</td>
                        </tr>
                        <tr>
                            <td><strong>Rocket</strong></td>
                            <td>Mobile Wallet</td>
                            <td><x-status-pill status="warning" label="Manual*" /></td>
                            <td>Manual Verification</td>
                        </tr>
                        <tr>
                            <td><strong>Manual</strong></td>
                            <td>Bank / Manual</td>
                            <td><x-status-pill status="neutral" label="Offline OK" /></td>
                            <td>Admin Review</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="text-muted" style="font-size: 12px; margin-top: 12px;">
                * Rocket M2M has no public sandbox. In production, manual verification fallback is used unless explicitly configured. All online providers require internet for creation - offline queue not allowed for financial safety.
            </div>
        </div>
    </div>
</div>
@endsection
