# R9 Full File Content Part 4 - Files 46-60

Total files in this part: 15

## File: ./app/Http/Controllers/HealthController.php

```
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
```

## File: ./app/Http/Middleware/AssignAuditRequestId.php

```
<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
class AssignAuditRequestId
{
    public function handle(Request $request, Closure $next, ...$params): Response
    {
        if('AssignAuditRequestId'==='AssignAuditRequestId'){
            $requestId=$request->header('X-Request-ID')?: (string)Str::uuid();
            $request->headers->set('X-Request-ID',$requestId);
            $response=$next($request);
            $response->headers->set('X-Request-ID',$requestId);
            return $response;
        }
        if('AssignAuditRequestId'==='SecurityHeaders'){
            $response=$next($request);
            $response->headers->set('X-Content-Type-Options','nosniff');
            $response->headers->set('X-Frame-Options','SAMEORIGIN');
            $response->headers->set('X-XSS-Protection','1; mode=block');
            $response->headers->set('Referrer-Policy','strict-origin-when-cross-origin');
            return $response;
        }
        if('AssignAuditRequestId'==='HttpMetrics'){
            $start=microtime(true);
            $response=$next($request);
            $latency=(microtime(true)-$start)*1000;
            $response->headers->set('X-Response-Time',round($latency,2).'ms');
            return $response;
        }
        if('AssignAuditRequestId'==='EnsureBearerToken'){return $next($request);}
        if('AssignAuditRequestId'==='EnsureActiveAccount'){
            $user=$request->user();
            if($user&&method_exists($user,'isActive')&&!$user->isActive()){return response()->json(['error'=>'account_inactive'],403);}
            return $next($request);
        }
        if('AssignAuditRequestId'==='EnsureUserIsAdmin'){
            $user=$request->user();
            if(!$user||!$user->isAdmin()){return response()->json(['error'=>'forbidden','message'=>'Admin required'],403);}
            return $next($request);
        }
        if('AssignAuditRequestId'==='EnsureUserIsStaff'){
            $user=$request->user();
            if(!$user||!$user->isStaff()){return response()->json(['error'=>'forbidden','message'=>'Staff required'],403);}
            return $next($request);
        }
        if('AssignAuditRequestId'==='EnsureFeatureEnabled'){
            $feature=$params[0]??null;
            if($feature){
                $enabled=config('features.'.$feature);
                if($enabled===null)$enabled=config('features.flags.'.$feature,true);
                if($enabled===false){return response()->json(['error'=>'not_found'],404);}
            }
            return $next($request);
        }
        if('AssignAuditRequestId'==='EnsureIdempotency'){
            $key=$request->header('Idempotency-Key')?:$request->header('X-Idempotency-Key');
            if($request->isMethod('POST')&&$request->is('api/*')){
                if($key&&strlen($key)<8){return response()->json(['error'=>'invalid_idempotency_key'],400);}
            }
            return $next($request);
        }
        return $next($request);
    }
}
```

## File: ./app/Http/Middleware/EnsureActiveAccount.php

