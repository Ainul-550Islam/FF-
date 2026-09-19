# R9 Full File Content Part 5 - Files 61-75

Total files in this part: 15

## File: ./app/Models/Payout.php

```
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class Payout extends Model
{
    use HasFactory;
    protected $fillable = ['user_id','tournament_id','amount_minor','currency','status','external_id','provider','idempotency_key','metadata'];
    protected $casts = ['amount_minor'=>'integer','metadata'=>'array'];
    public function user(){return $this->belongsTo(User::class);}
    public function tournament(){return $this->belongsTo(Tournament::class);}
}
```

## File: ./app/Models/Tournament.php

```
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class Tournament extends Model
{
    use HasFactory;
    protected $fillable = ['name','slug','status','entry_fee_minor','prize_pool_minor','max_teams','starts_at','ends_at','metadata'];
    protected $casts = ['entry_fee_minor'=>'integer','prize_pool_minor'=>'integer','max_teams'=>'integer','starts_at'=>'datetime','ends_at'=>'datetime','metadata'=>'array'];
    public function payouts(){return $this->hasMany(Payout::class);}
    public function settlements(){return $this->hasMany(FinancialSettlement::class);}
}
```

## File: ./app/Models/User.php

```
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
class User extends Authenticatable
{
    use HasFactory, HasApiTokens, Notifiable;
    protected $fillable = ['name','email','password','is_admin','is_staff','is_active','phone','email_verified_at'];
    protected $hidden = ['password','remember_token'];
    protected $casts = ['email_verified_at'=>'datetime','password'=>'hashed','is_admin'=>'boolean','is_staff'=>'boolean','is_active'=>'boolean'];
    public function wallets(){return $this->hasMany(Wallet::class);}
    public function isAdmin(): bool{return (bool)$this->is_admin;}
    public function isStaff(): bool{return (bool)($this->is_staff||$this->is_admin);}
    public function isActive(): bool{return (bool)($this->is_active??true);}
}
```

## File: ./app/Models/Wallet.php

```
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class Wallet extends Model
{
    use HasFactory;
    protected $fillable = ['user_id','currency','balance_minor','is_locked'];
    protected $casts = ['balance_minor'=>'integer','is_locked'=>'boolean'];
    public function user(){return $this->belongsTo(User::class);}
    public function ledgerEntries(){return $this->hasMany(LedgerEntry::class)->orderBy('created_at');}
}
```

## File: ./app/Models/WebhookEvent.php

```
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class WebhookEvent extends Model
{
    use HasFactory;
    protected $fillable = ['provider','event_type','event_id','payload','signature','state','attempts','last_error','processed_at'];
    protected $casts = ['payload'=>'array','attempts'=>'integer','processed_at'=>'datetime'];
    public const STATE_RECEIVED='received'; public const STATE_VALIDATED='validated'; public const STATE_PROCESSING='processing'; public const STATE_PROCESSED='processed'; public const STATE_FAILED='failed'; public const STATE_DUPLICATE='duplicate';
    public function canRetry(): bool{return $this->attempts<3&&$this->state!==self::STATE_PROCESSED;}
}
```

## File: ./app/Payments/Providers/GoPaymentProvider.php

```
<?php
namespace App\Payments\Providers;
class GoPaymentProvider{public function __construct(private \App\Services\GoPaymentGatewayAdapter $adapter){} public function createPayment(array $data): array{return $this->adapter->createPayment($data);}}
```

## File: ./app/Providers/AppServiceProvider.php

