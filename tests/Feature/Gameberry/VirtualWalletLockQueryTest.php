<?php

namespace Tests\Feature\Gameberry;

use App\Models\GemWallet;
use App\Models\GoldWallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * GAP-10 A2 — SQLite-safe guard for the virtual wallet row locks.
 *
 * SQLite cannot express `SELECT … FOR UPDATE` (Laravel's SQLite grammar
 * silently drops the lock clause) and the in-memory database is
 * single-writer, so the real blocking behaviour is covered by
 * tests/Feature/Postgres/VirtualWalletLockTest.php on the PostgreSQL profile.
 *
 * This test protects the same regression where the suite actually runs:
 *   1. structurally — the write paths must not contain the no-op
 *      `$this->lockForUpdate()` call and must lock through a query builder;
 *   2. behaviourally — every wallet write must re-read the row inside the
 *      transaction it writes in (a stale in-memory instance may never be used
 *      for the balance check).
 */
class VirtualWalletLockQueryTest extends TestCase
{
    use RefreshDatabase;

    protected function goldWallet(int $balance = 500): GoldWallet
    {
        $user = \App\Models\User::factory()->create();

        $wallet = new GoldWallet();
        $wallet->user_id = $user->id;
        $wallet->gold_balance = $balance;
        $wallet->total_earned = $balance;
        $wallet->total_spent = 0;
        $wallet->total_won = 0;
        $wallet->total_lost = 0;
        $wallet->save();

        return $wallet;
    }

    /**
     * The file's executable code with all comments removed, so a mention of
     * the old pattern inside a docblock can never satisfy (or break) the
     * structural assertions below.
     */
    protected function codeOnly(string $path): string
    {
        $code = '';

        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $code .= $token[1];

                continue;
            }

            $code .= $token;
        }

        return $code;
    }

    public function test_wallet_models_do_not_use_the_no_op_instance_lock(): void
    {
        foreach ([GoldWallet::class, GemWallet::class] as $model) {
            $source = $this->codeOnly((string) (new \ReflectionClass($model))->getFileName());

            $this->assertStringNotContainsString(
                '$this->lockForUpdate()',
                $source,
                $model.' must not call lockForUpdate() on the instance: it returns a builder and locks nothing.'
            );

            $this->assertMatchesRegularExpression(
                '/static::query\(\)[\s\S]{0,200}?->lockForUpdate\(\)/',
                $source,
                $model.' must lock the row through a query builder (SELECT … FOR UPDATE).'
            );
        }
    }

    public function test_gold_writes_rerun_a_select_inside_their_transaction(): void
    {
        $wallet = $this->goldWallet(500);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $wallet->spendGold(100, 'bet');

        $selects = array_values(array_filter(
            $queries,
            fn (string $sql) => str_contains($sql, 'gold_wallets') && str_starts_with(strtolower(ltrim($sql)), 'select')
        ));

        $this->assertNotEmpty($selects, 'spendGold() must re-read the wallet row inside the transaction.');
        $this->assertSame(400, GoldWallet::query()->whereKey($wallet->id)->firstOrFail()->gold_balance);
    }

    public function test_spend_uses_the_committed_balance_not_the_stale_instance(): void
    {
        $wallet = $this->goldWallet(500);

        // Someone else spends 450 and commits; the loaded instance still
        // shows 500. The write path must re-read the row, so a spend of 100
        // has to fail (450 < 100 is false → 450 - 100 = 350 actually succeeds;
        // the point is that the *committed* 450 is the basis, never the stale
        // 500 in memory).
        DB::table('gold_wallets')->where('id', $wallet->id)->update(['gold_balance' => 450]);

        $wallet->spendGold(100, 'bet');

        $this->assertSame(350, GoldWallet::query()->whereKey($wallet->id)->firstOrFail()->gold_balance);

        // And overspending against the committed balance is refused.
        DB::table('gold_wallets')->where('id', $wallet->id)->update(['gold_balance' => 10]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Insufficient gold balance');

        $wallet->spendGold(100, 'bet');
    }

    public function test_gem_writes_rerun_a_select_inside_their_transaction(): void
    {
        $user = \App\Models\User::factory()->create();

        $wallet = new GemWallet();
        $wallet->user_id = $user->id;
        $wallet->gem_balance = 20;
        $wallet->total_earned = 20;
        $wallet->total_spent = 0;
        $wallet->total_purchased = 0;
        $wallet->save();

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $wallet->spendGems(5, 'spin2win');

        $selects = array_values(array_filter(
            $queries,
            fn (string $sql) => str_contains($sql, 'gem_wallets') && str_starts_with(strtolower(ltrim($sql)), 'select')
        ));

        $this->assertNotEmpty($selects, 'spendGems() must re-read the wallet row inside the transaction.');
        $this->assertSame(15, GemWallet::query()->whereKey($wallet->id)->firstOrFail()->gem_balance);
    }
}
