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
