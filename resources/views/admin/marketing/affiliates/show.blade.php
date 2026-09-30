@extends('layouts.app')

@section('title', 'Payout #'.$payout->id.' Details — FF Arena Admin')

@section('content')
<section class="container" style="max-width: 820px">
    <div style="margin-bottom: 16px">
        <a href="{{ route('admin.marketing.affiliates.payouts.index') }}" class="btn btn-sm">&larr; Back to Payouts List</a>
    </div>

    @if (session('success'))
        <div class="alert alert-success" role="status">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-error" role="alert">{{ session('error') }}</div>
    @endif

    <div class="card" style="margin-bottom: 20px">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap">
            <div>
                <h1 style="margin: 0 0 6px">Affiliate Payout #{{ $payout->id }}</h1>
                <p class="muted" style="margin: 0">Requested on {{ $payout->requested_at?->format('d M Y, H:i') }}</p>
            </div>
            <div>
                @if ($payout->status === 'pending')
                    <span class="badge" style="background: rgba(245, 158, 11, 0.2); color: #f59e0b; padding: 6px 12px; border-radius: 4px; font-weight: bold">Pending Review</span>
                @elseif ($payout->status === 'completed')
                    <span class="badge" style="background: rgba(16, 185, 129, 0.2); color: #10b981; padding: 6px 12px; border-radius: 4px; font-weight: bold">Completed / Credited</span>
                @elseif ($payout->status === 'rejected')
                    <span class="badge" style="background: rgba(239, 68, 68, 0.2); color: #ef4444; padding: 6px 12px; border-radius: 4px; font-weight: bold">Rejected</span>
                @endif
            </div>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-top: 24px">
            <div>
                <span class="muted" style="font-size: 0.85rem">Requested Amount</span>
                <h2 style="margin: 4px 0 0">৳{{ number_format($payout->amount_minor / 100, 2) }}</h2>
                <p class="muted" style="margin: 2px 0 0; font-size: 0.8rem">{{ $payout->amount_minor }} poisha ({{ $payout->currency }})</p>
            </div>
            <div>
                <span class="muted" style="font-size: 0.85rem">Partner Affiliate</span>
                <p style="margin: 4px 0 0"><strong>{{ $payout->affiliate?->name }}</strong></p>
                <p class="muted" style="margin: 2px 0 0; font-size: 0.85rem">Code: <code>{{ $payout->affiliate?->code }}</code></p>
            </div>
            <div>
                <span class="muted" style="font-size: 0.85rem">Beneficiary User</span>
                <p style="margin: 4px 0 0"><strong>{{ $payout->user?->name }}</strong></p>
                <p class="muted" style="margin: 2px 0 0; font-size: 0.85rem">{{ $payout->user?->email }} (ID: {{ $payout->user_id }})</p>
            </div>
        </div>

        <div style="margin-top: 24px; padding-top: 16px; border-top: 1px solid rgba(255,255,255,0.1)">
            <h3>Server-Verified Financial Standing</h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; font-size: 0.9rem">
                <div>Total Signups: <strong>{{ $earnings['referrals_count'] }}</strong></div>
                <div>Lifetime Earned: <strong>৳{{ number_format($earnings['total_earned_minor'] / 100, 2) }}</strong></div>
                <div>Prior Paid: <strong>৳{{ number_format($earnings['paid_minor'] / 100, 2) }}</strong></div>
                <div>Remaining Available: <strong>৳{{ number_format($earnings['available_minor'] / 100, 2) }}</strong></div>
            </div>
        </div>

        @if ($payout->review_notes)
            <div style="margin-top: 16px; padding: 12px; background: rgba(255,255,255,0.05); border-radius: 4px">
                <span class="muted" style="font-size: 0.85rem">Reviewer Notes:</span>
                <p style="margin: 4px 0 0">{{ $payout->review_notes }}</p>
            </div>
        @endif

        @if ($payout->rejection_reason)
            <div style="margin-top: 16px; padding: 12px; background: rgba(239, 68, 68, 0.1); border-left: 3px solid #ef4444; border-radius: 4px">
                <span style="color: #ef4444; font-size: 0.85rem; font-weight: bold">Rejection Reason:</span>
                <p style="margin: 4px 0 0">{{ $payout->rejection_reason }}</p>
            </div>
        @endif

        @if ($payout->reviewer)
            <p class="muted" style="margin: 16px 0 0; font-size: 0.85rem">
                Reviewed by {{ $payout->reviewer->name }} on {{ $payout->reviewed_at?->format('d M Y, H:i') }}
            </p>
        @endif
    </div>

    @if ($payout->status === 'pending')
        <div class="row" style="display: flex; gap: 20px; flex-wrap: wrap">
            <div class="card" style="flex: 1 1 340px; border-top: 3px solid #10b981">
                <h3 style="margin-top: 0; color: #10b981">Approve & Disburse Payout</h3>
                <p class="muted" style="font-size: 0.85rem">
                    Approving will atomically transfer ৳{{ number_format($payout->amount_minor / 100, 2) }} to the partner's platform wallet with a certified ledger entry.
                </p>

                <form method="POST" action="{{ route('admin.marketing.affiliates.payouts.approve', $payout) }}">
                    @csrf
                    <div class="field" style="margin-bottom: 12px">
                        <label for="approve_notes">Review Note (optional)</label>
                        <input type="text" id="approve_notes" name="review_notes" placeholder="Approved monthly partner disbursement" maxlength="500">
                    </div>
                    <button type="submit" class="btn btn-cyan" onclick="return confirm('Are you sure you want to approve and credit ৳{{ number_format($payout->amount_minor / 100, 2) }}?')">
                        ✓ Confirm & Credit Wallet
                    </button>
                </form>
            </div>

            <div class="card" style="flex: 1 1 340px; border-top: 3px solid #ef4444">
                <h3 style="margin-top: 0; color: #ef4444">Reject Payout</h3>
                <p class="muted" style="font-size: 0.85rem">
                    Provide a mandatory reason for audit logging. The affiliate will be notified.
                </p>

                <form method="POST" action="{{ route('admin.marketing.affiliates.payouts.reject', $payout) }}">
                    @csrf
                    <div class="field" style="margin-bottom: 12px">
                        <label for="reject_reason">Reason for Rejection (required)</label>
                        <input type="text" id="reject_reason" name="rejection_reason" required placeholder="e.g. Account verification required" maxlength="255">
                    </div>
                    <div class="field" style="margin-bottom: 12px">
                        <label for="reject_notes">Internal Audit Note (optional)</label>
                        <input type="text" id="reject_notes" name="review_notes" placeholder="Flagged for irregular referral cluster" maxlength="500">
                    </div>
                    <button type="submit" class="btn" style="background: #ef4444; color: #fff" onclick="return confirm('Are you sure you want to reject this payout request?')">
                        ✕ Reject Payout Request
                    </button>
                </form>
            </div>
        </div>
    @endif
</section>
@endsection
