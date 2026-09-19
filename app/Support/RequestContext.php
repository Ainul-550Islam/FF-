<?php
namespace App\Support;
use Illuminate\Support\Str;
class RequestContext
{
    public static function snapshot(): array
    {
        try{
            $request=request();
            return ['request_id'=>$request->header('X-Request-ID')?: (string)Str::uuid(),'method'=>$request->method(),'path'=>$request->path(),'ip'=>$request->ip(),'user_id'=>$request->user()?->id,'timestamp'=>now()->toIso8601String()];
        }catch(\Throwable){return ['request_id'=>(string)Str::uuid(),'timestamp'=>now()->toIso8601String()];}
    }
}
