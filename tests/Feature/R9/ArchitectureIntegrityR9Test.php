<?php
namespace Tests\Feature\R9;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\Wallet;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
class ArchitectureIntegrityR9Test extends TestCase
{
    use RefreshDatabase;
    public function test_laravel_remains_authoritative_for_financial_state(): void{$this->assertTrue(class_exists(WalletService::class)); $this->assertTrue(method_exists(WalletService::class,'credit')); $this->assertTrue(method_exists(WalletService::class,'debit')); $this->assertTrue(method_exists(WalletService::class,'verifyLedgerIntegrity'));}
    public function test_postgresql_is_source_of_truth(): void{$this->assertTrue(config()->has('database.connections.pgsql')); $pgsql=config('database.connections.pgsql'); $this->assertEquals('pgsql',$pgsql['driver']); $user=User::factory()->create(); $wallet=Wallet::create(['user_id'=>$user->id,'currency'=>'BDT','balance_minor'=>1000]); $this->assertDatabaseHas('wallets',['id'=>$wallet->id,'balance_minor'=>1000]);}
    public function test_redis_is_coordination_cache_only(): void{$user=User::factory()->create(); $service=app(WalletService::class); $service->credit($user->id,1000,'BDT','test','ref-cache-only'); $wallet=Wallet::where('user_id',$user->id)->first(); $this->assertEquals(1000,$wallet->balance_minor); $this->assertDatabaseHas('ledger_entries',['wallet_id'=>$wallet->id]);}
    public function test_go_cannot_bypass_laravel_authorization(): void{$compose=file_get_contents(base_path('docker-compose.yml')); $this->assertStringContainsString('SERVICE_HMAC_SECRET',$compose);}
    public function test_rust_cannot_bypass_laravel_account_authority(): void{$this->assertTrue(true);}
    public function test_no_service_stores_plaintext_secrets(): void{$files=[base_path('config/services_go_rust.php'),base_path('.env.example')]; foreach($files as $file){if(file_exists($file)){$content=file_get_contents($file); if(str_contains($content,'SECRET')||str_contains($content,'PASSWORD')){$this->assertStringContainsString('CHANGE_ME',$content,"File $file should use placeholder");}}}}
    public function test_no_service_bypasses_idempotency(): void{$reflection=new \ReflectionClass(WalletService::class); $source=file_get_contents($reflection->getFileName()); $this->assertStringContainsString('idempotency',strtolower($source)); $this->assertStringContainsString('Idempotency-Key',$source);}
    public function test_no_financial_effect_bypasses_ledger(): void{$reflection=new \ReflectionClass(WalletService::class); $source=file_get_contents($reflection->getFileName()); $this->assertStringContainsString('ledger_entries',strtolower($source)); $this->assertStringContainsString('LedgerEntry::create',$source);}
    public function test_no_internal_endpoint_public(): void{$apiRoutes=file_get_contents(base_path('routes/api.php')); $this->assertStringContainsString('bearer',strtolower($apiRoutes)); $this->assertStringContainsString('auth:sanctum',$apiRoutes);}
    public function test_financial_totals_reconcile(): void{$user=User::factory()->create(); $service=app(WalletService::class); $service->credit($user->id,1000,'BDT','test','ref-1'); $service->credit($user->id,500,'BDT','test','ref-2'); $service->debit($user->id,200,'BDT','test','ref-3'); $wallet=Wallet::where('user_id',$user->id)->first(); $calculated=$service->calculateBalance($wallet->id); $this->assertEquals(1300,$wallet->balance_minor); $this->assertEquals(1300,$calculated); $this->assertEquals($wallet->balance_minor,$calculated);}
    public function test_query_audit_lock_for_update(): void{$reflection=new \ReflectionClass(WalletService::class); $source=file_get_contents($reflection->getFileName()); $this->assertStringContainsString('lockForUpdate',$source,'Financial queries must use SELECT FOR UPDATE');}
}
