<?php

namespace App\Services;

use App\Models\LedgerEntry;
use App\Models\MarketingAffiliate;
use App\Models\MarketingAffiliatePayout;
use App\Models\Notification;
use App\Models\User;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Phase 22 — affiliate payout workflow & settlement service.
 *
 * All balances, commissions, and limits are evaluated strictly server-side.
 * Financial credits are executed atomically via the immutable Ledger and
 * WalletService. State machine transitions are guarded, idempotent, and
 * recorded in audit logs.
 */
class MarketingAffiliatePayoutService
{
    public function __construct(
        protected WalletService $wallets,
        protected NotificationService $notifications,
        protected AuditLogService $audit,
    ) {}

    /**
     * Compute authoritative affiliate commission and payout availability.
     *
     * @return array{referrals_count: int, commission_per_signup_minor: int, total_earned_minor: int, paid_minor: int, pending_minor: int, available_minor: int}
     */
    public function calculateEarnings(MarketingAffiliate $affiliate): array
    {
        $referralsCount = $affiliate->referrals()->whereNotNull('referred_user_id')->count();
        $commissionPerSignup = (int) config('marketing.affiliate.commission_per_signup_minor', 5000); // ৳50.00
        $totalEarnedMinor = $referralsCount * $commissionPerSignup;

        $paidMinor = (int) MarketingAffiliatePayout::query()
            ->where('affiliate_id', $affiliate->id)
            ->whereIn('status', [
                MarketingAffiliatePayout::STATUS_APPROVED,
                MarketingAffiliatePayout::STATUS_PROCESSING,
                MarketingAffiliatePayout::STATUS_COMPLETED,
            ])
            ->sum('amount_minor');

        $pendingMinor = (int) MarketingAffiliatePayout::query()
            ->where('affiliate_id', $affiliate->id)
            ->where('status', MarketingAffiliatePayout::STATUS_PENDING)
            ->sum('amount_minor');

        $availableMinor = max(0, $totalEarnedMinor - $paidMinor - $pendingMinor);

        return [
            'referrals_count' => $referralsCount,
            'commission_per_signup_minor' => $commissionPerSignup,
            'total_earned_minor' => $totalEarnedMinor,
            'paid_minor' => $paidMinor,
            'pending_minor' => $pendingMinor,
            'available_minor' => $availableMinor,
        ];
    }

    /**
     * Check if an affiliate already has a pending payout request.
     */
    public function hasPendingRequest(MarketingAffiliate $affiliate): bool
    {
        return MarketingAffiliatePayout::query()
            ->where('affiliate_id', $affiliate->id)
            ->where('status', MarketingAffiliatePayout::STATUS_PENDING)
            ->exists();
    }

    /**
     * Submit a payout request for an affiliate.
     *
     * @throws DomainException
     */
    public function requestPayout(MarketingAffiliate $affiliate, ?int $requestedMinor = null, array $metadata = []): MarketingAffiliatePayout
    {
        if (! $affiliate->isActive()) {
            throw new DomainException('Affiliate account is not active.');
        }

        if ($affiliate->user_id === null) {
            throw new DomainException('Affiliate must have an associated user account.');
        }

        return DB::transaction(function () use ($affiliate, $requestedMinor, $metadata) {
            // Re-check pending state inside transaction
            if ($this->hasPendingRequest($affiliate)) {
                throw new DomainException('A pending payout request already exists for this affiliate.');
            }

            $earnings = $this->calculateEarnings($affiliate);
            $available = $earnings['available_minor'];
            $minPayout = (int) config('marketing.affiliate.min_payout_minor', 1000); // ৳10.00 min

            if ($available < $minPayout) {
                throw new DomainException('Available balance (৳'.number_format($available / 100, 2).') is below minimum payout threshold of ৳'.number_format($minPayout / 100, 2).'.');
            }

            if ($requestedMinor !== null && $requestedMinor > 0) {
                if ($requestedMinor > $available) {
                    throw new DomainException('Requested payout amount exceeds available balance.');
                }
                if ($requestedMinor < $minPayout) {
                    throw new DomainException('Requested payout amount is below minimum payout threshold of ৳'.number_format($minPayout / 100, 2).'.');
                }
                $amount = $requestedMinor;
            } else {
                $amount = $available;
            }

            $payout = MarketingAffiliatePayout::create([
                'affiliate_id' => $affiliate->id,
                'user_id' => $affiliate->user_id,
                'amount_minor' => $amount,
                'currency' => 'BDT',
                'status' => MarketingAffiliatePayout::STATUS_PENDING,
                'payout_method' => MarketingAffiliatePayout::METHOD_WALLET,
                'requested_at' => now(),
                'metadata' => $metadata,
            ]);

            try {
                $this->audit->recordQuietly(
                    $affiliate->user,
                    'affiliate.payout_requested',
                    'marketing_affiliate_payout',
                    $payout->id,
                    ['amount_minor' => $amount, 'affiliate_code' => $affiliate->code]
                );
            } catch (Throwable) {
                // Non-blocking audit recording
            }

            return $payout;
        });
    }

