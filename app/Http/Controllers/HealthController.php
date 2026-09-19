<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
class HealthController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(['status'=>'ok','service'=>'ffarena-laravel','version'=>config('app.version','1.0.0'),'environment'=>app()->environment(),'timestamp'=>now()->toIso8601String()])->header('X-Request-ID',$request->header('X-Request-ID')?: \Illuminate\Support\Str::uuid());
    }
    public function live(Request $request)
    {
        return response()->json(['status'=>'ok','service'=>'ffarena-laravel','timestamp'=>now()->toIso8601String()])->header('X-Request-ID',$request->header('X-Request-ID')?: \Illuminate\Support\Str::uuid());
    }
    public function ready(Request $request)
    {
        $checks=[]; $overall=true;
        try{DB::connection()->getPdo(); DB::select('SELECT 1'); $checks['database']='ok';}catch(\Throwable $e){$checks['database']='fail'; $overall=false;}
        try{Cache::put('health-check-'.time(),'ok',10); $checks['cache']='ok';}catch(\Throwable $e){$checks['cache']='fail'; if(config('cache.default')==='redis')$overall=false;}
        if(config('database.redis.default.host')){
            try{if(class_exists(\Illuminate\Support\Facades\Redis::class)){\Illuminate\Support\Facades\Redis::connection()->ping(); $checks['redis']='ok';}else $checks['redis']='not_configured';}catch(\Throwable $e){$checks['redis']='fail'; if(config('cache.default')==='redis'||config('queue.default')==='redis')$checks['redis']='fail_degraded';}
        }
        $status=$overall?200:503;
        return response()->json(['status'=>$overall?'ok':'degraded','service'=>'ffarena-laravel','checks'=>$checks,'timestamp'=>now()->toIso8601String()],$status)->header('X-Request-ID',$request->header('X-Request-ID')?: \Illuminate\Support\Str::uuid());
    }
}