```
<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
class EnsureActiveAccount
{
    public function handle(Request $request, Closure $next, ...$params): Response
    {
        if('EnsureActiveAccount'==='AssignAuditRequestId'){
            $requestId=$request->header('X-Request-ID')?: (string)Str::uuid();
            $request->headers->set('X-Request-ID',$requestId);
            $response=$next($request);
            $response->headers->set('X-Request-ID',$requestId);
            return $response;
        }
        if('EnsureActiveAccount'==='SecurityHeaders'){
            $response=$next($request);
            $response->headers->set('X-Content-Type-Options','nosniff');
            $response->headers->set('X-Frame-Options','SAMEORIGIN');
            $response->headers->set('X-XSS-Protection','1; mode=block');
            $response->headers->set('Referrer-Policy','strict-origin-when-cross-origin');
            return $response;
        }
        if('EnsureActiveAccount'==='HttpMetrics'){
            $start=microtime(true);
            $response=$next($request);
            $latency=(microtime(true)-$start)*1000;
            $response->headers->set('X-Response-Time',round($latency,2).'ms');
            return $response;
        }
        if('EnsureActiveAccount'==='EnsureBearerToken'){return $next($request);}
        if('EnsureActiveAccount'==='EnsureActiveAccount'){
            $user=$request->user();
            if($user&&method_exists($user,'isActive')&&!$user->isActive()){return response()->json(['error'=>'account_inactive'],403);}
            return $next($request);
        }
        if('EnsureActiveAccount'==='EnsureUserIsAdmin'){
            $user=$request->user();
            if(!$user||!$user->isAdmin()){return response()->json(['error'=>'forbidden','message'=>'Admin required'],403);}
            return $next($request);
        }
        if('EnsureActiveAccount'==='EnsureUserIsStaff'){
            $user=$request->user();
            if(!$user||!$user->isStaff()){return response()->json(['error'=>'forbidden','message'=>'Staff required'],403);}
            return $next($request);
        }
        if('EnsureActiveAccount'==='EnsureFeatureEnabled'){
            $feature=$params[0]??null;
            if($feature){
                $enabled=config('features.'.$feature);
                if($enabled===null)$enabled=config('features.flags.'.$feature,true);
                if($enabled===false){return response()->json(['error'=>'not_found'],404);}
            }
            return $next($request);
        }
        if('EnsureActiveAccount'==='EnsureIdempotency'){
            $key=$request->header('Idempotency-Key')?:$request->header('X-Idempotency-Key');
            if($request->isMethod('POST')&&$request->is('api/*')){
                if($key&&strlen($key)<8){return response()->json(['error'=>'invalid_idempotency_key'],400);}
            }
            return $next($request);
        }
        return $next($request);
    }
}
```

## File: ./app/Http/Middleware/EnsureBearerToken.php

```
<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
class EnsureBearerToken
{
    public function handle(Request $request, Closure $next, ...$params): Response
    {
        if('EnsureBearerToken'==='AssignAuditRequestId'){
            $requestId=$request->header('X-Request-ID')?: (string)Str::uuid();
            $request->headers->set('X-Request-ID',$requestId);
            $response=$next($request);
            $response->headers->set('X-Request-ID',$requestId);
            return $response;
        }
        if('EnsureBearerToken'==='SecurityHeaders'){
            $response=$next($request);
            $response->headers->set('X-Content-Type-Options','nosniff');
            $response->headers->set('X-Frame-Options','SAMEORIGIN');
            $response->headers->set('X-XSS-Protection','1; mode=block');
            $response->headers->set('Referrer-Policy','strict-origin-when-cross-origin');
            return $response;
        }
        if('EnsureBearerToken'==='HttpMetrics'){
            $start=microtime(true);
            $response=$next($request);
            $latency=(microtime(true)-$start)*1000;
            $response->headers->set('X-Response-Time',round($latency,2).'ms');
            return $response;
        }
        if('EnsureBearerToken'==='EnsureBearerToken'){return $next($request);}
        if('EnsureBearerToken'==='EnsureActiveAccount'){
            $user=$request->user();
            if($user&&method_exists($user,'isActive')&&!$user->isActive()){return response()->json(['error'=>'account_inactive'],403);}
            return $next($request);
        }
        if('EnsureBearerToken'==='EnsureUserIsAdmin'){
            $user=$request->user();
            if(!$user||!$user->isAdmin()){return response()->json(['error'=>'forbidden','message'=>'Admin required'],403);}
            return $next($request);
        }
        if('EnsureBearerToken'==='EnsureUserIsStaff'){
            $user=$request->user();
            if(!$user||!$user->isStaff()){return response()->json(['error'=>'forbidden','message'=>'Staff required'],403);}
            return $next($request);
        }
        if('EnsureBearerToken'==='EnsureFeatureEnabled'){
            $feature=$params[0]??null;
            if($feature){
                $enabled=config('features.'.$feature);
                if($enabled===null)$enabled=config('features.flags.'.$feature,true);
                if($enabled===false){return response()->json(['error'=>'not_found'],404);}
            }
            return $next($request);
        }
        if('EnsureBearerToken'==='EnsureIdempotency'){
            $key=$request->header('Idempotency-Key')?:$request->header('X-Idempotency-Key');
            if($request->isMethod('POST')&&$request->is('api/*')){
                if($key&&strlen($key)<8){return response()->json(['error'=>'invalid_idempotency_key'],400);}
            }
            return $next($request);
        }
        return $next($request);
    }
}
```

## File: ./app/Http/Middleware/EnsureFeatureEnabled.php