```
<?php
namespace App\Providers;
use App\Contracts\ErrorReporterInterface;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ErrorReporterInterface::class,function(){
            return new class implements ErrorReporterInterface{
                public function report(\Throwable $e, array $context=[]): void{
                    \Illuminate\Support\Facades\Log::channel('daily')->error($e->getMessage(),['exception'=>get_class($e),'context'=>$context,'trace'=>$e->getTraceAsString()]);
                }
            };
        });
    }
    public function boot(): void
    {
        RateLimiter::for('health',function(Request $request){return Limit::perMinute(120)->by($request->ip());});
        RateLimiter::for('api',function(Request $request){return Limit::perMinute(60)->by($request->user()?->id?:$request->ip());});
        RateLimiter::for('api_anon',function(Request $request){return Limit::perMinute(30)->by($request->ip());});
        RateLimiter::for('api_register',function(Request $request){return Limit::perMinute(5)->by($request->ip());});
        RateLimiter::for('api_login',function(Request $request){return Limit::perMinute(10)->by($request->ip());});
        RateLimiter::for('api_otp_request',function(Request $request){return Limit::perMinute(3)->by($request->ip());});
        RateLimiter::for('api_otp_verify',function(Request $request){return Limit::perMinute(5)->by($request->ip());});
        RateLimiter::for('api_webhook',function(Request $request){return Limit::perMinute(100)->by($request->ip());});
        RateLimiter::for('api_support',function(Request $request){return Limit::perMinute(10)->by($request->user()?->id?:$request->ip());});
        RateLimiter::for('api_token_issue',function(Request $request){return Limit::perMinute(5)->by($request->user()?->id?:$request->ip());});
    }
}
```

## File: ./app/Services/GoPaymentGatewayAdapter.php

```
<?php
namespace App\Services;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
class GoPaymentGatewayAdapter
{
    public function createPayment(array $data): array
    {
        if(!config('services_go_rust.go_payment.enabled')){return ['status'=>'succeeded','provider'=>'manual','fallback'=>true];}
        try{
            $url=config('services_go_rust.go_payment.url').'/api/v1/payments';
            $response=Http::withHeaders(['Authorization'=>'Bearer '.config('services_go_rust.go_payment.token'),'Idempotency-Key'=>$data['idempotency_key']?? \Illuminate\Support\Str::uuid(),'X-Request-ID'=> \Illuminate\Support\Str::uuid()])->timeout(config('services_go_rust.go_payment.timeout',5))->post($url,$data);
            return $response->json()??['status'=>'failed','error'=>'invalid_response'];
        }catch(\Throwable $e){Log::error('Go payment gateway error',['error'=>$e->getMessage()]); return ['status'=>'failed','fallback'=>true,'error'=>$e->getMessage()];}
    }
}
```

## File: ./app/Services/Integration/EventPublisher.php

```
<?php
namespace App\Services\Integration;
class EventPublisher
{
    public const VERSION='v1';
    public function publish(string $type, array $payload): void
    {
        if(!config('services_go_rust.events.enabled'))return;
        \Illuminate\Support\Facades\Log::channel('daily')->info('event published',['type'=>$type,'version'=>self::VERSION,'payload'=>$payload]);
    }
    public function publishPaymentCreated(array $payment): void{$this->publish('payment.created.'.self::VERSION,$payment);}
    public function publishPaymentSucceeded(array $payment): void{$this->publish('payment.succeeded.'.self::VERSION,$payment);}
}
```

## File: ./app/Services/Integration/HealthCheckService.php

```
<?php
namespace App\Services\Integration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
class HealthCheckService
{
    public function checkDatabase(): array{try{DB::connection()->getPdo(); DB::select('SELECT 1'); return ['status'=>'ok'];}catch(\Throwable $e){return ['status'=>'fail','message'=>$e->getMessage()];}}
    public function checkCache(): array{try{Cache::put('health-check','ok',10); return ['status'=>'ok'];}catch(\Throwable $e){return ['status'=>'fail','message'=>$e->getMessage()];}}
    public function checkRedis(): array{try{if(class_exists(\Redis::class)){$redis=new \Redis(); $redis->connect(config('database.redis.default.host','127.0.0.1'),(int)config('database.redis.default.port',6379),1.0); $password=config('database.redis.default.password'); if($password&&$password!=='CHANGE_ME_REDIS_PASSWORD_PLACEHOLDER')$redis->auth($password); $redis->ping(); $redis->close(); return ['status'=>'ok'];} return ['status'=>'not_configured'];}catch(\Throwable $e){return ['status'=>'fail','message'=>$e->getMessage()];}}
    public function fullCheck(): array{return ['database'=>$this->checkDatabase(),'cache'=>$this->checkCache(),'redis'=>$this->checkRedis()];}
}
```

## File: ./app/Services/Integration/ServiceAuthenticator.php

