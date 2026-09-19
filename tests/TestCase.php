<?php
namespace Tests;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
abstract class TestCase extends BaseTestCase
{
    protected function isPostgresAvailable(): bool
    {
        try{
            $config=config('database.connections.pgsql');
            if(!$config)return false;
            $pdo=new \PDO("pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}",$config['username'],$config['password'],[\PDO::ATTR_TIMEOUT=>2]);
            $pdo->query('SELECT 1'); return true;
        }catch(\Throwable $e){return false;}
    }
    protected function isRedisAvailable(): bool
    {
        try{
            if(!class_exists(\Redis::class)&&!class_exists(\Illuminate\Support\Facades\Redis::class))return false;
            if(class_exists(\Redis::class)){
                $redis=new \Redis(); $redis->connect(config('database.redis.default.host','127.0.0.1'),(int)config('database.redis.default.port',6379),1.0);
                $password=config('database.redis.default.password');
                if($password&&$password!=='CHANGE_ME_REDIS_PASSWORD_PLACEHOLDER')$redis->auth($password);
                $redis->ping(); $redis->close(); return true;
            }
            \Illuminate\Support\Facades\Redis::connection()->ping(); return true;
        }catch(\Throwable $e){return false;}
    }
    protected function isDockerAvailable(): bool{try{$output=shell_exec('docker --version 2>&1'); return $output&&str_contains($output,'Docker version');}catch(\Throwable $e){return false;}}
    protected function isGoAvailable(): bool{try{$output=shell_exec('go version 2>&1'); return $output&&str_contains($output,'go version');}catch(\Throwable $e){return false;}}
    protected function isRustAvailable(): bool{try{$output=shell_exec('cargo --version 2>&1'); return $output&&str_contains($output,'cargo');}catch(\Throwable $e){return false;}}
}