    /**
     * Approve an affiliate payout and credit wallet funds atomically.
     *
     * @throws DomainException
     */
    public function approve(MarketingAffiliatePayout $payout, User $actor, ?string $notes = null): MarketingAffiliatePayout
    {
        // Idempotency: if already completed, return as-is
        if ($payout->status === MarketingAffiliatePayout::STATUS_COMPLETED) {
            return $payout;
        }

        if (in_array($payout->status, [MarketingAffiliatePayout::STATUS_REJECTED, MarketingAffiliatePayout::STATUS_CANCELLED], true)) {
            throw new DomainException("Cannot approve a payout with status '{$payout->status}'.");
        }

        return DB::transaction(function () use ($payout, $actor, $notes) {
            /** @var MarketingAffiliatePayout $fresh */
            $fresh = MarketingAffiliatePayout::query()->lockForUpdate()->findOrFail($payout->id);

            if ($fresh->status === MarketingAffiliatePayout::STATUS_COMPLETED) {
                return $fresh;
            }

            if (in_array($fresh->status, [MarketingAffiliatePayout::STATUS_REJECTED, MarketingAffiliatePayout::STATUS_CANCELLED], true)) {
                throw new DomainException("Cannot approve a payout with status '{$fresh->status}'.");
            }

            $recipient = $fresh->user;
            if ($recipient === null) {
                throw new DomainException('Payout recipient user does not exist.');
            }

            $wallet = $this->wallets->walletFor($recipient);

            // Execute authoritative ledger-backed wallet credit
            $this->wallets->credit(
                $wallet,
                $fresh->amount_minor,
                LedgerEntry::TYPE_PAYOUT,
                'Affiliate commission payout for partner code '.($fresh->affiliate?->code ?? 'N/A'),
                $actor,
                'marketing_affiliate_payout',
                $fresh->id
            );

            $fresh->status = MarketingAffiliatePayout::STATUS_COMPLETED;
            $fresh->reviewed_by = $actor->id;
            $fresh->reviewed_at = now();
            $fresh->processed_at = now();
            $fresh->review_notes = $notes;
            $fresh->save();

            try {
                $this->notifications->send(
                    $recipient,
                    Notification::TYPE_PAYOUT,
                    'Affiliate Payout Approved',
                    'Your commission payout of ৳'.number_format($fresh->amount_minor / 100, 2).' has been credited to your wallet.',
                    route('wallet.index')
                );
            } catch (Throwable) {
                // Quiet notification fail
            }

            try {
                $this->audit->recordQuietly(
                    $actor,
                    'affiliate.payout_approved',
                    'marketing_affiliate_payout',
                    $fresh->id,
                    ['amount_minor' => $fresh->amount_minor, 'reviewer_id' => $actor->id]
                );
            } catch (Throwable) {
                // Non-blocking
            }

            return $fresh;
        });
    }