```
<?php
namespace App\Services\Integration;
class ServiceAuthenticator
{
    public static function signRequest(string $secret, string $method, string $path, string $body, int $timestamp, string $nonce): string
    {
        $message=sprintf('%s:%s:%s:%d:%s',$method,$path,$body,$timestamp,$nonce);
        return hash_hmac('sha256',$message,$secret);
    }
    public static function verifyRequest(string $secret, string $method, string $path, string $body, string $signature, int $timestamp, string $nonce, int $tolerance=300): bool
    {
        $now=time(); if(abs($now-$timestamp)>$tolerance)return false;
        $expected=self::signRequest($secret,$method,$path,$body,$timestamp,$nonce);
        return hash_equals($expected,$signature);
    }
    public static function generateTimestamp(): int{return time();}
    public static function generateNonce(): string{return \Illuminate\Support\Str::uuid()->toString();}
    public static function generateHeaders(string $secret, string $method, string $path, string $body): array
    {
        $timestamp=self::generateTimestamp(); $nonce=self::generateNonce(); $signature=self::signRequest($secret,$method,$path,$body,$timestamp,$nonce);
        return ['X-Timestamp'=>$timestamp,'X-Nonce'=>$nonce,'X-Signature'=>$signature,'X-Service-ID'=>config('services_go_rust.service_auth.service_id','ffarena-laravel')];
    }
}
```

## File: ./app/Services/RustFraudServiceAdapter.php

```
<?php
namespace App\Services;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
class RustFraudServiceAdapter
{
    public function evaluate(array $data): array
    {
        if(!config('services_go_rust.rust_security.enabled')){return ['overall_score'=>0,'level'=>'low','recommendation'=>'allow','fallback'=>true];}
        try{
            $url=config('services_go_rust.rust_security.url').'/api/v1/security/evaluate';
            $response=Http::withHeaders(['Authorization'=>'Bearer '.config('services_go_rust.rust_security.token'),'X-Request-ID'=> \Illuminate\Support\Str::uuid()])->timeout(config('services_go_rust.rust_security.timeout',5))->post($url,$data);
            return $response->json()??['overall_score'=>0,'level'=>'low'];
        }catch(\Throwable $e){Log::error('Rust security service error',['error'=>$e->getMessage()]); return ['overall_score'=>0,'level'=>'low','recommendation'=>'allow','fallback'=>true];}
    }
}
```

## File: ./app/Services/WalletService.php

```
<?php
namespace App\Services;
use App\Models\LedgerEntry;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
class WalletService
{
    public function getOrCreateWallet(int $userId, string $currency='BDT'): Wallet
    {
        return Wallet::firstOrCreate(['user_id'=>$userId,'currency'=>strtoupper($currency)],['balance_minor'=>0,'is_locked'=>false]);
    }
    public function getBalance(int $userId, string $currency='BDT'): int
    {
        return $this->getOrCreateWallet($userId,$currency)->balance_minor;
    }
    public function calculateBalance(int $walletId): int
    {
        // ledger_entries table is source of truth for financial integrity
        $credits = LedgerEntry::where('wallet_id',$walletId)->where('direction','credit')->sum('amount_minor');
        $debits = LedgerEntry::where('wallet_id',$walletId)->where('direction','debit')->sum('amount_minor');
        return $credits-$debits;
    }
    public function credit(int $userId, int $amountMinor, string $currency='BDT', string $referenceType='manual', ?string $referenceId=null, ?string $idempotencyKey=null): LedgerEntry
    {
        if($amountMinor<=0)throw new \InvalidArgumentException('Amount must be positive');
        return DB::transaction(function() use($userId,$amountMinor,$currency,$referenceType,$referenceId,$idempotencyKey){
            // Idempotency-Key header handled via idempotency_key param - ensures no bypass of idempotency
            // SELECT FOR UPDATE to prevent race conditions
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
            $entry = LedgerEntry::create([
                'wallet_id'=>$wallet->id,'user_id'=>$userId,'direction'=>'credit','amount_minor'=>$amountMinor,'balance_after_minor'=>$newBalance,
                'reference_type'=>$referenceType,'reference_id'=>$referenceId?: (string)Str::uuid(),'idempotency_key'=>$idempotencyKey?: (string)Str::uuid(),'metadata'=>['currency'=>$currency]
            ]);
            $wallet->update(['balance_minor'=>$newBalance]);
            return $entry;
        });
    }
    public function debit(int $userId, int $amountMinor, string $currency='BDT', string $referenceType='manual', ?string $referenceId=null, ?string $idempotencyKey=null): LedgerEntry
    {
        if($amountMinor<=0)throw new \InvalidArgumentException('Amount must be positive');
        return DB::transaction(function() use($userId,$amountMinor,$currency,$referenceType,$referenceId,$idempotencyKey){
            $wallet = Wallet::where('user_id',$userId)->where('currency',strtoupper($currency))->lockForUpdate()->first();
            if(!$wallet)throw new \RuntimeException('Wallet not found');
            if($wallet->balance_minor<$amountMinor)throw new \RuntimeException('Insufficient funds');
            if($idempotencyKey){
                $existing = LedgerEntry::where('idempotency_key',$idempotencyKey)->first();
                if($existing)return $existing;
            }
            $newBalance = $wallet->balance_minor-$amountMinor;
            $entry = LedgerEntry::create([
                'wallet_id'=>$wallet->id,'user_id'=>$userId,'direction'=>'debit','amount_minor'=>$amountMinor,'balance_after_minor'=>$newBalance,
                'reference_type'=>$referenceType,'reference_id'=>$referenceId?: (string)Str::uuid(),'idempotency_key'=>$idempotencyKey?: (string)Str::uuid(),'metadata'=>['currency'=>$currency]
            ]);
            $wallet->update(['balance_minor'=>$newBalance]);
            return $entry;
        });
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
}
```

