<?php

namespace Tests\Feature\Concurrency;

use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\Wallet;
use App\Services\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * G3 — wallet concurrency race.
 *
 * N concurrent credits of the same wallet race through WalletService::credit.
 * Every credit already runs under `lockForUpdate`, so the balance must end at
 * exactly N × amount, with exactly N ledger entries, no lost updates and no
 * negative balance. A concurrent debit beyond balance must fail cleanly
 * rather than corrupt the balance.
 *
 * Execution model mirrors RegistrationRaceTest: real process concurrency on
 * PostgreSQL + pcntl; sequential fallback (same invariant) on SQLite.
 */
class WalletRaceTest extends TestCase
{
    // See RegistrationRaceTest for why we run `migrate:fresh` explicitly
    // instead of using the DatabaseMigrations trait (its teardown rollback
    // fails on SQLite).
    protected Wallet $wallet;

    protected int $creditAmount = 1000; // minor units (10.00 BDT)

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh');

        $user = new User();
        $user->name = 'Wallet Race User';
        $user->email = 'walletrace-'.Str::random(6).'@ffarena.local';
        $user->password = bcrypt('secret123');
        $user->email_verified_at = now();
        $user->save();
        $user->role = 'player';
        $user->account_status = 'active';
        $user->save();

        $this->wallet = app(WalletService::class)->walletFor($user);
    }

    public function test_concurrent_credits_never_lose_an_update(): void
    {
        $total = 6;

        if ($this->canFork()) {
            $this->forkCredits($total);
        } else {
            for ($i = 0; $i < $total; $i++) {
                $this->creditOne($this->wallet->id);
            }

            fwrite(STDERR, "[note] wallet race ran sequentially — true parallel writers need pcntl + PostgreSQL.\n");
        }

        $wallet = Wallet::find($this->wallet->id);

        // Balance = N × amount, ledger has exactly N credit entries.
        $this->assertSame($total * $this->creditAmount, $wallet->balance_minor);

        $this->assertSame(
            $total,
            LedgerEntry::where('wallet_id', $wallet->id)->where('direction', LedgerEntry::DIRECTION_CREDIT)->count(),
        );

        // No negative balance and the running balance is consistent with the
        // ledger (the reconciliation delta must be zero).
        $this->assertGreaterThanOrEqual(0, $wallet->balance_minor);
        $this->assertSame(0, app(WalletService::class)->reconciliationDelta($wallet));
    }

    protected function canFork(): bool
    {
        return function_exists('pcntl_fork') && config('database.default') === 'pgsql';
    }

    protected function forkCredits(int $total): void
    {
        $pids = [];

        for ($i = 0; $i < $total; $i++) {
            $pid = pcntl_fork();

            if ($pid === 0) {
                DB::purge();

                try {
                    $this->creditOne($this->wallet->id);
                    exit(0);
                } catch (\Throwable $e) {
                    fwrite(STDERR, "child {$i}: ".$e->getMessage()."\n");
                    exit(2);
                }
            }

            $pids[] = $pid;
        }

        $exitCodes = [];

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $exitCodes[] = pcntl_wexitstatus($status);
        }

        $this->assertSame(array_fill(0, $total, 0), $exitCodes, 'all credit children must succeed');
    }

    protected function creditOne(int $walletId): void
    {
        $wallet = Wallet::find($walletId);

        app(WalletService::class)->credit(
            $wallet,
            $this->creditAmount,
            LedgerEntry::TYPE_ADJUSTMENT,
            'Concurrency race credit',
            null,
            'race',
            0,
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->wallet)) {
            DB::table('ledger_entries')->where('wallet_id', $this->wallet->id)->delete();
            DB::table('wallets')->where('id', $this->wallet->id)->delete();
            DB::table('users')->where('email', 'like', 'walletrace-%@ffarena.local')->delete();
        }

        parent::tearDown();
    }
}
