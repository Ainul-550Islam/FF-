<?php

namespace App\Services;

use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\Wallet;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Wallet + immutable ledger (Phase 08).
 *
 * The only place that mutates wallet balances. Every credit/debit is applied
 * atomically with a matching ledger entry (with a running balance snapshot),
 * so the balance is always reconcilable against the ledger. Controllers and
 * other services must never change a wallet balance directly.
 *
 * Every movement is appended to the `ledger_entries` table (append-only) and
 * the wallet row is updated inside the same transaction — the ledger is the
 * source of truth, the wallet balance is a cached projection of it.
 *
 * Two calling conventions are supported because the codebase grew through
 * several phases: the object-first form (`credit($wallet, 5000, $type,
 * $description, $actor)`) and the legacy id-first form (`credit($userId,
 * 5000, 'BDT', $type, $referenceId, $idempotencyKey)`). Both funnel into the
 * same locked, ledger-backed mutation path.
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
     * Legacy entry point: get (or create) a wallet by user id.
     */
    public function getOrCreateWallet(int $userId, string $currency = 'BDT'): Wallet
    {
        $currency = strtoupper($currency);

        $wallet = Wallet::query()
            ->where('user_id', $userId)
            ->where('currency', $currency)
            ->first();

        if ($wallet !== null) {
            return $wallet;
        }

        $user = User::query()->findOrFail($userId);

        if ($currency === 'BDT') {
            return $this->walletFor($user);
        }

        return DB::transaction(function () use ($user, $currency) {
            $wallet = Wallet::query()
                ->where('user_id', $user->id)
                ->where('currency', $currency)
                ->lockForUpdate()
                ->first();

            if ($wallet !== null) {
                return $wallet;
            }

            $wallet = new Wallet();
            $wallet->user_id = $user->id;
            $wallet->currency = $currency;
            $wallet->balance_minor = 0;
            $wallet->status = Wallet::STATUS_ACTIVE;
            $wallet->save();

            return $wallet;
        });
    }

    /**
     * The stored (authoritative) balance in minor units.
     */
    public function getBalance(int $userId, string $currency = 'BDT'): int
    {
        return $this->getOrCreateWallet($userId, $currency)->balanceMinor();
    }

    /**
     * The balance implied by the ledger rows (credits - debits).
     *
     * Single query with conditional SUM — the ledger is the source of truth.
     */
    public function calculateBalance(int $walletId): int
    {
        $result = LedgerEntry::query()
            ->where('wallet_id', $walletId)
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount_minor ELSE 0 END), 0) as credits")
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'debit' THEN amount_minor ELSE 0 END), 0) as debits")
            ->first();

        if ($result === null) {
            return 0;
        }

        return (int) $result->credits - (int) $result->debits;
    }

    /**
     * Paginated ledger history for a wallet (newest first, capped page size).
     */
    public function listLedger(int $walletId, int $perPage = 50): LengthAwarePaginator
    {
        $perPage = min(max($perPage, 1), 100);

        return LedgerEntry::query()
            ->where('wallet_id', $walletId)
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage);
    }

    /**
     * Credit a wallet (deposit/refund/adjustment) and record the ledger entry.
     *
     * Accepts either a Wallet (canonical) or a user id (legacy). Callers that
     * accept an HTTP request pass the `Idempotency-Key` header through as
     * $idempotencyKey, so a replayed request can never double-post.
     */
    public function credit(
        Wallet|int $wallet,
        int $amountMinor,
        string $type = LedgerEntry::TYPE_ADJUSTMENT,
        string $description = '',
        mixed $actor = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $idempotencyKey = null,
    ): LedgerEntry {
        $intent = $this->normaliseIntent($wallet, $type, $description, $actor, $referenceType, $referenceId, $idempotencyKey);

        if ($amountMinor <= 0) {
            throw new DomainException('Credit amount must be positive.');
        }

        $this->assertActive($intent['wallet']);

        return $this->retryTransaction(function () use ($intent, $amountMinor) {
            return DB::transaction(function () use ($intent, $amountMinor) {
                $fresh = Wallet::query()->where('id', $intent['wallet']->id)->lockForUpdate()->firstOrFail();

                $existing = $this->existingByIdempotencyKey($intent['idempotency_key']);

                if ($existing !== null) {
                    return $existing;
                }

                $balanceAfter = $fresh->balanceMinor() + $amountMinor;

                $fresh->balance_minor = $balanceAfter;
                $fresh->save();

                return $this->record(
                    $fresh,
                    LedgerEntry::DIRECTION_CREDIT,
                    $amountMinor,
                    $balanceAfter,
                    $intent['type'],
                    $intent['description'],
                    $intent['actor'],
                    $intent['reference_type'],
                    $intent['reference_id'],
                    $intent['idempotency_key'],
                    $intent['metadata'],
                );
            });
        });
    }

    /**
     * Debit a wallet (adjustment/reversal) and record the ledger entry.
     * A debit can never drive the balance negative.
     *
     * Accepts either a Wallet (canonical) or a user id (legacy).
     */
    public function debit(
        Wallet|int $wallet,
        int $amountMinor,
        string $type = LedgerEntry::TYPE_ADJUSTMENT,
        string $description = '',
        mixed $actor = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $idempotencyKey = null,
    ): LedgerEntry {
        $intent = $this->normaliseIntent($wallet, $type, $description, $actor, $referenceType, $referenceId, $idempotencyKey);

        if ($amountMinor <= 0) {
            throw new DomainException('Debit amount must be positive.');
        }

        $this->assertActive($intent['wallet']);

        return $this->retryTransaction(function () use ($intent, $amountMinor) {
            return DB::transaction(function () use ($intent, $amountMinor) {
                $fresh = Wallet::query()->where('id', $intent['wallet']->id)->lockForUpdate()->firstOrFail();

                $existing = $this->existingByIdempotencyKey($intent['idempotency_key']);

                if ($existing !== null) {
                    return $existing;
                }

                if ($fresh->balanceMinor() < $amountMinor) {
                    // The id-first callers historically catch RuntimeException
                    // with this exact wording; the object-first callers catch
                    // DomainException. Both are thrown for their own form so
                    // neither generation of callers breaks.
                    if ($intent['legacy']) {
                        throw new RuntimeException('Insufficient funds');
                    }

                    throw new DomainException('Insufficient wallet balance.');
                }

                $balanceAfter = $fresh->balanceMinor() - $amountMinor;

                $fresh->balance_minor = $balanceAfter;
                $fresh->save();

                return $this->record(
                    $fresh,
                    LedgerEntry::DIRECTION_DEBIT,
                    $amountMinor,
                    $balanceAfter,
                    $intent['type'],
                    $intent['description'],
                    $intent['actor'],
                    $intent['reference_type'],
                    $intent['reference_id'],
                    $intent['idempotency_key'],
                    $intent['metadata'],
                );
            });
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

    /**
     * True when every ledger row's running balance matches the wallet balance
     * walk (the integrity check run after settlement and by the admin tools).
     */
    public function verifyLedgerIntegrity(int $walletId): bool
    {
        $entries = LedgerEntry::query()
            ->where('wallet_id', $walletId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $running = 0;

        foreach ($entries as $entry) {
            $running += $entry->isCredit() ? $entry->amount_minor : -$entry->amount_minor;

            $balanceAfter = $entry->balance_after;

            if ($balanceAfter !== null && (int) $balanceAfter !== $running) {
                return false;
            }
        }

        $wallet = Wallet::query()->find($walletId);

        return $wallet === null || $wallet->balanceMinor() === $running;
    }

    /**
     * Translate both calling conventions into a single mutation intent.
     *
     * @return array{wallet: Wallet, type: string, description: string, actor: ?User, reference_type: ?string, reference_id: ?int, idempotency_key: ?string, metadata: array<string, mixed>, legacy: bool}
     */
    protected function normaliseIntent(
        Wallet|int $wallet,
        string $type,
        string $description,
        mixed $actor,
        ?string $referenceType,
        ?int $referenceId,
        ?string $idempotencyKey,
    ): array {
        $legacy = is_int($wallet);

        if ($legacy) {
            // credit($userId, $amount, $currency, $type, $referenceId, $idempotencyKey)
            $resolved = $this->getOrCreateWallet($wallet, $type !== '' ? $type : 'BDT');

            $reference = is_string($actor) ? $actor : null;

            // Positional legacy shape:
            //   credit($userId, $amount, $currency, $type, $referenceId, $idempotencyKey)
            // so the sixth argument (typed as $referenceType here) is the
            // caller's idempotency key.
            $legacyIdempotencyKey = $idempotencyKey
                ?? (is_string($referenceType) && $referenceType !== '' ? $referenceType : null);

            return [
                'wallet' => $resolved,
                'type' => $description !== '' ? $description : LedgerEntry::TYPE_ADJUSTMENT,
                'description' => $description !== '' ? $description : 'Wallet movement',
                'actor' => $actor instanceof User ? $actor : null,
                'reference_type' => $description !== '' ? $description : $referenceType,
                'reference_id' => $referenceId ?? $this->resolveReferenceId($reference),
                'idempotency_key' => $legacyIdempotencyKey,
                'metadata' => ['currency' => $resolved->currency, 'legacy' => true],
                'legacy' => true,
            ];
        }

        return [
            'wallet' => $wallet,
            'type' => $type !== '' ? $type : LedgerEntry::TYPE_ADJUSTMENT,
            'description' => $description,
            'actor' => $actor instanceof User ? $actor : null,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'idempotency_key' => $idempotencyKey,
            'metadata' => ['currency' => $wallet->currency],
            'legacy' => false,
        ];
    }

    /**
     * Legacy references are free-form strings; the ledger keeps both the
     * string (`reference_id`) and, where numeric, the integer column too.
     */
    protected function resolveReferenceId(?string $reference): ?int
    {
        if ($reference === null) {
            return null;
        }

        return ctype_digit($reference) ? (int) $reference : null;
    }

    /**
     * Idempotent replays return the original movement instead of double
     * posting (resolved inside the lock, so concurrent replays collapse).
     */
    protected function existingByIdempotencyKey(?string $idempotencyKey): ?LedgerEntry
    {
        if ($idempotencyKey === null || $idempotencyKey === '') {
            return null;
        }

        return LedgerEntry::query()->where('idempotency_key', $idempotencyKey)->first();
    }

    protected function assertActive(Wallet $wallet): void
    {
        if (! $wallet->isActive()) {
            throw new DomainException('This wallet is frozen.');
        }
    }

    /**
     * Write the immutable ledger row. The model is fully guarded, so the row
     * is built with explicit attribute assignment — mass assignment can never
     * create a financial movement.
     *
     * @param  array<string, mixed>  $metadata
     */
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
        ?string $idempotencyKey = null,
        array $metadata = [],
    ): LedgerEntry {
        $entry = new LedgerEntry();
        $entry->wallet_id = $wallet->id;
        $entry->user_id = $wallet->user_id;
        $entry->direction = $direction;
        $entry->amount_minor = $amountMinor;
        $entry->balance_after = $balanceAfter;
        $entry->currency = $metadata['currency'] ?? $wallet->currency ?? 'BDT';
        $entry->type = $type;
        $entry->description = $description;
        $entry->reference_type = $referenceType;
        $entry->reference_id = $referenceId;
        $entry->idempotency_key = $idempotencyKey ?? (string) Str::uuid();
        $entry->metadata = $metadata;
        $entry->actor_id = $actor?->id;
        $entry->save();

        return $entry;
    }

    /**
     * Retry a financial transaction when the database reports a deadlock.
     */
    protected function retryTransaction(callable $callback, int $attempts = 3)
    {
        $lastException = null;

        for ($i = 0; $i < $attempts; $i++) {
            try {
                return $callback();
            } catch (QueryException $e) {
                $lastException = $e;

                if (str_contains(strtolower($e->getMessage()), 'deadlock')) {
                    Log::warning('Wallet transaction deadlock, retrying', ['attempt' => $i + 1]);
                    usleep(100000 * ($i + 1));

                    continue;
                }

                throw $e;
            }
        }

        throw $lastException;
    }
}
