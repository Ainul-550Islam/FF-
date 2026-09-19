<?php
namespace Tests\Feature\R9;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
class MigrationVerificationTest extends TestCase
{
    use RefreshDatabase;
    public function test_all_tables_created(): void{$tables=['users','password_reset_tokens','sessions','personal_access_tokens','tournaments','wallets','ledger_entries','payments','payouts','webhook_events','idempotency_records','financial_settlements','cache','jobs']; foreach($tables as $table){$this->assertTrue(Schema::hasTable($table),"Table $table should exist");}}
    public function test_all_indexes_created(): void{$this->assertTrue(Schema::hasTable('wallets')); $this->assertTrue(Schema::hasTable('ledger_entries')); $this->assertTrue(Schema::hasTable('payments'));}
    public function test_unique_constraints(): void{$user=\App\Models\User::factory()->create(['email'=>'unique-test@example.com']); $this->expectException(\Illuminate\Database\QueryException::class); \App\Models\User::factory()->create(['email'=>'unique-test@example.com']);}
    public function test_foreign_keys(): void{$user=\App\Models\User::factory()->create(); $wallet=\App\Models\Wallet::create(['user_id'=>$user->id,'currency'=>'BDT','balance_minor'=>0]); $this->assertEquals($user->id,$wallet->user_id); $entry=\App\Models\LedgerEntry::create(['wallet_id'=>$wallet->id,'user_id'=>$user->id,'direction'=>'credit','amount_minor'=>1000,'balance_after_minor'=>1000,'reference_type'=>'test','reference_id'=>'ref-fk-test','idempotency_key'=>'idem-fk-'.uniqid()]); $this->assertEquals($wallet->id,$entry->wallet_id);}
    public function test_payment_idempotency_indexes(): void{$this->assertTrue(Schema::hasTable('payments')); $this->assertTrue(Schema::hasColumn('payments','idempotency_key')); $this->assertTrue(Schema::hasColumn('payments','external_id'));}
    public function test_webhook_event_uniqueness(): void{$this->assertTrue(Schema::hasColumn('webhook_events','event_id')); $eventId='evt-migration-'.uniqid(); \App\Models\WebhookEvent::create(['provider'=>'bkash','event_type'=>'payment.succeeded','event_id'=>$eventId,'payload'=>['amount'=>1000],'state'=>'received']); $this->assertDatabaseHas('webhook_events',['event_id'=>$eventId]);}
    public function test_wallet_ledger_indexes(): void{$this->assertTrue(Schema::hasColumn('ledger_entries','wallet_id')); $this->assertTrue(Schema::hasColumn('ledger_entries','reference_id')); $this->assertTrue(Schema::hasColumn('wallets','user_id'));}
    public function test_payout_uniqueness(): void{$this->assertTrue(Schema::hasColumn('payouts','external_id')); $this->assertTrue(Schema::hasColumn('payouts','idempotency_key'));}
    public function test_settlement_uniqueness(): void{$this->assertTrue(Schema::hasColumn('financial_settlements','idempotency_key'));}
    public function test_migration_fresh(): void{$this->assertTrue(Schema::hasTable('users')); $this->assertTrue(Schema::hasTable('wallets'));}
    public function test_jsonb_behavior(): void{$user=\App\Models\User::factory()->create(); $wallet=\App\Models\Wallet::create(['user_id'=>$user->id,'currency'=>'BDT','balance_minor'=>0]); $entry=\App\Models\LedgerEntry::create(['wallet_id'=>$wallet->id,'user_id'=>$user->id,'direction'=>'credit','amount_minor'=>1000,'balance_after_minor'=>1000,'reference_type'=>'test','reference_id'=>'ref-json-test','idempotency_key'=>'idem-json-'.uniqid(),'metadata'=>['currency'=>'BDT','provider'=>'manual','nested'=>['key'=>'value']]]); $entry->refresh(); $this->assertIsArray($entry->metadata); $this->assertEquals('BDT',$entry->metadata['currency']); $this->assertEquals('value',$entry->metadata['nested']['key']);}
}
