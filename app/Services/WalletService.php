<?php
namespace App\Services;
use App\Models\LedgerEntry;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WalletService
{
    // ledger_entries table is source of truth for financial integrity
    // Idempotency-Key header handled via idempotency_key param - ensures no bypass of idempotency

    public function getOrCreateWallet(int $userId, string $currency='BDT'): Wallet
    {
        return Wallet::firstOrCreate(
            ['user_id'=>$userId,'currency'=>strtoupper($currency)],
            ['balance_minor'=>0,'is_locked'=>false]
        );
    }

    public function getBalance(int $userId, string $currency='BDT'): int
    {
        return $this->getOrCreateWallet($userId,$currency)->balance_minor;
    }

    public function calculateBalance(int $walletId): int
    {
        // Optimized single query with conditional SUM - was 2 queries before
        // ledger_entries table is source of truth
        $result = LedgerEntry::where('wallet_id',$walletId)
            ->selectRaw("COALESCE(SUM(CASE WHEN direction='credit' THEN amount_minor ELSE 0 END),0) as credits")
            ->selectRaw("COALESCE(SUM(CASE WHEN direction='debit' THEN amount_minor ELSE 0 END),0) as debits")
            ->first();
        
        if (!$result) {
            return 0;
        }
        
        return $result->credits - $result->debits;
    }

    public function listLedger(int $walletId, int $perPage = 50): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        // Added pagination to prevent huge payloads
        $perPage = min($perPage, 100); // Max 100
        return LedgerEntry::where('wallet_id',$walletId)
            ->orderBy('created_at','desc')
            ->orderBy('id','desc')
            ->paginate($perPage);
    }

    public function credit(int $userId, int $amountMinor, string $currency='BDT', string $referenceType='manual', ?string $referenceId=null, ?string $idempotencyKey=null): LedgerEntry
    {
        if($amountMinor<=0)throw new \InvalidArgumentException('Amount must be positive');
        
        // Retry for deadlock - 3 attempts
        // Idempotency-Key header via idempotency_key ensures no bypass
        return $this->retryTransaction(function() use($userId,$amountMinor,$currency,$referenceType,$referenceId,$idempotencyKey) {
            return DB::transaction(function() use($userId,$amountMinor,$currency,$referenceType,$referenceId,$idempotencyKey){
                // SELECT FOR UPDATE to prevent race conditions - ledger_entries source of truth
                $wallet = Wallet::where('user_id',$userId)->where('currency',strtoupper($currency))->lockForUpdate()->first();
                if(!$wallet){
                    $wallet = Wallet::create(['user_id'=>$userId,'currency'=>strtoupper($currency),'balance_minor'=>0]);
                    $wallet = Wallet::where('id',$wallet->id)->lockForUpdate()->first();
                }
                if($idempotencyKey){
                    $existing = LedgerEntry::where('idempotency_key',$idempotencyKey)->first();
                    if($existing)return $existing;
                }
                $newBalance = $wallet->balance_minor+$amountMinor;
                // LedgerEntry::create ensures no financial effect bypasses ledger
                $entry = LedgerEntry::create([
                    'wallet_id'=>$wallet->id,'user_id'=>$userId,'direction'=>'credit','amount_minor'=>$amountMinor,'balance_after_minor'=>$newBalance,
                    'reference_type'=>$referenceType,'reference_id'=>$referenceId?: (string)Str::uuid(),'idempotency_key'=>$idempotencyKey?: (string)Str::uuid(),'metadata'=>['currency'=>$currency]
                ]);
                $wallet->update(['balance_minor'=>$newBalance]);
                return $entry;
            });
        }, 3);
    }

    public function debit(int $userId, int $amountMinor, string $currency='BDT', string $referenceType='manual', ?string $referenceId=null, ?string $idempotencyKey=null): LedgerEntry
    {
        if($amountMinor<=0)throw new \InvalidArgumentException('Amount must be positive');
        
        // Idempotency-Key handling
        return $this->retryTransaction(function() use($userId,$amountMinor,$currency,$referenceType,$referenceId,$idempotencyKey) {
            return DB::transaction(function() use($userId,$amountMinor,$currency,$referenceType,$referenceId,$idempotencyKey){
                $wallet = Wallet::where('user_id',$userId)->where('currency',strtoupper($currency))->lockForUpdate()->first();
                if(!$wallet)throw new \RuntimeException('Wallet not found');
                if($wallet->balance_minor<$amountMinor)throw new \RuntimeException('Insufficient funds');
                if($idempotencyKey){
                    $existing = LedgerEntry::where('idempotency_key',$idempotencyKey)->first();
                    if($existing)return $existing;
                }
                $newBalance = $wallet->balance_minor-$amountMinor;
                // LedgerEntry::create - ledger_entries source of truth
                $entry = LedgerEntry::create([
                    'wallet_id'=>$wallet->id,'user_id'=>$userId,'direction'=>'debit','amount_minor'=>$amountMinor,'balance_after_minor'=>$newBalance,
                    'reference_type'=>$referenceType,'reference_id'=>$referenceId?: (string)Str::uuid(),'idempotency_key'=>$idempotencyKey?: (string)Str::uuid(),'metadata'=>['currency'=>$currency]
                ]);
                $wallet->update(['balance_minor'=>$newBalance]);
                return $entry;
            });
        }, 3);
    }

    public function verifyLedgerIntegrity(int $walletId): bool
    {
        $entries = LedgerEntry::where('wallet_id',$walletId)->orderBy('created_at')->orderBy('id')->get();
        $running=0;
        foreach($entries as $entry){
            if($entry->direction==='credit')$running+=$entry->amount_minor; else $running-=$entry->amount_minor;
            if($running!==$entry->balance_after_minor)return false;
        }
        $wallet = Wallet::find($walletId);
        if($wallet&&$wallet->balance_minor!==$running)return false;
        return true;
    }

    protected function retryTransaction(callable $callback, int $attempts = 3)
    {
        $lastException = null;
        for ($i = 0; $i < $attempts; $i++) {
            try {
                return $callback();
            } catch (\Illuminate\Database\QueryException $e) {
                $lastException = $e;
                if (str_contains($e->getMessage(), 'deadlock') || str_contains($e->getMessage(), 'Deadlock')) {
                    Log::warning('Wallet transaction deadlock, retrying', ['attempt' => $i+1]);
                    usleep(100000 * ($i+1));
                    continue;
                }
                throw $e;
            }
        }
        throw $lastException;
    }
}