    /**
     * Reject a pending affiliate payout.
     *
     * @throws DomainException
     */
    public function reject(MarketingAffiliatePayout $payout, User $actor, string $reason, ?string $notes = null): MarketingAffiliatePayout
    {
        if ($payout->status === MarketingAffiliatePayout::STATUS_REJECTED) {
            return $payout;
        }

        if ($payout->status === MarketingAffiliatePayout::STATUS_COMPLETED) {
            throw new DomainException('Cannot reject an already completed payout.');
        }

        return DB::transaction(function () use ($payout, $actor, $reason, $notes) {
            /** @var MarketingAffiliatePayout $fresh */
            $fresh = MarketingAffiliatePayout::query()->lockForUpdate()->findOrFail($payout->id);

            if ($fresh->status === MarketingAffiliatePayout::STATUS_REJECTED) {
                return $fresh;
            }

            if ($fresh->status === MarketingAffiliatePayout::STATUS_COMPLETED) {
                throw new DomainException('Cannot reject an already completed payout.');
            }

            $fresh->status = MarketingAffiliatePayout::STATUS_REJECTED;
            $fresh->reviewed_by = $actor->id;
            $fresh->reviewed_at = now();
            $fresh->rejection_reason = $reason;
            $fresh->review_notes = $notes;
            $fresh->save();

            if ($fresh->user !== null) {
                try {
                    $this->notifications->send(
                        $fresh->user,
                        Notification::TYPE_PAYOUT,
                        'Affiliate Payout Rejected',
                        'Your affiliate payout request of ৳'.number_format($fresh->amount_minor / 100, 2).' was rejected: '.$reason,
                        route('marketing.affiliate.dashboard')
                    );
                } catch (Throwable) {
                    // Quiet fail
                }
            }

            try {
                $this->audit->recordQuietly(
                    $actor,
                    'affiliate.payout_rejected',
                    'marketing_affiliate_payout',
                    $fresh->id,
                    ['reason' => $reason, 'reviewer_id' => $actor->id]
                );
            } catch (Throwable) {
                // Non-blocking
            }

            return $fresh;
        });
    }

    /**
     * Payout history for a given affiliate.
     */
    public function payoutsForAffiliate(MarketingAffiliate $affiliate, int $perPage = 15): LengthAwarePaginator
    {
        return MarketingAffiliatePayout::query()
            ->where('affiliate_id', $affiliate->id)
            ->orderByDesc('requested_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * Admin view of all affiliate payouts with status filter.
     */
    public function adminPayoutsPaginated(?string $status = null, int $perPage = 15): LengthAwarePaginator
    {
        return MarketingAffiliatePayout::query()
            ->with(['affiliate', 'user', 'reviewer'])
            ->when($status !== null && $status !== '', fn ($query) => $query->where('status', $status))
            ->orderByDesc('requested_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * Aggregated payout statistics across the platform.
     *
     * @return array{total_payouts: int, pending_count: int, pending_minor: int, completed_minor: int, rejected_count: int}
     */
    public function summary(): array
    {
        return [
            'total_payouts' => MarketingAffiliatePayout::query()->count(),
            'pending_count' => MarketingAffiliatePayout::query()->where('status', MarketingAffiliatePayout::STATUS_PENDING)->count(),
            'pending_minor' => (int) MarketingAffiliatePayout::query()->where('status', MarketingAffiliatePayout::STATUS_PENDING)->sum('amount_minor'),
            'completed_minor' => (int) MarketingAffiliatePayout::query()->where('status', MarketingAffiliatePayout::STATUS_COMPLETED)->sum('amount_minor'),
            'rejected_count' => MarketingAffiliatePayout::query()->where('status', MarketingAffiliatePayout::STATUS_REJECTED)->count(),
        ];
    }
}