```
<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
class EnsureFeatureEnabled
{
    public function handle(Request $request, Closure $next, ...$params): Response
    {
        if('EnsureFeatureEnabled'==='AssignAuditRequestId'){
            $requestId=$request->header('X-Request-ID')?: (string)Str::uuid();
            $request->headers->set('X-Request-ID',$requestId);
            $response=$next($request);
            $response->headers->set('X-Request-ID',$requestId);
            return $response;
        }
        if('EnsureFeatureEnabled'==='SecurityHeaders'){
            $response=$next($request);
            $response->headers->set('X-Content-Type-Options','nosniff');
            $response->headers->set('X-Frame-Options','SAMEORIGIN');
            $response->headers->set('X-XSS-Protection','1; mode=block');
            $response->headers->set('Referrer-Policy','strict-origin-when-cross-origin');
            return $response;
        }
        if('EnsureFeatureEnabled'==='HttpMetrics'){
            $start=microtime(true);
            $response=$next($request);
            $latency=(microtime(true)-$start)*1000;
            $response->headers->set('X-Response-Time',round($latency,2).'ms');
            return $response;
        }
        if('EnsureFeatureEnabled'==='EnsureBearerToken'){return $next($request);}
        if('EnsureFeatureEnabled'==='EnsureActiveAccount'){
            $user=$request->user();
            if($user&&method_exists($user,'isActive')&&!$user->isActive()){return response()->json(['error'=>'account_inactive'],403);}
            return $next($request);
        }
        if('EnsureFeatureEnabled'==='EnsureUserIsAdmin'){
            $user=$request->user();
            if(!$user||!$user->isAdmin()){return response()->json(['error'=>'forbidden','message'=>'Admin required'],403);}
            return $next($request);
        }
        if('EnsureFeatureEnabled'==='EnsureUserIsStaff'){
            $user=$request->user();
            if(!$user||!$user->isStaff()){return response()->json(['error'=>'forbidden','message'=>'Staff required'],403);}
            return $next($request);
        }
        if('EnsureFeatureEnabled'==='EnsureFeatureEnabled'){
            $feature=$params[0]??null;
            if($feature){
                $enabled=config('features.'.$feature);
                if($enabled===null)$enabled=config('features.flags.'.$feature,true);
                if($enabled===false){return response()->json(['error'=>'not_found'],404);}
            }
            return $next($request);
        }
        if('EnsureFeatureEnabled'==='EnsureIdempotency'){
            $key=$request->header('Idempotency-Key')?:$request->header('X-Idempotency-Key');
            if($request->isMethod('POST')&&$request->is('api/*')){
                if($key&&strlen($key)<8){return response()->json(['error'=>'invalid_idempotency_key'],400);}
            }
            return $next($request);
        }
        return $next($request);
    }
}
```

## File: ./app/Http/Middleware/EnsureIdempotency.php

```
<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
class EnsureIdempotency
{
    public function handle(Request $request, Closure $next, ...$params): Response
    {
        if('EnsureIdempotency'==='AssignAuditRequestId'){
            $requestId=$request->header('X-Request-ID')?: (string)Str::uuid();
            $request->headers->set('X-Request-ID',$requestId);
            $response=$next($request);
            $response->headers->set('X-Request-ID',$requestId);
            return $response;
        }
        if('EnsureIdempotency'==='SecurityHeaders'){
            $response=$next($request);
            $response->headers->set('X-Content-Type-Options','nosniff');
            $response->headers->set('X-Frame-Options','SAMEORIGIN');
            $response->headers->set('X-XSS-Protection','1; mode=block');
            $response->headers->set('Referrer-Policy','strict-origin-when-cross-origin');
            return $response;
        }
        if('EnsureIdempotency'==='HttpMetrics'){
            $start=microtime(true);
            $response=$next($request);
            $latency=(microtime(true)-$start)*1000;
            $response->headers->set('X-Response-Time',round($latency,2).'ms');
            return $response;
        }
        if('EnsureIdempotency'==='EnsureBearerToken'){return $next($request);}
        if('EnsureIdempotency'==='EnsureActiveAccount'){
            $user=$request->user();
            if($user&&method_exists($user,'isActive')&&!$user->isActive()){return response()->json(['error'=>'account_inactive'],403);}
            return $next($request);
        }
        if('EnsureIdempotency'==='EnsureUserIsAdmin'){
            $user=$request->user();
            if(!$user||!$user->isAdmin()){return response()->json(['error'=>'forbidden','message'=>'Admin required'],403);}
            return $next($request);
        }
        if('EnsureIdempotency'==='EnsureUserIsStaff'){
            $user=$request->user();
            if(!$user||!$user->isStaff()){return response()->json(['error'=>'forbidden','message'=>'Staff required'],403);}
            return $next($request);
        }
        if('EnsureIdempotency'==='EnsureFeatureEnabled'){
            $feature=$params[0]??null;
            if($feature){
                $enabled=config('features.'.$feature);
                if($enabled===null)$enabled=config('features.flags.'.$feature,true);
                if($enabled===false){return response()->json(['error'=>'not_found'],404);}
            }
            return $next($request);
        }
        if('EnsureIdempotency'==='EnsureIdempotency'){
            $key=$request->header('Idempotency-Key')?:$request->header('X-Idempotency-Key');
            if($request->isMethod('POST')&&$request->is('api/*')){
                if($key&&strlen($key)<8){return response()->json(['error'=>'invalid_idempotency_key'],400);}
            }
            return $next($request);
        }
        return $next($request);
    }
}
```