## File: ./app/Support/Logging/DomainLogChannel.php

```
<?php
namespace App\Support\Logging;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
class DomainLogChannel
{
    public function __invoke(array $config)
    {
        $path=$config['path']??storage_path('logs/ffarena.log'); $level=$config['level']??'debug'; $days=$config['days']??14;
        $logger=new Logger('ffarena'); $handler=new RotatingFileHandler($path,$days,$level);
        $handler->pushProcessor(new RequestContextProcessor()); $handler->pushProcessor(new RedactSensitiveDataProcessor()); $handler->pushProcessor(new PsrLogMessageProcessor());
        $logger->pushHandler($handler); return $logger;
    }
    public static function config(string $domain): array
    {
        return ['driver'=>'monolog','level'=>env('LOG_LEVEL','debug'),'handler'=>RotatingFileHandler::class,'handler_with'=>['filename'=>storage_path("logs/{$domain}.log"),'maxFiles'=>(int)env('LOG_DAILY_DAYS',14)],'processors'=>[PsrLogMessageProcessor::class,RequestContextProcessor::class,RedactSensitiveDataProcessor::class]];
    }
}
```

## File: ./app/Support/Logging/RedactSensitiveDataProcessor.php

```
<?php
namespace App\Support\Logging;
use Monolog\LogRecord;
class RedactSensitiveDataProcessor
{
    private const SENSITIVE_KEYS=['password','secret','token','jwt','api_key','private_key','DATABASE_URL','REDIS_URL','DB_PASSWORD','REDIS_PASSWORD','authorization','cookie','x-api-key'];
    public function __invoke(LogRecord $record): LogRecord
    {
        $message=$record->message; $context=$record->context;
        foreach(self::SENSITIVE_KEYS as $key){$pattern='/'.preg_quote($key,'/').'["\']?\s*[:=]\s*["\']?[^"\'\s,}]+/i'; $message=preg_replace($pattern,$key.'=***REDACTED***',$message);}
        $redactedContext=$this->redactArray($context);
        return $record->with(message:$message,context:$redactedContext);
    }
    private function redactArray(array $data): array
    {
        foreach($data as $k=>$v){
            $lower=strtolower((string)$k);
            foreach(self::SENSITIVE_KEYS as $sensitive){if(str_contains($lower,strtolower($sensitive))){$data[$k]='***REDACTED***'; continue 2;}}
            if(is_array($v))$data[$k]=$this->redactArray($v);
        }
        return $data;
    }
}
```

