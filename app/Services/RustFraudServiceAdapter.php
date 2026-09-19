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