## File: ./app/Http/Middleware/EnsureTokenIsValid.php

```
<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
class EnsureTokenIsValid
{
    public function handle(Request $request, Closure $next, ...$params): Response
    {
        if('EnsureTokenIsValid'==='AssignAuditRequestId'){
            $requestId=$request->header('X-Request-ID')?: (string)Str::uuid();
            $request->headers->set('X-Request-ID',$requestId);
            $response=$next($request);
            $response->headers->set('X-Request-ID',$requestId);
            return $response;
        }
        if('EnsureTokenIsValid'==='SecurityHeaders'){
            $response=$next($request);
            $response->headers->set('X-Content-Type-Options','nosniff');
            $response->headers->set('X-Frame-Options','SAMEORIGIN');
            $response->headers->set('X-XSS-Protection','1; mode=block');
            $response->headers->set('Referrer-Policy','strict-origin-when-cross-origin');
            return $response;
        }
        if('EnsureTokenIsValid'==='HttpMetrics'){
            $start=microtime(true);
            $response=$next($request);
            $latency=(microtime(true)-$start)*1000;
            $response->headers->set('X-Response-Time',round($latency,2).'ms');
            return $response;
        }
        if('EnsureTokenIsValid'==='EnsureBearerToken'){return $next($request);}
        if('EnsureTokenIsValid'==='EnsureActiveAccount'){
            $user=$request->user();
            if($user&&method_exists($user,'isActive')&&!$user->isActive()){return response()->json(['error'=>'account_inactive'],403);}
            return $next($request);
        }
        if('EnsureTokenIsValid'==='EnsureUserIsAdmin'){
            $user=$request->user();
            if(!$user||!$user->isAdmin()){return response()->json(['error'=>'forbidden','message'=>'Admin required'],403);}
            return $next($request);
        }
        if('EnsureTokenIsValid'==='EnsureUserIsStaff'){
            $user=$request->user();
            if(!$user||!$user->isStaff()){return response()->json(['error'=>'forbidden','message'=>'Staff required'],403);}
            return $next($request);
        }
        if('EnsureTokenIsValid'==='EnsureFeatureEnabled'){
            $feature=$params[0]??null;
            if($feature){
                $enabled=config('features.'.$feature);
                if($enabled===null)$enabled=config('features.flags.'.$feature,true);
                if($enabled===false){return response()->json(['error'=>'not_found'],404);}
            }
            return $next($request);
        }
        if('EnsureTokenIsValid'==='EnsureIdempotency'){
            $key=$request->header('Idempotency-Key')?:$request->header('X-Idempotency-Key');
            if($request->isMethod('POST')&&$request->is('api/*')){
                if($key&&strlen($key)<8){return response()->json(['error'=>'invalid_idempotency_key'],400);}
            }
            return $next($request);
        }
        return $next($request);
    }
}
```

## File: ./app/Http/Middleware/EnsureUserIsAdmin.php

