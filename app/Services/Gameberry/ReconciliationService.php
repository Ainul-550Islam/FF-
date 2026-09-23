<?php

namespace App\Services\Gameberry;

use App\Models\GemTransaction;
use App\Models\GemWallet;
use App\Models\GoldTransaction;
use App\Models\GoldWallet;
use Illuminate\Support\Facades\Log;

class ReconciliationService
{
    const INITIAL_GOLD = 5000;

    const INITIAL_GEMS = 10;

    public function reconcileGold(int $userId): array
    {
        $wallet = GoldWallet::where('user_id', $userId)->first();
        if (! $wallet) {
            return ['is_balanced' => false, 'error' => 'Wallet not found'];
        }
        $transactions = GoldTransaction::where('user_id', $userId)->get();
        $credits = $transactions->where('amount', '>', 0)->sum('amount');
        $debits = abs($transactions->where('amount', '<', 0)->sum('amount'));
        $computed = self::INITIAL_GOLD + $credits - $debits;
        $isBalanced = $wallet->gold_balance === $computed;

        return ['wallet_balance' => $wallet->gold_balance, 'computed_balance' => $computed, 'credits' => $credits, 'debits' => $debits, 'initial' => self::INITIAL_GOLD, 'is_balanced' => $isBalanced, 'difference' => $wallet->gold_balance - $computed, 'total_transactions' => $transactions->count()];
    }

    public function reconcileGems(int $userId): array
    {
        $wallet = GemWallet::where('user_id', $userId)->first();
        if (! $wallet) {
            return ['is_balanced' => false, 'error' => 'Wallet not found'];
        }
        $transactions = GemTransaction::where('user_id', $userId)->get();
        $credits = $transactions->where('amount', '>', 0)->sum('amount');
        $debits = abs($transactions->where('amount', '<', 0)->sum('amount'));
        $computed = self::INITIAL_GEMS + $credits - $debits;
        $isBalanced = $wallet->gem_balance === $computed;

        return ['wallet_balance' => $wallet->gem_balance, 'computed_balance' => $computed, 'credits' => $credits, 'debits' => $debits, 'initial' => self::INITIAL_GEMS, 'is_balanced' => $isBalanced, 'difference' => $wallet->gem_balance - $computed, 'total_transactions' => $transactions->count()];
    }

    public function reconcileAll(int $userId): array
    {
        $gold = $this->reconcileGold($userId);
        $gems = $this->reconcileGems($userId);
        $allBalanced = ($gold['is_balanced'] ?? false) && ($gems['is_balanced'] ?? false);
        if (! $allBalanced) {
            // G1: Financial totals MUST reconcile, if differences STOP, do not declare complete
            Log::critical("Reconciliation failed for user {$userId}", ['gold' => $gold, 'gems' => $gems]);
        }

        return ['user_id' => $userId, 'gold' => $gold, 'gems' => $gems, 'all_balanced' => $allBalanced, 'must_stop_if_unbalanced' => ! $allBalanced];
    }

    public function reconcileAllUsers(): array
    {
        $goldWallets = GoldWallet::all();
        $results = ['balanced' => 0, 'unbalanced' => 0, 'details' => []];
        foreach ($goldWallets as $wallet) {
            $result = $this->reconcileAll($wallet->user_id);
            if ($result['all_balanced']) {
                $results['balanced']++;
            } else {
                $results['unbalanced']++;
            }
            if (! $result['all_balanced']) {
                $results['details'][] = $result;
            }
        }

        return $results;
    }
}
