<?php

namespace App\Services;

use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\Wallet;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Wallet + immutable ledger (Phase 08).
 *
 * The only place that mutates wallet balances. Every credit/debit is applied
 * atomically with a matching ledger entry (with a running balance snapshot),
 * so the balance is always reconcilable against the ledger. Controllers and
 * other services must never change a wallet balance directly.
 */
class WalletService
{
    /**
     * Get (or lazily create) the user's BDT wallet.
     */
    public function walletFor(User $user): Wallet
    {
        $wallet = $user->wallet()->first();

        if ($wallet !== null) {
            return $wallet;
        }

        return DB::transaction(function () use ($user) {
            // Re-check inside the transaction to avoid a duplicate-wallet race
            // (the unique(user_id) index is the final backstop).
            $wallet = $user->wallet()->lockForUpdate()->first();

            if ($wallet !== null) {
                return $wallet;
            }

            $wallet = new Wallet();
            $wallet->user_id = $user->id;
            $wallet->currency = 'BDT';
            $wallet->balance_minor = 0;
            $wallet->status = Wallet::STATUS_ACTIVE;
            $wallet->save();

            return $wallet;
        });
    }

    /**
     * Credit a wallet (deposit/refund/adjustment) and record the ledger entry.
     */
    public function credit(
        Wallet $wallet,
        int $amountMinor,
        string $type,
        string $description,
        ?User $actor = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
    ): LedgerEntry {
        if ($amountMinor <= 0) {
            throw new DomainException('Credit amount must be positive.');
        }

        $this->assertActive($wallet);

        return DB::transaction(function () use ($wallet, $amountMinor, $type, $description, $actor, $referenceType, $referenceId) {
            $fresh = Wallet::query()->where('id', $wallet->id)->lockForUpdate()->firstOrFail();

            $balanceAfter = $fresh->balanceMinor() + $amountMinor;

            $fresh->balance_minor = $balanceAfter;
            $fresh->save();

            return $this->record($fresh, LedgerEntry::DIRECTION_CREDIT, $amountMinor, $balanceAfter, $type, $description, $actor, $referenceType, $referenceId);
        });
    }

    /**
     * Debit a wallet (adjustment/reversal) and record the ledger entry.
     * A debit can never drive the balance negative.
     */
    public function debit(
        Wallet $wallet,
        int $amountMinor,
        string $type,
        string $description,
        ?User $actor = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
    ): LedgerEntry {
        if ($amountMinor <= 0) {
            throw new DomainException('Debit amount must be positive.');
        }

        $this->assertActive($wallet);

        return DB::transaction(function () use ($wallet, $amountMinor, $type, $description, $actor, $referenceType, $referenceId) {
            $fresh = Wallet::query()->where('id', $wallet->id)->lockForUpdate()->firstOrFail();

            if ($fresh->balanceMinor() < $amountMinor) {
                throw new DomainException('Insufficient wallet balance.');
            }

            $balanceAfter = $fresh->balanceMinor() - $amountMinor;

            $fresh->balance_minor = $balanceAfter;
            $fresh->save();

            return $this->record($fresh, LedgerEntry::DIRECTION_DEBIT, $amountMinor, $balanceAfter, $type, $description, $actor, $referenceType, $referenceId);
        });
    }

    /**
     * The balance reconciliation delta: total credits minus total debits,
     * compared against the stored balance. Returns 0 when consistent.
     */
    public function reconciliationDelta(Wallet $wallet): int
    {
        $credits = (int) $wallet->ledgerEntries()
            ->where('direction', LedgerEntry::DIRECTION_CREDIT)
            ->sum('amount_minor');

        $debits = (int) $wallet->ledgerEntries()
            ->where('direction', LedgerEntry::DIRECTION_DEBIT)
            ->sum('amount_minor');

        return ($credits - $debits) - $wallet->balanceMinor();
    }

    protected function assertActive(Wallet $wallet): void
    {
        if (! $wallet->isActive()) {
            throw new DomainException('This wallet is frozen.');
        }
    }

    protected function record(
        Wallet $wallet,
        string $direction,
        int $amountMinor,
        int $balanceAfter,
        string $type,
        string $description,
        ?User $actor,
        ?string $referenceType,
        ?int $referenceId,
    ): LedgerEntry {
        $entry = new LedgerEntry();
        $entry->wallet_id = $wallet->id;
        $entry->direction = $direction;
        $entry->amount_minor = $amountMinor;
        $entry->balance_after = $balanceAfter;
        $entry->currency = 'BDT';
        $entry->type = $type;
        $entry->reference_type = $referenceType;
        $entry->reference_id = $referenceId;
        $entry->description = $description;
        $entry->actor_id = $actor?->id;
        $entry->save();

        return $entry;
    }
}