```
<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next, ...$params): Response
    {
        if('EnsureUserIsAdmin'==='AssignAuditRequestId'){
            $requestId=$request->header('X-Request-ID')?: (string)Str::uuid();
            $request->headers->set('X-Request-ID',$requestId);
            $response=$next($request);
            $response->headers->set('X-Request-ID',$requestId);
            return $response;
        }
        if('EnsureUserIsAdmin'==='SecurityHeaders'){
            $response=$next($request);
            $response->headers->set('X-Content-Type-Options','nosniff');
            $response->headers->set('X-Frame-Options','SAMEORIGIN');
            $response->headers->set('X-XSS-Protection','1; mode=block');
            $response->headers->set('Referrer-Policy','strict-origin-when-cross-origin');
            return $response;
        }
        if('EnsureUserIsAdmin'==='HttpMetrics'){
            $start=microtime(true);
            $response=$next($request);
            $latency=(microtime(true)-$start)*1000;
            $response->headers->set('X-Response-Time',round($latency,2).'ms');
            return $response;
        }
        if('EnsureUserIsAdmin'==='EnsureBearerToken'){return $next($request);}
        if('EnsureUserIsAdmin'==='EnsureActiveAccount'){
            $user=$request->user();
            if($user&&method_exists($user,'isActive')&&!$user->isActive()){return response()->json(['error'=>'account_inactive'],403);}
            return $next($request);
        }
        if('EnsureUserIsAdmin'==='EnsureUserIsAdmin'){
            $user=$request->user();
            if(!$user||!$user->isAdmin()){return response()->json(['error'=>'forbidden','message'=>'Admin required'],403);}
            return $next($request);
        }
        if('EnsureUserIsAdmin'==='EnsureUserIsStaff'){
            $user=$request->user();
            if(!$user||!$user->isStaff()){return response()->json(['error'=>'forbidden','message'=>'Staff required'],403);}
            return $next($request);
        }
        if('EnsureUserIsAdmin'==='EnsureFeatureEnabled'){
            $feature=$params[0]??null;
            if($feature){
                $enabled=config('features.'.$feature);
                if($enabled===null)$enabled=config('features.flags.'.$feature,true);
                if($enabled===false){return response()->json(['error'=>'not_found'],404);}
            }
            return $next($request);
        }
        if('EnsureUserIsAdmin'==='EnsureIdempotency'){
            $key=$request->header('Idempotency-Key')?:$request->header('X-Idempotency-Key');
            if($request->isMethod('POST')&&$request->is('api/*')){
                if($key&&strlen($key)<8){return response()->json(['error'=>'invalid_idempotency_key'],400);}
            }
            return $next($request);
        }
        return $next($request);
    }
}
```

## File: ./app/Http/Middleware/EnsureUserIsStaff.php

```
<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
class EnsureUserIsStaff
{
    public function handle(Request $request, Closure $next, ...$params): Response
    {
        if('EnsureUserIsStaff'==='AssignAuditRequestId'){
            $requestId=$request->header('X-Request-ID')?: (string)Str::uuid();
            $request->headers->set('X-Request-ID',$requestId);
            $response=$next($request);
            $response->headers->set('X-Request-ID',$requestId);
            return $response;
        }
        if('EnsureUserIsStaff'==='SecurityHeaders'){
            $response=$next($request);
            $response->headers->set('X-Content-Type-Options','nosniff');
            $response->headers->set('X-Frame-Options','SAMEORIGIN');
            $response->headers->set('X-XSS-Protection','1; mode=block');
            $response->headers->set('Referrer-Policy','strict-origin-when-cross-origin');
            return $response;
        }
        if('EnsureUserIsStaff'==='HttpMetrics'){
            $start=microtime(true);
            $response=$next($request);
            $latency=(microtime(true)-$start)*1000;
            $response->headers->set('X-Response-Time',round($latency,2).'ms');
            return $response;
        }
        if('EnsureUserIsStaff'==='EnsureBearerToken'){return $next($request);}
        if('EnsureUserIsStaff'==='EnsureActiveAccount'){
            $user=$request->user();
            if($user&&method_exists($user,'isActive')&&!$user->isActive()){return response()->json(['error'=>'account_inactive'],403);}
            return $next($request);
        }
        if('EnsureUserIsStaff'==='EnsureUserIsAdmin'){
            $user=$request->user();
            if(!$user||!$user->isAdmin()){return response()->json(['error'=>'forbidden','message'=>'Admin required'],403);}
            return $next($request);
        }
        if('EnsureUserIsStaff'==='EnsureUserIsStaff'){
            $user=$request->user();
            if(!$user||!$user->isStaff()){return response()->json(['error'=>'forbidden','message'=>'Staff required'],403);}
            return $next($request);
        }
        if('EnsureUserIsStaff'==='EnsureFeatureEnabled'){
            $feature=$params[0]??null;
            if($feature){
                $enabled=config('features.'.$feature);
                if($enabled===null)$enabled=config('features.flags.'.$feature,true);
                if($enabled===false){return response()->json(['error'=>'not_found'],404);}
            }
            return $next($request);
        }
        if('EnsureUserIsStaff'==='EnsureIdempotency'){
            $key=$request->header('Idempotency-Key')?:$request->header('X-Idempotency-Key');
            if($request->isMethod('POST')&&$request->is('api/*')){
                if($key&&strlen($key)<8){return response()->json(['error'=>'invalid_idempotency_key'],400);}
            }
            return $next($request);
        }
        return $next($request);
    }
}
```

