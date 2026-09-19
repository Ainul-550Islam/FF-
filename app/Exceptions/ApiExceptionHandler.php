<?php
namespace App\Exceptions;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;
class ApiExceptionHandler
{
    public static function render(Throwable $e, Request $request)
    {
        if(!$request->is('api/*'))return null;
        $requestId=$request->header('X-Request-ID')?: \Illuminate\Support\Str::uuid()->toString();
        if($e instanceof ValidationException){
            return response()->json(['error'=>'validation_failed','message'=>'The given data was invalid.','errors'=>$e->errors(),'request_id'=>$requestId],422)->header('X-Request-ID',$requestId);
        }
        if($e instanceof HttpException){
            return response()->json(['error'=>'http_error','message'=>$e->getMessage()?:'HTTP error','request_id'=>$requestId],$e->getStatusCode())->header('X-Request-ID',$requestId);
        }
        $status=500; if(method_exists($e,'getStatusCode'))$status=$e->getStatusCode();
        $payload=['error'=>'server_error','message'=>app()->environment('production')?'Internal server error':$e->getMessage(),'request_id'=>$requestId];
        return response()->json($payload,$status)->header('X-Request-ID',$requestId);
    }
}
