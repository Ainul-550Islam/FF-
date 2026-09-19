<?php
namespace Tests\Integration;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class PostgreSQLIntegrationTest extends TestCase
{
    use RefreshDatabase;
    public function test_postgres_connection_ping(): void{if(!$this->isPostgresAvailable())$this->markTestSkipped('PostgreSQL not available - BLOCKED BY ENVIRONMENT'); $pdo=\Illuminate\Support\Facades\DB::connection('pgsql')->getPdo(); $this->assertNotNull($pdo); $result=\Illuminate\Support\Facades\DB::connection('pgsql')->select('SELECT 1 as val'); $this->assertEquals(1,$result[0]->val);}
    public function test_postgres_transaction_commit_rollback(): void{if(!$this->isPostgresAvailable())$this->markTestSkipped('PostgreSQL not available - BLOCKED BY ENVIRONMENT'); \Illuminate\Support\Facades\DB::connection('pgsql')->beginTransaction(); \Illuminate\Support\Facades\DB::connection('pgsql')->table('users')->insert(['name'=>'Test','email'=>'test-rollback@example.com','password'=>bcrypt('password'),'created_at'=>now(),'updated_at'=>now()]); \Illuminate\Support\Facades\DB::connection('pgsql')->rollBack(); $exists=\Illuminate\Support\Facades\DB::connection('pgsql')->table('users')->where('email','test-rollback@example.com')->exists(); $this->assertFalse($exists);}
    public function test_postgres_select_for_update_locking(): void{if(!$this->isPostgresAvailable())$this->markTestSkipped('PostgreSQL not available - BLOCKED BY ENVIRONMENT'); $user=User::factory()->create(); $wallet=Wallet::create(['user_id'=>$user->id,'currency'=>'BDT','balance_minor'=>1000]); \Illuminate\Support\Facades\DB::connection('pgsql')->transaction(function() use($wallet){$locked=Wallet::where('id',$wallet->id)->lockForUpdate()->first(); $this->assertNotNull($locked); $this->assertEquals(1000,$locked->balance_minor);});}
    public function test_postgres_ledger_append_only(): void{$user=User::factory()->create(); $wallet=Wallet::create(['user_id'=>$user->id,'currency'=>'BDT','balance_minor'=>0]); $service=app(\App\Services\WalletService::class); $service->credit($user->id,1000,'BDT','test','ref-1'); $service->credit($user->id,500,'BDT','test','ref-2'); $service->debit($user->id,200,'BDT','test','ref-3'); $wallet->refresh(); $this->assertEquals(1300,$wallet->balance_minor); $calculated=$service->calculateBalance($wallet->id); $this->assertEquals(1300,$calculated); $this->assertTrue($service->verifyLedgerIntegrity($wallet->id));}
}