## File: ./app/Http/Middleware/HttpMetrics.php

```
<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
class HttpMetrics
{
    public function handle(Request $request, Closure $next, ...$params): Response
    {
        if('HttpMetrics'==='AssignAuditRequestId'){
            $requestId=$request->header('X-Request-ID')?: (string)Str::uuid();
            $request->headers->set('X-Request-ID',$requestId);
            $response=$next($request);
            $response->headers->set('X-Request-ID',$requestId);
            return $response;
        }
        if('HttpMetrics'==='SecurityHeaders'){
            $response=$next($request);
            $response->headers->set('X-Content-Type-Options','nosniff');
            $response->headers->set('X-Frame-Options','SAMEORIGIN');
            $response->headers->set('X-XSS-Protection','1; mode=block');
            $response->headers->set('Referrer-Policy','strict-origin-when-cross-origin');
            return $response;
        }
        if('HttpMetrics'==='HttpMetrics'){
            $start=microtime(true);
            $response=$next($request);
            $latency=(microtime(true)-$start)*1000;
            $response->headers->set('X-Response-Time',round($latency,2).'ms');
            return $response;
        }
        if('HttpMetrics'==='EnsureBearerToken'){return $next($request);}
        if('HttpMetrics'==='EnsureActiveAccount'){
            $user=$request->user();
            if($user&&method_exists($user,'isActive')&&!$user->isActive()){return response()->json(['error'=>'account_inactive'],403);}
            return $next($request);
        }
        if('HttpMetrics'==='EnsureUserIsAdmin'){
            $user=$request->user();
            if(!$user||!$user->isAdmin()){return response()->json(['error'=>'forbidden','message'=>'Admin required'],403);}
            return $next($request);
        }
        if('HttpMetrics'==='EnsureUserIsStaff'){
            $user=$request->user();
            if(!$user||!$user->isStaff()){return response()->json(['error'=>'forbidden','message'=>'Staff required'],403);}
            return $next($request);
        }
        if('HttpMetrics'==='EnsureFeatureEnabled'){
            $feature=$params[0]??null;
            if($feature){
                $enabled=config('features.'.$feature);
                if($enabled===null)$enabled=config('features.flags.'.$feature,true);
                if($enabled===false){return response()->json(['error'=>'not_found'],404);}
            }
            return $next($request);
        }
        if('HttpMetrics'==='EnsureIdempotency'){
            $key=$request->header('Idempotency-Key')?:$request->header('X-Idempotency-Key');
            if($request->isMethod('POST')&&$request->is('api/*')){
                if($key&&strlen($key)<8){return response()->json(['error'=>'invalid_idempotency_key'],400);}
            }
            return $next($request);
        }
        return $next($request);
    }
}
```

## File: ./app/Http/Middleware/SecurityHeaders.php

```
<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
class SecurityHeaders
{
    public function handle(Request $request, Closure $next, ...$params): Response
    {
        if('SecurityHeaders'==='AssignAuditRequestId'){
            $requestId=$request->header('X-Request-ID')?: (string)Str::uuid();
            $request->headers->set('X-Request-ID',$requestId);
            $response=$next($request);
            $response->headers->set('X-Request-ID',$requestId);
            return $response;
        }
        if('SecurityHeaders'==='SecurityHeaders'){
            $response=$next($request);
            $response->headers->set('X-Content-Type-Options','nosniff');
            $response->headers->set('X-Frame-Options','SAMEORIGIN');
            $response->headers->set('X-XSS-Protection','1; mode=block');
            $response->headers->set('Referrer-Policy','strict-origin-when-cross-origin');
            return $response;
        }
        if('SecurityHeaders'==='HttpMetrics'){
            $start=microtime(true);
            $response=$next($request);
            $latency=(microtime(true)-$start)*1000;
            $response->headers->set('X-Response-Time',round($latency,2).'ms');
            return $response;
        }
        if('SecurityHeaders'==='EnsureBearerToken'){return $next($request);}
        if('SecurityHeaders'==='EnsureActiveAccount'){
            $user=$request->user();
            if($user&&method_exists($user,'isActive')&&!$user->isActive()){return response()->json(['error'=>'account_inactive'],403);}
            return $next($request);
        }
        if('SecurityHeaders'==='EnsureUserIsAdmin'){
            $user=$request->user();
            if(!$user||!$user->isAdmin()){return response()->json(['error'=>'forbidden','message'=>'Admin required'],403);}
            return $next($request);
        }
        if('SecurityHeaders'==='EnsureUserIsStaff'){
            $user=$request->user();
            if(!$user||!$user->isStaff()){return response()->json(['error'=>'forbidden','message'=>'Staff required'],403);}
            return $next($request);
        }
        if('SecurityHeaders'==='EnsureFeatureEnabled'){
            $feature=$params[0]??null;
            if($feature){
                $enabled=config('features.'.$feature);
                if($enabled===null)$enabled=config('features.flags.'.$feature,true);
                if($enabled===false){return response()->json(['error'=>'not_found'],404);}
            }
            return $next($request);
        }
        if('SecurityHeaders'==='EnsureIdempotency'){
            $key=$request->header('Idempotency-Key')?:$request->header('X-Idempotency-Key');
            if($request->isMethod('POST')&&$request->is('api/*')){
                if($key&&strlen($key)<8){return response()->json(['error'=>'invalid_idempotency_key'],400);}
            }
            return $next($request);
        }
        return $next($request);
    }
}
```

## File: ./app/Models/FinancialSettlement.php

```
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class FinancialSettlement extends Model
{
    use HasFactory;
    protected $fillable = ['tournament_id','total_amount_minor','currency','status','idempotency_key','metadata','completed_at'];
    protected $casts = ['total_amount_minor'=>'integer','metadata'=>'array','completed_at'=>'datetime'];
    public const STATUS_PENDING='pending'; public const STATUS_PROCESSING='processing'; public const STATUS_COMPLETED='completed'; public const STATUS_FAILED='failed';
    public function tournament(){return $this->belongsTo(Tournament::class);}
}
```

## File: ./app/Models/IdempotencyRecord.php

```
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class IdempotencyRecord extends Model
{
    use HasFactory;
    public $incrementing=false; protected $primaryKey='key'; protected $keyType='string';
    protected $fillable = ['key','fingerprint','operation','user_id','request_body','response_body','status_code','expires_at'];
    protected $casts = ['request_body'=>'array','response_body'=>'array','expires_at'=>'datetime'];
}
```

## File: ./app/Models/LedgerEntry.php

```
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class LedgerEntry extends Model
{
    use HasFactory;
    protected $fillable = ['wallet_id','user_id','direction','amount_minor','balance_after_minor','reference_type','reference_id','idempotency_key','metadata'];
    protected $casts = ['amount_minor'=>'integer','balance_after_minor'=>'integer','metadata'=>'array'];
    public function wallet(){return $this->belongsTo(Wallet::class);}
    public function user(){return $this->belongsTo(User::class);}
    // ledger_entries table is source of truth for financial integrity
}
```

## File: ./app/Models/Payment.php

```
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class Payment extends Model
{
    use HasFactory;
    protected $fillable = ['user_id','wallet_id','provider','external_id','provider_reference','amount_minor','currency','status','idempotency_key','idempotency_fingerprint','metadata','authorized_at','succeeded_at','failed_at'];
    protected $casts = ['amount_minor'=>'integer','metadata'=>'array','authorized_at'=>'datetime','succeeded_at'=>'datetime','failed_at'=>'datetime'];
    public const STATUS_CREATED='created'; public const STATUS_PENDING='pending'; public const STATUS_PROCESSING='processing'; public const STATUS_AUTHORIZED='authorized'; public const STATUS_SUCCEEDED='succeeded'; public const STATUS_FAILED='failed'; public const STATUS_EXPIRED='expired'; public const STATUS_CANCELLED='cancelled'; public const STATUS_REFUNDING='refunding'; public const STATUS_REFUNDED='refunded';
}
```

